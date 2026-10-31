<?php
/**
 * SealedBox - authenticated-encryption asymmetric sibling to SecretBox.
 *
 * SecretBox is one symmetric key the server holds and uses to protect its own
 * secrets. SealedBox is the primitive behind the Sealed Vault
 * (docs/sealed_vault.md): every user gets an X25519 keypair whose PUBLIC half
 * is cleartext at rest (anything can seal to it, even while the user is
 * offline) and whose SECRET half exists at rest only as wrappings — one per
 * enrolled unlocker (passkey PRF, recovery code, optional passphrase).
 *
 * Unlike SecretBox, this class has NO fallback: crypto_box_seal has no clean
 * OpenSSL equivalent, so the constructor throws when ext-sodium is absent
 * rather than silently degrading. Every output is a self-describing versioned
 * base64url blob (`v1.<kind>.<part>.<part>...`) so a value always carries the
 * algorithm that produced it. Fails closed throughout: malformed input,
 * tampered ciphertext, or an AD mismatch all raise RuntimeException; nothing
 * is ever returned half-verified.
 *
 * Whole FILES are not sealed here: SealedFileContainer is the platform's one
 * chunked format, and VaultCrypto::sealFieldFile()/openFieldFile() put it
 * behind the same DEK and AD discipline as the string forms.
 *
 * Beside the libsodium forms sits the BROWSER format (sealEdge/openEdge,
 * aeadEncryptGcm/aeadDecryptGcm): the exact bytes vault-crypto.js produces —
 * X25519 ECIES with HKDF-SHA256 and AES-256-GCM for a DEK, AES-256-GCM with an
 * AD for content, standard base64 of raw bytes. It exists so the server can
 * seal to a client-custody scope's key and open what a browser sealed to a
 * server key. These are raw primitives like the rest; the `v1.edgeseal.` /
 * `v1.edge.` framing belongs to the row layer (VaultCrypto).
 *
 * @version 2.0 - v2 seals (specs/post_quantum_sealing.md R2): generateKeypair() mints an X-Wing
 *                seed and 1216-byte key; sealDek()/sealBinary()/sealEdge() write v2 only and refuse
 *                a 32-byte key; openers choose by frame, public key or the caller's version;
 *                sealV2()/openV2() with a per-use scope; v2Key()/openV2WithKey() for a
 *                holder that decapsulates elsewhere and hands over only the dek-scope key; the v1 openers stay
 *                for the rotation off v1
 * @version 1.9 - pqReady()/pqReadyReason()/pqReadyCause(): whether this process can reach ML-KEM
 *                (specs/post_quantum_sealing.md R4)
 * @version 1.8 - the `v1.stream.` file format retired: files seal as SealedFileContainer
 * @version 1.7 - kekFromPassphrase() retired: the browser derives a phrase's KEK
 * @version 1.6 - the browser format: sealEdge()/openEdge() and
 *                aeadEncryptGcm()/aeadDecryptGcm(), byte-compatible with
 *                vault-crypto.js (tests/vault/edge_format_test.php)
 * @version 1.5 - unframeSeal() splits the `v1.seal.` text form from the raw
 *                crypto_box_seal bytes, so a VaultKey (which opens raw bytes
 *                only) can be handed a stored DEK; openDek() is that plus
 *                openBinary()
 * @version 1.4 - openStreamFile's plaintext is 0600 from the byte it is created, not
 *                whatever the umask gives: the destination may be a shared tmpfs
 *                (specs/vault_exposure_quick_fixes.md Q1)
 * @version 1.3
 */
class SealedBox {

	const RECOVERY_CODE_BYTES = 16;   // 128 bits, encoded to 26 Crockford-base32 chars
	const CROCKFORD_ALPHABET  = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	// Minimum length for a vault passphrase, enforced by EVERY enrollment path
	// (setup, enroll, rotation resupply). The vault is exactly as strong as its
	// weakest wrapping; a short passphrase is an offline-guessable unlocker for
	// anyone holding a copy of the database.
	const PASSPHRASE_MIN_CHARS = 12;

	public function __construct() {
		if (!extension_loaded('sodium')) {
			throw new RuntimeException(
				'SealedBox: the sodium extension is required and is not loaded. '
				. 'The Sealed Vault has no fallback for asymmetric sealing.'
			);
		}
	}

	private static $pq_reason = false;

	/**
	 * Whether this process can seal with post-quantum keys: FFI is loaded and
	 * allowed in this SAPI, libcrypto is OpenSSL 3.5 or later, and one ML-KEM
	 * encapsulate/decapsulate round trip agrees. Answered once per process.
	 */
	public static function pqReady(): bool {
		return self::pqReadyReason() === null;
	}

	/**
	 * Null when pqReady(), otherwise one plain sentence naming what is missing
	 * and the floor (Ubuntu 26.04, ffi.enable = On). Never throws.
	 */
	public static function pqReadyReason(): ?string {
		if (self::$pq_reason !== false) {
			return self::$pq_reason;
		}
		$why = MlKem768::unavailableReason();
		if ($why === null) {
			try {
				$kem = new MlKem768();
				$seed = random_bytes(MlKem768::SEED_BYTES);
				[$ct, $ss] = $kem->encapsulate($kem->keygen($seed));
				if (!hash_equals($ss, $kem->decapsulate($seed, $ct))) {
					$why = 'an ML-KEM round trip did not agree';
				}
			} catch (Throwable $e) {
				$why = 'an ML-KEM round trip failed: ' . $e->getMessage();
			}
		}
		self::$pq_reason = $why === null ? null
			: 'This server is not ready for post-quantum key protection (' . $why . '). ' . MlKem768::FLOOR_MESSAGE;
		return self::$pq_reason;
	}

	/**
	 * Null when pqReady(); otherwise one of MlKem768's CAUSE_* codes, or
	 * 'round_trip' when ML-KEM loaded but did not agree with itself. Lets a
	 * caller name the fix: configuration for a setting, a newer OS for OpenSSL.
	 */
	public static function pqReadyCause(): ?string {
		if (self::pqReadyReason() === null) {
			return null;
		}
		return MlKem768::unavailableCause() ?? 'round_trip';
	}

	// ------------------------------------------------------------------
	// Keys and the two seal versions (specs/post_quantum_sealing.md R2).
	//
	// v2 (every seal written): X-Wing to a 1216-byte key, then AES-256-GCM.
	//   raw = ct[1120] ‖ iv[12] ‖ AES-256-GCM(plaintext) ‖ tag[16]
	//   key = HKDF-SHA256(ss, salt = '', info = 'joinery-seal:v2:' ‖ scope)
	// v1 (opened only, for the rotation off it): libsodium crypto_box_seal to a
	//   32-byte X25519 key, and the browser's X25519 ECIES (sealEdge's v1 half).
	//
	// A public key names its version by its length. A secret cannot: a v1
	// X25519 secret and a v2 seed are both 32 bytes, so an opener learns the
	// version from the blob's frame, the public key it is handed, or its caller.
	// ------------------------------------------------------------------

	const V1 = 1;
	const V2 = 2;

	const V2_INFO      = 'joinery-seal:v2:';
	const SCOPE_DEK    = 'dek';
	const V2_OVERHEAD  = 1148;   // XWing::CIPHERTEXT_BYTES + IV + tag

	/** What every sealer says when handed a v1 (32-byte X25519) public key. */
	const V1_KEY_REFUSAL = 'This is an old X25519 (v1) key, and nothing is sealed to one any more: its holder owes a key rotation to a post-quantum key.';

	/**
	 * Generate a fresh keypair: a 32-byte X-Wing seed and its 1216-byte public
	 * key. The public half is safe to store in cleartext; the secret half must
	 * never be persisted unwrapped.
	 *
	 * @return array{public:string,secret:string,version:int} base64url-encoded halves
	 */
	public function generateKeypair(): array {
		$seed = random_bytes(XWing::SEED_BYTES);
		$public = (new XWing())->publicKey($seed);
		$out = [
			'public'  => self::b64url($public),
			'secret'  => self::b64url($seed),
			'version' => self::V2,
		];
		sodium_memzero($seed);
		return $out;
	}

	/**
	 * The public key a secret generates, base64url, for the version the caller
	 * knows it to be (a secret cannot say). v1: the X25519 base-point multiple;
	 * v2: X-Wing key generation from the seed.
	 */
	public static function publicKeyFor(string $secret_key, int $version): string {
		$raw = self::b64url_decode($secret_key);
		if ($raw === false || strlen($raw) !== 32) {
			throw new RuntimeException('SealedBox: malformed secret key.');
		}
		$public = $version === self::V2 ? (new XWing())->publicKey($raw) : sodium_crypto_scalarmult_base($raw);
		sodium_memzero($raw);
		return self::b64url($public);
	}

	/**
	 * The version a raw public key names: 1 for a 32-byte X25519 key, 2 for a
	 * 1216-byte X-Wing key. Throws on anything else.
	 */
	public static function publicKeyVersion(string $public_raw): int {
		$len = strlen($public_raw);
		if ($len === XWing::PUBLIC_KEY_BYTES) {
			return self::V2;
		}
		if ($len === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
			return self::V1;
		}
		throw new RuntimeException('SealedBox: malformed public key.');
	}

	/**
	 * Seal bytes to a raw 1216-byte X-Wing key: the v2 raw form. $scope is the
	 * HKDF label that separates one use from another ('dek' for every key
	 * seal; a challenge passes its own), so a box made for one scope never
	 * opens as another. A 32-byte key is refused with V1_KEY_REFUSAL.
	 */
	public static function sealV2(string $bytes, string $public_raw, string $scope = self::SCOPE_DEK): string {
		return self::sealV2With($bytes, $public_raw, $scope, null, random_bytes(self::EDGE_IV_BYTES));
	}

	/**
	 * Open a sealV2() box with the 32-byte seed, under the same scope. Throws on
	 * tamper, a wrong seed, or a scope that is not the one it was sealed for.
	 */
	public static function openV2(string $raw, string $seed_raw, string $scope = self::SCOPE_DEK): string {
		if (strlen($raw) < self::V2_OVERHEAD) {
			throw new RuntimeException('SealedBox: malformed v2 sealed box.');
		}
		$ss = (new XWing())->decapsulate($seed_raw, substr($raw, 0, XWing::CIPHERTEXT_BYTES));
		$aes = self::v2Kdf($ss, $scope);
		sodium_memzero($ss);
		try {
			return self::openV2WithKey($raw, $aes);
		} finally {
			sodium_memzero($aes);
		}
	}

	/**
	 * The AES key a v2 box of $scope opens with, from the X-Wing shared
	 * secret. A holder that decapsulates elsewhere (a browser that will not
	 * hand over the seed) derives this for the `dek` scope and hands over THIS,
	 * never the shared secret: the shared secret depends only on the
	 * ciphertext and the seed, so whoever chose the ciphertext could turn it
	 * into the key for any scope, an approval challenge included. The scoped
	 * key opens only boxes of its own scope (spec R2).
	 */
	public static function v2Key(string $shared, string $scope = self::SCOPE_DEK): string {
		if (strlen($shared) !== XWing::SHARED_BYTES) {
			throw new RuntimeException('SealedBox: an X-Wing shared secret is ' . XWing::SHARED_BYTES . ' bytes.');
		}
		return self::v2Kdf($shared, $scope);
	}

	/**
	 * The second half of a v2 open, given the scope's AES key (v2Key()): what
	 * a holder that decapsulates elsewhere leaves for the opener to finish.
	 */
	public static function openV2WithKey(string $raw, string $aes_key): string {
		if (strlen($raw) < self::V2_OVERHEAD || strlen($aes_key) !== 32) {
			throw new RuntimeException('SealedBox: malformed v2 sealed box.');
		}
		$plain = self::gcmDecrypt(substr($raw, XWing::CIPHERTEXT_BYTES + self::EDGE_IV_BYTES), $aes_key,
			substr($raw, XWing::CIPHERTEXT_BYTES, self::EDGE_IV_BYTES), '');
		if ($plain === null) {
			throw new RuntimeException('SealedBox: unseal failed (tampered, wrong key, or another scope\'s box).');
		}
		return $plain;
	}

	/** sealV2() with its randomness supplied — private so only the test vector can fix it. */
	private static function sealV2With(string $bytes, string $public_raw, string $scope, ?string $eseed, string $iv): string {
		if (self::publicKeyVersion($public_raw) !== self::V2) {
			throw new RuntimeException(self::V1_KEY_REFUSAL);
		}
		[$ct, $ss] = (new XWing())->encapsulate($public_raw, $eseed);
		$aes = self::v2Kdf($ss, $scope);
		sodium_memzero($ss);
		$sealed = self::gcmEncrypt($bytes, $aes, $iv, '');
		sodium_memzero($aes);
		return $ct . $iv . $sealed;
	}

	private static function v2Kdf(string $shared, string $scope): string {
		if ($scope === '') {
			throw new RuntimeException('SealedBox: a v2 seal names its scope.');
		}
		return hash_hkdf('sha256', $shared, 32, self::V2_INFO . $scope, '');
	}

	/**
	 * Seal arbitrary bytes (a per-item DEK) to a public key: `v2.seal.` +
	 * base64url of the v2 raw form. Anonymous — anyone holding the public key
	 * can seal, only the secret key can open. A v1 key is refused.
	 */
	public function sealDek(string $bytes, string $public_key): string {
		return 'v2.seal.' . self::b64url($this->sealBinary($bytes, $public_key));
	}

	/**
	 * Open a `v1.seal.` or `v2.seal.` blob. The frame names the version, so
	 * the secret is read as an X25519 key or an X-Wing seed accordingly.
	 * Throws on tamper or a wrong secret key.
	 */
	public function openDek(string $sealed, string $secret_key): string {
		[$version, $raw] = self::unframeSeal($sealed);
		return $this->openBinary($raw, $secret_key, $version);
	}

	/**
	 * Split a sealDek() blob into its version and the raw sealed bytes — the
	 * form a VaultKey::unseal() takes. Throws on anything that is not a
	 * well-formed `v1.seal.` or `v2.seal.` blob; nothing is decrypted here.
	 *
	 * @return array{0:int,1:string} [version, raw bytes]
	 */
	public static function unframeSeal(string $sealed): array {
		$parts = explode('.', $sealed);
		if (count($parts) !== 3 || ($parts[0] !== 'v1' && $parts[0] !== 'v2') || $parts[1] !== 'seal') {
			throw new RuntimeException('SealedBox: malformed sealed blob.');
		}
		$ciphertext = self::b64url_decode($parts[2]);
		if ($ciphertext === false) {
			throw new RuntimeException('SealedBox: malformed sealed blob encoding.');
		}
		return [$parts[0] === 'v2' ? self::V2 : self::V1, $ciphertext];
	}

	/**
	 * Seal BULK bytes to a public key, RAW: the v2 raw form with no `v2.seal.`
	 * prefix and no base64url. A v1 key is refused.
	 *
	 * sealDek's text wrapping is right for a ~32-byte DEK travelling in a text
	 * column. For a multi-megabyte payload it is dead weight: base64 inflates the
	 * wire by a third, and the encoded string sits in memory beside both the
	 * plaintext and the raw ciphertext — three copies at the peak. A large
	 * Joinery Direct part seals with this and pays for none of that.
	 */
	public function sealBinary(string $bytes, string $public_key): string {
		$public_raw = self::b64url_decode($public_key);
		if ($public_raw === false) {
			throw new RuntimeException('SealedBox: malformed public key.');
		}
		return self::sealV2($bytes, $public_raw);
	}

	/**
	 * Open raw sealed bytes with a secret of the version the caller names (raw
	 * bytes carry no frame). v1: a crypto_box_seal, the public half derived from
	 * the secret; v2: sealBinary()'s form under the `dek` scope. Throws on tamper
	 * or a wrong secret key — including a box of the other version.
	 */
	public function openBinary(string $sealed, string $secret_key, int $version): string {
		$secret_raw = self::b64url_decode($secret_key);
		if ($secret_raw === false || strlen($secret_raw) !== 32) {
			throw new RuntimeException('SealedBox: malformed secret key.');
		}
		if ($version === self::V2) {
			try {
				return self::openV2($sealed, $secret_raw);
			} finally {
				sodium_memzero($secret_raw);
			}
		}
		if ($version !== self::V1) {
			throw new RuntimeException('SealedBox: unknown seal version ' . $version . '.');
		}
		$public_raw = sodium_crypto_box_publickey_from_secretkey($secret_raw);
		$keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret_raw, $public_raw);
		sodium_memzero($secret_raw);
		$plain = sodium_crypto_box_seal_open($sealed, $keypair);
		sodium_memzero($keypair);
		if ($plain === false) {
			throw new RuntimeException('SealedBox: unseal failed (tampered, wrong keypair, or a v2 box handed a v1 key).');
		}
		return $plain;
	}

	/**
	 * Authenticated encryption (xchacha20poly1305_ietf) of arbitrary plaintext
	 * under a symmetric key, with additional data binding the ciphertext to
	 * its row (splice defense — see docs/sealed_vault.md). AD is NOT stored in
	 * the blob; the caller must supply the identical AD again to decrypt.
	 */
	public function aeadEncrypt(string $plaintext, string $key, string $ad): string {
		if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
			throw new RuntimeException('SealedBox: AEAD key must be exactly '
				. SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES . ' bytes.');
		}
		$nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
		$cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $ad, $nonce, $key);
		return 'v1.aead.' . self::b64url($nonce) . '.' . self::b64url($cipher);
	}

	/**
	 * Decrypt a blob produced by aeadEncrypt(). Throws on tamper, a malformed
	 * blob, or an AD mismatch (a ciphertext spliced onto a different row).
	 */
	public function aeadDecrypt(string $blob, string $key, string $ad): string {
		$parts = explode('.', $blob);
		if (count($parts) !== 4 || $parts[0] !== 'v1' || $parts[1] !== 'aead') {
			throw new RuntimeException('SealedBox: malformed AEAD blob.');
		}
		$nonce = self::b64url_decode($parts[2]);
		$cipher = self::b64url_decode($parts[3]);
		if ($nonce === false || $cipher === false) {
			throw new RuntimeException('SealedBox: malformed AEAD blob encoding.');
		}
		$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $ad, $nonce, $key);
		if ($plain === false) {
			throw new RuntimeException('SealedBox: AEAD decryption failed (tampered, wrong key, or AD mismatch).');
		}
		return $plain;
	}

	// ------------------------------------------------------------------
	// The browser format — byte-compatible with assets/js/vault-crypto.js.
	//
	// sealed DEK : base64 of the v2 raw form above, to a 1216-byte key. (v1, opened
	//              only: base64( ephPub[32] ‖ IV[12] ‖ AES-256-GCM(ct ‖ tag[16]) ),
	//              the AES key = HKDF-SHA256(X25519(eph, recipient), salt = empty,
	//              info = 'sealed-vault:dek' ‖ ephPub ‖ recipientPub).)
	// field      : base64( IV[12] ‖ AES-256-GCM(ct ‖ tag[16]) ), AD = the caller's
	//
	// WebCrypto appends the tag to the ciphertext; OpenSSL hands it back apart,
	// so these glue it on and split it off. A public key is accepted in either
	// base64 alphabet: a browser stores standard base64, a server vault base64url.
	// ------------------------------------------------------------------

	const EDGE_KDF_LABEL = 'sealed-vault:dek';
	const EDGE_IV_BYTES  = 12;
	const EDGE_TAG_BYTES = 16;

	/** Seal bytes (a DEK) to a 1216-byte public key, standard base64 of the v2 raw form. A v1 key is refused. */
	public function sealEdge(string $bytes, string $recipient_pub_b64): string {
		return base64_encode(self::sealV2($bytes, self::edgePublicKey($recipient_pub_b64)));
	}

	/**
	 * Open a sealEdge() blob (or a vault-crypto.js sealToPublicKey() one) with
	 * the recipient's secret. The recipient's public key names the version (32
	 * bytes v1, 1216 bytes v2); it is DERIVED from the secret for that version
	 * and checked against the one the caller names, so a mismatched pair is
	 * refused rather than silently failing the tag.
	 */
	public function openEdge(string $blob_b64, string $secret_b64url, string $recipient_pub_b64): string {
		$recipient_raw = self::edgePublicKey($recipient_pub_b64);
		$version = self::publicKeyVersion($recipient_raw);
		$derived = self::b64url_decode(self::publicKeyFor($secret_b64url, $version));
		if (!hash_equals($derived, $recipient_raw)) {
			throw new RuntimeException('SealedBox: the public key named is not this secret key\'s.');
		}
		$raw = self::b64any_decode($blob_b64);
		if ($raw === false) {
			throw new RuntimeException('SealedBox: malformed edge-sealed blob.');
		}
		$secret_raw = self::b64url_decode($secret_b64url);
		if ($version === self::V2) {
			try {
				return self::openV2($raw, $secret_raw);
			} finally {
				sodium_memzero($secret_raw);
			}
		}
		if (strlen($raw) < 32 + self::EDGE_IV_BYTES + self::EDGE_TAG_BYTES) {
			throw new RuntimeException('SealedBox: malformed edge-sealed blob.');
		}
		$eph_pub = substr($raw, 0, 32);
		$iv = substr($raw, 32, self::EDGE_IV_BYTES);
		$ct = substr($raw, 32 + self::EDGE_IV_BYTES);
		$aes = self::edgeKdf($secret_raw, $eph_pub, $eph_pub, $recipient_raw);
		sodium_memzero($secret_raw);
		$plain = self::gcmDecrypt($ct, $aes, $iv, '');
		sodium_memzero($aes);
		if ($plain === null) {
			throw new RuntimeException('SealedBox: edge unseal failed (tampered or wrong keypair).');
		}
		return $plain;
	}

	/** AES-256-GCM of content under a 32-byte key, bound to $ad: base64(IV ‖ ct ‖ tag). */
	public function aeadEncryptGcm(string $plaintext, string $key, string $ad): string {
		return self::aeadEncryptGcmWith($plaintext, $key, $ad, random_bytes(self::EDGE_IV_BYTES));
	}

	/** Open an aeadEncryptGcm() blob (or a vault-crypto.js encrypt() one). Throws on tamper or an AD mismatch. */
	public function aeadDecryptGcm(string $blob_b64, string $key, string $ad): string {
		self::assertGcmKey($key);
		$raw = self::b64any_decode($blob_b64);
		if ($raw === false || strlen($raw) < self::EDGE_IV_BYTES + self::EDGE_TAG_BYTES) {
			throw new RuntimeException('SealedBox: malformed GCM blob.');
		}
		$plain = self::gcmDecrypt(substr($raw, self::EDGE_IV_BYTES), $key, substr($raw, 0, self::EDGE_IV_BYTES), $ad);
		if ($plain === null) {
			throw new RuntimeException('SealedBox: GCM decryption failed (tampered, wrong key, or AD mismatch).');
		}
		return $plain;
	}

	/** aeadEncryptGcm() with its IV supplied — private so only the test vector can fix it. */
	private static function aeadEncryptGcmWith(string $plaintext, string $key, string $ad, string $iv): string {
		self::assertGcmKey($key);
		return base64_encode($iv . self::gcmEncrypt($plaintext, $key, $iv, $ad));
	}

	/** The v1 ECIES AES key: HKDF-SHA256 over the X25519 shared secret, both public keys in the info. */
	private static function edgeKdf(string $own_secret, string $peer_public, string $eph_pub, string $recipient_pub): string {
		try {
			$shared = sodium_crypto_scalarmult($own_secret, $peer_public);
		} catch (SodiumException $e) {
			throw new RuntimeException('SealedBox: X25519 refused the key (a low-order point).');
		}
		$aes = hash_hkdf('sha256', $shared, 32, self::EDGE_KDF_LABEL . $eph_pub . $recipient_pub, '');
		sodium_memzero($shared);
		return $aes;
	}

	/** A public key in either base64 alphabet, raw; 32 or 1216 bytes (publicKeyVersion() refuses anything else). */
	private static function edgePublicKey(string $b64): string {
		$raw = self::b64any_decode($b64);
		if ($raw === false) {
			throw new RuntimeException('SealedBox: malformed public key.');
		}
		self::publicKeyVersion($raw);
		return $raw;
	}

	private static function assertGcmKey(string $key): void {
		if (strlen($key) !== 32) {
			throw new RuntimeException('SealedBox: AES-256-GCM key must be exactly 32 bytes.');
		}
	}

	private static function gcmEncrypt(string $plaintext, string $key, string $iv, string $ad): string {
		$tag = '';
		$ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $ad, self::EDGE_TAG_BYTES);
		if ($ct === false) {
			throw new RuntimeException('SealedBox: AES-256-GCM encryption failed.');
		}
		return $ct . $tag;
	}

	/** null on any authentication failure; the callers word the refusal. */
	private static function gcmDecrypt(string $ct_and_tag, string $key, string $iv, string $ad): ?string {
		if (strlen($ct_and_tag) < self::EDGE_TAG_BYTES) {
			return null;
		}
		$tag = substr($ct_and_tag, -self::EDGE_TAG_BYTES);
		$ct = substr($ct_and_tag, 0, -self::EDGE_TAG_BYTES);
		$plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $ad);
		return $plain === false ? null : $plain;
	}

	/** Strict base64 in either alphabet, padding optional. */
	private static function b64any_decode(string $s) {
		$s = strtr($s, '-_', '+/');
		$pad = strlen($s) % 4;
		if ($pad) {
			$s .= str_repeat('=', 4 - $pad);
		}
		return base64_decode($s, true);
	}

	/**
	 * Wrap a raw secret key (e.g. the vault's X25519 secret) under a KEK
	 * derived from an unlocker (passkey PRF output, recovery-code hash,
	 * passphrase hash). Same primitive as aeadEncrypt, named for the specific
	 * "one key wraps another" use.
	 */
	public function wrapKey(string $secret_key, string $kek, string $ad): string {
		return $this->aeadEncrypt($secret_key, $kek, $ad);
	}

	public function unwrapKey(string $wrapped, string $kek, string $ad): string {
		return $this->aeadDecrypt($wrapped, $kek, $ad);
	}

	/**
	 * Derive a KEK from a recovery code. Recovery codes carry >=128 bits of
	 * their own entropy, so a slow KDF adds cost without adding security — a
	 * fast keyed hash (BLAKE2b via crypto_generichash) is the correct choice.
	 * The code is normalized (uppercased, hyphens/whitespace stripped) so it
	 * hashes the same whether the user pastes it grouped or ungrouped.
	 */
	public function kekFromRecoveryCode(string $code, string $salt): string {
		$normalized = self::normalizeRecoveryCode($code);
		$salt_raw = self::b64url_decode($salt);
		if ($salt_raw === false) {
			throw new RuntimeException('SealedBox: malformed salt.');
		}
		return sodium_crypto_generichash($normalized, $salt_raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
	}

	/**
	 * A fresh 16-byte salt for a vault row's `uev_salt` (the keyed-hash key
	 * kekFromRecoveryCode() uses for codes made before code sets). A passphrase
	 * is derived in the browser, never here (specs/one_vault_experience.md § R7).
	 */
	public function generateSalt(): string {
		return self::b64url(random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES));
	}

	/**
	 * A single recovery code: 16 random bytes (128 bits) Crockford-base32
	 * encoded to 26 characters, grouped for readability. Crockford's alphabet
	 * excludes I/L/O/U to avoid transcription confusion.
	 */
	public function generateRecoveryCode(): string {
		$raw = self::crockfordEncode(random_bytes(self::RECOVERY_CODE_BYTES));
		return implode('-', str_split($raw, 5));
	}

	/**
	 * Strip grouping/whitespace and uppercase, so entry format never matters
	 * for hashing — then apply Crockford base32's canonical read-side
	 * substitutions (O reads as 0, I and L read as 1; U is excluded from the
	 * alphabet entirely). Generated codes never contain the confusable
	 * letters, so the mapping only converts a mistranscribed code into the
	 * one that was actually printed.
	 */
	public static function normalizeRecoveryCode(string $code): string {
		$stripped = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $code));
		return strtr($stripped, array('O' => '0', 'I' => '1', 'L' => '1'));
	}

	private static function crockfordEncode(string $bytes): string {
		$bits = '';
		for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
			$bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
		}
		$out = '';
		foreach (str_split($bits, 5) as $chunk) {
			$chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
			$out .= self::CROCKFORD_ALPHABET[bindec($chunk)];
		}
		return $out;
	}

	public static function b64url(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}

	public static function b64url_decode(string $s) {
		return base64_decode(strtr($s, '-_', '+/'), true);
	}
}
?>

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
 * @version 1.0
 */
class SealedBox {

	const RECOVERY_CODE_BYTES = 16;   // 128 bits, encoded to 26 Crockford-base32 chars
	const CROCKFORD_ALPHABET  = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	public function __construct() {
		if (!extension_loaded('sodium')) {
			throw new RuntimeException(
				'SealedBox: the sodium extension is required and is not loaded. '
				. 'The Sealed Vault has no fallback for asymmetric sealing.'
			);
		}
	}

	/**
	 * Generate a fresh X25519 keypair. The public half is safe to store in
	 * cleartext; the secret half must never be persisted unwrapped.
	 *
	 * @return array{public:string,secret:string} base64url-encoded halves
	 */
	public function generateKeypair(): array {
		$kp = sodium_crypto_box_keypair();
		$public = sodium_crypto_box_publickey($kp);
		$secret = sodium_crypto_box_secretkey($kp);
		sodium_memzero($kp);
		return [
			'public' => self::b64url($public),
			'secret' => self::b64url($secret),
		];
	}

	/**
	 * Seal arbitrary bytes (a per-item DEK) to a public key. Anonymous —
	 * anyone holding the public key can seal, only the secret key can open.
	 */
	public function sealDek(string $bytes, string $public_key): string {
		$public_raw = self::b64url_decode($public_key);
		if ($public_raw === false || strlen($public_raw) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
			throw new RuntimeException('SealedBox: malformed public key.');
		}
		$sealed = sodium_crypto_box_seal($bytes, $public_raw);
		return 'v1.seal.' . self::b64url($sealed);
	}

	/**
	 * Open a blob produced by sealDek(). Throws on tamper or a mismatched
	 * keypair.
	 */
	public function openDek(string $sealed, string $public_key, string $secret_key): string {
		$parts = explode('.', $sealed);
		if (count($parts) !== 3 || $parts[0] !== 'v1' || $parts[1] !== 'seal') {
			throw new RuntimeException('SealedBox: malformed sealed blob.');
		}
		$ciphertext = self::b64url_decode($parts[2]);
		$public_raw = self::b64url_decode($public_key);
		$secret_raw = self::b64url_decode($secret_key);
		if ($ciphertext === false || $public_raw === false || $secret_raw === false) {
			throw new RuntimeException('SealedBox: malformed sealed blob encoding.');
		}

		$keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret_raw, $public_raw);
		$plain = sodium_crypto_box_seal_open($ciphertext, $keypair);
		sodium_memzero($keypair);
		if ($plain === false) {
			throw new RuntimeException('SealedBox: unseal failed (tampered or wrong keypair).');
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
	 * Derive a KEK from a user passphrase via Argon2id, at least the
	 * INTERACTIVE cost profile (a passphrase is much lower entropy than a
	 * recovery code, so it needs a deliberately slow KDF).
	 */
	public function kekFromPassphrase(string $passphrase, string $salt): string {
		$salt_raw = self::b64url_decode($salt);
		if ($salt_raw === false || strlen($salt_raw) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) {
			throw new RuntimeException('SealedBox: passphrase salt must decode to '
				. SODIUM_CRYPTO_PWHASH_SALTBYTES . ' bytes.');
		}
		return sodium_crypto_pwhash(
			SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
			$passphrase,
			$salt_raw,
			SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
		);
	}

	/**
	 * A fresh KDF salt, sized for kekFromPassphrase()'s Argon2id requirement
	 * (also reused as the keyed-hash key in kekFromRecoveryCode() — 16 bytes
	 * is within crypto_generichash's valid key-length range). One `uev_salt`
	 * column serves both unlockers.
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

	/** Strip grouping/whitespace and uppercase, so entry format never matters for hashing. */
	public static function normalizeRecoveryCode(string $code): string {
		return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $code));
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

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
 * Alongside the string forms there is one FILE form: the chunked secretstream
 * format (`v1.stream.`, sealStreamFile/openStreamFile), path-to-path with
 * memory bounded by one chunk — for content too large to ever hold as a
 * string, such as the sealed mailbox search index.
 *
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
	 * Open a blob produced by sealDek(). Throws on tamper or a wrong secret
	 * key. The matching public key is DERIVED from the secret key — a caller
	 * can never supply a mismatched pair, so the one thing it must get right
	 * is the one thing it holds: the in-window secret.
	 */
	public function openDek(string $sealed, string $secret_key): string {
		$parts = explode('.', $sealed);
		if (count($parts) !== 3 || $parts[0] !== 'v1' || $parts[1] !== 'seal') {
			throw new RuntimeException('SealedBox: malformed sealed blob.');
		}
		$ciphertext = self::b64url_decode($parts[2]);
		$secret_raw = self::b64url_decode($secret_key);
		if ($ciphertext === false || $secret_raw === false || strlen($secret_raw) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
			throw new RuntimeException('SealedBox: malformed sealed blob encoding.');
		}

		$public_raw = sodium_crypto_box_publickey_from_secretkey($secret_raw);
		$keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret_raw, $public_raw);
		$plain = sodium_crypto_box_seal_open($ciphertext, $keypair);
		sodium_memzero($keypair);
		if ($plain === false) {
			throw new RuntimeException('SealedBox: unseal failed (tampered or wrong keypair).');
		}
		return $plain;
	}

	/**
	 * Seal BULK bytes to a public key, RAW. The same anonymous crypto_box_seal
	 * as sealDek, but the output is the sealed bytes themselves — no `v1.seal.`
	 * prefix and no base64url.
	 *
	 * sealDek's text wrapping is right for a ~32-byte DEK travelling in a text
	 * column. For a multi-megabyte payload it is dead weight: base64 inflates the
	 * wire by a third, and the encoded string sits in memory beside both the
	 * plaintext and the raw ciphertext — three copies at the peak. A large
	 * Joinery Direct part seals with this and pays for none of that.
	 */
	public function sealBinary(string $bytes, string $public_key): string {
		$public_raw = self::b64url_decode($public_key);
		if ($public_raw === false || strlen($public_raw) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
			throw new RuntimeException('SealedBox: malformed public key.');
		}
		return sodium_crypto_box_seal($bytes, $public_raw);
	}

	/**
	 * Open raw bytes produced by sealBinary(). Throws on tamper or a wrong secret
	 * key; like openDek() the public key is derived from the secret, so a caller
	 * can never supply a mismatched pair.
	 */
	public function openBinary(string $sealed, string $secret_key): string {
		$secret_raw = self::b64url_decode($secret_key);
		if ($secret_raw === false || strlen($secret_raw) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
			throw new RuntimeException('SealedBox: malformed secret key.');
		}
		$public_raw = sodium_crypto_box_publickey_from_secretkey($secret_raw);
		$keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret_raw, $public_raw);
		$plain = sodium_crypto_box_seal_open($sealed, $keypair);
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

	// ------------------------------------------------------------------
	// The streaming sealed-file format (v1.stream.) — authenticated encryption
	// of a whole FILE in bounded memory, built on libsodium's secretstream.
	//
	// Layout: ASCII magic `v1.stream.` + the secretstream header + repeated
	// frames of [4-byte big-endian ciphertext length][ciphertext]. The last
	// frame carries the secretstream FINAL tag, so truncation is detectable
	// and rejected exactly as the AEAD tag rejects tamper. The caller's AD is
	// bound to EVERY frame, preserving the splice defense: a frame, or a whole
	// file, sealed in one context can never decrypt in another.
	// ------------------------------------------------------------------

	const STREAM_MAGIC = 'v1.stream.';

	/** Plaintext bytes per secretstream frame. Peak memory for a seal or an
	 *  open is one chunk in and one chunk out, regardless of file size. */
	const STREAM_CHUNK_BYTES = 1048576;

	/**
	 * Seal $src_path into the stream format at $dst_path, path to path — the
	 * plaintext is never held as one string. The destination is written to a
	 * temp name and renamed in only on success, so a failure never leaves a
	 * partial sealed file behind. Uses the same 32-byte symmetric key size as
	 * the AEAD string form.
	 */
	public function sealStreamFile(string $src_path, string $dst_path, string $key, string $ad): void {
		if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
			throw new RuntimeException('SealedBox: secretstream key must be exactly '
				. SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES . ' bytes.');
		}
		$in = @fopen($src_path, 'rb');
		if ($in === false) {
			throw new RuntimeException('SealedBox: cannot open stream source for reading.');
		}
		$tmp = $dst_path . '.sealing.' . bin2hex(random_bytes(6));
		$out = @fopen($tmp, 'xb');
		if ($out === false) {
			fclose($in);
			throw new RuntimeException('SealedBox: cannot open stream destination for writing.');
		}
		try {
			list($state, $header) = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
			if (fwrite($out, self::STREAM_MAGIC . $header) === false) {
				throw new RuntimeException('SealedBox: stream header write failed.');
			}
			// Read one chunk ahead: only after seeing EOF is it known that the
			// chunk in hand is the last, and the last frame must carry FINAL.
			$chunk = fread($in, self::STREAM_CHUNK_BYTES);
			if ($chunk === false) {
				throw new RuntimeException('SealedBox: stream source read failed.');
			}
			do {
				$next = fread($in, self::STREAM_CHUNK_BYTES);
				if ($next === false) {
					throw new RuntimeException('SealedBox: stream source read failed.');
				}
				$final = ($next === '' && feof($in));
				$cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, $ad,
					$final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
					       : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
				if (fwrite($out, pack('N', strlen($cipher)) . $cipher) === false) {
					throw new RuntimeException('SealedBox: stream frame write failed.');
				}
				$chunk = $next;
			} while (!$final);
			fclose($out);
			$out = null;
			fclose($in);
			$in = null;
			if (!@rename($tmp, $dst_path)) {
				throw new RuntimeException('SealedBox: cannot move the sealed stream into place.');
			}
		} catch (Throwable $e) {
			if ($in) { fclose($in); }
			if ($out) { fclose($out); }
			@unlink($tmp);
			throw ($e instanceof RuntimeException) ? $e
				: new RuntimeException('SealedBox: stream seal failed: ' . $e->getMessage());
		}
	}

	/**
	 * Open a file sealed by sealStreamFile() into $dst_path, path to path.
	 * Throws on tamper, truncation (a missing FINAL frame), trailing data
	 * after the FINAL frame, a wrong key, or an AD mismatch. The destination
	 * is written to a temp name and renamed in only on success, so a failed
	 * open never leaves a partial plaintext file behind.
	 */
	public function openStreamFile(string $src_path, string $dst_path, string $key, string $ad): void {
		if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
			throw new RuntimeException('SealedBox: secretstream key must be exactly '
				. SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES . ' bytes.');
		}
		$in = @fopen($src_path, 'rb');
		if ($in === false) {
			throw new RuntimeException('SealedBox: cannot open sealed stream for reading.');
		}
		$tmp = $dst_path . '.opening.' . bin2hex(random_bytes(6));
		$out = @fopen($tmp, 'xb');
		if ($out === false) {
			fclose($in);
			throw new RuntimeException('SealedBox: cannot open stream destination for writing.');
		}
		try {
			if (fread($in, strlen(self::STREAM_MAGIC)) !== self::STREAM_MAGIC) {
				throw new RuntimeException('SealedBox: not a sealed stream file.');
			}
			$header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
			if (!is_string($header) || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
				throw new RuntimeException('SealedBox: sealed stream header truncated.');
			}
			$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
			// A frame length outside what sealStreamFile can produce is corrupt;
			// rejecting it here also refuses a forged length that would demand a
			// giant allocation before decryption could catch it.
			$max_frame = self::STREAM_CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
			$saw_final = false;
			while (!$saw_final) {
				$len_raw = fread($in, 4);
				if ($len_raw === '' || $len_raw === false) {
					throw new RuntimeException('SealedBox: sealed stream truncated (no FINAL frame).');
				}
				if (strlen($len_raw) !== 4) {
					throw new RuntimeException('SealedBox: sealed stream frame length truncated.');
				}
				$len = unpack('N', $len_raw)[1];
				if ($len < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $len > $max_frame) {
					throw new RuntimeException('SealedBox: sealed stream frame length out of range.');
				}
				$cipher = fread($in, $len);
				if (!is_string($cipher) || strlen($cipher) !== $len) {
					throw new RuntimeException('SealedBox: sealed stream frame truncated.');
				}
				$pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher, $ad);
				if ($pulled === false) {
					throw new RuntimeException('SealedBox: sealed stream decryption failed (tampered, wrong key, or AD mismatch).');
				}
				if (fwrite($out, $pulled[0]) === false) {
					throw new RuntimeException('SealedBox: stream plaintext write failed.');
				}
				$saw_final = ($pulled[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
			}
			if (fread($in, 1) !== '' || !feof($in)) {
				throw new RuntimeException('SealedBox: sealed stream carries data after the FINAL frame.');
			}
			fclose($out);
			$out = null;
			fclose($in);
			$in = null;
			if (!@rename($tmp, $dst_path)) {
				throw new RuntimeException('SealedBox: cannot move the opened stream into place.');
			}
		} catch (Throwable $e) {
			if ($in) { fclose($in); }
			if ($out) { fclose($out); }
			@unlink($tmp);
			throw ($e instanceof RuntimeException) ? $e
				: new RuntimeException('SealedBox: stream open failed: ' . $e->getMessage());
		}
	}

	/** Magic-prefix sniff: is this file in the sealed stream format? */
	public static function isStreamFile(string $path): bool {
		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			return false;
		}
		$magic = fread($fh, strlen(self::STREAM_MAGIC));
		fclose($fh);
		return $magic === self::STREAM_MAGIC;
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
	 * Derive a KEK from a user passphrase via Argon2id at the MODERATE cost
	 * profile (~256 MB, noticeably slow — a passphrase is much lower entropy
	 * than a recovery code, and its threat model is offline guessing against a
	 * stolen database, so each guess must cost real memory and CPU). The cost
	 * runs only on the passphrase unlock/enroll paths; passkey and
	 * recovery-code unlocks never pay it.
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
			SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
			SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
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

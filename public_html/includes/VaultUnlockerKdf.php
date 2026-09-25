<?php
/**
 * VaultUnlockerKdf - the key derivations the browser runs for the unlockers a
 * person knows (a recovery code, a passphrase) and for the root vault's
 * content keys, written out once in PHP (specs/one_vault_experience.md
 * § R2, R6, R7).
 *
 * THE SERVER NEVER CALLS THIS WITH A REAL CODE OR PASSPHRASE. It never
 * receives one: the browser derives these and posts only the account half.
 * This class exists so the tests and fixtures can build exactly what a
 * browser sends, and so the constants have one home the JS mirrors
 * (assets/js/vault-crypto.js, the same names). A production caller is a bug;
 * tests/vault/one_vault_kdf_test.php pins that none exists.
 *
 *   code KEK, account half   HKDF-SHA256(ikm = normalized code, salt = the
 *                            root vault's salt, info RECOVERY_ACCOUNT_INFO)
 *   code KEK, root half      SHA-256(root salt ‖ normalized code) — the
 *                            client-custody recovery KEK (VaultCrypto.kekFromRecoveryCode)
 *   passphrase split         h = Argon2id(phrase, root salt, root KDF params),
 *                            then HKDF(h, info PASSPHRASE_*_INFO) per half
 *   content-scope key        HKDF-SHA256(root secret, info SCOPE_INFO_PREFIX + scope)
 *
 * Every derivation is salted by the ROOT vault's salt (standard base64, made
 * by the browser). The root is created in the same request that gives the
 * account vault its codes or its phrase, so that salt exists whenever either
 * half does; the account vault's own salt is the server's and plays no part.
 *
 * @version 1.0
 */

class VaultUnlockerKdf {

	const RECOVERY_ACCOUNT_INFO = 'joinery-vault:recovery:account:v1';
	const PASSPHRASE_ACCOUNT_INFO = 'joinery-vault:passphrase:account:v1';
	const PASSPHRASE_ROOT_INFO = 'joinery-vault:passphrase:root:v1';
	const SCOPE_INFO_PREFIX = 'joinery-vault:scope:v1:';

	/** Crockford read-side leniency, exactly as the browser applies it. */
	public static function normalizeCode(string $code): string {
		$code = strtoupper($code);
		$code = str_replace('O', '0', $code);
		$code = str_replace(array('I', 'L'), '1', $code);
		return preg_replace('/[^A-Z0-9]/', '', $code);
	}

	/** The account half of a recovery code's KEK (raw 32 bytes). */
	public static function codeKekAccount(string $code, string $root_salt_b64): string {
		$salt = base64_decode($root_salt_b64, true);
		if ($salt === false || $salt === '') {
			throw new RuntimeException('VaultUnlockerKdf: malformed root salt.');
		}
		return hash_hkdf('sha256', self::normalizeCode($code), 32, self::RECOVERY_ACCOUNT_INFO, $salt);
	}

	/** The root half of a recovery code's KEK (raw 32 bytes); salt is standard base64. */
	public static function codeKekRoot(string $code, string $root_salt_b64): string {
		return hash('sha256', base64_decode($root_salt_b64) . self::normalizeCode($code), true);
	}

	/** Split the passphrase's Argon2id output into [account KEK, root KEK]. */
	public static function passphraseSplit(string $argon2_raw): array {
		return array(
			hash_hkdf('sha256', $argon2_raw, 32, self::PASSPHRASE_ACCOUNT_INFO, ''),
			hash_hkdf('sha256', $argon2_raw, 32, self::PASSPHRASE_ROOT_INFO, ''),
		);
	}

	/** A content scope's wrapping key from the root vault's secret. */
	public static function scopeKek(string $root_secret_raw, string $scope): string {
		return hash_hkdf('sha256', $root_secret_raw, 32, self::SCOPE_INFO_PREFIX . $scope, '');
	}
}

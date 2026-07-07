<?php
/**
 * MailboxDkimSigner - resolves the in-app DKIM signer for a protected sending
 * identity (specs/mailbox_outbound_send_protection.md, closure 1).
 *
 * A protected domain's DKIM private key is never on disk and never given to
 * opendkim: it is sealed to the domain owner's vault public key and lives at
 * rest as ciphertext (ied_dkim_sealed_key). This class unwraps it in-window,
 * for one send, and hands the plaintext PEM back as an in-memory string for
 * PHPMailer's DKIM_private_string. The key never touches disk.
 *
 * Registered into the core MailIdentityGuard at plugin bootstrap so core send
 * code (SmtpProvider, EmailSender) never names a mailbox symbol.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));

class MailboxDkimSigner {

	/**
	 * Request-scoped domain-model cache. The guard runs on EVERY send —
	 * including each message of a batch loop — so the lookup must not cost a
	 * query per message. Request-scoped only: protectedness changes via an
	 * admin ceremony, never mid-request.
	 *
	 * @var array<string, InboundEmailDomain|null>
	 */
	private static $domain_cache = array();

	/** The domain model for $from_domain (memoized per request), or null. */
	private static function loadDomain(string $from_domain): ?InboundEmailDomain {
		$key = strtolower(trim($from_domain));
		if (!array_key_exists($key, self::$domain_cache)) {
			$model = InboundEmailDomain::GetByDomain($key);
			self::$domain_cache[$key] = $model ?: null;
		}
		return self::$domain_cache[$key];
	}

	/**
	 * True when $from_domain is an enforced protected sending identity. Read by
	 * the ambient-send guard regardless of unlock state — protectedness is a
	 * property of the domain, not of the current window.
	 */
	public static function isProtected(string $from_domain): bool {
		$domain = self::loadDomain($from_domain);
		return $domain !== null && $domain->is_protected_identity();
	}

	/**
	 * Resolve the in-app DKIM signer for $from_domain.
	 *
	 *   - Non-protected domain → null (opendkim signs it, or it is unsigned).
	 *   - Protected domain, no open unlock window for its owner → throws
	 *     VaultLockedException so the compose path prompts a one-tap unlock
	 *     rather than silently sending unsigned.
	 *   - Protected domain, in-window → ['domain','selector','private_string'].
	 *
	 * @return array{domain:string,selector:string,private_string:string}|null
	 * @throws VaultLockedException
	 */
	public static function resolveFor(string $from_domain): ?array {
		$domain = self::loadDomain($from_domain);
		if ($domain === null || !$domain->is_protected_identity()) {
			return null;
		}

		$sealed   = (string)$domain->get('ied_dkim_sealed_key');
		$selector = (string)$domain->get('ied_dkim_selector');
		$owner_id = (int)$domain->get('ied_owner_usr_user_id');
		if ($sealed === '' || $selector === '' || $owner_id <= 0) {
			// Marked protected but not yet provisioned — refuse rather than send
			// unsigned. A protected domain with no signer must never leak ambient mail.
			throw new VaultLockedException();
		}

		$secret = VaultUnlock::secretKey($owner_id);
		if ($secret === null) {
			throw new VaultLockedException(); // locked — the compose path turns this into an unlock prompt
		}

		$crypto = new VaultCrypto();
		$private_string = $crypto->openItemDek($sealed, $secret); // opens any crypto_box_seal blob, not only DEKs

		return array(
			'domain'         => strtolower($from_domain),
			'selector'       => $selector,
			'private_string' => $private_string,
		);
	}

	/**
	 * Generate a fresh 2048-bit RSA DKIM keypair. Returns the PKCS#8/PEM private
	 * key (what PHPMailer's DKIM_private_string wants, sealed at rest) and the
	 * DKIM DNS TXT value carrying the public half (`v=DKIM1; k=rsa; p=<base64
	 * DER SubjectPublicKeyInfo>`), which is exactly the openssl PEM public key
	 * with its armor and whitespace stripped.
	 *
	 * @return array{private_pem:string,dns_value:string}
	 * @throws RuntimeException on an OpenSSL failure
	 */
	public static function generateKeypair(): array {
		$res = openssl_pkey_new(array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		));
		if ($res === false) {
			throw new RuntimeException('DKIM keypair generation failed (OpenSSL): ' . openssl_error_string());
		}
		$private_pem = '';
		if (!openssl_pkey_export($res, $private_pem)) {
			throw new RuntimeException('DKIM private key export failed (OpenSSL): ' . openssl_error_string());
		}
		$details = openssl_pkey_get_details($res);
		if ($details === false || empty($details['key'])) {
			throw new RuntimeException('DKIM public key extraction failed (OpenSSL).');
		}
		$p = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $details['key']);
		return array(
			'private_pem' => $private_pem,
			'dns_value'   => 'v=DKIM1; k=rsa; p=' . $p,
		);
	}
}

<?php
/**
 * MailIdentityGuard - the core, crypto-agnostic entry point for protected
 * sending identities (specs/mailbox_outbound_send_protection.md).
 *
 * Core send code (EmailSender, SmtpProvider) must never name a mailbox symbol
 * directly — the same discipline as File::resolve_decrypt_hook(). Instead the
 * mailbox plugin registers two well-known callables here at bootstrap
 * (VaultUnlock::loadConsumerBootstraps(), guarded to the plugin being active):
 *
 *   - a protected-domain predicate — "is this From-domain a protected sending
 *     identity?" — read by EmailSender's ambient-send guard;
 *   - a DKIM signer resolver — "give me the in-app DKIM signer for this
 *     From-domain" — read by SmtpProvider's compose-time signing hook.
 *
 * With no plugin registered, isProtectedDomain() is always false and
 * resolveDkimSigner() always null, so core send behavior is unchanged on a
 * deployment without the mailbox plugin.
 *
 * @version 1.0
 */

class MailIdentityGuard {

	/** @var callable|null fn(string $domain): bool */
	private static $protected_check = null;

	/** @var callable|null fn(string $domain): ?array — may throw VaultLockedException */
	private static $dkim_signer = null;

	/**
	 * Register the "is this domain a protected sending identity" predicate.
	 * Called once, from the mailbox plugin bootstrap.
	 */
	public static function registerProtectedDomainCheck(callable $fn): void {
		self::$protected_check = $fn;
	}

	/**
	 * Register the DKIM signer resolver. The resolver returns an array
	 * ['domain','selector','private_string'] for a protected, in-window domain,
	 * null for a non-protected domain, and throws VaultLockedException for a
	 * protected domain with no open unlock window.
	 */
	public static function registerDkimSigner(callable $fn): void {
		self::$dkim_signer = $fn;
	}

	/** Lowercased domain part of an email address, or '' when there is none. */
	public static function domainOf(string $address): string {
		$at = strrpos($address, '@');
		return $at === false ? '' : strtolower(trim(substr($address, $at + 1)));
	}

	/**
	 * True when $domain is a protected sending identity. Lazily loads consumer
	 * bootstraps so the check works from any send call site.
	 */
	public static function isProtectedDomain(string $domain): bool {
		if ($domain === '') {
			return false;
		}
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		VaultUnlock::loadConsumerBootstraps();
		if (self::$protected_check === null) {
			return false;
		}
		return (bool) call_user_func(self::$protected_check, $domain);
	}

	/**
	 * The in-app DKIM signer for $domain, or null when the domain is not a
	 * protected identity (opendkim signs those, or they are unsigned). Throws
	 * VaultLockedException when the domain is protected but no unlock window is
	 * open — the compose path turns that into the locked-state unlock prompt.
	 *
	 * @return array{domain:string,selector:string,private_string:string}|null
	 */
	public static function resolveDkimSigner(string $domain): ?array {
		if ($domain === '') {
			return null;
		}
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		VaultUnlock::loadConsumerBootstraps();
		if (self::$dkim_signer === null) {
			return null;
		}
		return call_user_func(self::$dkim_signer, $domain);
	}
}

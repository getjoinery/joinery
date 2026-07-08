<?php
/**
 * Password-reset authorizers — the shared spine of Population-3 account recovery
 * (specs/mailbox_security_levels.md § Password reset & account recovery).
 *
 * The governing property: a reset re-issues the SESSION, never the vault. Every
 * authorizer here (passkey, TOTP-alone, external recovery address) proves control
 * of the account and then hands off to the ONE built completion path
 * (password_reset_2_logic via a single-use code), which sets the new password and
 * fires the credential-event wiring — VaultUnlock::lockAll() + the security alert.
 * Sealed content still demands an unlocker ceremony the resetter cannot fake, so
 * a reset is not a total-takeover event.
 *
 * Vault recovery codes are deliberately NOT here: they answer "give me my data",
 * never "log me in", and are only ever consumed by vault_unlock_recovery_logic.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

class PasswordResetAuthorizers {

	/** How long a proven-but-not-yet-completed reset ticket lives in $_SESSION. */
	const PENDING_TTL = 600;

	/** Lifetime of the single-use code handed to the completion page. Short —
	 *  the authorizer already proved control; the code only bridges to
	 *  /password-reset-2 in the same sitting. */
	const CODE_TTL = '15 min';

	/**
	 * Mint a single-use reset code for a fully-authorized account and return the
	 * /password-reset-2 URL that consumes it. The code is an ordinary account
	 * activation code (EMAIL_VERIFY) — the same capability the emailed reset link
	 * carries — never anything vault-scoped.
	 *
	 * Note: the shared completion path (Activation::ActivateUser) marks a
	 * previously-unverified login email verified. For a passkey/TOTP/recovery
	 * authorizer that proved ownership without proving the login inbox, that is a
	 * mild overreach, accepted deliberately: nothing in the codebase gates a
	 * security decision on usr_email_is_verified (its only readers are Activation's
	 * own verify-if-unverified guards), and possession of a registered
	 * passkey/authenticator is a stronger ownership signal than an email link.
	 */
	public static function issueResetUrl(User $user): string {
		$code = Activation::getTempCode(
			$user->key, self::CODE_TTL, Activation::EMAIL_VERIFY, NULL, $user->get('usr_email')
		);
		return '/password-reset-2?act_code=' . rawurlencode($code);
	}

	/**
	 * The base64url credential id a client assertion used, or '' if undecodable.
	 * (The `id` field of a WebAuthn credential IS the base64url pkc_credential_id.)
	 */
	public static function usedCredentialId($credential): string {
		if (is_array($credential) && isset($credential['id']) && is_string($credential['id'])) {
			return $credential['id'];
		}
		return '';
	}

	/**
	 * Decide whether a vault holder's passkey reset needs an INDEPENDENT second
	 * factor, and which kinds are available. Independence is the whole point: a
	 * vault holder always owns ≥1 passkey (it is their vault unlocker), so a
	 * stolen authenticator would otherwise reset + log in + unlock with one key.
	 * A factor is independent when it is NOT the passkey that authorized the
	 * reset — TOTP always qualifies; any OTHER live passkey qualifies.
	 *
	 * @return array{needs:bool, has_totp:bool, other_passkeys:bool}
	 */
	public static function secondFactorRequirement(User $user, string $used_credential_id): array {
		$has_totp = $user->has_totp_enabled();

		require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
		$creds = new MultiPasskey(['user_id' => (int)$user->key]);
		$creds->load();
		$other_passkeys = false;
		foreach ($creds as $passkey) {
			if ($passkey->get('pkc_credential_id') !== $used_credential_id) {
				$other_passkeys = true;
				break;
			}
		}

		return array(
			'needs'          => ($has_totp || $other_passkeys),
			'has_totp'       => $has_totp,
			'other_passkeys' => $other_passkeys,
		);
	}
}
?>

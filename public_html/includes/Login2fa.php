<?php
/**
 * Second-factor login completion (specs/mailbox_security_levels.md § 5.4).
 *
 * The pending-login state set by login_logic (totp_pending_*) is finished by
 * whichever second factor the user proves — a TOTP/backup code (verify_totp_logic)
 * or a passkey step-up (login_2fa_passkey_verify_logic). Both paths converge here
 * so the session establishment, remember-me cookie, trusted-device cookie, and
 * pending-state teardown are defined once.
 */
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('data/login_class.php'));

class Login2fa {

	/**
	 * Complete a pending second-factor login for a verified user: establish the
	 * session, honor the remember-me choice, issue the trusted-device cookie only
	 * when the user checked "Trust this device" on the interstitial, clear the
	 * pending state, and return the post-login redirect target.
	 */
	public static function completePendingLogin(User $user, bool $trust_device = false): string {
		$session = SessionControl::get_instance();
		$settings = Globalvars::get_instance();

		$remember  = !empty($_SESSION['totp_pending_remember']);
		$returnurl = $_SESSION['totp_pending_return'] ?? null;

		unset($_SESSION['totp_pending_user_id'], $_SESSION['totp_pending_remember'],
			$_SESSION['totp_pending_return'], $_SESSION['totp_pending_expires']);

		$session->store_session_variables($user);
		LoginClass::StoreUserLogin($user->key, LoginClass::LOGIN_FORM);

		if ($remember) {
			$session->save_user_to_cookie();
		}
		if ($trust_device) {
			$session->set_trusted_device_cookie($user);
		}

		$alternate = $settings->get_setting('alternate_loggedin_homepage');
		return $returnurl ?: ($alternate ?: '/profile');
	}
}
?>

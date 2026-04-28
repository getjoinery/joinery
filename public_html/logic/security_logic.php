<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function security_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();

	$settings = Globalvars::get_instance();
	$composer_path = $settings->get_setting('composerAutoLoad');
	require_once($composer_path . 'autoload.php');

	$user = new User($session->get_user_id(), TRUE);

	$page_vars = array();
	$page_vars['settings'] = $settings;
	$page_vars['user'] = $user;
	$page_vars['totp_enabled'] = $user->has_totp_enabled();
	$page_vars['totp_enabled_time'] = $user->get('usr_totp_enabled_time');
	$page_vars['setup_in_progress'] = false;
	$page_vars['secret'] = null;
	$page_vars['qr_uri'] = null;
	$page_vars['provisioning_uri'] = null;
	$page_vars['backup_codes'] = null;
	$page_vars['just_enabled'] = false;

	$msgtxt_from_get = $get_vars['msgtext'] ?? null;
	if ($msgtxt_from_get) {
		$message = new DisplayMessage(htmlspecialchars($msgtxt_from_get), 'Two-Factor Authentication',
			'/\/profile\/security.*/', DisplayMessage::MESSAGE_WARNING,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
		$session->save_message($message);
	}

	$action = $post_vars['action'] ?? '';

	if ($action === 'start_enable' && !$page_vars['totp_enabled']) {
		$totp = \OTPHP\TOTP::generate();
		$_SESSION['totp_setup_secret'] = $totp->getSecret();
		// Fall through to display the QR
	}

	if ($action === 'confirm_enable' && !$page_vars['totp_enabled']) {
		if (empty($_SESSION['totp_setup_secret'])) {
			$msgtxt = 'Setup expired. Please start again.';
			$message = new DisplayMessage($msgtxt, 'Setup expired', '/\/profile\/security.*/',
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
			$session->save_message($message);
			return LogicResult::redirect('/profile/security');
		}

		$submitted = isset($post_vars['totp_code']) ? trim($post_vars['totp_code']) : '';
		$canonical = preg_replace('/[\s-]+/', '', $submitted);
		if (!preg_match('/^\d{6}$/', $canonical)) {
			$msgtxt = 'Please enter the 6-digit code from your authenticator app.';
			$message = new DisplayMessage($msgtxt, 'Invalid code', '/\/profile\/security.*/',
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
			$session->save_message($message);

			$page_vars['setup_in_progress'] = true;
			$page_vars['secret'] = $_SESSION['totp_setup_secret'];
			$page_vars['provisioning_uri'] = _build_totp_uri($_SESSION['totp_setup_secret'], $user, $settings);
			$page_vars['qr_uri'] = _build_qr_data_uri($page_vars['provisioning_uri']);
			$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
			$session->clear_clearable_messages();
			return LogicResult::render($page_vars);
		}

		$candidate_totp = \OTPHP\TOTP::createFromSecret($_SESSION['totp_setup_secret']);
		if (!$candidate_totp->verify($canonical, null, 1)) {
			$msgtxt = 'That code did not match. Please try again.';
			$message = new DisplayMessage($msgtxt, 'Invalid code', '/\/profile\/security.*/',
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
			$session->save_message($message);

			$page_vars['setup_in_progress'] = true;
			$page_vars['secret'] = $_SESSION['totp_setup_secret'];
			$page_vars['provisioning_uri'] = _build_totp_uri($_SESSION['totp_setup_secret'], $user, $settings);
			$page_vars['qr_uri'] = _build_qr_data_uri($page_vars['provisioning_uri']);
			$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
			$session->clear_clearable_messages();
			return LogicResult::render($page_vars);
		}

		// Code valid — enable 2FA on the account
		$user->enable_totp($_SESSION['totp_setup_secret']);
		$backup_codes = $user->generate_backup_codes();
		unset($_SESSION['totp_setup_secret']);

		$page_vars['totp_enabled'] = true;
		$page_vars['totp_enabled_time'] = $user->get('usr_totp_enabled_time');
		$page_vars['just_enabled'] = true;
		$page_vars['backup_codes'] = $backup_codes;
		$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
		$session->clear_clearable_messages();
		return LogicResult::render($page_vars);
	}

	if ($action === 'cancel_enable') {
		unset($_SESSION['totp_setup_secret']);
		return LogicResult::redirect('/profile/security');
	}

	if ($action === 'regenerate_backup_codes' && $page_vars['totp_enabled']) {
		$backup_codes = $user->generate_backup_codes();
		$page_vars['backup_codes'] = $backup_codes;
		$msgtxt = 'New backup codes have been generated. Your previous codes are no longer valid.';
		$message = new DisplayMessage($msgtxt, 'Backup codes regenerated', '/\/profile\/security.*/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
		$session->save_message($message);
		$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
		$session->clear_clearable_messages();
		return LogicResult::render($page_vars);
	}

	if ($action === 'disable' && $page_vars['totp_enabled']) {
		$confirmation = isset($post_vars['confirm_code']) ? trim($post_vars['confirm_code']) : '';
		$confirmed = false;
		if ($confirmation !== '') {
			$canonical = strtoupper(preg_replace('/[\s-]+/', '', $confirmation));
			if (preg_match('/^\d{6}$/', $canonical)) {
				$confirmed = $user->verify_totp($canonical);
			}
			else if (preg_match('/^[A-Z0-9]{8}$/', $canonical)) {
				$confirmed = $user->verify_backup_code($canonical);
			}
		}

		if (!$confirmed) {
			$msgtxt = 'Please confirm with a current 6-digit code or an 8-character backup code.';
			$message = new DisplayMessage($msgtxt, 'Confirmation required', '/\/profile\/security.*/',
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
			$session->save_message($message);
			return LogicResult::redirect('/profile/security');
		}

		$user->disable_totp();
		$session->delete_trusted_device_cookie();
		$page_vars['totp_enabled'] = false;
		$page_vars['totp_enabled_time'] = null;
		$msgtxt = 'Two-factor authentication has been disabled.';
		$message = new DisplayMessage($msgtxt, '2FA disabled', '/\/profile\/security.*/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
		$session->save_message($message);
		return LogicResult::redirect('/profile/security');
	}

	// Default render — set up display state if a setup is currently in progress
	if (!$page_vars['totp_enabled'] && !empty($_SESSION['totp_setup_secret'])) {
		$page_vars['setup_in_progress'] = true;
		$page_vars['secret'] = $_SESSION['totp_setup_secret'];
		$page_vars['provisioning_uri'] = _build_totp_uri($_SESSION['totp_setup_secret'], $user, $settings);
		$page_vars['qr_uri'] = _build_qr_data_uri($page_vars['provisioning_uri']);
	}

	$page_vars['tab_menus'] = array(
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
		'Security' => '/profile/security',
	);

	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
	$session->clear_clearable_messages();
	return LogicResult::render($page_vars);
}

function _build_totp_uri($secret, $user, $settings) {
	$totp = \OTPHP\TOTP::createFromSecret($secret);
	$issuer = $settings->get_setting('totp_issuer_name');
	if (empty($issuer)) {
		$issuer = $settings->get_setting('site_name');
	}
	if (empty($issuer)) {
		$issuer = 'Joinery';
	}
	$totp->setLabel($user->get('usr_email'));
	$totp->setIssuer($issuer);
	return $totp->getProvisioningUri();
}

function _build_qr_data_uri($provisioning_uri) {
	$opts = new \chillerlan\QRCode\QROptions([
		'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
		'eccLevel'   => \chillerlan\QRCode\QRCode::ECC_M,
		'scale'      => 5,
	]);
	return (new \chillerlan\QRCode\QRCode($opts))->render($provisioning_uri);
}

function security_logic_api() {
    return [
        'requires_session' => true,
        'description' => 'Manage two-factor authentication settings',
    ];
}
?>

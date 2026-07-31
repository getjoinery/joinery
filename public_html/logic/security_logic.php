<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function security_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

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
	$page_vars['has_second_factor'] = $session->user_has_second_factor($user);
	$page_vars['cadence'] = $user->two_factor_cadence();
	// Factor summary for the Second-factor sign-in panel — computed from the
	// same predicate the sign-in divert and step-up gates use, so the page can
	// never contradict what enforcement does (specs/second_factor_ux_coherence.md).
	$live_passkey_count = 0;
	if ($settings->get_setting('passkeys_enabled')) {
		require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
		$live_passkeys = new MultiPasskey(array('user_id' => (int)$user->key));
		$live_passkeys->load();
		$live_passkey_count = count($live_passkeys);
	}
	$page_vars['factor_summary'] = array(
		'active'        => $page_vars['has_second_factor'],
		'totp'          => $page_vars['totp_enabled'],
		'passkey_count' => $live_passkey_count,
	);
	// External recovery address (specs/mailbox_security_levels.md § Password reset).
	$page_vars['recovery_email'] = trim((string)$user->get('usr_recovery_email'));
	$page_vars['recovery_email_verified'] = $user->has_verified_recovery_email();

	$msgtxt_from_get = $input['msgtext'] ?? null;
	if ($msgtxt_from_get) {
		$message = new DisplayMessage(htmlspecialchars($msgtxt_from_get), 'Security',
			'/\/profile\/security.*/', DisplayMessage::MESSAGE_WARNING,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
		$session->save_message($message);
	}

	$action = $input['action'] ?? '';

	if ($action === 'set_cadence') {
		// 2FA cadence (specs/mailbox_security_levels.md § 5.2). Changing it is a
		// sensitive action — re-confirm the second factor first.
		$stepup = $session->require_recent_second_factor('/profile/security');
		if ($stepup !== null) {
			return $stepup;
		}
		$new_cadence = ($input['cadence'] ?? '') === 'sensitive_only' ? 'sensitive_only' : 'every_login';
		$user->set('usr_2fa_cadence', $new_cadence);
		$user->save();
		$page_vars['cadence'] = $new_cadence;
		$msgtxt = $new_cadence === 'sensitive_only'
			? 'Sign-in is now password-only. Your second factor is asked at sensitive actions. Note: a phished password can then see your Standard mail and mailbox metadata.'
			: 'Your second factor is now asked at every sign-in.';
		$message = new DisplayMessage($msgtxt, 'Second-factor cadence updated', '/\/profile\/security.*/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
		$session->save_message($message);
		return LogicResult::redirect('/profile/security');
	}

	if ($action === 'set_recovery_email') {
		// Setting an external recovery address adds a reset path
		// (specs/mailbox_security_levels.md § Password reset), so it is a
		// sensitive action — re-confirm the second factor first.
		$stepup = $session->require_recent_second_factor('/profile/security');
		if ($stepup !== null) {
			return $stepup;
		}
		require_once(PathHelper::getIncludePath('includes/Activation.php'));
		$candidate = strtolower(trim((string)($input['recovery_email'] ?? '')));
		if ($candidate === '' || strlen($candidate) > 64 || !LibraryFunctions::IsValidEmail($candidate)) {
			$msgtxt = 'Please enter a valid email address (up to 64 characters) for account recovery.';
			$message = new DisplayMessage($msgtxt, 'Invalid address', '/\/profile\/security.*/',
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
			$session->save_message($message);
			return LogicResult::redirect('/profile/security');
		}
		if ($candidate === strtolower((string)$user->get('usr_email'))) {
			$msgtxt = 'Your recovery address must be different from your login email — the point is an inbox you can still reach if you are locked out.';
			$message = new DisplayMessage($msgtxt, 'Choose a different address', '/\/profile\/security.*/',
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
			$session->save_message($message);
			return LogicResult::redirect('/profile/security');
		}
		// A recovery address on a mailbox hosted HERE is circular — locked out of the
		// account, the user cannot read that inbox either. Same Population-2 guard the
		// register/account-email flows apply (specs/mailbox_security_levels.md).
		$domain_class = PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php');
		if (is_file($domain_class)) {
			require_once($domain_class);
			if (class_exists('InboundEmailDomain') && InboundEmailDomain::isHostedEmailAddress($candidate)) {
				$msgtxt = 'Choose a recovery address on an outside provider (Gmail, Outlook, etc.). A mailbox hosted here would be unreachable when you are locked out — the very situation recovery exists to solve.';
				$message = new DisplayMessage($msgtxt, 'Use an outside address', '/\/profile\/security.*/',
					DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
				$session->save_message($message);
				return LogicResult::redirect('/profile/security');
			}
		}
		// Throttle sends — the confirmation email goes to an arbitrary external
		// address, so an authenticated account must not become a spam cannon.
		require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
		if (!RequestLogger::check_rate_limit('recovery_verify_send', 5, 3600)) {
			$msgtxt = 'Too many confirmation emails sent recently. Please wait an hour and try again.';
			$message = new DisplayMessage($msgtxt, 'Please wait', '/\/profile\/security.*/',
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
			$session->save_message($message);
			return LogicResult::redirect('/profile/security');
		}
		RequestLogger::log('recovery_verify_send', 'send', true, ['user_id' => (int)$user->key]);
		// Any earlier confirmation links die now (defense in depth; the verify step
		// also reconciles against this candidate).
		Activation::deleteUserCodes($user->key, Activation::RECOVERY_VERIFY);
		// Store the candidate as unverified; the emailed link promotes it.
		$user->set('usr_recovery_email', $candidate);
		$user->set('usr_recovery_email_verified_time', null);
		$user->save();
		Activation::email_recovery_verify_send($user->key, $candidate);
		$msgtxt = 'A confirmation link was sent to ' . htmlspecialchars($candidate) . '. Open it from that inbox to activate recovery. Until then it is not yet a reset path.';
		$message = new DisplayMessage($msgtxt, 'Confirm your recovery address', '/\/profile\/security.*/',
			DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
		$session->save_message($message);
		return LogicResult::redirect('/profile/security');
	}

	if ($action === 'remove_recovery_email') {
		// Removing a reset path is also sensitive — re-confirm the second factor.
		$stepup = $session->require_recent_second_factor('/profile/security');
		if ($stepup !== null) {
			return $stepup;
		}
		require_once(PathHelper::getIncludePath('includes/Activation.php'));
		// Kill any outstanding confirmation links so a removed address cannot be
		// resurrected by an old link.
		Activation::deleteUserCodes($user->key, Activation::RECOVERY_VERIFY);
		$user->set('usr_recovery_email', null);
		$user->set('usr_recovery_email_verified_time', null);
		$user->save();
		$msgtxt = 'Your recovery address has been removed.';
		$message = new DisplayMessage($msgtxt, 'Recovery address removed', '/\/profile\/security.*/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
		$session->save_message($message);
		return LogicResult::redirect('/profile/security');
	}

	if ($action === 'start_enable' && !$page_vars['totp_enabled']) {
		// 20-byte secret (RFC 4226 recommendation for SHA-1) -> 32 base32
		// chars, the industry-standard length for manual entry. The library
		// default of 64 bytes produces a 103-char key with no practical
		// security gain.
		$totp = \OTPHP\TOTP::generate(null, 20);
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

		$submitted = isset($input['totp_code']) ? trim($input['totp_code']) : '';
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
		// Sensitive action (specs/mailbox_security_levels.md § 5.5): new backup
		// codes are a persistence mechanism, so re-confirm the second factor first.
		$stepup = $session->require_recent_second_factor('/profile/security');
		if ($stepup !== null) {
			return $stepup;
		}
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

	if ($action === 'revoke_trusted_devices' && $page_vars['has_second_factor']) {
		$user->rotate_second_factor_hmac_key();
		$session->delete_trusted_device_cookie();
		$msgtxt = 'All trusted devices forgotten. Every device will be asked for your second factor at its next sign-in.';
		$message = new DisplayMessage($msgtxt, 'Trusted devices forgotten', '/\/profile\/security.*/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
		$session->save_message($message);
		return LogicResult::redirect('/profile/security');
	}

	if ($action === 'disable' && $page_vars['totp_enabled']) {
		// Possession-factor invariant: a vault holder must always retain a
		// second factor beyond memorized secrets — TOTP or a live passkey.
		// Without this, disabling 2FA after revoking every passkey would
		// leave the vault openable with a phished password + recovery code.
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
		$vaults = new MultiUserEncryptionVault(['user_id' => $user->key]);
		$vaults->load();
		if ($vaults->count()) {
			$live_passkeys = new MultiPasskey(['user_id' => $user->key, 'deleted' => false]);
			$live_passkeys->load();
			if ($live_passkeys->count() === 0) {
				$msgtxt = 'Your encrypted vault needs a second factor that is not just a memorized code. '
					. 'Add a passkey first, then disable two-factor authentication.';
				$message = new DisplayMessage($msgtxt, 'Vault protection', '/\/profile\/security.*/',
					DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
				$session->save_message($message);
				return LogicResult::redirect('/profile/security');
			}
		}

		$confirmation = isset($input['confirm_code']) ? trim($input['confirm_code']) : '';
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
		// Credential event (specs/mailbox_security_levels.md § 6.6): a 2FA method
		// change ends every vault window everywhere.
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		VaultUnlock::lockAll($user->key);
		$page_vars['totp_enabled'] = false;
		$page_vars['totp_enabled_time'] = null;
		$msgtxt = 'The authenticator app has been turned off.';
		if ($page_vars['factor_summary']['passkey_count'] > 0) {
			$msgtxt .= ' Sign-ins will still ask for your passkey.';
		}
		$message = new DisplayMessage($msgtxt, 'Authenticator app turned off', '/\/profile\/security.*/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
		$session->save_message($message);
		return LogicResult::redirect('/profile/security');
	}

	if ($action === 'revoke_app_session') {
		$key_id = (int)($input['apk_api_key_id'] ?? 0);
		$api_key = $key_id ? new ApiKey($key_id, TRUE) : NULL;
		if ($api_key && $api_key->key && $api_key->is_session()
			&& $api_key->get('apk_usr_user_id') == $user->key
			&& !$api_key->get('apk_delete_time')) {
			$api_key->soft_delete();
			$message = new DisplayMessage('The app session has been signed out.', 'Session revoked',
				'/\/profile\/security.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
			$session->save_message($message);
		}
		return LogicResult::redirect('/profile/security');
	}

	// Sync devices. Unlinking must also revoke the credential, or the page would
	// be telling the user something untrue — the machine would vanish from the
	// list and carry on syncing.
	if ($action === 'revoke_sync_device' || $action === 'rename_sync_device') {
		require_once(PathHelper::getIncludePath('data/sync_devices_class.php'));
		$device_id = (int)($input['sde_sync_device_id'] ?? 0);
		$device = $device_id ? new SyncDevice($device_id, TRUE) : NULL;
		$owned = $device && $device->key
			&& (int)$device->get('sde_usr_user_id') === (int)$user->key
			&& !$device->get('sde_delete_time');

		if ($owned && $action === 'revoke_sync_device') {
			$key_id = (int)$device->get('sde_apk_api_key_id');
			if ($key_id > 0) {
				$device_key = new ApiKey($key_id, TRUE);
				if ($device_key->key && $device_key->get('apk_usr_user_id') == $user->key
					&& !$device_key->get('apk_delete_time')) {
					$device_key->soft_delete();
				}
			}
			$device->soft_delete();
			$message = new DisplayMessage(
				htmlspecialchars($device->get('sde_device_name')) . ' can no longer reach your files. Anything it already downloaded stays on that computer.',
				'Device unlinked',
				'/\/profile\/security.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
			$session->save_message($message);
		} elseif ($owned && $action === 'rename_sync_device') {
			$new_name = trim((string)($input['sde_device_name'] ?? ''));
			if ($new_name !== '') {
				$device->set('sde_device_name', substr($new_name, 0, 64));
				$device->save();
			}
		}
		return LogicResult::redirect('/profile/security');
	}

	if ($action === 'revoke_all_app_sessions') {
		ApiKey::RevokeSessionKeysForUser($user->key);
		// Credential event (specs/mailbox_security_levels.md § 6.6): revoking app
		// sessions ends every vault window with them.
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		VaultUnlock::lockAll($user->key);
		$message = new DisplayMessage('All app sessions have been signed out.', 'Sessions revoked',
			'/\/profile\/security.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'securitybox', TRUE);
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

	$app_sessions = new MultiApiKey(array(
		'user_id' => $user->key,
		'type' => ApiKey::TYPE_SESSION,
		'deleted' => false,
	), array('create_time' => 'DESC'));
	$app_sessions->load();
	$page_vars['app_sessions'] = $app_sessions;

	// Linked sync devices. Listed separately from app sessions even though each
	// one owns a session key underneath: a computer that continuously syncs the
	// user's files is a different thing to reason about than a phone that has
	// signed in, and it carries facts (last check-in, feed position) that a bare
	// credential does not have.
	require_once(PathHelper::getIncludePath('data/sync_devices_class.php'));
	$sync_devices = new MultiSyncDevice(array(
		'user_id' => $user->key,
		'deleted' => false,
	), array('sde_create_time' => 'DESC'));
	$sync_devices->load();
	$page_vars['sync_devices'] = $sync_devices;
	// The api keys those devices own, so the App Sessions list below does not
	// show the same machine twice under a second name.
	$device_key_ids = array();
	foreach ($sync_devices as $sync_device) {
		$device_key_ids[(int)$sync_device->get('sde_apk_api_key_id')] = true;
	}
	$page_vars['sync_device_key_ids'] = $device_key_ids;


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

function security_logic_descriptor(): array {
	return [
		'description'      => 'Manage two-factor authentication settings. action=start_enable begins TOTP setup; confirm_enable confirms it; cancel_enable aborts pending setup; regenerate_backup_codes issues new codes; disable turns off TOTP.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => [
			'action' => ['type' => 'select', 'required' => true, 'label' => 'Action', 'options' => ['start_enable', 'confirm_enable', 'cancel_enable', 'regenerate_backup_codes', 'disable']],
			'totp_code' => ['type' => 'string', 'required' => false, 'label' => 'TOTP code (confirm_enable and disable)'],
			'confirm_code' => ['type' => 'string', 'required' => false, 'label' => 'Backup code (disable)'],
		],
	];
}
?>

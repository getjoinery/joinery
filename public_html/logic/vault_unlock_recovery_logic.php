<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_unlock_recovery_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	if (!RequestLogger::check_rate_limit('vault_unlock_recovery', 10, 900, false)) {
		return LogicResult::error('Too many attempts. Please wait a few minutes and try again.');
	}

	$vault = UserEncryptionVault::loadForUser($user->key);
	if (!$vault) {
		return LogicResult::error('Your vault is not set up yet.');
	}

	// Recovery-code unlock is the everything-bypass, so it gets the strictest path
	// (specs/mailbox_security_levels.md § 5.6): the account's second factor is
	// required REGARDLESS of the 2FA cadence setting whenever one is enrolled. As
	// an API action this can't redirect, so it rejects with a flag the client uses
	// to run the step-up ceremony first, then retry.
	if ($session->user_has_second_factor($user) && !$session->has_recent_second_factor()) {
		return LogicResult::render([
			'second_factor_required' => true,
			'error' => 'Confirm your identity with your second factor, then retry your recovery code.',
		]);
	}

	$code = isset($input['code']) ? (string)$input['code'] : '';
	if (trim($code) === '') {
		return LogicResult::error('Enter a recovery code.');
	}

	$box = new SealedBox();

	$wrappings = new MultiUserEncryptionWrapping([
		'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY, 'is_used' => false,
	]);
	$wrappings->load();

	// Each wrapping records the salt its KEK was derived under (a rotation
	// replaces uev_salt, and in a two-generation state both salts' codes are
	// live). Derive per distinct salt — the recovery KDF is a fast keyed hash.
	$keks = [];
	$secret_key = null;
	$matched = null;
	foreach ($wrappings as $wrapping) {
		$salt = (string)$wrapping->get('uew_salt');
		if ($salt === '') {
			$salt = (string)$vault->get('uev_salt'); // legacy row predating uew_salt
		}
		if (!isset($keks[$salt])) {
			$keks[$salt] = $box->kekFromRecoveryCode($code, $salt);
		}
		try {
			$ad = UserEncryptionWrapping::adFor($vault->key, $wrapping->key);
			$secret_key = $box->unwrapKey($wrapping->get('uew_wrapped_secret_key'), $keks[$salt], $ad);
			$matched = $wrapping;
			break;
		} catch (Exception $e) {
			continue; // wrong code for this row - try the next
		}
	}

	if (!$matched) {
		RequestLogger::log('vault_unlock_recovery', 'verify', false, ['user_id' => $user->key]);
		return LogicResult::error('Invalid or already-used recovery code.');
	}

	$matched->set('uew_is_used', true);
	$matched->set('uew_used_time', gmdate('Y-m-d H:i:s'));
	$matched->save();

	// Kill-switch semantics (specs/mailbox_security_levels.md § 5.6): a
	// recovery-code use ends EVERY open window everywhere first, then opens one
	// only for this (the recovering) session. If the code was stolen rather than
	// recovered, the attacker's pre-existing windows die and the owner's alert
	// arrives while they hold a re-locked vault.
	VaultUnlock::lockAll($user->key);
	VaultUnlock::open($user->key, $secret_key, UserEncryptionVault::SCOPE_USER);
	RequestLogger::log('vault_unlock_recovery', 'verify', true, ['user_id' => $user->key]);

	// Notify the account immediately — the reach-every-device channel that does
	// exist is the account email (a richer multi-device push rides the native
	// package). Best-effort: a failed alert never blocks the unlock.
	try {
		$to = (string)$user->get('usr_email');
		if ($to !== '') {
			$site = (string)$settings->get_setting('site_name');
			EmailSender::quickSend(
				$to,
				trim($site . ' security alert'),
				"A vault recovery code was just used on your account. If this was you, no action is needed — "
				. "all other unlocked sessions were signed out of your vault as a precaution. If this was NOT you, "
				. "change your password immediately from a device you trust."
			);
		}
	} catch (\Throwable $e) {
		error_log('vault_unlock_recovery: alert email failed for user ' . $user->key . ': ' . $e->getMessage());
	}

	$remaining = new MultiUserEncryptionWrapping([
		'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY, 'is_used' => false,
	]);

	return LogicResult::render([
		'unlocked' => true,
		'regenerate_recommended' => $remaining->count_all() < 3,
	]);
}

function vault_unlock_recovery_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Unlock the vault with a one-time recovery code',
	];
}
?>

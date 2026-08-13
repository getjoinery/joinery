<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_unlock_recovery_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/VaultCeremonies.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
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

	try {
		$ceremonies = new VaultCeremonies();
		$result = $ceremonies->unlockWithRecoveryCode($user, $vault, $code);
	} catch (VaultCeremonyException $e) {
		RequestLogger::log('vault_unlock_recovery', 'verify', false, ['user_id' => $user->key]);
		return LogicResult::error($e->getMessage());
	}

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

	return LogicResult::render([
		'unlocked' => true,
		'regenerate_recommended' => $result['regenerate_recommended'],
	]);
}

function vault_unlock_recovery_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Unlock the vault with a one-time recovery code',
	];
}
?>

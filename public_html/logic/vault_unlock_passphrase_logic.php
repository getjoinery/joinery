<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_unlock_passphrase_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/VaultCeremonies.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	if (!RequestLogger::check_rate_limit('vault_unlock_passphrase', 10, 900, false)) {
		return LogicResult::error('Too many attempts. Please wait a few minutes and try again.');
	}

	$vault = UserEncryptionVault::loadForUser($user->key);
	if (!$vault) {
		return LogicResult::error('Your vault is not set up yet.');
	}

	// A passphrase is a phishable knowledge factor, so like the recovery-code
	// path it demands the account's second factor regardless of the 2FA
	// cadence setting: a remote attacker must hold a possession factor, not
	// just two stolen strings. As an API action this can't redirect, so it
	// rejects with a flag the client uses to run the step-up ceremony first,
	// then retry.
	if ($session->user_has_second_factor($user) && !$session->has_recent_second_factor()) {
		return LogicResult::render([
			'second_factor_required' => true,
			'error' => 'Confirm your identity with your second factor, then retry your bypass phrase.',
		]);
	}

	$passphrase = isset($input['passphrase']) ? (string)$input['passphrase'] : '';

	try {
		$ceremonies = new VaultCeremonies();
		$secret_key = $ceremonies->unlockWithPassphrase($user, $vault, $passphrase);
	} catch (VaultCeremonyException $e) {
		RequestLogger::log('vault_unlock_passphrase', 'verify', false, ['user_id' => $user->key]);
		return LogicResult::error($e->getMessage());
	}

	VaultUnlock::open($user->key, $secret_key, UserEncryptionVault::SCOPE_USER, null, VaultAudit::VIA_PASSPHRASE);
	RequestLogger::log('vault_unlock_passphrase', 'verify', true, ['user_id' => $user->key]);

	return LogicResult::render(['unlocked' => true]);
}

function vault_unlock_passphrase_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Unlock the vault with the enrolled bypass phrase',
	];
}
?>

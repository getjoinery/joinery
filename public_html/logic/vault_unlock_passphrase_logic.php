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

	$passphrase = isset($input['passphrase']) ? (string)$input['passphrase'] : '';

	try {
		$ceremonies = new VaultCeremonies();
		$secret_key = $ceremonies->unlockWithPassphrase($user, $vault, $passphrase);
	} catch (VaultCeremonyException $e) {
		RequestLogger::log('vault_unlock_passphrase', 'verify', false, ['user_id' => $user->key]);
		return LogicResult::error($e->getMessage());
	}

	VaultUnlock::open($user->key, $secret_key, UserEncryptionVault::SCOPE_USER);
	RequestLogger::log('vault_unlock_passphrase', 'verify', true, ['user_id' => $user->key]);

	return LogicResult::render(['unlocked' => true]);
}

function vault_unlock_passphrase_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Unlock the vault with the enrolled passphrase',
	];
}
?>

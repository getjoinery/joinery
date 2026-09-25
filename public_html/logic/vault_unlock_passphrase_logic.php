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

	// The phrase opens the vault on its own. A vault opens with a passkey, its
	// passphrase or a recovery code; the account's sign-in second factor
	// (an authenticator code) never takes part in opening one. The browser
	// ran the slow derivation and posts only the account half of its KEK: the
	// phrase never reaches here (specs/one_vault_experience.md § R7).
	try {
		$kek = VaultCeremonies::decodeKek($input['passphrase_kek'] ?? '');
		$ceremonies = new VaultCeremonies();
		$key = $ceremonies->unlockWithPassphrase($user, $vault, $kek);
	} catch (VaultCeremonyException $e) {
		RequestLogger::log('vault_unlock_passphrase', 'verify', false, ['user_id' => $user->key]);
		return LogicResult::error($e->getMessage());
	}

	VaultUnlock::arm($user->key, $key, UserEncryptionVault::SCOPE_USER, null, VaultAudit::VIA_PASSPHRASE);
	RequestLogger::log('vault_unlock_passphrase', 'verify', true, ['user_id' => $user->key]);

	return LogicResult::render(['unlocked' => true]);
}

function vault_unlock_passphrase_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Unlock the vault with the passphrase: the account half of its KEK, derived in the browser (the phrase itself is never sent)',
		'input' => [
			'passphrase_kek' => ['type' => 'password', 'required' => true, 'label' => 'Account half of the passphrase KEK (base64url, 32 bytes)'],
		],
	];
}
?>

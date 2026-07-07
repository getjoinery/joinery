<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_add_passkey_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
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

	$vault = UserEncryptionVault::loadForUser($user->key);
	if (!$vault) {
		return LogicResult::error('Set up your vault before adding another passkey to it.');
	}

	$secret_key = VaultUnlock::secretKey($user->key, UserEncryptionVault::SCOPE_USER);
	if ($secret_key === null) {
		return LogicResult::error('Unlock your vault before adding another passkey to it.', ['locked' => true]);
	}

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}

	try {
		$service = new PasskeyService();
		[$derived_user, $passkey, $prf_output] = $service->verifyDerivation(json_encode($credential), 'vault-kek');
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}
	if ((int)$derived_user->key !== (int)$user->key) {
		return LogicResult::error('This passkey does not belong to your account.');
	}

	$existing = new MultiUserEncryptionWrapping([
		'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY, 'credential_id' => $passkey->key,
	]);
	if ($existing->count_all() > 0) {
		return LogicResult::error('This passkey already unlocks your vault.');
	}

	$wrapping = UserEncryptionWrapping::createWrapped(
		$vault->key, UserEncryptionWrapping::TYPE_PASSKEY, $secret_key, $prf_output,
		$passkey->key, $passkey->get('pkc_label')
	);

	return LogicResult::render(['wrapping_id' => (int)$wrapping->key]);
}

function vault_add_passkey_verify_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Complete adding a vault wrapping for another PRF-capable passkey',
	];
}
?>

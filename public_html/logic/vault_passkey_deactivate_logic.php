<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_passkey_deactivate_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));
	require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
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
		return LogicResult::error('Set up your vault first.');
	}

	if ($session->step_up_outstanding($user)) {
		return LogicResult::error('Please re-confirm with an existing passkey before deactivating one for your vault.');
	}

	$credential_id = isset($input['credential_id']) ? intval($input['credential_id']) : 0;
	$credential = new Passkey($credential_id, TRUE);
	if (!$credential->key || intval($credential->get('pkc_usr_user_id')) !== intval($user->key)) {
		return LogicResult::error('That passkey does not exist on your account.');
	}

	$wrappings = new MultiUserEncryptionWrapping([
		'vault_id' => $vault->key,
		'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY,
		'credential_id' => $credential_id,
	]);
	$wrappings->load();
	if ($wrappings->count() === 0) {
		return LogicResult::error('This passkey is not activated for your vault.');
	}

	// The same unlocker floor that gates a passkey revocation: deactivating
	// must not leave the vault without a working unlocker set.
	try {
		VaultUnlock::assertWrappingDeleteSafe((int)$vault->key, $credential_id);
	} catch (RuntimeException $e) {
		return LogicResult::error(
			'Deactivating this passkey would lock you out of your encrypted vault - activate '
			. 'another passkey for the vault, or make sure you have at least 3 unused recovery '
			. 'codes, before deactivating it.'
		);
	}

	foreach ($wrappings as $wrapping) {
		$wrapping->soft_delete();
	}

	return LogicResult::render(['deactivated' => true]);
}

function vault_passkey_deactivate_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Remove a passkey\'s vault wrapping so it can no longer unlock the vault; requires a recent step-up',
	];
}
?>

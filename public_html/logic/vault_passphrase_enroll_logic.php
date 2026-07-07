<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_passphrase_enroll_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
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
		return LogicResult::error('Set up your vault first.');
	}

	$service = new PasskeyService();
	if (!$service->hasRecentStepUp()) {
		return LogicResult::error('Please re-confirm with an existing passkey before enrolling a vault passphrase.');
	}

	$secret_key = VaultUnlock::secretKey($user->key, UserEncryptionVault::SCOPE_USER);
	if ($secret_key === null) {
		return LogicResult::error('Unlock your vault before enrolling a passphrase.', ['locked' => true]);
	}

	$passphrase = isset($input['passphrase']) ? (string)$input['passphrase'] : '';
	if (strlen($passphrase) < 12) {
		return LogicResult::error('Your vault passphrase must be at least 12 characters.');
	}

	$existing = new MultiUserEncryptionWrapping(['vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSPHRASE]);
	$existing->load();
	foreach ($existing as $wrapping) {
		$wrapping->soft_delete();
	}

	$box = new SealedBox();
	$kek = $box->kekFromPassphrase($passphrase, $vault->get('uev_salt'));
	UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, $secret_key, $kek);

	return LogicResult::render(['enrolled' => true]);
}

function vault_passphrase_enroll_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Enroll (or replace) the optional vault passphrase unlocker; requires a recent step-up and an unlocked vault',
	];
}
?>

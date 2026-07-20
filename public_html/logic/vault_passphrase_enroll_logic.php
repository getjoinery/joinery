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
		return LogicResult::error('Please re-confirm with an existing passkey before adding a bypass phrase.');
	}

	$secret_key = VaultUnlock::secretKey($user->key, UserEncryptionVault::SCOPE_USER);
	if ($secret_key === null) {
		return LogicResult::error('Unlock your vault before adding a bypass phrase.', ['locked' => true]);
	}

	$passphrase = isset($input['passphrase']) ? (string)$input['passphrase'] : '';
	if (strlen($passphrase) < SealedBox::PASSPHRASE_MIN_CHARS) {
		return LogicResult::error('Your bypass phrase must be at least ' . SealedBox::PASSPHRASE_MIN_CHARS . ' characters.');
	}

	// A wrapping must be tagged with a single truthful generation, and in a
	// partially-rotated vault the in-window secret's generation is ambiguous.
	if (count(UserEncryptionWrapping::liveGenerations((int)$vault->key)) > 1) {
		return LogicResult::error('Your vault has an unfinished key rotation. Run the rotation again to complete it, then add your bypass phrase again.');
	}

	$existing = new MultiUserEncryptionWrapping(['vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSPHRASE]);
	$existing->load();
	foreach ($existing as $wrapping) {
		$wrapping->soft_delete();
	}

	$box = new SealedBox();
	$salt = (string)$vault->get('uev_salt');
	$kek = $box->kekFromPassphrase($passphrase, $salt);
	UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, $secret_key, $kek, null, null, (int)$vault->get('uev_key_generation'), $salt);

	return LogicResult::render(['enrolled' => true]);
}

function vault_passphrase_enroll_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Add (or replace) the optional vault bypass phrase unlocker; requires a recent step-up and an unlocked vault',
	];
}
?>

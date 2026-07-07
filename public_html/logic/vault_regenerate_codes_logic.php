<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_regenerate_codes_logic(array $input): LogicResult {
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
		return LogicResult::error('Please re-confirm with an existing passkey before regenerating your recovery codes.');
	}

	$secret_key = VaultUnlock::secretKey($user->key, UserEncryptionVault::SCOPE_USER);
	if ($secret_key === null) {
		return LogicResult::error('Unlock your vault before regenerating your recovery codes.', ['locked' => true]);
	}

	$code_count = isset($input['recovery_code_count']) ? (int)$input['recovery_code_count'] : 10;
	$code_count = max(5, min(20, $code_count));

	$old_codes = new MultiUserEncryptionWrapping(['vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY]);
	$old_codes->load();
	foreach ($old_codes as $wrapping) {
		$wrapping->soft_delete();
	}

	$box = new SealedBox();
	$recovery_codes = [];
	for ($i = 0; $i < $code_count; $i++) {
		$code = $box->generateRecoveryCode();
		$recovery_codes[] = $code;
		$kek = $box->kekFromRecoveryCode($code, $vault->get('uev_salt'));
		UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_RECOVERY, $secret_key, $kek);
	}

	return LogicResult::render(['recovery_codes' => $recovery_codes]);
}

function vault_regenerate_codes_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Invalidate all existing recovery codes and issue a fresh set; requires a recent step-up and an unlocked vault',
	];
}
?>

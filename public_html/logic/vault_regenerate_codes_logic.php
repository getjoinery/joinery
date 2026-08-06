<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_regenerate_codes_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
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

	if ($session->step_up_outstanding($user)) {
		return LogicResult::error('Please re-confirm with an existing passkey before regenerating your recovery codes.');
	}

	$secret_key = VaultUnlock::secretKey($user->key, UserEncryptionVault::SCOPE_USER);
	if ($secret_key === null) {
		return LogicResult::error('Unlock your vault before regenerating your recovery codes.', ['locked' => true]);
	}

	// A wrapping must be tagged with a single truthful generation, and in a
	// partially-rotated vault the in-window secret's generation is ambiguous.
	if (count(UserEncryptionWrapping::liveGenerations((int)$vault->key)) > 1) {
		return LogicResult::error('Your vault has an unfinished key rotation. Run the rotation again to complete it, then regenerate your codes.');
	}

	$code_count = isset($input['recovery_code_count']) ? (int)$input['recovery_code_count'] : 10;
	$code_count = max(5, min(20, $code_count));

	$box = new SealedBox();
	$salt = (string)$vault->get('uev_salt');
	$generation = (int)$vault->get('uev_key_generation');

	// Retire the old codes and mint the new set in ONE transaction: a failure
	// mid-mint (e.g. a malformed uev_salt failing the KEK derivation) must roll
	// the retirement back too — recovery codes are the last-resort unlockers,
	// so a failed regeneration must leave the existing set fully usable.
	$db = DbConnector::get_instance()->get_db_link();
	$recovery_codes = [];
	try {
		$db->beginTransaction();

		$old_codes = new MultiUserEncryptionWrapping(['vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY]);
		$old_codes->load();
		foreach ($old_codes as $wrapping) {
			$wrapping->soft_delete();
		}

		for ($i = 0; $i < $code_count; $i++) {
			$code = $box->generateRecoveryCode();
			$recovery_codes[] = $code;
			$kek = $box->kekFromRecoveryCode($code, $salt);
			UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_RECOVERY, $secret_key, $kek, null, null, $generation, $salt);
		}

		$db->commit();
	} catch (Throwable $e) {
		if ($db->inTransaction()) {
			$db->rollBack();
		}
		error_log('Recovery code regeneration: could not replace the codes for vault ' . (int)$vault->key . ': ' . $e->getMessage());
		return LogicResult::error('Could not regenerate your recovery codes - nothing was changed and your existing codes still work. Try again.');
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

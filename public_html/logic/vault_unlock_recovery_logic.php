<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_unlock_recovery_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
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

	if (!RequestLogger::check_rate_limit('vault_unlock_recovery', 10, 900, false)) {
		return LogicResult::error('Too many attempts. Please wait a few minutes and try again.');
	}

	$vault = UserEncryptionVault::loadForUser($user->key);
	if (!$vault) {
		return LogicResult::error('Your vault is not set up yet.');
	}

	$code = isset($input['code']) ? (string)$input['code'] : '';
	if (trim($code) === '') {
		return LogicResult::error('Enter a recovery code.');
	}

	$box = new SealedBox();
	$kek = $box->kekFromRecoveryCode($code, $vault->get('uev_salt'));

	$wrappings = new MultiUserEncryptionWrapping([
		'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY, 'is_used' => false,
	]);
	$wrappings->load();

	$secret_key = null;
	$matched = null;
	foreach ($wrappings as $wrapping) {
		try {
			$ad = UserEncryptionWrapping::adFor($vault->key, $wrapping->key);
			$secret_key = $box->unwrapKey($wrapping->get('uew_wrapped_secret_key'), $kek, $ad);
			$matched = $wrapping;
			break;
		} catch (Exception $e) {
			continue; // wrong code for this row - try the next
		}
	}

	if (!$matched) {
		RequestLogger::log('vault_unlock_recovery', 'verify', false, ['user_id' => $user->key]);
		return LogicResult::error('Invalid or already-used recovery code.');
	}

	$matched->set('uew_is_used', true);
	$matched->set('uew_used_time', gmdate('Y-m-d H:i:s'));
	$matched->save();

	VaultUnlock::open($user->key, $secret_key, UserEncryptionVault::SCOPE_USER);
	RequestLogger::log('vault_unlock_recovery', 'verify', true, ['user_id' => $user->key]);

	$remaining = new MultiUserEncryptionWrapping([
		'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY, 'is_used' => false,
	]);

	return LogicResult::render([
		'unlocked' => true,
		'regenerate_recommended' => $remaining->count_all() < 3,
	]);
}

function vault_unlock_recovery_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Unlock the vault with a one-time recovery code',
	];
}
?>

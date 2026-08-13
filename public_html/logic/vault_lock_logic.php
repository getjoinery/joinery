<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_lock_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	VaultUnlock::close($user->key, UserEncryptionVault::SCOPE_USER);

	return LogicResult::render(['locked' => true]);
}

function vault_lock_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Explicitly lock the vault (close the unlock window) for the current session',
	];
}
?>

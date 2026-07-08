<?php
/**
 * vault_heartbeat — keep the unlock window alive while a mail surface is visible
 * (specs/mailbox_security_levels.md § The Unlock Window). The web/native mail
 * surfaces stamp this on a visibility-aware interval; the window ends at the next
 * read once heartbeats stop for longer than the grace interval. Returns
 * `alive:false` when there is no open window so the client stops beating.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_heartbeat_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();
	if ($user_id <= 0) {
		return LogicResult::render(['alive' => false]);
	}

	$alive = VaultUnlock::heartbeat($user_id, UserEncryptionVault::SCOPE_USER);
	return LogicResult::render(['alive' => $alive]);
}

function vault_heartbeat_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Heartbeat to keep the vault unlock window alive while a mail surface is visible',
	];
}
?>

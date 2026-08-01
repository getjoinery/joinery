<?php
/**
 * vault_heartbeat — keep the unlock window alive while a mail surface is visible
 * (specs/mailbox_security_levels.md § The Unlock Window). The web/native mail
 * surfaces stamp this on a visibility-aware interval; the window ends at the next
 * read once heartbeats stop for longer than the grace interval. Returns
 * `alive:false` when there is no open window so the client stops beating.
 *
 * Also reports `work_pending` — whether any feature has deferred work waiting on
 * this window (specs/in_window_deferred_work.md). Reporting only: the drain runs
 * in its own request so a slow model can never stall the beat.
 *
 * @version 1.1
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

	// Whether any feature has work waiting on this window (see
	// specs/in_window_deferred_work.md). The beat only ANSWERS this — it never
	// does the work, because the work can involve a language model and the beat
	// must stay fast enough to keep the window open reliably. The client fires
	// vault_deferred_work as a separate request when this is true.
	$work_pending = false;
	if ($alive) {
		require_once(PathHelper::getIncludePath('includes/VaultDeferredWork.php'));
		$work_pending = VaultDeferredWork::hasWork($user_id);
	}

	return LogicResult::render(['alive' => $alive, 'work_pending' => $work_pending]);
}

function vault_heartbeat_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Heartbeat to keep the vault unlock window alive while a mail surface is visible',
	];
}
?>

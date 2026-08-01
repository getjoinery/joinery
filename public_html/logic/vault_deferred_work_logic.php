<?php
/**
 * vault_deferred_work — run one slice of work that needs an open vault
 * (specs/in_window_deferred_work.md).
 *
 * The browser fires this after a heartbeat reports `work_pending`. It is a
 * separate request from the heartbeat on purpose: a slice can involve a
 * language model, and the beat that holds the window open must never be able
 * to block on one.
 *
 * Returns what was completed, and whether more remains, so the client can
 * decide whether to come back before the next beat.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_deferred_work_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultDeferredWork.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();
	if ($user_id <= 0) {
		return LogicResult::render(['locked' => true, 'done' => [], 'more' => false]);
	}

	// A caller cannot ask for a bigger slice than the site allows — the budget
	// is a server policy, not a client hint.
	$result = VaultDeferredWork::drain($user_id, UserEncryptionVault::SCOPE_USER);

	return LogicResult::render([
		'locked' => (bool)$result['locked'],
		'done'   => $result['done'],
		'more'   => (bool)$result['more'],
	]);
}

function vault_deferred_work_logic_descriptor() {
	return [
		'requires_session' => true,
		// The unlock window is keyed to the browser session, so an API key can
		// never carry one — state the boundary rather than let it fail
		// incidentally. Same contract every vault endpoint declares.
		'auth' => ['requires_browser_session' => true],
		'description' => 'Run one slice of deferred work that requires the caller\'s vault to be unlocked',
		'input' => [],
	];
}
?>

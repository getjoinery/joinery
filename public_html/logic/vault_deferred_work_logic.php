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
 * decide whether to come back before the next beat. A page waiting on a drain
 * posts `foreground`: the slice runs only the work a page can wait on, and
 * `foreground_more` says whether any of that remains.
 *
 * `lane` picks which work runs (VaultDeferredWork § Two lanes): `slice`, the
 * default, or `long`, the work that may wait on a language model, which the
 * browser sends on a track of its own. `more` answers for the slice lane and
 * `long_more` for the long lane. Nothing a page waits on is in the long lane,
 * so a foreground long drain is refused.
 *
 * @version 1.2 - the lane input; long_more in the answer
 * @version 1.1 - the foreground input; foreground_more and ms in the answer
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
		return LogicResult::render(['locked' => true, 'done' => [], 'more' => false, 'long_more' => false, 'foreground_more' => false, 'ms' => []]);
	}

	$lane = (string)($input['lane'] ?? VaultDeferredWork::LANE_SLICE);
	if ($lane !== VaultDeferredWork::LANE_SLICE && $lane !== VaultDeferredWork::LANE_LONG) {
		return LogicResult::error('Unknown lane.');
	}
	$foreground = !empty($input['foreground']);
	if ($foreground && $lane === VaultDeferredWork::LANE_LONG) {
		return LogicResult::error('A long drain cannot be foreground.');
	}

	// A caller cannot ask for a bigger slice than the site allows — the budget
	// is a server policy, not a client hint. Foreground can only shrink it.
	$result = VaultDeferredWork::drain($user_id, UserEncryptionVault::SCOPE_USER, null, $foreground, $lane);

	return LogicResult::render([
		'locked' => (bool)$result['locked'],
		'done'   => $result['done'],
		'more'   => (bool)$result['more'],
		'long_more' => (bool)$result['long_more'],
		'foreground_more' => (bool)$result['foreground_more'],
		'ms'     => $result['ms'],
	]);
}

function vault_deferred_work_logic_descriptor() {
	return [
		'requires_session' => true,
		// The unlock window is keyed to the browser session, so an API key can
		// never carry one — state the boundary rather than let it fail
		// incidentally. Same contract every vault endpoint declares.
		'auth' => ['min_user_permission' => 0, 'requires_browser_session' => true],
		'description' => 'Run one slice of deferred work that requires the caller\'s vault to be unlocked',
		'input' => [
			'foreground' => ['type' => 'bool', 'required' => false, 'label' => 'A page is waiting on this work'],
			'lane'       => ['type' => 'string', 'required' => false, 'enum' => ['slice', 'long'], 'label' => 'Which work to run: slice (default) or long (may wait on a model)'],
		],
	];
}
?>

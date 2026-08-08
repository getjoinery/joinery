<?php
/**
 * API action: joinery_ai/ai_action_resolve — approve or decline one of the
 * signed-in user's pending AI actions (specs/implemented/ai_action_queue.md § Resolving).
 *
 * POST /api/v1/action/joinery_ai/ai_action_resolve (browser session). Params:
 * action_id, resolution (approve | decline). Approve executes the action IN
 * THIS REQUEST, as the owner, re-validating against live state through the
 * same audited tool path — an execution failure resolves the row `failed`
 * with the reason on the card. Decline runs nothing. Resolving a non-pending
 * action is refused (idempotent-safe: the card refreshes to its true state).
 * There is deliberately no approve-all: rubber-stamping is the queue's
 * failure mode.
 *
 * @version 1.0
 */

function ai_action_resolve_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionQueue.php'));

	$session = SessionControl::get_instance();
	$uid = (int)$session->get_user_id();
	if (!$uid) return LogicResult::error('Sign in required.');

	$action_id = (int)($input['action_id'] ?? 0);
	$resolution = (string)($input['resolution'] ?? '');

	try {
		$row = ActionQueue::resolve($action_id, $uid, $resolution);
	} catch (ActionQueueException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render([
		'card'          => ActionQueue::card($row),
		'pending_count' => ActionQueue::pendingCount($uid),
	]);
}

function ai_action_resolve_logic_descriptor(): array {
	return [
		'description' => "Approve (execute now, as the owner) or decline one pending AI action.",
		'mutates'     => true,
		'auth'        => [
			'capability'       => 'write',
			'requires_session' => true,
		],
		'input'       => [
			'action_id'  => ['type' => 'int', 'required' => true, 'label' => 'The pending action'],
			'resolution' => ['type' => 'string', 'required' => true,
				'enum' => ['approve', 'decline'], 'label' => 'Approve or decline'],
		],
	];
}

?>

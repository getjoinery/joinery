<?php
/**
 * API action: joinery_ai/ai_status — what the AI is doing for the signed-in
 * user, and what it is waiting on them for.
 *
 * POST /api/v1/action/joinery_ai/ai_status (browser session). No params. One
 * call answers both halves of the AI panel, so the panel's header counts and
 * its two lists can never disagree with each other:
 *
 *   jobs / job_count       recipe runs in flight — running, queued for a
 *                          worker, or waiting for the owner's own unlocked
 *                          session (AiPanelService::jobs)
 *   actions / pending_count  queued actions awaiting an approve or a decline,
 *                          as the same server-rendered cards ai_actions_list
 *                          returns (ActionQueue::card)
 *
 * Ownership scoping is the authorization: everyone sees exactly their own
 * runs and their own queued actions, so there is no permission gate.
 *
 * @version 1.0
 */

function ai_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AiPanelService.php'));
	require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionQueue.php'));

	$session = SessionControl::get_instance();
	$uid = (int)$session->get_user_id();
	if (!$uid) {
		return LogicResult::error('Sign in required.');
	}

	// A pending row past its expiry can never run, so it is swept before it is
	// counted — the number on the button is the truth, not a stale promise.
	ActionQueue::expireOverdueFor($uid);

	$rows = new MultiAiQueuedAction(
		['owner_user_id' => $uid, 'status' => AiQueuedAction::STATUS_PENDING],
		['aqa_ai_queued_action_id' => 'DESC'], 50);
	$rows->load();
	$actions = [];
	foreach ($rows as $row) {
		$actions[] = ActionQueue::card($row);
	}

	$jobs = AiPanelService::jobs($uid);

	return LogicResult::render([
		'jobs'          => $jobs['jobs'],
		'job_count'     => $jobs['count'],
		'actions'       => $actions,
		'pending_count' => ActionQueue::pendingCount($uid),
	]);
}

function ai_status_logic_descriptor(): array {
	return [
		'description' => "What the AI is working on for the signed-in user, and the actions it is waiting on them to approve or decline.",
		'mutates'     => false,
		'requires_session' => true,
		'auth'        => [
			'capability' => 'read',
		],
		'input'       => [],
	];
}

?>

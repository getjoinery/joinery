<?php
/**
 * API action: joinery_ai/ai_actions_list — the signed-in user's queued AI
 * actions as server-rendered cards (specs/implemented/ai_action_queue.md § API surface).
 *
 * POST /api/v1/action/joinery_ai/ai_actions_list (browser session). Params:
 * status (default 'pending'), area, conversation_id — all optional filters.
 * Card facts are rendered by the platform from each action's stored literal
 * arguments; the client never interprets them. A card sealed to a locked
 * vault carries locked=true and no facts. Ownership scoping is the
 * authorization: everyone lists exactly their own actions.
 *
 * @version 1.0
 */

function ai_actions_list_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionQueue.php'));

	$session = SessionControl::get_instance();
	$uid = (int)$session->get_user_id();
	if (!$uid) return LogicResult::error('Sign in required.');

	// A pending row past its expiry can never run — sweep before listing so
	// the list is always the truth.
	ActionQueue::expireOverdueFor($uid);

	$options = ['owner_user_id' => $uid];
	$status = trim((string)($input['status'] ?? 'pending'));
	if ($status !== '' && $status !== 'all') $options['status'] = $status;
	if (!empty($input['conversation_id'])) {
		$options['conversation_id'] = (int)$input['conversation_id'];
	}
	if (trim((string)($input['area'] ?? '')) !== '') {
		$options['area'] = trim((string)$input['area']);
	}

	$rows = new MultiAiQueuedAction($options, ['aqa_ai_queued_action_id' => 'DESC'], 50);
	$rows->load();
	$cards = [];
	foreach ($rows as $row) {
		$cards[] = ActionQueue::card($row);
	}

	return LogicResult::render([
		'actions'       => $cards,
		'pending_count' => ActionQueue::pendingCount($uid),
	]);
}

function ai_actions_list_logic_descriptor(): array {
	return [
		'description' => "The signed-in user's queued AI actions, as server-rendered approval cards.",
		'mutates'     => false,
		'auth'        => [
			'capability'       => 'read',
			'requires_session' => true,
		],
		'input'       => [
			'status' => ['type' => 'string', 'required' => false,
				'enum' => ['pending', 'approved', 'declined', 'expired', 'failed', 'all'],
				'label' => "Status filter (default 'pending')"],
			'area'   => ['type' => 'string', 'required' => false, 'label' => 'Area filter'],
			'conversation_id' => ['type' => 'int', 'required' => false,
				'label' => 'Only actions proposed in this conversation'],
		],
	];
}

?>

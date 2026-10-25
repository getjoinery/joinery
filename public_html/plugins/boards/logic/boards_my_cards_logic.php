<?php
/**
 * API action: boards/boards_my_cards — every open card (and checklist item)
 * assigned to the signed-in member, across all the boards they can open.
 *
 * POST /api/v1/action/boards/boards_my_cards
 *
 * Soonest due first, undated last. Each entry names its board and list and says
 * whether it is overdue; the page groups them by due date.
 *
 * Params:
 *   window   'week' keeps only what is overdue or due in the next seven days
 *
 * @version 1.0.0
 */

function boards_my_cards_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		return BoardsPayload::myCards($user_id, array('window' => (string)($input['window'] ?? '')));
	});
}

function boards_my_cards_logic_descriptor(): array {
	return array(
		'description'      => 'The signed-in member\'s open cards and assigned checklist items across all boards, soonest due first.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => false,
		'input'            => array(
			'window' => array('type' => 'string', 'required' => false, 'enum' => array('week'), 'label' => 'Window'),
		),
		'auth'             => array('min_user_permission' => 0, 'capability' => 'read'),
		'ai_agent'         => 'auto',
	);
}

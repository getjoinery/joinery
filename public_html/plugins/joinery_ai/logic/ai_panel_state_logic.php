<?php
/**
 * API action: joinery_ai/ai_panel_state — the signed-in user's AI recipe cards
 * for one area page's panel (specs/implemented/ai_recipes_multi_mailbox_and_ai_panel.md
 * § Phase 2).
 *
 * POST /api/v1/action/joinery_ai/ai_panel_state (browser session). Params:
 * area ('mailbox'), plus the area's own context fields (mailbox = the address
 * currently open in the reader's rail). Returns { cards: [...] } — the user's
 * own area recipes plus one template card per shipped declaration they have no
 * instance of. Card facts are server-rendered; the client never interprets
 * recipe config itself. Ownership scoping is the authorization: everyone sees
 * exactly their own recipes, so there is no permission gate.
 *
 * @version 1.0
 */

function ai_panel_state_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AiPanelService.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$area = (string)($input['area'] ?? '');
	$context = ['mailbox' => strtolower(trim((string)($input['mailbox'] ?? '')))];

	$cards = AiPanelService::state(
		(int)$session->get_user_id(), (int)$session->get_permission(), $area, $context);

	return LogicResult::render(['cards' => $cards]);
}

function ai_panel_state_logic_descriptor(): array {
	return [
		'description' => "The signed-in user's AI recipe cards for one area page's AI panel.",
		'mutates'     => false,
		'requires_session' => true,
		'auth'        => [
			'capability'       => 'read',
		],
		'input'       => [
			'area'    => ['type' => 'string', 'required' => true, 'enum' => ['mailbox'],
				'label' => 'Area page'],
			'mailbox' => ['type' => 'string', 'required' => false,
				'label' => 'Mailbox address currently open (the mailbox area context)'],
		],
	];
}

?>

<?php
/**
 * API action: joinery_ai/ai_panel_toggle — bind or unbind the panel's current
 * context on one of the signed-in user's AI recipes
 * (specs/implemented/ai_recipes_multi_mailbox_and_ai_panel.md § Phase 2).
 *
 * POST /api/v1/action/joinery_ai/ai_panel_toggle (browser session). Params:
 * area, the area context (mailbox), enabled, and either recipe_id (the user's
 * own recipe) or template_key (a shipped declaration — first toggle-ON creates
 * the user's own enabled instance; the seeded row is never mutated).
 *
 * Turning ON a tainted-capable recipe before its owner has accepted tainted
 * writes answers { confirm_required: true, confirm_text } rather than an
 * error, so the panel renders its confirm dialog from server truth; the client
 * retries with accept_tainted_writes once the person agrees. A toggle against
 * a globally disabled recipe is refused — the kill switch is dashboard-only.
 *
 * Ownership scoping is the authorization; there is no permission gate.
 *
 * @version 1.0
 */

function ai_panel_toggle_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AiPanelService.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$area = (string)($input['area'] ?? '');
	$context = ['mailbox' => strtolower(trim((string)($input['mailbox'] ?? '')))];
	$recipe_id = isset($input['recipe_id']) ? (int)$input['recipe_id'] : null;
	$template_key = trim((string)($input['template_key'] ?? ''));
	$enabled = !empty($input['enabled']);
	$accept = !empty($input['accept_tainted_writes']);

	try {
		$card = AiPanelService::toggle(
			(int)$session->get_user_id(), (int)$session->get_permission(),
			$area, $context, $recipe_id, $template_key, $enabled, $accept);
	} catch (AiPanelConfirmRequired $e) {
		return LogicResult::render([
			'confirm_required' => true,
			'confirm_text'     => $e->getMessage(),
		]);
	} catch (AiPanelServiceException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['card' => $card]);
}

function ai_panel_toggle_logic_descriptor(): array {
	return [
		'description' => "Bind or unbind the area context on one of the signed-in user's AI recipes.",
		'mutates'     => true,
		'requires_session' => true,
		'auth'        => [
			'capability'       => 'write',
		],
		'input'       => [
			'area'         => ['type' => 'string', 'required' => true, 'enum' => ['mailbox'],
				'label' => 'Area page'],
			'mailbox'      => ['type' => 'string', 'required' => true,
				'label' => 'Mailbox address the toggle was clicked on'],
			'recipe_id'    => ['type' => 'int', 'required' => false,
				'label' => "One of the caller's own recipes"],
			'template_key' => ['type' => 'string', 'required' => false,
				'label' => 'A shipped template (first toggle-ON creates the caller\'s instance)'],
			'enabled'      => ['type' => 'bool', 'required' => true,
				'label' => 'Bind (true) or unbind (false) the context'],
			'accept_tainted_writes' => ['type' => 'bool', 'required' => false,
				'label' => 'The confirm dialog was accepted'],
		],
	];
}

?>

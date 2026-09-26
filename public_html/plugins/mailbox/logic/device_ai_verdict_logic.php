<?php
/**
 * API action: mailbox/device_ai_verdict — store a verdict the caller's browser
 * sealed under a Fortress message's own key (specs/fortress_mail_device_ai.md
 * § R4).
 *
 * POST /api/v1/action/mailbox/device_ai_verdict (browser session). Params: id
 * (the message), recipe_id, fields ({iem_ai_summary} for the triage,
 * {iem_ai_scan} for the security scan, each `v1.edge.` ciphertext), and
 * danger_score (0–10, the scan only; kept in the clear as a Private row keeps
 * it). The fields, the clear score and scan time, and the recipe's `done` row
 * are written in one transaction. Returns {recorded}: false when the recipe
 * had already judged the message (a second tab got there first), in which
 * case nothing was written.
 *
 * @version 1.0
 */

function device_ai_verdict_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : array();
	$score = (isset($input['danger_score']) && $input['danger_score'] !== '' && $input['danger_score'] !== null)
		? (int)$input['danger_score'] : null;
	try {
		$recorded = MailboxDeviceAi::recordVerdict((int)$session->get_user_id(), (int)($input['recipe_id'] ?? 0),
			(int)($input['id'] ?? 0), $fields, $score);
	} catch (MailboxDeviceAiException $e) {
		return LogicResult::error($e->getMessage());
	}
	return LogicResult::render(array('recorded' => $recorded));
}

function device_ai_verdict_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Store a device-computed AI verdict, sealed by the browser under a Fortress message\'s own key, with the recipe\'s done mark, atomically',
		'input' => [
			'id'           => ['type' => 'int', 'required' => true, 'label' => 'Message ID'],
			'recipe_id'    => ['type' => 'int', 'required' => true, 'label' => 'Recipe ID (the caller\'s own)'],
			'fields'       => ['type' => 'object', 'required' => true, 'label' => 'Sealed verdict fields (v1.edge. ciphertext)'],
			'danger_score' => ['type' => 'int', 'required' => false, 'label' => 'Danger score 0-10 (security scan only)'],
		],
	);
}
?>

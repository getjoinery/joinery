<?php
/**
 * API action: mailbox/ai_device_record — record that the caller's own model
 * answered for a Fortress message but its answer failed validation after the
 * retry, so the recipe does not offer the message again
 * (specs/fortress_mail_device_ai.md § R3).
 *
 * POST /api/v1/action/mailbox/ai_device_record (browser session). Params:
 * recipe_id, item_key (the message id). Records `error` and nothing else: a
 * verdict records its own `done` with the verdict (mailbox/device_ai_verdict),
 * and a call that never reached the model records nothing, so the message
 * comes back next time. Returns {recorded}.
 *
 * @version 1.0
 */

function ai_device_record_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	try {
		$recorded = MailboxDeviceAi::recordError((int)$session->get_user_id(), (int)($input['recipe_id'] ?? 0),
			(int)($input['item_key'] ?? 0));
	} catch (MailboxDeviceAiException $e) {
		return LogicResult::error($e->getMessage());
	}
	return LogicResult::render(array('recorded' => $recorded));
}

function ai_device_record_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Record that the caller\'s own model gave an invalid verdict for one of their Fortress messages, so the recipe skips it',
		'input' => [
			'recipe_id' => ['type' => 'int', 'required' => true, 'label' => 'Recipe ID (the caller\'s own)'],
			'item_key'  => ['type' => 'string', 'required' => true, 'label' => 'Message ID'],
		],
	);
}
?>

<?php
/**
 * API action: mailbox/ai_device_recipes — what the owner's browser needs to
 * judge their end-to-end encrypted mail on one mailbox against their own model
 * (specs/fortress_mail_device_ai.md § R5).
 *
 * POST /api/v1/action/mailbox/ai_device_recipes (browser session). Params:
 * mailbox (address). Returns {alias_id, recipes, authserv_id, model_reference,
 * consent_refusal}: each recipe is {recipe_id, job_id, label, system, nonce,
 * verdict_descriptor, max_tokens, min_tier, attachments}, `system` being the
 * full system prompt a server run would send (PipelineRunner::systemText)
 * under a fresh nonce the browser wraps its digest in. consent_refusal is the
 * reason the mailbox's domain refuses the registered model, or null.
 * MailboxDeviceAi holds the rules.
 *
 * @version 1.0
 */

function ai_device_recipes_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	try {
		return LogicResult::render(MailboxDeviceAi::recipesFor((int)$session->get_user_id(), (string)($input['mailbox'] ?? '')));
	} catch (MailboxDeviceAiException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function ai_device_recipes_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'The caller\'s device-capable AI recipes on one mailbox, with the full system prompt, nonce and verdict shape their browser judges with',
		'input' => [
			'mailbox' => ['type' => 'string', 'required' => true, 'label' => 'Mailbox address'],
		],
	);
}
?>

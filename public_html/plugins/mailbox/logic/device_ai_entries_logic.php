<?php
/**
 * API action: mailbox/device_ai_entries — one page of the caller's end-to-end
 * encrypted (Fortress) messages a recipe has not judged yet, for their browser
 * to judge against their own model (specs/fortress_mail_device_ai.md § R3).
 *
 * POST /api/v1/action/mailbox/device_ai_entries (browser session). Params:
 * recipe_id; alias_id (0 or absent: every mailbox the recipe covers);
 * before_id (the cursor; 0 or absent: from the newest). Returns {entries,
 * next_before_id}: each entry is {id, received_time, dkim_result, spf_result,
 * dmarc_result, auth_source, sealed} with `sealed` in the shape the reader's
 * MailboxFortress opens (key, sealed_dek, sealed_ad_prefix and the sealed
 * columns one judgement needs). No content is opened here; the server holds
 * no key that could. MailboxDeviceAi holds the rules.
 *
 * @version 1.0
 */

function device_ai_entries_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	try {
		return LogicResult::render(MailboxDeviceAi::entries((int)$session->get_user_id(),
			(int)($input['recipe_id'] ?? 0), (int)($input['alias_id'] ?? 0), (int)($input['before_id'] ?? 0)));
	} catch (MailboxDeviceAiException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function device_ai_entries_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Page the caller\'s end-to-end encrypted messages a device-capable recipe has not judged, newest first, with their sealed columns for the browser to open',
		'input' => [
			'recipe_id' => ['type' => 'int', 'required' => true, 'label' => 'Recipe ID (the caller\'s own)'],
			'alias_id'  => ['type' => 'int', 'required' => false, 'label' => 'One mailbox the recipe covers (0: all)'],
			'before_id' => ['type' => 'int', 'required' => false, 'label' => 'Cursor: message ids below this (0: newest)'],
		],
	);
}
?>

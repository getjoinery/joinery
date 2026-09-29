<?php
/**
 * API action: mailbox/relay_seal_target — the relay's signed word on which key
 * it seals one of the caller's Fortress mailboxes to (specs/client_custody_mail.md
 * § R10), for the caller's browser to check against the relay it pinned.
 *
 * POST /api/v1/action/mailbox/relay_seal_target (browser session). Input:
 * alias_id, a Fortress mailbox of the caller's under Seal at the relay.
 * Returns {address, relay_answer, relay_identity_public_key, pin}: the relay's
 * body byte for byte (JSON {statement, signature}), the relay identity this
 * server pins the relay's TLS to (what a first use pins), and the stored pin
 * ({relay_identity_public_key, mac}) or null.
 *
 * @version 1.0
 */

function relay_seal_target_logic(array $input): LogicResult {
	$user_id = (int)SessionControl::get_instance()->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}
	try {
		return LogicResult::render(MailboxRelayPin::sealTarget($user_id, intval($input['alias_id'] ?? 0)));
	} catch (MailboxRelayPinException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function relay_seal_target_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'mutates' => false,
		'description' => 'The relay\'s signed statement of which key it seals one of the caller\'s end-to-end mailboxes to, unchanged, with the stored pin',
		'input' => [
			'alias_id' => ['type' => 'int', 'required' => true, 'label' => 'Mailbox alias ID'],
		],
	);
}
?>

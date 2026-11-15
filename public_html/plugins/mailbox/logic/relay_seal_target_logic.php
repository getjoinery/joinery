<?php
/**
 * API action: mailbox/relay_seal_target — the relay's signed word on which key
 * it seals one of the caller's Fortress mailboxes to (specs/client_custody_mail.md
 * § R10), for the caller's browser to check against the relay it pinned.
 *
 * POST /api/v1/action/mailbox/relay_seal_target (browser session or app session key). Input:
 * alias_id, a Fortress mailbox of the caller's under Seal at the relay;
 * challenge (optional), the browser's one-time value for the relay to sign.
 * Returns {address, relay_answer, relay_identity_public_key, pin}: the relay's
 * body byte for byte (JSON {statement, signature}), the relay identity this
 * server pins the relay's TLS to (what a first use pins), and the stored pin
 * ({relay_identity_public_key, mac}) or null, and update: the site's account
 * of the run that gave the relay its identity (MailboxRelayPin::updateFor), or
 * null. The browser shows update as what the site says, never as a check.
 *
 * @version 1.3 - the answer carries update (specs/relay_update_trust_step.md R6)
 * @version 1.2 - passes the browser's challenge to the relay
 * @version 1.1 - reachable with an app session key too (requires_person_credential,
 * specs/fortress_mobile_apps.md § R8)
 * @version 1.0
 */

function relay_seal_target_logic(array $input): LogicResult {
	$user_id = (int)SessionControl::get_instance()->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}
	try {
		return LogicResult::render(MailboxRelayPin::sealTarget($user_id, intval($input['alias_id'] ?? 0), null,
			(string)($input['challenge'] ?? '')));
	} catch (MailboxRelayPinException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function relay_seal_target_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('min_user_permission' => 0, 'requires_person_credential' => true),
		'mutates' => false,
		'description' => 'The relay\'s signed statement of which key it seals one of the caller\'s end-to-end mailboxes to, unchanged, with the stored pin',
		'input' => [
			'alias_id' => ['type' => 'int', 'required' => true, 'label' => 'Mailbox alias ID'],
			'challenge' => ['type' => 'string', 'required' => false, 'label' => 'A one-time value for the relay to sign into its answer'],
		],
	);
}
?>

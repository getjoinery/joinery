<?php
/**
 * API action: mailbox/relay_pin_set — store the relay pin the caller's browser
 * made for one of their mailboxes (specs/client_custody_mail.md § R10).
 *
 * POST /api/v1/action/mailbox/relay_pin_set (browser session). Input:
 * alias_id, relay_identity_public_key (standard base64 Ed25519), mac (standard
 * base64 HMAC-SHA256, made with a key only the caller's mail vault derives, so
 * nothing here can check it). A first pin, and a new MAC over the same
 * identity (a mail key rotation), need nothing more; pinning ANOTHER relay
 * identity needs a recent step-up (requires_stepup), because it is the one act
 * that tells the browser to trust a different relay.
 *
 * @version 1.0
 */

function relay_pin_set_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}
	$alias_id = intval($input['alias_id'] ?? 0);
	$identity = (string)($input['relay_identity_public_key'] ?? '');
	if (MailboxRelayPin::changesIdentity($user_id, $alias_id, $identity) && $session->step_up_outstanding(null, 300)) {
		return LogicResult::error('Confirm it is you before trusting a different relay.', ['requires_stepup' => true]);
	}
	try {
		MailboxRelayPin::setPin($user_id, $alias_id, $identity, (string)($input['mac'] ?? ''));
	} catch (MailboxRelayPinException $e) {
		return LogicResult::error($e->getMessage());
	}
	return LogicResult::render(array('alias_id' => $alias_id, 'pinned' => true));
}

function relay_pin_set_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Store the relay pin the caller\'s browser made for one of their mailboxes; another relay identity needs a recent step-up',
		'input' => [
			'alias_id' => ['type' => 'int', 'required' => true, 'label' => 'Mailbox alias ID'],
			'relay_identity_public_key' => ['type' => 'string', 'required' => true, 'max_length' => 64, 'label' => 'The relay identity public key (standard base64)'],
			'mac' => ['type' => 'string', 'required' => true, 'max_length' => 64, 'label' => 'The pin MAC (standard base64)'],
		],
	);
}
?>

<?php
/**
 * API action: mailbox/relay_pins — every relay pin the caller's browser made
 * (specs/client_custody_mail.md § R10), for a mail key rotation to make again
 * under the new key (mailbox-reseal.js). [{alias_id, relay_identity_public_key, mac}].
 *
 * @version 1.0
 */

function relay_pins_logic(array $input): LogicResult {
	$user_id = (int)SessionControl::get_instance()->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}
	return LogicResult::render(array('pins' => MailboxRelayPin::pins($user_id)));
}

function relay_pins_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'mutates' => false,
		'description' => 'The relay pins the caller\'s browser made, for a mail key rotation to make again: {pins}',
		'input' => [],
	);
}
?>

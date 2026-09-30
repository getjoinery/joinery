<?php
/**
 * API action: mailbox/fortress_pending — the newest relay-sealed Fortress
 * message the caller's browser still has to parse (specs/client_custody_mail.md
 * § R9), and how many remain.
 *
 * POST /api/v1/action/mailbox/fortress_pending (browser session or app session key). Input:
 * skip, optional, comma-separated ids this device could not parse.
 * Returns {remaining, item}: item is null when nothing waits, else {id,
 * sealed_dek, sealed_raw, raw_ad, sealed_ad_prefix} — the relay's key for the
 * row, the message sealed under it, the AD that binds it, and the prefix the
 * browser seals the parsed fields under. Everything in it is ciphertext only
 * the caller's mail key opens. The browser parses it and posts the result to
 * mailbox/fortress_parse_store.
 *
 * @version 1.2 - max_bytes: a device hands out only messages it can hold (a phone)
 * @version 1.1 - reachable with an app session key too (requires_person_credential,
 * specs/fortress_mobile_apps.md § R8)
 * @version 1.0
 */

function fortress_pending_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}
	$skip = array_map('intval', explode(',', (string)($input['skip'] ?? '')));
	return LogicResult::render(MailboxFortressParse::next($user_id, $skip, intval($input['max_bytes'] ?? 0)));
}

function fortress_pending_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_person_credential' => true),
		'mutates' => false,
		'description' => 'The newest relay-sealed end-to-end message the caller still has to parse in the browser, as ciphertext, and how many remain: {remaining, item}',
		'input' => [
			'skip' => ['type' => 'string', 'required' => false, 'max_length' => 2000, 'label' => 'Comma-separated ids this device could not parse'],
			'max_bytes' => ['type' => 'int', 'required' => false, 'label' => 'The largest message this device can open (0: no limit); larger ones are left for another device'],
		],
	);
}
?>

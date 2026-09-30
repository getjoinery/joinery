<?php
/**
 * API action: mailbox/fortress_parse_store — store a relay-sealed Fortress
 * message the caller's browser has parsed (specs/client_custody_mail.md § R9).
 *
 * POST /api/v1/action/mailbox/fortress_parse_store (browser session,
 * multipart). Params: id; sealed_dek (the row's own key, as
 * mailbox/fortress_pending handed it); fields (JSON: column => `v1.edge.`
 * ciphertext under that key); parts (JSON: [{mime_part, size, inline, offset,
 * length}]) naming where each part's ciphertext sits in the one `bundle`
 * upload (one file, whatever the number of parts: PHP drops uploads past
 * max_file_uploads); spam_headers (JSON: {x_spam, x_spam_flag, x_spam_score,
 * x_spam_status} as read out of the message). No plaintext reaches the server.
 *
 * Returns {id, stored, stale?}: stored is false when nothing changed — the row
 * was parsed already (by another of the caller's devices), or, with stale,
 * its key changed since it was fetched (fetch it again).
 *
 * @version 1.3 - rule_matches and the forward_raw upload (specs/fortress_mobile_apps.md § R14)
 * @version 1.2 - reachable with an app session key too (requires_person_credential,
 * specs/fortress_mobile_apps.md § R8)
 * @version 1.1 - one bundle upload; the stale answer
 * @version 1.0
 */

function fortress_parse_store_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}
	$decode = function ($raw) {
		if (is_array($raw)) {
			return $raw;
		}
		$v = json_decode((string)$raw, true);
		return is_array($v) ? $v : array();
	};
	try {
		$result = MailboxFortressParse::store($user_id, array(
			'id'           => intval($input['id'] ?? 0),
			'sealed_dek'   => (string)($input['sealed_dek'] ?? ''),
			'fields'       => $decode($input['fields'] ?? ''),
			'parts'        => $decode($input['parts'] ?? ''),
			'spam_headers' => $decode($input['spam_headers'] ?? ''),
			'rule_matches' => $decode($input['rule_matches'] ?? ''),
		), isset($_FILES['bundle']) && is_array($_FILES['bundle']) ? $_FILES['bundle'] : null,
			isset($_FILES['forward_raw']) && is_array($_FILES['forward_raw']) ? $_FILES['forward_raw'] : null);
	} catch (MailboxFortressParseException $e) {
		return LogicResult::error($e->getMessage());
	}
	return LogicResult::render($result);
}

function fortress_parse_store_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_person_credential' => true),
		'description' => 'Store an end-to-end message the caller\'s browser parsed from its relay-sealed form: the fields and attachments as ciphertext under the row\'s own key',
		'input' => [
			'id' => ['type' => 'int', 'required' => true, 'label' => 'Message ID'],
			'sealed_dek' => ['type' => 'string', 'required' => true, 'max_length' => 4096, 'label' => 'The row\'s key (v1.edgeseal.mail.)'],
			'fields' => ['type' => 'text', 'required' => true, 'label' => 'JSON: sealed column => v1.edge. ciphertext'],
			'parts' => ['type' => 'text', 'required' => false, 'max_length' => 100000, 'label' => 'JSON: [{mime_part, size, inline, offset, length}] placing each sealed part in the bundle upload'],
			'spam_headers' => ['type' => 'text', 'required' => false, 'max_length' => 4000, 'label' => 'JSON: the X-Spam* header values read from the message'],
			'rule_matches' => ['type' => 'text', 'required' => false, 'max_length' => 4000, 'label' => 'JSON: the ids of the mailbox\'s mail rules (device_rules) the parsed message matched'],
		],
	);
}
?>

<?php
/**
 * API action: mailbox/search_entries — one page of the caller's end-to-end
 * encrypted (Fortress) messages with their sealed search text, for the
 * caller's browser to open and add to its own search index
 * (specs/client_custody_mail.md § R5).
 *
 * POST /api/v1/action/mailbox/search_entries (browser session or app session key). Params:
 * order ('old': backwards from a point in time, newest first — a browser's
 * first build; 'new': forwards from where it got to — catching up); time and
 * id (the cursor; time '' starts from now for 'old', from the beginning for
 * 'new'); overlap (a catch-up's first page reaches ten minutes further back);
 * with_total. Returns {entries: [{id, sealed}], next, last, server_time,
 * total?}. No content is opened here; the server holds no key that could.
 * MailboxDeviceSearch holds the rules.
 *
 * @version 1.1 - reachable with an app session key too (requires_person_credential,
 * specs/fortress_mobile_apps.md § R8)
 * @version 1.0
 */

function search_entries_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	try {
		return LogicResult::render(MailboxDeviceSearch::entries((int)$session->get_user_id(),
			(string)($input['order'] ?? ''), (string)($input['time'] ?? ''), (int)($input['id'] ?? 0),
			!empty($input['overlap']), !empty($input['with_total'])));
	} catch (MailboxDeviceSearchException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function search_entries_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_person_credential' => true),
		'mutates' => false,
		'description' => 'Page the caller\'s end-to-end encrypted messages with their sealed search text, for their browser\'s own search index',
		'input' => [
			'order'      => ['type' => 'string', 'required' => true, 'enum' => ['old', 'new'], 'label' => 'Direction: old (first build, newest first) or new (catch up)'],
			'time'       => ['type' => 'string', 'required' => false, 'max_length' => 32, 'label' => 'Cursor: search-text written time (UTC)'],
			'id'         => ['type' => 'int', 'required' => false, 'label' => 'Cursor: message id at that time'],
			'overlap'    => ['type' => 'bool', 'required' => false, 'label' => 'Reach ten minutes behind the cursor (a catch-up\'s first page)'],
			'with_total' => ['type' => 'bool', 'required' => false, 'label' => 'Also count every such message'],
		],
	);
}
?>

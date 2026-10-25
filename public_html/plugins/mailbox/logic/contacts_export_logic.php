<?php
/**
 * API action: mailbox/contacts_export — download one mailbox's contacts as a file.
 *
 * POST /api/v1/action/mailbox/contacts_export (session credential). Params: alias_id (the
 * mailbox), format ('vcard' or 'csv'). Returns {filename, content_type, content}: a vCard
 * 3.0 file or a Google Contacts CSV of every contact the CALLER keeps for that mailbox,
 * which the page hands to the browser as a download. Both read back through
 * mailbox/contacts_import as they were.
 *
 * Scoped like mailbox/contacts: the caller must be able to open the mailbox, and only the
 * caller's own rows are read. A sealed store needs the vault window open; with it closed
 * the export is refused rather than coming back empty.
 *
 * @version 1.0
 */

function contacts_export_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$uid = intval($session->get_user_id());
	if (!$uid) {
		return LogicResult::error('Sign in required.');
	}

	$alias_id = intval($input['alias_id'] ?? 0);
	if ($alias_id <= 0) {
		return LogicResult::error('No mailbox specified.');
	}
	if (!MailboxViewer::fromSession($session)->canAccess($alias_id)) {
		return LogicResult::error('Not authorized.');
	}
	$format = (string)($input['format'] ?? '');
	if ($format !== 'vcard' && $format !== 'csv') {
		return LogicResult::error('Choose vCard or CSV.');
	}

	$list = (new MailboxContacts())->listForMailbox($uid, $alias_id, null);
	if (!empty($list['locked'])) {
		return LogicResult::error('Your contacts for this mailbox are sealed to your vault. Unlock it and try again.');
	}

	$alias = new InboundEmailAlias($alias_id, TRUE);
	$base = 'contacts-' . trim(preg_replace('/[^A-Za-z0-9@._-]+/', '-', (string)$alias->get_full_address()), '-');
	if ($format === 'vcard') {
		return LogicResult::render(array(
			'filename'     => $base . '.vcf',
			'content_type' => 'text/vcard;charset=utf-8',
			'content'      => MailboxContacts::toVcard($list['contacts']),
		));
	}
	return LogicResult::render(array(
		'filename'     => $base . '.csv',
		'content_type' => 'text/csv;charset=utf-8',
		'content'      => MailboxContacts::toGoogleCsv($list['contacts']),
	));
}

function contacts_export_logic_descriptor() {
	return array(
		'auth' => array('min_user_permission' => 0),
		'requires_session' => true,
		'description' => 'Download one mailbox\'s contacts as a vCard 3.0 file or a Google Contacts CSV',
		'input' => [
			'alias_id' => ['type' => 'int', 'required' => true, 'label' => 'Mailbox alias ID'],
			'format' => ['type' => 'string', 'required' => true, 'enum' => array('vcard', 'csv'), 'label' => 'File format'],
		],
	);
}
?>

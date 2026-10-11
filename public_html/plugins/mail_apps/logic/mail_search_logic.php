<?php
/**
 * mail_apps/mail_search — one text criterion of an IMAP SEARCH, answered as
 * the folder's numbers that match. The gateway evaluates flags, dates,
 * numbers, NOT and OR itself from what mail_sync told it, and asks here only
 * for what needs the mail's words: TEXT, BODY, SUBJECT, FROM, TO, CC, BCC,
 * and HEADER Message-ID (how a program finds a draft it saved).
 *
 * Words are found the way the reader finds them: the full-text index for
 * unsealed mail, and the person's own sealed search index in the window for a
 * Private mailbox (MailboxIndex).
 *
 * @version 1.0.0
 */

function mail_search_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		$folder = MailAppAction::folder($ctx, (string)$input['folder']);
		$uids = MailAppSearch::uids($ctx, $folder, (string)$input['field'], (string)$input['value']);
		$ctx->countCheckAsActivity();
		return LogicResult::render(array('folder' => $folder['key'], 'uids' => $uids));
	});
}

function mail_search_logic_descriptor() {
	return MailAppAction::descriptor('Find the messages in a mail-app folder matching one text criterion', array(
		'folder' => array('type' => 'string', 'required' => true, 'label' => 'Folder key', 'max_length' => 64),
		'field'  => array('type' => 'string', 'required' => true, 'label' => 'Field',
			'enum' => array('text', 'body', 'subject', 'from', 'to', 'cc', 'bcc', 'message-id')),
		'value'  => array('type' => 'string', 'required' => true, 'label' => 'Words', 'max_length' => 1000),
	));
}
?>

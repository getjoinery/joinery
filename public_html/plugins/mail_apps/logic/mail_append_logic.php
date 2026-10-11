<?php
/**
 * mail_apps/mail_append — a mail program puts a message in a folder (IMAP
 * APPEND): a draft into Drafts (saved as a Joinery draft), its copy of a sent
 * message into Sent (recognised as the message already stored, by
 * Message-ID), anything else stored as mail of this mailbox and placed in the
 * folder. Sealed with the mailbox's key where the mailbox seals, so adding
 * works while locked. Answers the message's number (APPENDUID), or none when
 * it cannot be numbered until the mailbox is unlocked.
 *
 * @version 1.0.0
 */

function mail_append_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		// The message arrives base64 in JSON and is parsed and stored: held several
		// times over for the largest mail a mailbox takes.
		@ini_set('memory_limit', '384M');
		$folder = MailAppAction::folder($ctx, (string)$input['folder']);
		$raw = base64_decode((string)$input['message'], true);
		if ($raw === false) {
			throw new MailAppRefusal('That message did not arrive whole.', 'CLIENTBUG');
		}
		$appended = MailAppMutations::append($ctx, $folder, $raw, (array)($input['flags'] ?? array()),
			isset($input['date']) ? (string)$input['date'] : null);
		return LogicResult::render(array(
			'folder'      => $folder['key'],
			'uid'         => $appended['uid'],
			'uidvalidity' => $appended['uidvalidity'],
		));
	});
}

function mail_append_logic_descriptor() {
	return MailAppAction::descriptor('Add a message a mail program put in a mail-app folder', array(
		'folder'  => array('type' => 'string', 'required' => true, 'label' => 'Folder key', 'max_length' => 64),
		'message' => array('type' => 'string', 'required' => true, 'label' => 'Message (base64)'),
		'flags'   => array('type' => 'array', 'required' => false, 'label' => 'Flags', 'max_items' => 64,
			'items' => array('type' => 'string', 'max_length' => 64)),
		'date'    => array('type' => 'string', 'required' => false, 'label' => 'Internal date', 'max_length' => 64),
	));
}
?>

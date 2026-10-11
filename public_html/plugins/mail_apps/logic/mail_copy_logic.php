<?php
/**
 * mail_apps/mail_copy — copy numbered messages to another folder (IMAP
 * COPY). A copy into a label adds the label; into Trash, Spam, Inbox or
 * Archive it is the same state change a move makes, because a message cannot
 * be in two of those at once. Answers the destination numbers (COPYUID).
 *
 * @version 1.0.0
 */

function mail_copy_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		$from = MailAppAction::folder($ctx, (string)$input['folder']);
		$to = MailAppAction::folder($ctx, (string)$input['to']);
		$uids = MailAppAction::uidSet($ctx, $from, (string)$input['uids']);
		$placed = MailAppMutations::place($ctx, $from, $uids, $to, false);
		return LogicResult::render(array(
			'folder'           => $from['key'],
			'to'               => $to['key'],
			'uid_map'          => MailAppAction::pairs($placed['map']),
			'dest_uidvalidity' => $placed['uidvalidity'],
		));
	});
}

function mail_copy_logic_descriptor() {
	return MailAppAction::descriptor('Copy numbered messages to another mail-app folder', array(
		'folder' => array('type' => 'string', 'required' => true, 'label' => 'Folder key', 'max_length' => 64),
		'uids'   => array('type' => 'string', 'required' => true, 'label' => 'UID set', 'max_length' => 65536),
		'to'     => array('type' => 'string', 'required' => true, 'label' => 'Destination folder key', 'max_length' => 64),
	));
}
?>

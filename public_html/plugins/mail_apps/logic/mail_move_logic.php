<?php
/**
 * mail_apps/mail_move — move numbered messages to another folder (IMAP
 * MOVE). Each move is the mailbox action it stands for (archive, trash, spam
 * verdict, label membership — MailAppMutations). Answers the numbers the
 * messages have in the destination (COPYUID).
 *
 * @version 1.0.0
 */

function mail_move_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		$from = MailAppAction::folder($ctx, (string)$input['folder']);
		$to = MailAppAction::folder($ctx, (string)$input['to']);
		$uids = MailAppAction::uidSet($ctx, $from, (string)$input['uids']);
		$placed = MailAppMutations::place($ctx, $from, $uids, $to, true);
		return LogicResult::render(array(
			'folder'           => $from['key'],
			'to'               => $to['key'],
			'uid_map'          => MailAppAction::pairs($placed['map']),
			'dest_uidvalidity' => $placed['uidvalidity'],
		));
	});
}

function mail_move_logic_descriptor() {
	return MailAppAction::descriptor('Move numbered messages to another mail-app folder', array(
		'folder' => array('type' => 'string', 'required' => true, 'label' => 'Folder key', 'max_length' => 64),
		'uids'   => array('type' => 'string', 'required' => true, 'label' => 'UID set', 'max_length' => 65536),
		'to'     => array('type' => 'string', 'required' => true, 'label' => 'Destination folder key', 'max_length' => 64),
	));
}
?>

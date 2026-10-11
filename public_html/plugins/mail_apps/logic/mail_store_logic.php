<?php
/**
 * mail_apps/mail_store — change flags on numbered messages (IMAP STORE):
 * op add, remove or set, over a UID set. \Seen and \Flagged go to mailbox
 * (markRead / setStarred, the web reader's own calls), \Answered and
 * $Forwarded to this plugin's row, \Deleted marks the number for EXPUNGE.
 * Flags Joinery does not keep (a program's own tags) are not stored; the
 * program keeps them locally (they are absent from PERMANENTFLAGS).
 *
 * Answers the messages' flags and change numbers after the change.
 *
 * @version 1.0.0
 */

function mail_store_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		$folder = MailAppAction::folder($ctx, (string)$input['folder']);
		$uids = MailAppAction::uidSet($ctx, $folder, (string)$input['uids']);
		MailAppMutations::store($ctx, $folder, $uids, (string)$input['op'], (array)($input['flags'] ?? array()));
		$state = MailAppFolders::reconcile($ctx, $folder);
		$wanted = array_flip($uids);
		$messages = array();
		foreach (MailAppFolders::rows($ctx, $folder)['messages'] as $row) {
			if (isset($wanted[$row['uid']])) {
				$messages[] = array('uid' => $row['uid'], 'flags' => $row['flags'], 'modseq' => $row['modseq']);
			}
		}
		return LogicResult::render(array(
			'folder'         => $folder['key'],
			'messages'       => $messages,
			'highest_modseq' => $state['highest_modseq'],
			'expunged'       => $state['expunged'],
		));
	});
}

function mail_store_logic_descriptor() {
	return MailAppAction::descriptor('Change flags on numbered messages in a mail-app folder', array(
		'folder' => array('type' => 'string', 'required' => true, 'label' => 'Folder key', 'max_length' => 64),
		'uids'   => array('type' => 'string', 'required' => true, 'label' => 'UID set', 'max_length' => 65536),
		'op'     => array('type' => 'string', 'required' => true, 'label' => 'Operation', 'enum' => array('add', 'remove', 'set')),
		'flags'  => array('type' => 'array', 'required' => false, 'label' => 'Flags', 'max_items' => 64,
			'items' => array('type' => 'string', 'max_length' => 64)),
	));
}
?>

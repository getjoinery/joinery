<?php
/**
 * mail_apps/mail_expunge — carry out the \Deleted messages in a folder (IMAP
 * EXPUNGE), or only those in a UID set (UID EXPUNGE). In Trash that deletes
 * for good; in a label it takes the label off; in Drafts it deletes the
 * draft; anywhere else it moves the message to Trash. Answers the numbers
 * expunged.
 *
 * @version 1.0.0
 */

function mail_expunge_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		$folder = MailAppAction::folder($ctx, (string)$input['folder']);
		$uids = isset($input['uids']) && (string)$input['uids'] !== ''
			? MailAppAction::uidSet($ctx, $folder, (string)$input['uids']) : null;
		$expunged = MailAppMutations::expunge($ctx, $folder, $uids);
		$state = MailAppFolders::reconcile($ctx, $folder);
		return LogicResult::render(array(
			'folder'         => $folder['key'],
			'expunged'       => array_values(array_unique(array_merge($expunged, $state['expunged']))),
			'highest_modseq' => $state['highest_modseq'],
		));
	});
}

function mail_expunge_logic_descriptor() {
	return MailAppAction::descriptor('Carry out the deleted messages in a mail-app folder', array(
		'folder' => array('type' => 'string', 'required' => true, 'label' => 'Folder key', 'max_length' => 64),
		'uids'   => array('type' => 'string', 'required' => false, 'label' => 'UID set', 'max_length' => 65536),
	));
}
?>

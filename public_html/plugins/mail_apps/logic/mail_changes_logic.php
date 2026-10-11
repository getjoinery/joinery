<?php
/**
 * mail_apps/mail_changes — the gateway's cheap poll while a program waits
 * for new mail (IMAP IDLE): reconcile the selected folder and answer whether
 * anything changed since the change number the gateway last saw, and whether
 * the mailbox is locked. Counts as activity for a borrowed unlock
 * (decision 2), which is what keeps a Private mailbox unlocked while a mail
 * program is checking it, up to the absolute cap.
 *
 * @version 1.0.0
 */

function mail_changes_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		$folder = MailAppAction::folder($ctx, (string)$input['folder']);
		$state = MailAppFolders::reconcile($ctx, $folder);
		$ctx->countCheckAsActivity();
		$known = intval($input['known_modseq'] ?? 0);
		return LogicResult::render(array(
			'folder'         => $folder['key'],
			'uidvalidity'    => $state['uidvalidity'],
			'uidnext'        => $state['uidnext'],
			'highest_modseq' => $state['highest_modseq'],
			'exists'         => $state['exists'],
			'changed'        => $state['highest_modseq'] !== $known,
			'locked'         => $state['locked'],
		));
	});
}

function mail_changes_logic_descriptor() {
	return MailAppAction::descriptor('Poll one mail-app folder for changes; counts as activity for a borrowed unlock', array(
		'folder'       => array('type' => 'string', 'required' => true, 'label' => 'Folder key', 'max_length' => 64),
		'known_modseq' => array('type' => 'int', 'required' => false, 'label' => 'Last change number seen', 'min' => 0),
	));
}
?>

<?php
/**
 * mail_apps/mail_folders — the mailbox's folders (IMAP LIST), each with its
 * key, name and special use. Numbers and counts come from mail_sync, which
 * reconciles; listing never does.
 *
 * @version 1.0.0
 */

function mail_folders_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		$folders = array();
		foreach (MailAppFolders::listFor($ctx) as $folder) {
			$folders[] = array('key' => $folder['key'], 'name' => $folder['name'], 'special_use' => $folder['special_use']);
		}
		return LogicResult::render(array('folders' => $folders));
	});
}

function mail_folders_logic_descriptor() {
	return MailAppAction::descriptor('The folders a mail program sees for one mailbox');
}
?>

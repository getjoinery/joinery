<?php
/**
 * mail_apps/mail_login — a mail program signs in to one mailbox.
 *
 * The gateway calls this when a program authenticates (IMAP LOGIN /
 * AUTHENTICATE, SMTP AUTH), with the credential the program presented relayed
 * unchanged (ApiAuth resolves it) and the username it gave as `mailbox`.
 * Answers the mailbox, its level, whether it is locked now, where to unlock,
 * and its folders. A Fortress mailbox is refused with the sentence the Mail
 * apps page shows.
 *
 * @version 1.0.0
 */

function mail_login_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		$folders = array();
		foreach (MailAppFolders::listFor($ctx) as $folder) {
			$folders[] = array('key' => $folder['key'], 'name' => $folder['name'], 'special_use' => $folder['special_use']);
		}
		$ctx->countCheckAsActivity();
		return LogicResult::render(array(
			'mailbox'    => $ctx->address,
			'level'      => $ctx->levelName(),
			'locked'     => !$ctx->windowOpen(),
			'unlock_url' => $ctx->unlockUrl(),
			'folders'    => $folders,
		));
	});
}

function mail_login_logic_descriptor() {
	return MailAppAction::descriptor('A mail program signs in to one mailbox: its level, lock state and folders');
}
?>

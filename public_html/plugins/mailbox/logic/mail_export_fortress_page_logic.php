<?php
/**
 * API action: mailbox/mail_export_fortress_page — the next rows of a Fortress
 * mailbox export, for the owner's browser to open and write.
 *
 * POST /api/v1/action/mailbox/mail_export_fortress_page (browser session).
 * Params: run_id (from mailbox/mail_export_start), folder (the index into the
 * run's folders, from 0), after (the last message id handed over, 0 to start).
 * Returns MailExport::fortressPage(): the run's folders, and up to a page of
 * this folder's messages — ciphertext as the reader's thread payload carries
 * it, or the bytes of mail the server can still read — with each one's
 * X-Gmail-Labels value.
 *
 * Only for the person who asked for the run, while it is fresh: the second
 * factor given to mail_export_start covers the export it started, not a
 * standing licence to page through the mailbox.
 *
 * @version 1.0
 */

function mail_export_fortress_page_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$uid = intval($session->get_user_id());
	if (!$uid) {
		return LogicResult::error('Sign in required.');
	}
	$run = new MailExportRun(intval($input['run_id'] ?? 0), TRUE);
	if (!$run->key || intval($run->get('mxr_usr_user_id')) !== $uid
			|| (string)$run->get('mxr_level') !== MailExportRun::LEVEL_FORTRESS
			|| (string)$run->get('mxr_state') !== MailExportRun::STATE_BROWSER) {
		return LogicResult::error('That download is not yours, or is already gone.');
	}
	$age = time() - (strtotime((string)$run->get('mxr_create_time') . ' UTC') ?: 0);
	if ($age > MailExport::FORTRESS_HOURS * 3600) {
		return LogicResult::error('This download was started too long ago. Start it again.');
	}
	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->canAccess(intval($run->get('mxr_iea_inbound_email_alias_id')))) {
		return LogicResult::error('Not authorized.');
	}
	return LogicResult::render(MailExport::fortressPage($run, $viewer,
		max(0, intval($input['folder'] ?? 0)), max(0, intval($input['after'] ?? 0))));
}

function mail_export_fortress_page_logic_descriptor(): array {
	return array(
		'description' => 'The next rows of a Fortress mailbox export, as ciphertext for the owner\'s browser',
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'input' => array(
			'run_id' => array('type' => 'int', 'required' => true, 'label' => 'Export run ID'),
			'folder' => array('type' => 'int', 'required' => false, 'label' => 'Folder index'),
			'after'  => array('type' => 'int', 'required' => false, 'label' => 'Last message id handed over'),
		),
	);
}
?>

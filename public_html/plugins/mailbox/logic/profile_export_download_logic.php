<?php
/**
 * Logic for a mailbox download (/profile/mailbox/export_download?run_id=N): the
 * zip of a ready export, put together as it streams (MailExport::streamZip).
 *
 * Only the person who asked for the export may take it. A Private export's
 * pieces are sealed to their vault, so it downloads only while their window
 * is open; the key is opened here, before the session is let go. On success
 * MailExport::send() streams the zip and exit()s, so anything that returns
 * from here is a refusal the view shows.
 *
 * @version 1.0
 */

function profile_export_download_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$page_url = '/profile/mailbox/export';
	if (!$session->get_user_id()) {
		return LogicResult::redirect('/login?return=' . urlencode($page_url));
	}
	$fail = function (string $message) use ($page_url) {
		return LogicResult::render(array('error' => $message, 'page_url' => $page_url));
	};

	$run = new MailExportRun(intval($input['run_id'] ?? 0), TRUE);
	if (!$run->key || intval($run->get('mxr_usr_user_id')) !== intval($session->get_user_id())) {
		return $fail('That download is not yours, or it has been deleted.');
	}
	if ((string)$run->get('mxr_state') !== MailExportRun::STATE_READY) {
		return $fail('That download is not ready yet.');
	}

	$fk = null;
	if ((string)$run->get('mxr_level') === MailExportRun::LEVEL_PRIVATE) {
		try {
			$fk = $run->exportKey();
		} catch (VaultLockedException $e) {
			return $fail('This download is sealed to your vault. Unlock it on the downloads page, then try again.');
		}
	}

	MailExport::send($run, $fk);   // emits and exits
}
?>

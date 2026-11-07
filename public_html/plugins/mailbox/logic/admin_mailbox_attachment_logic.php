<?php
/**
 * Logic for the per-attachment download endpoint.
 *
 * Given an attachment id: load the manifest row + its message, authorize, retrieve
 * the bytes, and stream them pass-through with the original filename.
 *
 * Retrieval dispatches on where the bytes live (specs/implemented/inbound_email_attachment_storage.md):
 *   - file-backed (ima_fil_file_id set, push mail): the bytes are a private File.
 *     Authorize with File::is_viewable() — owner-or-admin via fil_private, NOT the
 *     mailbox grant — and stream the File's bytes. Attachment access is gated
 *     independently of mailbox read access (and can be coarser for a shared mailbox).
 *   - section-pointer / IMAP rows (no ima_fil_file_id): enforce the SAME mailbox-grant
 *     check the reader uses, then dispatch on iem_raw_storage_driver —
 *       · 'remote': fetch the single MIME part on demand via ImapIngestor
 *         (Message-ID fallback if UIDVALIDITY changed);
 *       · 'inline'/'local'/'cloud': MIME-parse the stored raw and extract the one
 *         part via InboundEmailMessage::getRawMimePart().
 *
 * On success this streams and exit()s. On any failure it returns a LogicResult so
 * the view can render an honest message.
 *
 * Retrieval + streaming are shared with the member endpoint
 * (includes/attachment_retrieval.php); only the authorization posture here
 * is admin-specific.
 *
 * @version 1.6 - the file-backed branch asks the mailbox's rule too (canReadMessage), so a level-6 staff member or an
 *   unconfirmed level-10 cannot take an attachment the reader would refuse them (reviewer2 B3); a step-up pause redirects
 * @version 1.5 - an all-access viewer's download from a message they hold no grant on is written down (MailboxReadAudit);
 *   the download counts against the person's read budget (MailReadBudget)
 * @version 1.4 - a Fortress attachment streams as stored ciphertext, unnamed
 * @version 1.3
 */

function admin_mailbox_attachment_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachments_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_messages_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	$reader_url = '/plugins/mailbox/admin/admin_mailbox_reader';

	$id = intval($input['ima_inbound_message_attachment_id'] ?? 0);
	if ($id <= 0) {
		return _attachment_error($session, $settings, 'No attachment specified.', $reader_url);
	}

	$att = new InboundMessageAttachment($id, TRUE);
	if (!$att->key) {
		return _attachment_error($session, $settings, 'Attachment not found.', $reader_url);
	}

	$message = new InboundEmailMessage(intval($att->get('ima_iem_inbound_email_message_id')), TRUE);
	if (!$message->key || $message->get('iem_delete_time')) {
		return _attachment_error($session, $settings, 'The message for this attachment no longer exists.', $reader_url);
	}

	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/attachment_retrieval.php'));

	$fil_id = intval($att->get('ima_fil_file_id'));

	if ($fil_id > 0) {
		// File-backed (push mail, lean record): authorize with File::is_viewable()
		// — owner-or-admin via fil_private, the same algorithm serve.php's
		// /uploads/* path uses — NOT the mailbox grant. (Mailbox read access
		// governs the reader, enforced separately; the attachment File is gated
		// independently and can be coarser for a shared mailbox — any admin.
		// See specs/implemented/inbound_email_attachment_storage.md.)
		require_once(PathHelper::getIncludePath('data/files_class.php'));
		$file = new File($fil_id, TRUE);
		if (!$file->key || $file->get('fil_delete_time')) {
			return _attachment_error($session, $settings, 'This attachment is no longer available.', $reader_url);
		}
		if (!$file->is_viewable($session)) {
			return _attachment_error($session, $settings, 'You do not have access to this attachment.', $reader_url);
		}
		// And the mailbox's own rule on top (reviewer2 B3): the File's level is
		// the personal class's, which any level-6 staff meets, while an
		// attachment is as private as its message. A level-10 viewer yet to
		// confirm oversight is sent to confirm and comes back here.
		$viewer = MailboxViewer::fromSession($session);
		if (!$viewer->canReadMessage($message)) {
			if ($viewer->oversightState() === MailboxViewer::OVERSIGHT_NEEDS_STEPUP) {
				return LogicResult::redirect('/profile/verify-stepup?return=' . urlencode((string)($_SERVER['REQUEST_URI'] ?? $reader_url)));
			}
			return _attachment_error($session, $settings, 'You do not have access to this mailbox.', $reader_url);
		}
	} else {
		// Section-pointer / IMAP rows: grant check — identical to the reader (an
		// attachment is as private as its message). A NULL-alias (catch-all/
		// unmatched) message is superadmin-only.
		$viewer = MailboxViewer::fromSession($session);
		if (!$viewer->canReadMessage($message)) {
			if ($viewer->oversightState() === MailboxViewer::OVERSIGHT_NEEDS_STEPUP) {
				return LogicResult::redirect('/profile/verify-stepup?return=' . urlencode((string)($_SERVER['REQUEST_URI'] ?? $reader_url)));
			}
			return _attachment_error($session, $settings, 'You do not have access to this mailbox.', $reader_url);
		}
	}
	// Both branches: an all-access viewer taking an attachment from a message
	// they hold no grant on is written down. Here, not in the retrieval helper,
	// which does not authorize and is also called by unattended work.
	MailboxReadAudit::messages($viewer, MailboxReadAudit::SURFACE_ATTACHMENT, array(intval($message->key)));
	$budget = MailReadBudget::messages((int)$session->get_user_id(), array(intval($message->key)));
	if ($budget['paused']) {
		// A page, not an API answer: a pause that asks for a step-up goes there and comes back.
		return MailReadBudget::stepUpRedirect($budget, (string)($_SERVER['REQUEST_URI'] ?? $reader_url))
			?? _attachment_error($session, $settings, MailReadBudget::refusal($budget)->error, $reader_url);
	}

	$result = mailbox_retrieve_attachment_bytes($att, $message);
	if (!$result['ok']) {
		return _attachment_error($session, $settings, $result['error'], $reader_url);
	}

	mailbox_stream_attachment($att, $result['content'], !empty($result['browser_sealed']));
}

function _attachment_error($session, $settings, string $message, string $reader_url): LogicResult {
	return LogicResult::render(array(
		'session' => $session,
		'settings' => $settings,
		'error' => $message,
		'reader_url' => $reader_url,
	));
}
?>

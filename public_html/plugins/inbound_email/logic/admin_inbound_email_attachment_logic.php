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
 * @version 1.3
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_inbound_email_attachment_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_message_attachment_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxViewer.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$reader_url = '/plugins/inbound_email/admin/admin_inbound_email_reader';

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

	require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/attachment_retrieval.php'));

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
	} else {
		// Section-pointer / IMAP rows: grant check — identical to the reader (an
		// attachment is as private as its message). A NULL-alias (catch-all/
		// unmatched) message is superadmin-only.
		$viewer = MailboxViewer::fromSession($session);
		$alias_id = intval($message->get('iem_iea_inbound_email_alias_id'));
		$allowed = $alias_id > 0 ? $viewer->canAccess($alias_id) : $viewer->isAllAccess();
		if (!$allowed) {
			return _attachment_error($session, $settings, 'You do not have access to this mailbox.', $reader_url);
		}
	}

	$result = inbound_email_retrieve_attachment_bytes($att, $message);
	if (!$result['ok']) {
		return _attachment_error($session, $settings, $result['error'], $reader_url);
	}

	inbound_email_stream_attachment($att, $result['content']);
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

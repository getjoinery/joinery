<?php
/**
 * Logic for the per-attachment download endpoint.
 *
 * Given an attachment id: load the manifest row + its message, enforce the SAME
 * mailbox-grant + permission check the reader uses (an attachment is exactly as
 * private as its message), then retrieve the part and stream it pass-through. The
 * bytes are never persisted on the platform.
 *
 * Retrieval dispatches on the message's iem_raw_storage_driver:
 *   - 'remote' (IMAP-backed): fetch the single MIME part on demand via ImapIngestor
 *     (Message-ID fallback if UIDVALIDITY changed).
 *   - 'inline'/'local'/'cloud' (stored raw, push transports): MIME-parse the stored
 *     raw and extract the one part via InboundEmailMessage::getRawMimePart().
 * Dispatch is unified on the driver flag — account_id is no longer a dispatch signal
 * (it is purely the 'remote' locator), so the path is transport-blind.
 *
 * On success this streams and exit()s. On any failure it returns a LogicResult so
 * the view can render an honest message.
 *
 * @version 1.1
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

	// Grant check — identical to the reader: an attachment is as private as its
	// message. A NULL-alias (catch-all/unmatched) message is superadmin-only.
	$viewer = MailboxViewer::fromSession($session);
	$alias_id = intval($message->get('iem_iea_inbound_email_alias_id'));
	$allowed = $alias_id > 0 ? $viewer->canAccess($alias_id) : $viewer->isAllAccess();
	if (!$allowed) {
		return _attachment_error($session, $settings, 'You do not have access to this mailbox.', $reader_url);
	}

	// Retrieve the part by the message's raw-storage driver.
	$driver = (string)$message->get('iem_raw_storage_driver') ?: 'inline';

	if ($driver === 'remote') {
		// IMAP on-demand single-part fetch.
		$account_id = intval($message->get('iem_iia_inbound_imap_account_id'));
		if ($account_id <= 0) {
			return _attachment_error($session, $settings,
				'The source mailbox for this message is no longer available.', $reader_url);
		}

		require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_account_class.php'));
		require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/ImapIngestor.php'));

		$account = new InboundImapAccount($account_id, TRUE);
		if (!$account->key) {
			return _attachment_error($session, $settings,
				'The source IMAP account for this message no longer exists.', $reader_url);
		}

		$ingestor = new ImapIngestor($account);
		$result = $ingestor->fetchPart(
			(string)$att->get('ima_mime_part'),
			intval($message->get('iem_imap_uid')),
			$message->get('iem_imap_uidvalidity') !== null ? intval($message->get('iem_imap_uidvalidity')) : null,
			(string)$message->get('iem_imap_folder'),
			$message->get('iem_message_id_header')
		);
		$ingestor->close();

		if (empty($result['ok'])) {
			return _attachment_error($session, $settings,
				$result['message'] ?? 'This attachment is no longer available in the source mailbox.', $reader_url);
		}
		$content = (string)$result['content'];
	} else {
		// Stored raw (inline / local / cloud): MIME-parse and extract the one part.
		$part = $message->getRawMimePart((string)$att->get('ima_mime_part'));
		if ($part === null) {
			return _attachment_error($session, $settings,
				'This attachment is no longer available.', $reader_url);
		}
		$content = (string)$part['content'];
	}

	// Stream pass-through. Sanitize the filename for the header (strip CR/LF and
	// path separators) to prevent header injection; serve with nosniff +
	// attachment disposition so attacker-controlled bytes are never rendered inline.
	$filename = _attachment_safe_filename((string)$att->get('ima_filename'));
	$content_type = (string)$att->get('ima_content_type') ?: 'application/octet-stream';
	// Never let a text/html attachment be treated as renderable in our origin.
	if (stripos($content_type, 'text/html') !== false) {
		$content_type = 'application/octet-stream';
	}

	header('Content-Type: ' . $content_type);
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('X-Content-Type-Options: nosniff');
	header('Content-Length: ' . strlen($content));
	echo $content;
	exit();
}

/** Strip CR/LF and path separators; fall back to a safe default. */
function _attachment_safe_filename(string $name): string {
	$name = str_replace(array("\r", "\n", '"', '\\', '/'), '', $name);
	$name = trim($name);
	if ($name === '') {
		$name = 'attachment';
	}
	return substr($name, 0, 255);
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

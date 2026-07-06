<?php
/**
 * Shared attachment retrieval + streaming for the per-attachment download
 * endpoints (admin and member mounts).
 *
 * Retrieval dispatches on where the bytes live
 * (specs/implemented/inbound_email_attachment_storage.md):
 *   - file-backed (ima_fil_file_id set): the bytes are a private File;
 *   - 'remote' rows: single MIME part fetched on demand via ImapIngestor
 *     (Message-ID fallback if UIDVALIDITY changed);
 *   - 'inline'/'local'/'cloud' rows: MIME-parse the stored raw and extract
 *     the one part via InboundEmailMessage::getRawMimePart().
 *
 * Retrieval is authorization-free by design — each endpoint gates FIRST
 * (the admin endpoint per its backing rules, the member endpoint via
 * MailboxViewer scope) and only then retrieves.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

/**
 * Fetch the raw bytes for an attachment row. Does NOT authorize.
 *
 * @return array ['ok' => bool, 'content' => string|null, 'error' => string|null]
 */
function mailbox_retrieve_attachment_bytes(InboundMessageAttachment $att, InboundEmailMessage $message): array {
	$fail = function (string $error) {
		return array('ok' => false, 'content' => null, 'error' => $error);
	};

	$fil_id = intval($att->get('ima_fil_file_id'));

	if ($fil_id > 0) {
		// File-backed (push mail, lean record): the bytes are a private File.
		require_once(PathHelper::getIncludePath('data/files_class.php'));
		$file = new File($fil_id, TRUE);
		if (!$file->key || $file->get('fil_delete_time')) {
			return $fail('This attachment is no longer available.');
		}
		$content = $file->read_bytes('original');
		if ($content === null) {
			return $fail('This attachment is no longer available.');
		}
		return array('ok' => true, 'content' => $content, 'error' => null);
	}

	// Section-pointer / IMAP rows: retrieve by the message's raw-storage driver.
	$driver = (string)$message->get('iem_raw_storage_driver') ?: 'inline';

	if ($driver === 'remote') {
		// IMAP on-demand single-part fetch.
		$account_id = intval($message->get('iem_iia_inbound_imap_account_id'));
		if ($account_id <= 0) {
			return $fail('The source mailbox for this message is no longer available.');
		}

		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));

		$account = new InboundImapAccount($account_id, TRUE);
		if (!$account->key) {
			return $fail('The source IMAP account for this message no longer exists.');
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
			return $fail($result['message'] ?? 'This attachment is no longer available in the source mailbox.');
		}
		return array('ok' => true, 'content' => (string)$result['content'], 'error' => null);
	}

	// Stored raw (inline / local / cloud): MIME-parse and extract the one part.
	$part = $message->getRawMimePart((string)$att->get('ima_mime_part'));
	if ($part === null) {
		return $fail('This attachment is no longer available.');
	}
	return array('ok' => true, 'content' => (string)$part['content'], 'error' => null);
}

/** Strip CR/LF and path separators; fall back to a safe default. */
function mailbox_attachment_safe_filename(string $name): string {
	$name = str_replace(array("\r", "\n", '"', '\\', '/'), '', $name);
	$name = trim($name);
	if ($name === '') {
		$name = 'attachment';
	}
	return substr($name, 0, 255);
}

/**
 * Stream attachment bytes pass-through and exit(). Sanitizes the filename
 * for the header (header-injection guard) and serves with nosniff +
 * attachment disposition so attacker-controlled bytes are never rendered
 * inline; text/html is downgraded so it can never render in our origin.
 */
function mailbox_stream_attachment(InboundMessageAttachment $att, string $content): void {
	$filename = mailbox_attachment_safe_filename((string)$att->get('ima_filename'));
	$content_type = (string)$att->get('ima_content_type') ?: 'application/octet-stream';
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

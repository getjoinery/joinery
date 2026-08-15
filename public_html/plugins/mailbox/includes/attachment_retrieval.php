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
 * @version 1.3.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

/**
 * Fetch the raw bytes for an attachment row. Does NOT authorize.
 *
 * A sealed attachment on a locked vault fails with 'locked' => true as well as
 * a message, because the two consumers want different things from that fact:
 * the download endpoints render the sentence, and the reader's JSON endpoints
 * answer {locked:true} so the browser can run the one-tap unlock ceremony and
 * retry instead of showing an error.
 *
 * @return array ['ok' => bool, 'content' => string|null, 'error' => string|null, 'locked' => bool]
 */
function mailbox_retrieve_attachment_bytes(InboundMessageAttachment $att, InboundEmailMessage $message): array {
	$fail = function (string $error, bool $locked = false) {
		return array('ok' => false, 'content' => null, 'error' => $error, 'locked' => $locked);
	};

	$fil_id = intval($att->get('ima_fil_file_id'));

	if ($fil_id > 0) {
		// File-backed (push mail, lean record): the bytes are a private File.
		require_once(PathHelper::getIncludePath('data/files_class.php'));
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException
		$file = new File($fil_id, TRUE);
		if (!$file->key || $file->get('fil_delete_time')) {
			return $fail('This attachment is no longer available.');
		}
		$content = $file->read_bytes('original');
		if ($content === null) {
			return $fail('This attachment is no longer available.');
		}
		// read_bytes() returns raw on-disk bytes, bypassing File's decrypt hook
		// (which only fires through serve_from_path()) — a sealed attachment
		// must be opened explicitly before streaming, in either sealed shape.
		try {
			$content = InboundEmailMessage::openSealedAttachment($message, $att, $content, $file);
		} catch (VaultLockedException $e) {
			return $fail('Unlock your vault to download this attachment.', true);
		}
		return array('ok' => true, 'content' => $content, 'error' => null, 'locked' => false);
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
		return array('ok' => true, 'content' => (string)$result['content'], 'error' => null, 'locked' => false);
	}

	// Stored raw (inline / local / cloud): MIME-parse and extract the one part.
	// A sealed stored raw (iem_raw_sealed) decrypts inside getRawMessage() and
	// raises VaultLockedException when the owner's window is closed.
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException
	try {
		$part = $message->getRawMimePart((string)$att->get('ima_mime_part'));
	} catch (VaultLockedException $e) {
		return $fail('Unlock your vault to download this attachment.', true);
	}
	if ($part === null) {
		return $fail('This attachment is no longer available.');
	}
	return array('ok' => true, 'content' => (string)$part['content'], 'error' => null, 'locked' => false);
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

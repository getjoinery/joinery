<?php
/**
 * MailboxSender - compose + send a Reply / Reply-All / Forward AS a mailbox, then
 * store the sent copy as an outbound row so the reader shows the full dialog.
 *
 * Every send funnels through the one outbound pipeline: the mailbox is resolved to
 * a transport (resolveOutboundTransport) and the message is sent via
 * EmailSender::send($msg, false, $transport). This class owns the reader-feature
 * concerns ONLY — building the message (quote/forward body, threading headers,
 * re-attached originals + uploads), the scope check, and persisting the local
 * outbound row. It never re-decides transport.
 *
 * Scope: the source (replied-to / forwarded) message must belong to a mailbox the
 * viewer may access; that mailbox is also the sending identity. Unmatched
 * (NULL-alias) mail has no mailbox to send AS and is rejected.
 *
 * Failure is synchronous (queue_on_failure = false): a failed send stores NO row
 * and returns an error the reader shows inline; the draft stays in the compose
 * panel for "fix and Send again" (spec §10).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/OutboundTransport.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_message_attachment_class.php'));

/** A user-facing send failure (bad input, transport unavailable, original gone). */
class MailboxSenderException extends Exception {}

class MailboxSender {

	const MODE_REPLY      = 'reply';
	const MODE_REPLY_ALL  = 'reply_all';
	const MODE_FORWARD    = 'forward';

	/** Upload caps (server-side enforcement, §10). */
	const MAX_UPLOAD_FILES = 10;
	const MAX_UPLOAD_BYTES = 10485760;   // 10 MB per file
	const MAX_TOTAL_BYTES  = 26214400;   // 25 MB total (incl. re-attached originals)

	/** @var MailboxViewer */
	private $viewer;

	public function __construct(MailboxViewer $viewer) {
		$this->viewer = $viewer;
	}

	private function db() {
		return DbConnector::get_instance()->get_db_link();
	}

	/**
	 * Compose and send. Returns ['ok'=>true, 'outbound_id'=>int] on success.
	 * Throws MailboxSenderException with a user-facing message on any failure
	 * (validation, transport, original-no-longer-available, send failure).
	 *
	 * @param array $params  mode, source_id, to, cc, subject, body
	 * @param array $files   normalized upload entries (name,type,tmp_name,size,error)
	 */
	public function send(array $params, array $files = array()): array {
		$mode = (string)($params['mode'] ?? '');
		if (!in_array($mode, array(self::MODE_REPLY, self::MODE_REPLY_ALL, self::MODE_FORWARD), true)) {
			throw new MailboxSenderException('Unknown compose action.');
		}

		$source = $this->loadSourceInScope(intval($params['source_id'] ?? 0));
		$alias_id = intval($source->get('iem_iea_inbound_email_alias_id'));
		$alias = new InboundEmailAlias($alias_id, TRUE);
		if (!$alias->key || $alias->get('iea_delete_time')) {
			throw new MailboxSenderException('The mailbox for this conversation no longer exists.');
		}
		$alias_address = strtolower($alias->get_full_address());

		// Resolve "send AS this mailbox" — connected IMAP account or hosted alias.
		$account = $this->connectedAccountFor($alias_id);
		$transport = resolveOutboundTransport($account ?: $alias_address);
		if ($transport->error) {
			throw new MailboxSenderException($transport->error);
		}
		$from_address = $transport->fromAddress ?: $alias_address;

		// Recipients: trust the (admin-entered) To/Cc; for reply-all the client
		// pre-populates them, but the server is the authority on what is sent.
		$to = $this->parseAddressList($params['to'] ?? '');
		$cc = ($mode === self::MODE_FORWARD) ? array() : $this->parseAddressList($params['cc'] ?? '');
		if (empty($to)) {
			throw new MailboxSenderException('Add at least one recipient.');
		}

		$subject = $this->normalizeSubject((string)($params['subject'] ?? ''), $mode, (string)$source->get('iem_subject'));
		$message_id = $this->generateMessageId($from_address);

		$email = new EmailMessage();
		$email->from($from_address, $alias->get('iea_description') ?: null);
		foreach ($to as $addr) { $email->to($addr); }
		foreach ($cc as $addr) { $email->cc($addr); }
		$email->subject($subject);
		$email->html($this->buildBody($mode, (string)($params['body'] ?? ''), $source));
		$email->messageId($message_id);

		// Threading: replies are In-Reply-To/References the original; a forward
		// starts a fresh external thread (no reply headers) but still files into
		// this conversation locally (§5).
		if ($mode !== self::MODE_FORWARD) {
			$this->applyThreadingHeaders($email, $source);
		}

		// Forward re-attaches the original's attachments; uploads ride along in
		// every mode.
		$total = 0;
		if ($mode === self::MODE_FORWARD) {
			$total += $this->attachOriginal($email, $source);
		}
		$this->attachUploads($email, $files, $total);

		// One pipeline, synchronous (no retry-queue): success/failure is shown now.
		try {
			$sender = new EmailSender();
			$ok = $sender->send($email, false, $transport->transport);
		} catch (Throwable $e) {
			error_log('MailboxSender: send threw for alias ' . $alias_id . ': ' . $e->getMessage());
			throw new MailboxSenderException('The message could not be sent: ' . $e->getMessage());
		}
		if (!$ok) {
			throw new MailboxSenderException('The message could not be sent. Check the mailbox connection and try again.');
		}

		// §9 Sent / compose interop, only when the feed opted into compose/Sent sync.
		// Dedup is Message-ID only: the source Sent copy (provider-filed or APPENDed)
		// reconciles to one row on the next Sent ingest by Message-ID.
		if ($account && $account->showCompose()) {
			if (!$transport->filesSent) {
				// The provider's SMTP does not file Sent (generic / self-hosted): APPEND
				// the exact MIME ourselves, carrying the same Message-ID so the ingest
				// dedups. Best-effort — a failed APPEND never fails the send.
				$this->appendSentCopy($account, $email);
			} elseif ($account->smtpRewritesMessageId()) {
				// Gmail rewrites the Message-ID on send, so a stored row could never
				// match the filed copy. Store no local row; the message appears on the
				// next Sent ingest (one poll-interval latency).
				return array('ok' => true, 'outbound_id' => 0, 'pending_sent_ingest' => true);
			}
			// else (files Sent, preserves Message-ID): store the local row now; the
			// filed copy dedups by Message-ID on ingest.
		}

		$outbound_id = $this->storeOutboundRow($source, $alias, $mode, $from_address,
			array_merge($to, $cc), $subject, $email, $message_id);

		return array('ok' => true, 'outbound_id' => $outbound_id);
	}

	// ── source + identity ──────────────────────────────────────────────────

	/** Load the replied-to/forwarded message, enforcing the viewer's scope. */
	private function loadSourceInScope(int $source_id): InboundEmailMessage {
		if ($source_id <= 0) {
			throw new MailboxSenderException('No source message specified.');
		}
		$source = new InboundEmailMessage($source_id, TRUE);
		if (!$source->key || $source->get('iem_delete_time')) {
			throw new MailboxSenderException('The message you are replying to no longer exists.');
		}
		$alias_id = intval($source->get('iem_iea_inbound_email_alias_id'));
		if ($alias_id <= 0) {
			throw new MailboxSenderException('This message belongs to no mailbox, so it cannot be replied to.');
		}
		if (!$this->viewer->canAccess($alias_id)) {
			throw new MailboxSenderException('You do not have access to this mailbox.');
		}
		return $source;
	}

	/** The enabled connected IMAP account bound to this alias, or null (hosted). */
	private function connectedAccountFor(int $alias_id): ?InboundImapAccount {
		$accounts = new MultiInboundImapAccount(array(
			'alias_id' => $alias_id,
			'enabled'  => true,
			'deleted'  => false,
		));
		$accounts->load();
		return count($accounts) ? $accounts->get(0) : null;
	}

	// ── message building ───────────────────────────────────────────────────

	/** Add Re:/Fwd: only when the (possibly user-edited) subject lacks it. */
	private function normalizeSubject(string $subject, string $mode, string $original): string {
		$subject = trim($subject);
		if ($subject === '') {
			$subject = trim($original);
		}
		if ($mode === self::MODE_FORWARD) {
			return preg_match('/^\s*(fwd?|fw)\s*:/i', $subject) ? $subject : 'Fwd: ' . $subject;
		}
		return preg_match('/^\s*re\s*:/i', $subject) ? $subject : 'Re: ' . $subject;
	}

	/** Build the outgoing HTML body: the user's note + a quoted/forwarded original. */
	private function buildBody(string $mode, string $userText, InboundEmailMessage $source): string {
		$userHtml = $this->textToHtml($userText);
		$origHtml = trim((string)$source->get('iem_body_html'));
		$origPlain = trim((string)$source->get('iem_body_plain'));
		$quoted = $origHtml !== '' ? $origHtml : ($origPlain !== '' ? $this->textToHtml($origPlain) : '');

		$sender = htmlspecialchars((string)$source->get('iem_sender'));
		$date = htmlspecialchars($this->displayDate((string)$source->get('iem_received_time')));

		if ($mode === self::MODE_FORWARD) {
			$subject = htmlspecialchars((string)$source->get('iem_subject'));
			$to = htmlspecialchars((string)$source->get('iem_recipient'));
			return '<div>' . $userHtml . '</div><br>'
				. '<div class="gmail_quote">---------- Forwarded message ----------<br>'
				. 'From: ' . $sender . '<br>Date: ' . $date . '<br>'
				. 'Subject: ' . $subject . '<br>To: ' . $to . '<br><br>'
				. $quoted . '</div>';
		}

		return '<div>' . $userHtml . '</div><br>'
			. '<div class="gmail_quote">On ' . $date . ', ' . $sender . ' wrote:<br>'
			. '<blockquote style="margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex">'
			. $quoted . '</blockquote></div>';
	}

	private function textToHtml(string $text): string {
		return nl2br(htmlspecialchars($text));
	}

	/** Format a stored UTC time in the current user's timezone for the quote line. */
	private function displayDate(string $utc): string {
		if ($utc === '') {
			return '';
		}
		$tz = 'UTC';
		try {
			$tz = SessionControl::get_instance()->get_timezone() ?: 'UTC';
		} catch (Throwable $e) { /* fall back to UTC */ }
		$out = LibraryFunctions::convert_time($utc, 'UTC', $tz, 'M j, Y g:i A T');
		return $out ?: $utc;
	}

	/**
	 * Set In-Reply-To and References from the replied-to message so the recipient's
	 * client threads the reply. References = the conversation root (when stored as a
	 * real Message-ID) followed by the replied-to Message-ID. Wire-only — nothing is
	 * persisted (§5).
	 */
	private function applyThreadingHeaders(EmailMessage $email, InboundEmailMessage $source): void {
		$parent = trim((string)$source->get('iem_message_id_header'));
		if ($parent === '') {
			return;
		}
		$email->header('In-Reply-To', $parent);

		$refs = array();
		$root = trim((string)$source->get('iem_thread_key'));
		if ($root !== '' && $root !== $parent && strncmp($root, 'm:', 2) !== 0) {
			$refs[] = $root;
		}
		$refs[] = $parent;
		$email->header('References', implode(' ', $refs));
	}

	private function generateMessageId(string $fromAddress): string {
		$domain = 'localhost';
		$at = strrpos($fromAddress, '@');
		if ($at !== false) {
			$domain = substr($fromAddress, $at + 1) ?: 'localhost';
		}
		return '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
	}

	// ── attachments ────────────────────────────────────────────────────────

	/**
	 * Re-attach the forwarded original's attachments. Returns the total bytes added.
	 * IMAP-source: load the ima_ manifest and fetchPart() each (no MIME walk).
	 * Hosted: parse iem_raw_message with Horde_Mime. A reference-backed original no
	 * longer in the source mailbox fails the forward (§10) rather than sending empty.
	 */
	private function attachOriginal(EmailMessage $email, InboundEmailMessage $source): int {
		$account_id = intval($source->get('iem_iia_inbound_imap_account_id'));
		if ($account_id > 0) {
			return $this->attachFromImap($email, $source, $account_id);
		}
		$raw = (string)$source->get('iem_raw_message');
		if (trim($raw) !== '') {
			return $this->attachFromRaw($email, $raw);
		}
		return 0; // nothing stored to re-attach
	}

	private function attachFromImap(EmailMessage $email, InboundEmailMessage $source, int $account_id): int {
		require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/ImapIngestor.php'));

		$account = new InboundImapAccount($account_id, TRUE);
		if (!$account->key) {
			throw new MailboxSenderException('The source mailbox for this message no longer exists, so it cannot be forwarded.');
		}

		$manifest = new MultiInboundMessageAttachment(array(
			'message_id' => intval($source->key),
			'is_inline'  => false,
		));
		$manifest->load();
		if (!count($manifest)) {
			return 0; // no attachments to carry
		}

		$ingestor = new ImapIngestor($account);
		$uid = intval($source->get('iem_imap_uid'));
		$uidvalidity = $source->get('iem_imap_uidvalidity') !== null ? intval($source->get('iem_imap_uidvalidity')) : null;
		$folder = (string)$source->get('iem_imap_folder');
		$mid = $source->get('iem_message_id_header');

		$total = 0;
		foreach ($manifest as $att) {
			$res = $ingestor->fetchPart((string)$att->get('ima_mime_part'), $uid, $uidvalidity, $folder, $mid);
			if (empty($res['ok'])) {
				$ingestor->close();
				throw new MailboxSenderException(
					$res['message'] ?? 'The original message is no longer available in the source mailbox.');
			}
			$bytes = (string)$res['content'];
			$total += strlen($bytes);
			if ($total > self::MAX_TOTAL_BYTES) {
				$ingestor->close();
				throw new MailboxSenderException('The forwarded attachments exceed the size limit.');
			}
			$email->attachData($bytes,
				$att->get('ima_filename') ?: 'attachment',
				(string)$att->get('ima_content_type') ?: 'application/octet-stream');
		}
		$ingestor->close();
		return $total;
	}

	private function attachFromRaw(EmailMessage $email, string $raw): int {
		require_once(PathHelper::getComposerAutoloadPath());

		try {
			$part = Horde_Mime_Part::parseMessage($raw);
		} catch (Throwable $e) {
			error_log('MailboxSender: raw MIME parse failed: ' . $e->getMessage());
			return 0;
		}

		$total = 0;
		foreach ($part->partIterator() as $p) {
			if ($p->getPrimaryType() === 'multipart') {
				continue;
			}
			$type = strtolower((string)$p->getType());
			$name = $p->getName();
			$disp = $p->getDisposition();
			$isBodyText = ($type === 'text/plain' || $type === 'text/html')
				&& $disp !== 'attachment' && ($name === null || $name === '');
			if ($isBodyText) {
				continue;
			}
			$bytes = (string)$p->getContents();
			if ($bytes === '') {
				continue;
			}
			$total += strlen($bytes);
			if ($total > self::MAX_TOTAL_BYTES) {
				throw new MailboxSenderException('The forwarded attachments exceed the size limit.');
			}
			$email->attachData($bytes, $name ?: 'attachment', $type ?: 'application/octet-stream');
		}
		return $total;
	}

	/** Attach validated uploads. $runningTotal carries any forwarded-original bytes. */
	private function attachUploads(EmailMessage $email, array $files, int $runningTotal): void {
		$count = 0;
		foreach ($files as $f) {
			if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
				continue;
			}
			if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
				throw new MailboxSenderException('An attachment failed to upload.');
			}
			$tmp = (string)($f['tmp_name'] ?? '');
			if ($tmp === '' || !is_uploaded_file($tmp)) {
				throw new MailboxSenderException('An attachment could not be read.');
			}
			$size = intval($f['size'] ?? 0);
			if ($size > self::MAX_UPLOAD_BYTES) {
				throw new MailboxSenderException('An attachment exceeds the ' . (self::MAX_UPLOAD_BYTES / 1048576) . ' MB per-file limit.');
			}
			if (++$count > self::MAX_UPLOAD_FILES) {
				throw new MailboxSenderException('Too many attachments (max ' . self::MAX_UPLOAD_FILES . ').');
			}
			$runningTotal += $size;
			if ($runningTotal > self::MAX_TOTAL_BYTES) {
				throw new MailboxSenderException('The attachments exceed the total size limit.');
			}
			$bytes = file_get_contents($tmp);
			if ($bytes === false) {
				throw new MailboxSenderException('An attachment could not be read.');
			}
			$email->attachData($bytes,
				$this->safeFilename((string)($f['name'] ?? 'attachment')),
				(string)($f['type'] ?? 'application/octet-stream'));
		}
	}

	private function safeFilename(string $name): string {
		$name = str_replace(array("\r", "\n", '"', '\\', '/'), '', $name);
		$name = trim($name);
		return $name !== '' ? substr($name, 0, 255) : 'attachment';
	}

	// ── Sent / compose interop (§9) ──────────────────────────────────────────

	/**
	 * APPEND the just-sent message into the source mailbox's Sent folder (§9), for
	 * a connected feed whose SMTP does not auto-file. Best-effort: a failure is
	 * logged but never fails the user's send (the local row still shows it, and the
	 * copy will simply be absent from the source Sent folder).
	 */
	private function appendSentCopy(InboundImapAccount $account, EmailMessage $email): void {
		require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/ImapIngestor.php'));
		try {
			$raw = $this->buildRawMime($email);
			if ($raw === '') {
				return;
			}
			$ingestor = new ImapIngestor($account);
			try {
				$ingestor->appendToSent($raw);
			} finally {
				$ingestor->close();
			}
		} catch (Throwable $e) {
			error_log('MailboxSender: APPEND-to-Sent failed: ' . $e->getMessage());
		}
	}

	/**
	 * Serialize an EmailMessage to raw RFC822 MIME (via Horde_Mime_Mail) for the
	 * APPEND, carrying the same Message-ID + threading headers so the Sent ingest
	 * dedups by Message-ID.
	 */
	private function buildRawMime(EmailMessage $email): string {
		require_once(PathHelper::getComposerAutoloadPath());

		$mail = new Horde_Mime_Mail();
		$from = $email->getFromName()
			? $email->getFromName() . ' <' . $email->getFrom() . '>'
			: $email->getFrom();
		$mail->addHeader('From', $from);
		$mail->addHeader('To', $this->joinAddresses($email->getRecipients()));
		$cc = $this->joinAddresses($email->getCc());
		if ($cc !== '') {
			$mail->addHeader('Cc', $cc);
		}
		$mail->addHeader('Subject', (string)$email->getSubject());
		$mail->addHeader('Date', gmdate('r'));
		if ($email->getMessageId()) {
			$mail->addHeader('Message-ID', $email->getMessageId());
		}
		// Threading + any other custom headers (In-Reply-To / References).
		foreach ((array)$email->getHeaders() as $name => $value) {
			$mail->addHeader($name, $value);
		}

		$html = (string)$email->getHtmlBody();
		$text = (string)$email->getTextBody();
		if ($html !== '') {
			$mail->setHtmlBody($html, 'UTF-8', true);
		} elseif ($text !== '') {
			$mail->setBody($text, 'UTF-8');
		}

		foreach ($email->getAttachments() as $a) {
			$bytes = null;
			if (!empty($a['data'])) {
				$bytes = (string)$a['data'];
			} elseif (!empty($a['path']) && is_readable($a['path'])) {
				$bytes = (string)file_get_contents($a['path']);
			}
			if ($bytes === null) {
				continue;
			}
			$part = new Horde_Mime_Part();
			$part->setType($a['type'] ?? 'application/octet-stream');
			$part->setContents($bytes);
			$part->setName($a['name'] ?? 'attachment');
			$part->setDisposition('attachment');
			$mail->addMimePart($part);
		}

		$raw = $mail->getRaw(false);
		return is_string($raw) ? $raw : '';
	}

	/** Join EmailMessage recipient arrays into a "Name <email>" comma list. */
	private function joinAddresses(array $list): string {
		$parts = array();
		foreach ($list as $r) {
			if (!empty($r['email'])) {
				$parts[] = !empty($r['name']) ? $r['name'] . ' <' . $r['email'] . '>' : $r['email'];
			}
		}
		return implode(', ', $parts);
	}

	// ── storage ────────────────────────────────────────────────────────────

	/**
	 * Persist the sent message as an outbound iem_ row in the same thread. When the
	 * source was a singleton (no stored thread key), backfill it so both rows group
	 * together (§6).
	 */
	private function storeOutboundRow(InboundEmailMessage $source, InboundEmailAlias $alias,
			string $mode, string $from_address, array $recipients, string $subject,
			EmailMessage $email, string $message_id): int {

		$thread_key = $this->resolveThreadKey($source, $message_id);

		$row = new InboundEmailMessage(NULL);
		$row->set('iem_ied_inbound_email_domain_id', $source->get('iem_ied_inbound_email_domain_id'));
		$row->set('iem_iea_inbound_email_alias_id', $alias->key);
		$row->set('iem_direction', 'outbound');
		$row->set('iem_sender', substr($from_address, 0, 500));
		$row->set('iem_recipient', substr(implode(', ', $recipients), 0, 500));
		$row->set('iem_subject', substr($subject, 0, 1000));
		$row->set('iem_body_plain', (string)$email->getTextBody());
		$row->set('iem_body_html', (string)$email->getHtmlBody());
		$row->set('iem_raw_message', '');
		$row->set('iem_message_id_header', substr($message_id, 0, 255));
		$row->set('iem_thread_key', $thread_key);
		$row->set('iem_is_read', true);
		$row->set('iem_is_starred', false);
		$row->set('iem_dkim_result', 'unverified');
		$row->set('iem_spf_result', 'unverified');
		$row->set('iem_dmarc_result', 'unverified');
		$row->set('iem_auth_source', 'none');
		$row->set('iem_received_time', gmdate('Y-m-d H:i:s'));
		$row->save();

		return intval($row->key);
	}

	/**
	 * The thread key the outbound row joins. Reuse the source's stored key; if the
	 * source was a singleton (no key), establish a real root (the source's
	 * Message-ID, else the new send's Message-ID) and backfill the source row so the
	 * two group as a conversation.
	 */
	private function resolveThreadKey(InboundEmailMessage $source, string $message_id): string {
		$tk = trim((string)$source->get('iem_thread_key'));
		if ($tk !== '') {
			return substr($tk, 0, 255);
		}
		$root = trim((string)$source->get('iem_message_id_header'));
		if ($root === '') {
			$root = $message_id;
		}
		$root = substr($root, 0, 255);

		$stmt = $this->db()->prepare(
			"UPDATE iem_inbound_email_messages SET iem_thread_key = ?
			 WHERE iem_inbound_email_message_id = ?
			 AND (iem_thread_key IS NULL OR iem_thread_key = '')");
		$stmt->execute(array($root, intval($source->key)));
		return $root;
	}

	// ── address parsing ──────────────────────────────────────────────────────

	/**
	 * Parse a To/Cc field into validated email addresses. Accepts comma/semicolon
	 * separated "Name <email>" or bare "email" tokens. Throws on any invalid token.
	 *
	 * @return string[] email addresses
	 */
	private function parseAddressList($raw): array {
		$raw = trim((string)$raw);
		if ($raw === '') {
			return array();
		}
		$out = array();
		foreach (preg_split('/[,;]+/', $raw) as $token) {
			$token = trim($token);
			if ($token === '') {
				continue;
			}
			if (preg_match('/<([^>]+)>/', $token, $m)) {
				$token = trim($m[1]);
			}
			if (!filter_var($token, FILTER_VALIDATE_EMAIL)) {
				throw new MailboxSenderException('Not a valid email address: ' . $token);
			}
			$out[strtolower($token)] = $token;
		}
		return array_values($out);
	}
}
?>

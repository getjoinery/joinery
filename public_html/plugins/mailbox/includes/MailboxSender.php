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
 * Forward re-attach is ONE manifest-driven loop dispatching each row on where its
 * bytes live (specs/implemented/inbound_email_attachment_storage.md): a file-backed row
 * (ima_fil_file_id) reads its private File; a 'remote' row fetches the part from
 * the IMAP source; a legacy raw row extracts it from the stored raw. Inline (cid:)
 * parts are re-embedded with their Content-ID via EmailMessage::attachInlineData()
 * so forwarded inline images still render.
 *
 * User uploads (not forwarded originals) persist on the SENT copy: after the
 * transport send succeeds, each accepted upload is stored as a private File
 * (fil_source = email_attachment, owned by the sending user) with an ima_
 * manifest row on the new outbound message — the same manifest/serving
 * plumbing that already renders and downloads inbound attachments, so the
 * sent copy shows what went out with no new rendering code
 * (specs/implemented/inbound_email_compose_attachments.md).
 *
 * A fourth mode, `new` (MODE_NEW), starts a conversation from scratch: there
 * is no source message, so identity comes directly from `alias_id` (still
 * gated by the same viewer->canAccess() grant), the subject is sent as
 * entered with no Re:/Fwd: prefix, the body carries no quote block, and no
 * reply threading headers are set. The stored row's thread key is the new
 * message's own Message-ID — the same "singleton thread" rule inbound
 * ingest uses — so a recipient's reply threads back into this conversation
 * (specs/implemented/inbound_email_new_message_compose.md).
 *
 * @version 1.6
 */

require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/OutboundTransport.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));

/** A user-facing send failure (bad input, transport unavailable, original gone). */
class MailboxSenderException extends Exception {}

/**
 * A send blocked because the vault window is closed — a content action under the
 * locked-state contract (specs/mailbox_security_levels.md § 4.2). Distinct from a
 * plain send failure so the native API can return `locked: true` (prompt one-tap
 * unlock, then resume) rather than an error; the web reader's existing
 * MailboxSenderException catch still surfaces the message.
 */
class MailboxLockedException extends MailboxSenderException {}

class MailboxSender {

	const MODE_REPLY      = 'reply';
	const MODE_REPLY_ALL  = 'reply_all';
	const MODE_FORWARD    = 'forward';
	const MODE_NEW        = 'new';

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
	 * @param array $params  mode, source_id (reply/reply_all/forward) or
	 *                       alias_id (new), to, cc, subject, body
	 * @param array $files   normalized upload entries (name,type,tmp_name,size,error)
	 */
	public function send(array $params, array $files = array()): array {
		$mode = (string)($params['mode'] ?? '');
		if (!in_array($mode, array(self::MODE_REPLY, self::MODE_REPLY_ALL, self::MODE_FORWARD, self::MODE_NEW), true)) {
			throw new MailboxSenderException('Unknown compose action.');
		}

		if ($mode === self::MODE_NEW) {
			// No source message: identity comes straight from the selected mailbox,
			// gated by the same "a grant means full access" rule as every other mode.
			$source = null;
			$alias_id = intval($params['alias_id'] ?? 0);
			if ($alias_id <= 0) {
				throw new MailboxSenderException('Choose a mailbox to send as.');
			}
			if (!$this->viewer->canAccess($alias_id)) {
				throw new MailboxSenderException('You do not have access to this mailbox.');
			}
			$alias = new InboundEmailAlias($alias_id, TRUE);
			if (!$alias->key || $alias->get('iea_delete_time')) {
				throw new MailboxSenderException('The selected mailbox no longer exists.');
			}
		} else {
			$source = $this->loadSourceInScope(intval($params['source_id'] ?? 0));
			$alias_id = intval($source->get('iem_iea_inbound_email_alias_id'));
			$alias = new InboundEmailAlias($alias_id, TRUE);
			if (!$alias->key || $alias->get('iea_delete_time')) {
				throw new MailboxSenderException('The mailbox for this conversation no longer exists.');
			}
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

		// Reading the source message's sealed fields (quoting/subject) requires
		// an open window for its owner — composing is always in-window per
		// specs/implemented/inbound_email_encryption_at_rest.md § 4.6, but a
		// window can still close mid-compose (idle timeout, another tab's
		// explicit lock); surface that as a clean user-facing message rather
		// than an uncaught fatal.
		try {
			$subject = $this->normalizeSubject((string)($params['subject'] ?? ''), $mode,
				$source !== null ? (string)$source->get('iem_subject') : '');
			$body_html = $this->buildBody($mode, (string)($params['body'] ?? ''), $source);
		} catch (VaultLockedException $e) {
			throw new MailboxLockedException('Your vault is locked — unlock it to reply to or forward this message.');
		}
		$message_id = $this->generateMessageId($from_address);

		$email = new EmailMessage();
		$email->from($from_address, $alias->get('iea_description') ?: null);
		foreach ($to as $addr) { $email->to($addr); }
		foreach ($cc as $addr) { $email->cc($addr); }
		$email->subject($subject);
		$email->html($body_html);
		$email->messageId($message_id);

		// Threading: replies are In-Reply-To/References the original; a forward
		// starts a fresh external thread (no reply headers) but still files into
		// this conversation locally (§5). A new message has no original to thread
		// from — the recipient's reply threads back via its own Message-ID (§6 of
		// the new-message spec, resolveThreadKey()/storeOutboundRow() below).
		if ($mode !== self::MODE_FORWARD && $mode !== self::MODE_NEW) {
			$this->applyThreadingHeaders($email, $source);
		}

		// Forward re-attaches the original's attachments; uploads ride along in
		// every mode.
		$total = 0;
		if ($mode === self::MODE_FORWARD) {
			$total += $this->attachOriginal($email, $source);
		}
		$accepted = $this->attachUploads($email, $files, $total);

		// One pipeline, synchronous (no retry-queue): success/failure is shown now.
		try {
			$sender = new EmailSender();
			$ok = $sender->send($email, false, $transport->transport);
		} catch (VaultLockedException $e) {
			// Signing a protected identity domain unwraps its sealed DKIM key, so it
			// is a content action under the locked-state contract: a closed window
			// (never opened, or lapsed mid-compose) prompts the same one-tap unlock
			// rather than escaping an unsigned/ambient send.
			throw new MailboxLockedException('Your vault is locked — unlock it to send from this address.');
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

		$stored = $this->storeOutboundRow($source, $alias, $mode, $from_address,
			array_merge($to, $cc), $subject, $email, $message_id);
		if ($accepted) {
			$this->persistOutboundUploads($stored['id'], $accepted, $stored['dek']);
		}

		return array('ok' => true, 'outbound_id' => $stored['id']);
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

	/**
	 * Add Re:/Fwd: only when the (possibly user-edited) subject lacks it. A new
	 * message is sent exactly as entered — no prefix, no fallback to a source
	 * subject (there is none), empty allowed.
	 */
	private function normalizeSubject(string $subject, string $mode, string $original): string {
		$subject = trim($subject);
		if ($mode === self::MODE_NEW) {
			return $subject;
		}
		if ($subject === '') {
			$subject = trim($original);
		}
		if ($mode === self::MODE_FORWARD) {
			return preg_match('/^\s*(fwd?|fw)\s*:/i', $subject) ? $subject : 'Fwd: ' . $subject;
		}
		return preg_match('/^\s*re\s*:/i', $subject) ? $subject : 'Re: ' . $subject;
	}

	/**
	 * Build the outgoing HTML body: the user's note + a quoted/forwarded
	 * original. A new message ($source === null) carries only the user's text
	 * — there is nothing to quote.
	 */
	private function buildBody(string $mode, string $userText, ?InboundEmailMessage $source): string {
		$userHtml = $this->textToHtml($userText);
		if ($mode === self::MODE_NEW) {
			return '<div>' . $userHtml . '</div>';
		}
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
	 * Dispatch is unified on the raw-storage driver flag:
	 *   - 'remote'                    → load the ima_ manifest and fetchPart() each
	 *                                   from the source mailbox (no MIME walk).
	 *   - 'inline'/'local'/'cloud'    → parse the stored raw (via getRawMessage())
	 *                                   with Horde_Mime and walk its parts.
	 * A reference-backed original no longer in the source mailbox fails the forward
	 * (§10) rather than sending empty.
	 */
	/**
	 * Re-attach the source message's original parts to a forward: ONE
	 * manifest-driven loop dispatching each row on WHERE its bytes live
	 * (specs/implemented/inbound_email_attachment_storage.md):
	 *   - ima_fil_file_id set   → read the private File (push mail, lean record);
	 *   - else 'remote'         → fetch the part from the IMAP source on demand;
	 *   - else (legacy raw row) → extract the part from the stored raw.
	 * An inline (cid:) part is re-embedded with its original Content-ID via
	 * attachInlineData() so the forwarded HTML body's cid: references still resolve
	 * in the recipient's client; every other part attaches normally. The whole
	 * message is rebuilt fresh (forwarding re-signs DKIM/SRS), so byte-exact replay
	 * was never on the wire. Returns the total bytes re-attached.
	 */
	private function attachOriginal(EmailMessage $email, InboundEmailMessage $source): int {
		$manifest = new MultiInboundMessageAttachment(array(
			'message_id' => intval($source->key),
		));
		$manifest->load();
		if (!count($manifest)) {
			return 0; // nothing to carry
		}

		$ingestor = null; // opened lazily for the first 'remote' row, reused, closed below
		$total = 0;
		try {
			foreach ($manifest as $att) {
				$bytes = $this->readOriginalPartBytes($att, $source, $ingestor);
				if ($bytes === null) {
					throw new MailboxSenderException('An original attachment is no longer available, so this message cannot be forwarded.');
				}

				$total += strlen($bytes);
				if ($total > self::MAX_TOTAL_BYTES) {
					throw new MailboxSenderException('The forwarded attachments exceed the size limit.');
				}

				$filename = $att->get('ima_filename') ?: 'attachment';
				$type = (string)$att->get('ima_content_type') ?: 'application/octet-stream';
				$cid  = trim((string)$att->get('ima_content_id'));

				if ($this->isInlineRow($att) && $cid !== '') {
					$email->attachInlineData($bytes, $cid, $filename, $type);
				} else {
					$email->attachData($bytes, $filename, $type);
				}
			}
		} finally {
			if ($ingestor !== null) {
				$ingestor->close();
			}
		}

		return $total;
	}

	/**
	 * Fetch one original part's bytes by where they live (see attachOriginal).
	 * Returns null when the bytes are gone (deleted File, IMAP miss, missing raw
	 * section), which attachOriginal turns into a user-facing forward failure.
	 * $ingestor is opened on the first 'remote' row and reused for the rest.
	 */
	private function readOriginalPartBytes(InboundMessageAttachment $att, InboundEmailMessage $source, &$ingestor): ?string {
		$fil_id = intval($att->get('ima_fil_file_id'));
		if ($fil_id > 0) {
			require_once(PathHelper::getIncludePath('data/files_class.php'));
			$file = new File($fil_id, TRUE);
			if (!$file->key || $file->get('fil_delete_time')) {
				return null;
			}
			$bytes = $file->read_bytes('original');
			if ($bytes === null) {
				return null;
			}
			// read_bytes() returns raw on-disk bytes, bypassing File's decrypt hook
			// (which only fires through serve_from_path()/HTTP serving) — a sealed
			// attachment (ima_is_sealed) must be opened explicitly here. Composing
			// is always in-window (specs/implemented/inbound_email_encryption_at_rest.md
			// § 4.6), so the owner's window should be open; a VaultLockedException
			// (window closed mid-compose) surfaces as the same "no longer available"
			// forward failure the caller already handles for a missing part.
			try {
				return InboundEmailMessage::openSealedAttachment($source, $att, $bytes);
			} catch (VaultLockedException $e) {
				return null;
			}
		}

		$driver = (string)$source->get('iem_raw_storage_driver') ?: 'inline';
		if ($driver === 'remote') {
			if ($ingestor === null) {
				$ingestor = $this->openImapIngestor($source);
			}
			$res = $ingestor->fetchPart(
				(string)$att->get('ima_mime_part'),
				intval($source->get('iem_imap_uid')),
				$source->get('iem_imap_uidvalidity') !== null ? intval($source->get('iem_imap_uidvalidity')) : null,
				(string)$source->get('iem_imap_folder'),
				$source->get('iem_message_id_header')
			);
			return empty($res['ok']) ? null : (string)$res['content'];
		}

		// Legacy raw row (inline/local/cloud stored raw): extract the one part.
		// A sealed stored raw with the window closed mid-compose surfaces as the
		// same "no longer available" failure the file-backed branch returns.
		try {
			$part = $source->getRawMimePart((string)$att->get('ima_mime_part'));
		} catch (VaultLockedException $e) {
			return null;
		}
		return $part === null ? null : (string)$part['content'];
	}

	/** Open (and validate) the IMAP ingestor for a 'remote' source message. */
	private function openImapIngestor(InboundEmailMessage $source): ImapIngestor {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
		$account = new InboundImapAccount(intval($source->get('iem_iia_inbound_imap_account_id')), TRUE);
		if (!$account->key) {
			throw new MailboxSenderException('The source mailbox for this message no longer exists, so it cannot be forwarded.');
		}
		return new ImapIngestor($account);
	}

	/** Robust truthiness for the ima_is_inline bool across PDO representations. */
	private function isInlineRow(InboundMessageAttachment $att): bool {
		$v = $att->get('ima_is_inline');
		return ($v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1);
	}

	/**
	 * Attach validated uploads. $runningTotal carries any forwarded-original bytes.
	 * Returns the accepted uploads (bytes + display name) so the caller can persist
	 * them onto the outbound manifest once the send succeeds.
	 *
	 * @return array<int, array{bytes:string, name:string}>
	 */
	private function attachUploads(EmailMessage $email, array $files, int $runningTotal): array {
		$count = 0;
		$accepted = array();
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
			$name = $this->safeFilename((string)($f['name'] ?? 'attachment'));
			$email->attachData($bytes, $name, (string)($f['type'] ?? 'application/octet-stream'));
			$accepted[] = array('bytes' => $bytes, 'name' => $name);
		}
		return $accepted;
	}

	private function safeFilename(string $name): string {
		$name = str_replace(array("\r", "\n", '"', '\\', '/'), '', $name);
		$name = trim($name);
		return $name !== '' ? substr($name, 0, 255) : 'attachment';
	}

	/**
	 * Persist each accepted upload as a private File plus an ima_ manifest row on
	 * the just-created outbound message — the same manifest/serving plumbing that
	 * already renders and downloads inbound attachments, so the sent copy shows
	 * what went out with no new rendering code. Ownership is the sending session
	 * user (we know exactly who uploaded, unlike inbound mail's grant-based guess);
	 * serving still authorizes via mailbox grants, not File ownership.
	 *
	 * Never throws: the message is already on the wire, so a storage failure here
	 * only degrades the sent copy to showing no attachments (logged for follow-up).
	 *
	 * @param array<int, array{bytes:string, name:string}> $accepted
	 */
	private function persistOutboundUploads(int $message_id, array $accepted, ?string $dek = null): void {
		$crypto = null;
		if ($dek !== null) {
			require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
			$crypto = new VaultCrypto();
		}
		$index = 0;
		foreach ($accepted as $a) {
			$index++;
			try {
				$mime = File::detect_mime_bytes($a['bytes']) ?: 'application/octet-stream';
				$bytes = $a['bytes'];
				$original_size = strlen($bytes);
				// A stable-enough part id for an upload (no real MIME part, since
				// this bypasses MIME parsing entirely) — unique per message, which
				// is all attachmentAd()'s row-binding needs.
				$part_id = 'upload:' . $index;
				if ($crypto !== null) {
					$bytes = $crypto->sealField($bytes, $dek, InboundEmailMessage::attachmentAd($message_id, $part_id));
				}
				$file = File::createFromBytes($bytes, $a['name'], $mime, $this->viewer->getUserId(), array(
					'fil_private' => true,
					'fil_source'  => File::SOURCE_EMAIL_ATTACHMENT,
				));
				if ($crypto !== null) {
					// Magic-byte detection on save() saw ciphertext — restore the real type.
					$file->set('fil_type', substr($mime, 0, 128));
					$file->save();
				}
				InboundMessageAttachment::CreateEntry(array(
					'ima_iem_inbound_email_message_id' => $message_id,
					'ima_filename'     => $a['name'],
					'ima_content_type' => $mime,
					'ima_size_bytes'   => $original_size,
					'ima_mime_part'    => $part_id,
					'ima_is_inline'    => false,
					'ima_fil_file_id'  => (int)$file->key,
					'ima_is_sealed'    => ($crypto !== null),
				));
			} catch (Throwable $e) {
				error_log('MailboxSender: failed to persist outbound upload "' . $a['name'] . '" on message '
					. $message_id . ': ' . $e->getMessage());
			}
		}
	}

	/**
	 * Normalize the multipart `attachments` upload(s) into a flat list of
	 * ['name','type','tmp_name','size','error'], tolerating both a single file and
	 * the array (`attachments[]`) shape. Shared by both send surfaces (the ajax
	 * endpoint and the API action) so they cannot drift.
	 */
	public static function collectUploads(): array {
		$files = array();
		if (!isset($_FILES['attachments'])) {
			return $files;
		}
		$f = $_FILES['attachments'];
		if (is_array($f['name'])) {
			$n = count($f['name']);
			for ($i = 0; $i < $n; $i++) {
				$files[] = array(
					'name'     => $f['name'][$i] ?? '',
					'type'     => $f['type'][$i] ?? '',
					'tmp_name' => $f['tmp_name'][$i] ?? '',
					'size'     => $f['size'][$i] ?? 0,
					'error'    => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
				);
			}
			return $files;
		}
		// Single-file (non-array) shape, e.g. a client that posts one `attachments`
		// field rather than `attachments[]`.
		if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE || ($f['tmp_name'] ?? '') !== '') {
			$files[] = array(
				'name'     => $f['name'] ?? '',
				'type'     => $f['type'] ?? '',
				'tmp_name' => $f['tmp_name'] ?? '',
				'size'     => $f['size'] ?? 0,
				'error'    => $f['error'] ?? UPLOAD_ERR_NO_FILE,
			);
		}
		return $files;
	}

	// ── Sent / compose interop (§9) ──────────────────────────────────────────

	/**
	 * APPEND the just-sent message into the source mailbox's Sent folder (§9), for
	 * a connected feed whose SMTP does not auto-file. Best-effort: a failure is
	 * logged but never fails the user's send (the local row still shows it, and the
	 * copy will simply be absent from the source Sent folder).
	 */
	private function appendSentCopy(InboundImapAccount $account, EmailMessage $email): void {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
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
	 * together (§6). A new message ($source === null) has no thread to join: its
	 * own Message-ID becomes the thread root — the same "singleton thread" rule
	 * inbound ingest uses — so a recipient's reply resolves back to this row.
	 *
	 * Encryption at rest (specs/implemented/inbound_email_encryption_at_rest.md §
	 * 4.6): composing is always in-window, so when the sending alias's owner
	 * holds a Sealed Vault, the row is inserted with empty content columns
	 * (mirroring InboundEmailRouter::storeMessage) and immediately sealed —
	 * including iem_recipient, which on an outbound row is real content (who
	 * you emailed), unlike an inbound row's routing-only alias address.
	 *
	 * @return array{id:int,dek:?string} the row id, and the per-message DEK
	 *         (raw bytes) when sealed — persistOutboundUploads() reuses it to
	 *         seal any re-uploaded attachments under the same key.
	 */
	private function storeOutboundRow(?InboundEmailMessage $source, InboundEmailAlias $alias,
			string $mode, string $from_address, array $recipients, string $subject,
			EmailMessage $email, string $message_id): array {

		$thread_key = $source !== null
			? $this->resolveThreadKey($source, $message_id)
			: substr($message_id, 0, 255);

		// Never truncated: the full list is real content (iem_recipient is text;
		// a sealed row stores its AEAD blob, which outgrows any plaintext cap).
		$recipient_str = implode(', ', $recipients);
		$body_plain = (string)$email->getTextBody();
		$body_html = (string)$email->getHtmlBody();
		$subject_trunc = substr($subject, 0, 4000);

		$owner_id = InboundEmailMessage::singleOwnerUserId(intval($alias->key));
		$vault = $owner_id !== null ? UserEncryptionVault::loadForUser($owner_id) : null;
		$sealing = ($vault !== null);

		$row = new InboundEmailMessage(NULL);
		$row->set('iem_ied_inbound_email_domain_id', $source !== null
			? $source->get('iem_ied_inbound_email_domain_id')
			: $alias->get('iea_ied_inbound_email_domain_id'));
		$row->set('iem_iea_inbound_email_alias_id', $alias->key);
		$row->set('iem_direction', 'outbound');
		$row->set('iem_sender', $sealing ? '' : substr($from_address, 0, 500));
		$row->set('iem_recipient', $sealing ? '' : $recipient_str);
		$row->set('iem_subject', $sealing ? '' : $subject_trunc);
		$row->set('iem_body_plain', $sealing ? '' : $body_plain);
		$row->set('iem_body_html', $sealing ? '' : $body_html);
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

		// A sealing row's insert and its seal UPDATE are one transaction — the
		// empty-content insert must never survive a seal failure (mirrors
		// InboundEmailRouter::storeMessage; the mail is already on the wire, so
		// a failure here reports honestly rather than leaving a hollow Sent row).
		$db = null;
		if ($sealing) {
			$db = DbConnector::get_instance()->get_db_link();
			$db->beginTransaction();
		}
		$dek = null;
		try {
			$row->save();
			if ($sealing) {
				$dek = InboundEmailMessage::sealAndPersistContent(intval($row->key), $vault,
					substr($from_address, 0, 500), $recipient_str, $subject_trunc, $body_plain, $body_html, true);
				$db->commit();
			}
		} catch (\Throwable $e) {
			if ($db !== null) {
				$db->rollBack();
			}
			throw $e;
		}

		return array('id' => intval($row->key), 'dek' => $dek);
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

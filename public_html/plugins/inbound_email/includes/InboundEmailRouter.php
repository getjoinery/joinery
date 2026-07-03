<?php
/**
 * InboundEmailRouter - Core inbound email routing logic.
 *
 * Parses raw email, looks up alias, reads authentication results, checks rate
 * limits, and either forwards or stores locally (or both, depending on the
 * alias / catch-all delivery mode). Handles SRS bounce processing.
 *
 * Forwarding relays through the selected outbound provider when that provider
 * implements RawMessageRelay (Mailgun, SMTP, SES) — reusing its credential, no
 * separate SMTP password. It uses a raw-SMTP relay (the forwarding-specific
 * inbound_email_forwarding_smtp_* settings, else base smtp_*) for providers
 * without raw-MIME relay, or whenever an explicit forwarding-SMTP host override
 * is set. The path is chosen in resolveRelayProvider(). When the provider relay
 * is primary, destinations it fails are retried over the SMTP relay
 * (primary→fallback, like outbound EmailSender) — see relay().
 *
 * Authentication (SPF/DKIM/DMARC) verdicts are NOT computed here. They come
 * from one of two trusted sources, in precedence order (see readAuthResults):
 *   1. A webhook provider that verified the message upstream and delivered it
 *      over its authenticated path passes the verdicts in as $provider_auth.
 *   2. Otherwise they are read from the message's Authentication-Results header
 *      (stamped by the verifying MTA milters) via AuthenticationResults — the
 *      app trusts only a line carrying our own authserv-id.
 * When neither is present the message is recorded 'unverified'. SPF/DMARC are
 * structurally impossible to compute at this layer anyway (the connecting
 * client IP never reaches the pipe). See specs/inbound_dkim_verification_fix.md
 * and specs/inbound_mailgun_verification.md.
 *
 * The IMAP poller (ImapIngestor) is reference-backed and never parses a raw: it
 * already holds the decoded bodies + headers, so it calls storeExtracted() to
 * write a row with an empty iem_raw_message plus the IMAP locator columns. Dedup
 * (UNIQUE on iem_message_id_header, iem_recipient) is shared with the push path,
 * so re-fetching a message stores nothing new. See specs/inbound_imap_provider.md.
 *
 * A stored push message is a LEAN RECORD (specs/implemented/inbound_email_attachment_storage.md):
 * headers + decoded text bodies + the ima_ manifest, with each non-text MIME part
 * extracted at ingest into a private File (linked by ima_fil_file_id). The bytes live
 * in exactly one place — the File — so nothing is stored twice; the offload drain and
 * per-attachment encryption come for free from the File machinery. On the happy path
 * NO raw is retained (iem_raw_storage_driver stays 'inline' with an empty body). The
 * extraction is all-or-nothing per message: if any File write fails (disk full), the
 * message's Files are rolled back and it FALLS BACK to persisting the whole raw via
 * RawMessageStore (descriptor: driver='local' + key) with a section-pointer manifest —
 * today's storage shape — and a local-write failure falls back again to an inline
 * write. Ingest never aborts. The degradation chain is lean record → raw-to-disk →
 * inline-in-DB. IMAP ('remote') mail is untouched — its parts stay on the server and
 * are fetched on demand. See specs/inbound_raw_message_storage.md.
 *
 * Spam disposition (specs/inbound_email_spam_filtering.md): classifySpam() turns the
 * already-resolved auth verdicts into a 'ham'/'spam' verdict (primary rule: DMARC
 * fail; fallback for no-DMARC providers: SPF and DKIM both fail). It is recorded on
 * the stored row, and a judged-spam message is never relayed — the forward is
 * suppressed (logged spam_held) while forward_and_store still keeps a reviewable copy.
 *
 * Content spam (specs/inbound_email_content_spam_filtering.md): a second verdict source
 * is OR'd into classifySpam() — a content scanner signal resolved per ingest path by
 * resolveContentSpam() (the rspamd milter's X-Spam header read by readSpamHeader() on
 * the Postfix path; a webhook provider's own spam flag passed in as $provider_spam).
 * The scanner's numeric score is recorded on the row (iem_spam_score) for transparency
 * only — never read for disposition. Gated on inbound_email_content_spam_filtering_enabled.
 *
 * Inbound filters (specs/implemented/inbound_email_filters.md): after a locally-received
 * message is persisted and its spam verdict set, storeMessage runs every in-scope operator
 * filter via InboundEmailFilter::runForMessage — one hook covering the Postfix and webhook
 * paths alike, never IMAP-polled mail. forwardStoredMessage() relays a copy for a filter's
 * "Forward to" action, reusing the alias-forward envelope rebuild + relay.
 *
 * @version 1.17
 */

require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_log_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/RawMessageStore.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/AuthenticationResults.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/SRSRewriter.php'));

class InboundEmailRouter {

	// Content-spam header contract (specs/inbound_email_content_spam_filtering.md).
	// rspamd's milter_headers module stamps these on the Postfix path and
	// readSpamHeader() parses the same names — this is the single place the name is
	// pinned. The rspamd config in provisioning/install_email.sh stamps the IDENTICAL
	// names; keep the two in step. SPAM_FLAG_HEADER is the binary flag ('X-Spam: Yes').
	// The numeric score is read from SPAM_SCORE_HEADER when present (SpamAssassin-style
	// 'X-Spam-Score'), else from the 'score=' field of SPAM_STATUS_HEADER (rspamd's
	// native 'X-Spam-Status: Yes, score=N').
	const SPAM_FLAG_HEADER   = 'X-Spam';
	const SPAM_SCORE_HEADER  = 'X-Spam-Score';
	const SPAM_STATUS_HEADER = 'X-Spam-Status';

	private $settings;

	function __construct() {
		$this->settings = Globalvars::get_instance();
	}

	/**
	 * Process a raw email from stdin.
	 *
	 * @param string $raw_email          Raw email content from Postfix
	 * @param string $envelope_recipient Envelope recipient from Postfix ${recipient}
	 * @param array|null $provider_auth  Optional upstream verdicts from a webhook
	 *                                   provider (the 'auth' key its handleInbound
	 *                                   returned). Preferred over the message's
	 *                                   Authentication-Results header when present.
	 * @param array|null $provider_spam  Optional content-spam signal a webhook provider
	 *                                   supplied (the 'spam' key its handleInbound
	 *                                   returned: ['result'=>spam|ham|none, 'score'=>?float,
	 *                                   'source'=>key]). A content-spam signal is NOT an
	 *                                   auth verdict, so it is a sibling argument, never
	 *                                   folded into $provider_auth. The Postfix path leaves
	 *                                   this null — its signal is read from the milter's
	 *                                   X-Spam header on the raw.
	 * @return int Exit code (0=success, 67=unknown user, 75=temp failure)
	 */
	public function processEmail($raw_email, $envelope_recipient, $provider_auth = null, $provider_spam = null) {
		$envelope_recipient = strtolower(trim($envelope_recipient));
		$parsed = $this->parseEmail($raw_email);

		// 1. SRS bounce check
		if ($this->settings->get_setting('inbound_email_srs_enabled') && SRSRewriter::isSRSAddress($envelope_recipient)) {
			return $this->handleSRSBounce($parsed, $raw_email, $envelope_recipient);
		}

		// 2. Look up alias
		$parts = explode('@', $envelope_recipient, 2);
		if (count($parts) !== 2) {
			return 67;
		}
		$local_part = $parts[0];
		$domain_name = $parts[1];

		// Look up domain
		$domain = InboundEmailDomain::GetByDomain($domain_name);
		if (!$domain || !$domain->get('ied_is_enabled')) {
			return 67;
		}

		// 3. Size cap applies to every path (forward, store, catch-all)
		if (strlen($raw_email) > 25 * 1024 * 1024) {
			$this->logTransaction($parsed, null, InboundEmailLog::STATUS_REJECTED, $envelope_recipient, null, 'Message too large', $domain->key);
			return 0;
		}

		// Authentication results (informational — we record them either way).
		// Prefer verdicts the webhook provider supplied; otherwise read the
		// message's Authentication-Results header. No verdict => 'unverified'.
		$auth = $this->readAuthResults($raw_email, $provider_auth);

		// Content-spam signal, resolved per ingest path (rspamd milter X-Spam header
		// on the Postfix path; the provider's own spam flag on webhook paths). Off
		// (signal 'none', no score) unless content filtering is enabled. OR'd into the
		// verdict by classifySpam(); the score is recorded for transparency only.
		$content_spam = $this->resolveContentSpam($raw_email, $provider_spam);

		// Look up alias
		$alias = $this->lookupAlias($local_part, $domain);
		if (!$alias) {
			// Catch-all branch
			$catch_all_mode = $domain->get('ied_catch_all_mode') ?: InboundEmailDomain::CATCHALL_FORWARD;

			if ($catch_all_mode === InboundEmailDomain::CATCHALL_STORE) {
				// Store every unmatched recipient — supersedes ied_reject_unmatched.
				return $this->handleStoreOnly($parsed, $raw_email, $envelope_recipient, $domain, null, $auth, $content_spam);
			}

			$catch_all = $domain->get('ied_catch_all_address');
			if ($catch_all) {
				return $this->forwardToCatchAll($parsed, $raw_email, $envelope_recipient, $domain, $catch_all);
			}

			// No match
			if ($domain->get('ied_reject_unmatched')) {
				$this->logTransaction($parsed, null, InboundEmailLog::STATUS_REJECTED, $envelope_recipient, null, 'No matching alias', $domain->key);
				return 67; // Reject
			} else {
				$this->logTransaction($parsed, null, InboundEmailLog::STATUS_DISCARDED, $envelope_recipient, null, null, $domain->key);
				return 0; // Discard silently
			}
		}

		// 4. Delivery mode (auth verdicts were resolved above as $auth).
		$mode = $alias->get('iea_delivery_mode') ?: InboundEmailAlias::MODE_FORWARD;
		$forwards = ($mode === InboundEmailAlias::MODE_FORWARD || $mode === InboundEmailAlias::MODE_FORWARD_AND_STORE);
		$stores = ($mode === InboundEmailAlias::MODE_STORE || $mode === InboundEmailAlias::MODE_FORWARD_AND_STORE);

		// Pure-store mode skips forwarding-side gates (rate limit, From-header check)
		// because they only apply to relay attempts.
		if (!$forwards) {
			return $this->handleStoreOnly($parsed, $raw_email, $envelope_recipient, $domain, $alias, $auth, $content_spam);
		}

		// 4b. Spam disposition (specs/inbound_email_spam_filtering.md): a judged-spam
		// message is never relayed — forwarding spam burns the platform's sending
		// reputation and can relay abuse. The forward is suppressed and logged
		// spam_held; a forward_and_store alias still keeps the message (with its spam
		// verdict) so it stays reviewable in the reader's Spam view.
		if ($this->classifySpam($auth, $content_spam['signal']) === InboundEmailMessage::SPAM_VERDICT_SPAM) {
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_SPAM_HELD, $envelope_recipient, null, null, $domain->key);
			if ($stores) {
				try {
					$this->storeMessage($raw_email, $parsed, $alias, $domain, $envelope_recipient, $auth, $content_spam);
				} catch (\Throwable $e) {
					error_log('InboundEmailRouter: store of spam-held message failed: ' . $e->getMessage());
					$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_ERROR, $envelope_recipient, null, 'Store of spam-held message failed: ' . $e->getMessage(), $domain->key);
				}
			}
			return 0;
		}

		// 5. Rate limiting (gates the forward path only)
		if (!$this->checkAliasRateLimit($alias->key)) {
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_RATE_LIMITED, $envelope_recipient, null, null, $domain->key);
			return 0;
		}
		if (!$this->checkDomainRateLimit($domain->key)) {
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_RATE_LIMITED, $envelope_recipient, null, null, $domain->key);
			return 0;
		}

		// 6. Basic header checks (forward path requires a usable From header)
		if (empty($parsed['from'])) {
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_REJECTED, $envelope_recipient, null, 'Missing From header', $domain->key);
			return 0;
		}

		// 7. Forward
		$destinations = $alias->get_destinations_array();
		$results = $this->forwardEmail($raw_email, $parsed, $alias, $domain, $destinations);

		$all_success = !in_array(false, $results, true);
		$dest_str = implode(',', $destinations);

		if ($all_success) {
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_FORWARDED, $envelope_recipient, $dest_str, null, $domain->key);
			$alias->record_forward();
		} else {
			$failed = array();
			foreach ($results as $dest => $success) {
				if (!$success) {
					$failed[] = $dest;
				}
			}
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_ERROR, $envelope_recipient, $dest_str, 'Failed to deliver to: ' . implode(', ', $failed), $domain->key);
		}

		// 8. forward_and_store — best-effort copy after the forward. A failure
		// here is logged but does NOT change the exit code, because the forward
		// already happened and retrying would double-forward.
		if ($stores) {
			try {
				$this->storeMessage($raw_email, $parsed, $alias, $domain, $envelope_recipient, $auth, $content_spam);
			} catch (\Throwable $e) {
				error_log('InboundEmailRouter: store after forward failed: ' . $e->getMessage());
				$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_ERROR, $envelope_recipient, null, 'Store after forward failed: ' . $e->getMessage(), $domain->key);
			}
		}

		return 0;
	}

	/**
	 * Pure-store path: persist the message and return success/temp-fail.
	 * Used by alias-store mode AND domain catch-all-store mode (alias=null).
	 *
	 * Exit code: 0 on success or successful dedup; 75 on transient DB
	 * failure so Postfix retries. The dedup mechanism is the UNIQUE
	 * constraint on (iem_message_id_header, iem_recipient).
	 */
	private function handleStoreOnly($parsed, $raw_email, $envelope_recipient, $domain, $alias, $auth = null, $content_spam = null) {
		if ($auth === null) {
			$auth = $this->readAuthResults($raw_email);
		}

		// Volume cap (per-domain stores within forwarding window)
		$cap = intval($this->settings->get_setting('inbound_email_mailbox_max_per_window'));
		if ($cap > 0) {
			$window = intval($this->settings->get_setting('inbound_email_forwarding_rate_limit_window')) ?: 3600;
			$count = $this->countStoresInWindow($domain->key, $window);
			if ($count >= $cap) {
				$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_STORE_CAPPED, $envelope_recipient, null, 'Store volume cap reached (' . $cap . ')', $domain->key);
				return 0;
			}
		}

		try {
			$saved = $this->storeMessage($raw_email, $parsed, $alias, $domain, $envelope_recipient, $auth, $content_spam);
			$this->logTransaction(
				$parsed,
				$alias,
				InboundEmailLog::STATUS_STORED,
				$envelope_recipient,
				$saved['dedup'] ? 'duplicate (Message-ID already stored)' : null,
				null,
				$domain->key
			);
			return 0;
		} catch (\Throwable $e) {
			error_log('InboundEmailRouter: store failed: ' . $e->getMessage());
			// No alias.record_forward() etc — and DO NOT log here because
			// returning 75 will cause Postfix to retry; logging would create
			// noise rows for transient failures. The retry succeeds via the
			// DB unique constraint.
			return 75;
		}
	}

	/**
	 * Persist a message to iem_inbound_email_messages.
	 *
	 * Returns ['message' => InboundEmailMessage|null, 'dedup' => bool].
	 * On unique-violation (SQLSTATE 23505), treats the store as a successful
	 * retry — returns dedup=true with message=null. Other PDO errors propagate.
	 *
	 * Always stores the ORIGINAL raw_email (never the header-rewritten copy
	 * forwardEmail() builds for relay), so forward_and_store preserves the
	 * faithful message.
	 *
	 * $auth is the verdict array from readAuthResults()
	 * (['dkim','spf','dmarc','source']); when null it is read here so a direct
	 * caller still records honest verdicts.
	 *
	 * $content_spam is the content-spam signal from resolveContentSpam()
	 * (['signal'=>spam|ham|none, 'score'=>?float]); when null it is resolved here
	 * (Postfix milter X-Spam header), so a direct caller still records it.
	 */
	public function storeMessage($raw_email, $parsed, $alias, $domain, $envelope_recipient, $auth = null, $content_spam = null) {
		if ($auth === null) {
			$auth = $this->readAuthResults($raw_email);
		}
		if ($content_spam === null) {
			$content_spam = $this->resolveContentSpam($raw_email);
		}
		$bodies = $this->extractBodies($raw_email, $parsed);

		$message_id_header = isset($parsed['headers']['message-id'])
			? (is_array($parsed['headers']['message-id'])
				? $parsed['headers']['message-id'][0]
				: $parsed['headers']['message-id'])
			: '';
		$message_id_header = trim((string)$message_id_header);
		if ($message_id_header === '') {
			$message_id_header = null;
		} else {
			$message_id_header = substr($message_id_header, 0, 255);
		}

		$subject_raw = $parsed['subject'] ?? '';
		$subject = $this->decodeMimeHeader($subject_raw);

		// Conversation grouping for the Mailbox Reader. Computed in-memory from
		// the already-parsed In-Reply-To / References headers — the raw headers
		// themselves are not persisted (recoverable from iem_raw_message).
		$thread_key = $this->computeThreadKey($parsed, $message_id_header);

		$row = [
			'iem_ied_inbound_email_domain_id' => $domain->key,
			'iem_iea_inbound_email_alias_id'  => $alias ? $alias->key : null,
			'iem_sender'      => substr($parsed['from_email'] ?? ($parsed['from'] ?? ''), 0, 500),
			'iem_recipient'   => substr($envelope_recipient, 0, 500),
			'iem_subject'     => substr($subject, 0, 1000),
			'iem_body_plain'  => $bodies['plain'],
			'iem_body_html'   => $bodies['html'],
			'iem_raw_message' => '', // raw goes to the store after insert (see below)
			'iem_message_id_header' => $message_id_header,
			'iem_thread_key'  => $thread_key,
			'iem_dkim_result'  => $auth['dkim'],
			'iem_spf_result'   => $auth['spf'],
			'iem_dmarc_result' => $auth['dmarc'],
			'iem_auth_source'  => $auth['source'],
			'iem_spam_verdict' => $this->classifySpam($auth, $content_spam['signal']),
			'iem_spam_score'   => $content_spam['score'],
			'iem_size_bytes'  => strlen($raw_email),
			'iem_received_time' => gmdate('Y-m-d H:i:s'),
		];

		try {
			$msg = InboundEmailMessage::CreateEntry($row);
		} catch (PDOException $e) {
			if ($e->getCode() === '23505') {
				// Dedup before any file is written — a deduped message never orphans
				// a stored object.
				return ['message' => null, 'dedup' => true];
			}
			throw $e;
		} catch (\Throwable $e) {
			// Some SystemBase implementations may wrap the PDOException.
			$prev = $e->getPrevious();
			if ($prev instanceof PDOException && $prev->getCode() === '23505') {
				return ['message' => null, 'dedup' => true];
			}
			throw $e;
		}

		// Row inserted (we now have the serial id): split attachments into private
		// Files and store the lean record (or fall back to raw storage on failure).
		$this->persistRawAndManifest(intval($msg->key), $raw_email, $alias);

		// Inbound filters (specs/implemented/inbound_email_filters.md). storeMessage
		// is the single local-only post-persist point, reached by every locally-
		// received path (Postfix milter + provider webhook) — so this one call runs
		// filters identically for all of them, and never for IMAP-polled mail (which
		// uses storeExtracted). It runs AFTER the spam verdict is set, so a filter's
		// never_spam/mark_spam is the last word on disposition. Best-effort: a filter
		// failure is logged but never aborts ingest (the message is already stored).
		try {
			require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_filter_class.php'));
			InboundEmailFilter::runForMessage($msg, $parsed, $alias);
		} catch (\Throwable $e) {
			error_log('InboundEmailRouter: inbound filter run failed for message ' . $msg->key . ': ' . $e->getMessage());
		}

		return ['message' => $msg, 'dedup' => false];
	}

	/**
	 * Store a freshly-inserted push message as a LEAN RECORD: split every
	 * non-text MIME part into a private File and link it from the manifest,
	 * retaining NO raw (specs/implemented/inbound_email_attachment_storage.md).
	 *
	 * All-or-nothing per message:
	 *  - Happy path — every part extracts to a File: the manifest links each and
	 *    the raw is not persisted (iem_raw_storage_driver stays 'inline', body
	 *    empty; getRawMessage() then returns null).
	 *  - Failure path — any File write fails (disk full, the pressure this design
	 *    relieves): roll back this message's Files + manifest rows, then fall back
	 *    to today's raw storage — RawMessageStore::write() the raw with a
	 *    section-pointer manifest, and if that fails too, an inline write.
	 *
	 * Ingest never aborts; a message always lands in whichever shape succeeded.
	 * The fallback is logged (a distinct marker) so an operator sees disk pressure.
	 */
	private function persistRawAndManifest(int $message_id, string $raw_email, $alias = null) {
		try {
			$this->extractAttachmentsToFiles($message_id, $raw_email, $alias);
			return; // lean record: Files written, manifest linked, no raw retained
		} catch (\Throwable $e) {
			// extractAttachmentsToFiles() rolled back its own Files + manifest rows.
			error_log('INBOUND_ATTACHMENT_EXTRACTION_FAILED message_id=' . $message_id
				. ' (falling back to raw storage): ' . $e->getMessage());
		}

		// Fallback: persist the whole raw and write a section-pointer manifest —
		// the pre-lean storage shape, still fully supported by download/forward.
		$this->persistRawFallback($message_id, $raw_email);
		try {
			$this->writeManifestFromRaw($message_id, $raw_email);
		} catch (\Throwable $e) {
			error_log('InboundEmailRouter: attachment manifest write failed for message ' . $message_id . ': ' . $e->getMessage());
		}
	}

	/**
	 * The lean-record split. MIME-parse the raw, and for each non-text part mint
	 * a private File (owner-or-admin via fil_private) and a manifest row linking
	 * it. Throws on any failure AFTER rolling back every File + manifest row it
	 * created for this message, so the caller's fallback starts from a clean slate.
	 *
	 * Files are created first (the failure-prone step — disk I/O); the manifest
	 * rows are written only once every File exists, so a partial File failure
	 * never leaves dangling links.
	 */
	private function extractAttachmentsToFiles(int $message_id, string $raw_email, $alias) {
		$owner_id = $this->attachmentOwnerId($alias);
		$parts    = $this->enumerateNonTextParts($raw_email);

		$created_files = array(); // File[] — for rollback
		$rows = array();          // pending ima_ row arrays

		try {
			// Phase 1: mint a private File per part (all-or-nothing).
			foreach ($parts as $part) {
				$bytes = (string)$part->getContents();
				$cid   = $part->getContentId();
				$disp  = $part->getDisposition();
				$isInline = ($disp === 'inline') || ($cid !== null && $cid !== '' && $disp !== 'attachment');
				$name = $part->getName() ? substr($part->getName(), 0, 500) : null;
				$type = (string)$part->getType() ?: 'application/octet-stream';

				// No resize()/variants — email attachments are served as their
				// original; skipping resize is exactly the small-VPS relief.
				$file = File::createFromBytes(
					$bytes,
					$name !== null ? $name : 'attachment',
					$type,
					$owner_id,
					array('fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT)
				);
				$created_files[] = $file;

				$rows[] = array(
					'ima_iem_inbound_email_message_id' => $message_id,
					'ima_filename'     => $name,
					'ima_content_type' => substr($type, 0, 255),
					'ima_size_bytes'   => strlen($bytes),
					'ima_mime_part'    => substr((string)$part->getMimeId(), 0, 40),
					'ima_encoding'     => substr($this->partTransferEncoding($part), 0, 40),
					'ima_content_id'   => $cid ? substr(trim($cid, '<>'), 0, 255) : null,
					'ima_is_inline'    => $isInline,
					'ima_fil_file_id'  => intval($file->key),
				);
			}

			// Phase 2: write the manifest rows now every File exists.
			foreach ($rows as $row) {
				InboundMessageAttachment::CreateEntry($row);
			}
		} catch (\Throwable $e) {
			foreach ($created_files as $f) {
				try { $f->permanent_delete(); } catch (\Throwable $ignore) {}
			}
			$this->deleteManifestRows($message_id);
			throw $e;
		}
	}

	/**
	 * The user who owns a message's attachment Files. With fil_private the owner
	 * IS the access subject (plus admins), so this decides who — besides admins —
	 * can see them:
	 *  - a single-grantee alias (an individual mailbox) → that user, so a
	 *    permission-0 owner sees their own attachments;
	 *  - a shared alias (several grantees) or an ownerless catch-all/NULL alias →
	 *    User::USER_SYSTEM, which matches no human, so only admins see them
	 *    (the accepted shared-mailbox tradeoff; see the spec's Access model).
	 */
	private function attachmentOwnerId($alias): int {
		if ($alias && $alias->key) {
			$grantees = InboundEmailMailboxGrant::user_ids_for_alias(intval($alias->key));
			if (count($grantees) === 1) {
				return intval($grantees[0]);
			}
		}
		return User::USER_SYSTEM;
	}

	/**
	 * The non-text MIME parts of a raw message (attachments AND inline cid:
	 * parts), mirroring ImapIngestor::writeManifest()'s enumeration: the first
	 * inline text/plain and text/html parts are the bodies (skipped), every other
	 * non-multipart part is returned. Shared by the lean-record split and the
	 * fallback manifest writer so both see exactly the same part set.
	 *
	 * @return Horde_Mime_Part[]
	 */
	private function enumerateNonTextParts(string $raw_email): array {
		require_once(PathHelper::getComposerAutoloadPath());

		$message = Horde_Mime_Part::parseMessage($raw_email);

		$bodyPlainId = null; $bodyHtmlId = null; $parts = array();
		foreach ($message->partIterator() as $part) {
			if ($part->getPrimaryType() === 'multipart') { continue; }
			$id   = (string)$part->getMimeId();
			$type = strtolower((string)$part->getType());
			$name = $part->getName();
			$disp = $part->getDisposition();
			$isInlineText = ($type === 'text/plain' || $type === 'text/html')
				&& $disp !== 'attachment' && ($name === null || $name === '');
			if ($isInlineText && $type === 'text/plain' && $bodyPlainId === null) { $bodyPlainId = $id; continue; }
			if ($isInlineText && $type === 'text/html'  && $bodyHtmlId  === null) { $bodyHtmlId  = $id; continue; }
			$parts[] = $part;
		}

		return $parts;
	}

	/** Hard-delete every manifest row for a message (rollback of a failed split). */
	private function deleteManifestRows(int $message_id) {
		try {
			$rows = new MultiInboundMessageAttachment(array('message_id' => $message_id));
			$rows->load();
			foreach ($rows as $row) {
				try { $row->permanent_delete(); } catch (\Throwable $ignore) {}
			}
		} catch (\Throwable $e) {
			error_log('InboundEmailRouter: manifest rollback failed for message ' . $message_id . ': ' . $e->getMessage());
		}
	}

	/**
	 * Persist the whole raw off-row (the fallback when attachment extraction
	 * fails): RawMessageStore::write() → LOCAL file + descriptor (driver='local',
	 * key); the shared engine offloads to the private store later. A local-write
	 * failure keeps the raw inline (iem_raw_message) so nothing is lost — the one
	 * place a new 'inline' write still happens.
	 */
	private function persistRawFallback(int $message_id, string $raw_email) {
		$db = DbConnector::get_instance()->get_db_link();

		try {
			$descriptor = RawMessageStore::write($message_id, $raw_email);
			$upd = $db->prepare(
				"UPDATE iem_inbound_email_messages
				 SET iem_raw_storage_driver = ?, iem_raw_storage_key = ?
				 WHERE iem_inbound_email_message_id = ?");
			$upd->execute([$descriptor['driver'], $descriptor['key'], $message_id]);
		} catch (\Throwable $e) {
			// Disk full / perms: keep the raw inline so nothing is lost.
			error_log('INBOUND_RAW_LOCAL_WRITE_FAILED message_id=' . $message_id . ': ' . $e->getMessage());
			try {
				$fallback = $db->prepare(
					"UPDATE iem_inbound_email_messages
					 SET iem_raw_message = ?, iem_raw_storage_driver = 'inline', iem_raw_storage_key = NULL
					 WHERE iem_inbound_email_message_id = ?");
				$fallback->execute([$raw_email, $message_id]);
			} catch (\Throwable $e2) {
				error_log('INBOUND_RAW_INLINE_FALLBACK_FAILED message_id=' . $message_id . ': ' . $e2->getMessage());
			}
		}
	}

	/**
	 * Write one ima_ section-pointer manifest row per non-text MIME part (the
	 * fallback path — bytes stay inside the stored raw, ima_fil_file_id is null).
	 * The MIME-section ids (getMimeId) match what getRawMimePart() resolves, since
	 * both parse the same raw with the same Horde method.
	 */
	private function writeManifestFromRaw(int $message_id, string $raw_email) {
		$parts = $this->enumerateNonTextParts($raw_email);

		foreach ($parts as $part) {
			$cid  = $part->getContentId();
			$disp = $part->getDisposition();
			$isInline = ($disp === 'inline') || ($cid !== null && $cid !== '' && $disp !== 'attachment');
			InboundMessageAttachment::CreateEntry(array(
				'ima_iem_inbound_email_message_id' => $message_id,
				'ima_filename'     => $part->getName() ? substr($part->getName(), 0, 500) : null,
				'ima_content_type' => substr((string)$part->getType(), 0, 255),
				'ima_size_bytes'   => strlen((string)$part->getContents()),
				'ima_mime_part'    => substr((string)$part->getMimeId(), 0, 40),
				'ima_encoding'     => substr($this->partTransferEncoding($part), 0, 40),
				'ima_content_id'   => $cid ? substr(trim($cid, '<>'), 0, 255) : null,
				'ima_is_inline'    => $isInline,
			));
		}
	}

	/** The transfer encoding for a parsed Horde_Mime_Part (best-effort, informational). */
	private function partTransferEncoding($part): string {
		try {
			$ref = new ReflectionProperty('Horde_Mime_Part', '_transferEncoding');
			$ref->setAccessible(true);
			$enc = (string)$ref->getValue($part);
			return $enc !== '' ? $enc : '7bit';
		} catch (Throwable $e) {
			return '';
		}
	}

	/**
	 * Store a reference-backed message from pre-extracted parts (the IMAP path).
	 *
	 * Unlike storeMessage(), there is no raw to parse: the IMAP ingestor already
	 * holds the decoded text bodies and the headers, so it hands them in directly.
	 * iem_raw_message is left empty and the IMAP locator columns
	 * (account id / uid / uidvalidity / folder) are written so individual MIME
	 * parts can be re-fetched on demand. iem_size_bytes records the message's
	 * RFC822.SIZE (for display), NOT the body length.
	 *
	 * $msg keys (all optional unless noted):
	 *   sender, subject, body_plain, body_html, message_id_header (string|null),
	 *   headers (assoc array for thread-key computation; or pass thread_key
	 *   directly), size_bytes, received_time (UTC 'Y-m-d H:i:s'),
	 *   imap_account_id (required), imap_uid, imap_uidvalidity, imap_folder.
	 *
	 * $auth is a verdict array (['dkim','spf','dmarc','source']); IMAP-sourced mail
	 * has no trusted local verdict, so the ingestor passes 'unverified'/'none'.
	 *
	 * Returns ['message' => InboundEmailMessage|null, 'dedup' => bool], matching
	 * storeMessage() — a unique violation (SQLSTATE 23505) is a successful dedup.
	 */
	public function storeExtracted(array $msg, $alias, $domain, $envelope_recipient, array $auth): array {
		$message_id_header = $msg['message_id_header'] ?? null;
		if ($message_id_header !== null) {
			$message_id_header = trim((string)$message_id_header);
			$message_id_header = ($message_id_header === '') ? null : substr($message_id_header, 0, 255);
		}

		$thread_key = $msg['thread_key'] ?? null;
		if ($thread_key === null) {
			$thread_key = $this->computeThreadKey(
				array('headers' => $msg['headers'] ?? array()),
				$message_id_header
			);
		}

		$row = array(
			'iem_ied_inbound_email_domain_id' => $domain->key,
			'iem_iea_inbound_email_alias_id'  => $alias ? $alias->key : null,
			'iem_sender'      => substr((string)($msg['sender'] ?? ''), 0, 500),
			'iem_recipient'   => substr((string)$envelope_recipient, 0, 500),
			'iem_subject'     => substr((string)($msg['subject'] ?? ''), 0, 1000),
			'iem_body_plain'  => $msg['body_plain'] ?? '',
			'iem_body_html'   => $msg['body_html'] ?? '',
			'iem_raw_message' => '', // reference-backed: parts fetched on demand
			// 'remote' is the single source of truth for "fetch parts from IMAP";
			// the locator columns below say which source/message. iem_raw_storage_key
			// stays null — the IMAP locator tuple IS the key.
			'iem_raw_storage_driver' => 'remote',
			'iem_message_id_header' => $message_id_header,
			'iem_thread_key'  => $thread_key,
			'iem_dkim_result'  => $auth['dkim'] ?? 'unverified',
			'iem_spf_result'   => $auth['spf'] ?? 'unverified',
			'iem_dmarc_result' => $auth['dmarc'] ?? 'unverified',
			'iem_auth_source'  => $auth['source'] ?? 'none',
			'iem_size_bytes'  => intval($msg['size_bytes'] ?? 0),
			'iem_iia_inbound_imap_account_id' => intval($msg['imap_account_id'] ?? 0) ?: null,
			'iem_imap_uid'         => isset($msg['imap_uid']) ? intval($msg['imap_uid']) : null,
			'iem_imap_uidvalidity' => isset($msg['imap_uidvalidity']) ? intval($msg['imap_uidvalidity']) : null,
			'iem_imap_folder'      => $msg['imap_folder'] ?? null,
			'iem_received_time' => $msg['received_time'] ?? gmdate('Y-m-d H:i:s'),
		);

		try {
			$saved = InboundEmailMessage::CreateEntry($row);
			return array('message' => $saved, 'dedup' => false);
		} catch (\Throwable $e) {
			// Dedup can surface two ways: SystemBase::save() pre-validates the
			// unique_with (iem_message_id_header, iem_recipient) and throws a
			// DisplayableUserException, OR a concurrent insert trips the DB UNIQUE
			// (SQLSTATE 23505). Either way, if the duplicate row genuinely exists
			// it is a successful dedup; otherwise the error is real — rethrow.
			if ($this->duplicateMessageExists($message_id_header, $row['iem_recipient']) || $this->isUniqueViolation($e)) {
				return array('message' => null, 'dedup' => true);
			}
			throw $e;
		}
	}

	/** True if a row with this (message-id, recipient) already exists. */
	private function duplicateMessageExists(?string $message_id_header, string $recipient): bool {
		if ($message_id_header === null || $message_id_header === '') {
			return false;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT 1 FROM iem_inbound_email_messages
			 WHERE iem_message_id_header = ? AND iem_recipient = ? LIMIT 1"
		);
		$stmt->execute(array($message_id_header, $recipient));
		return (bool)$stmt->fetchColumn();
	}

	/** True if the throwable is (or wraps) a SQLSTATE 23505 unique violation. */
	private function isUniqueViolation(\Throwable $e): bool {
		if ($e instanceof PDOException && $e->getCode() === '23505') {
			return true;
		}
		$prev = $e->getPrevious();
		return $prev instanceof PDOException && $prev->getCode() === '23505';
	}

	/**
	 * Compute the conversation root key for threading, from already-parsed
	 * headers. Precedence:
	 *   1. References present → the FIRST Message-ID token (the thread root).
	 *   2. Else In-Reply-To present → that Message-ID.
	 *   3. Else the message's own Message-ID (a singleton thread).
	 *   4. No Message-ID at all → null (reader treats null as a singleton).
	 *
	 * Out-of-order arrivals still converge because References normally carries
	 * the root id. Result is truncated to 255 to fit iem_thread_key.
	 *
	 * @param array       $parsed             Parsed email (with ['headers'])
	 * @param string|null $message_id_header  This message's own Message-ID (already trimmed)
	 * @return string|null
	 */
	public function computeThreadKey(array $parsed, ?string $message_id_header): ?string {
		$headers = $parsed['headers'] ?? array();

		$references = $headers['references'] ?? '';
		if (is_array($references)) { $references = $references[0] ?? ''; }
		$references = trim((string)$references);
		if ($references !== '') {
			if (preg_match('/<[^>]+>/', $references, $m)) {
				return substr($m[0], 0, 255);
			}
			// References with no angle-bracketed token — fall back to first whitespace token.
			$first = preg_split('/\s+/', $references)[0] ?? '';
			if ($first !== '') {
				return substr($first, 0, 255);
			}
		}

		$in_reply_to = $headers['in-reply-to'] ?? '';
		if (is_array($in_reply_to)) { $in_reply_to = $in_reply_to[0] ?? ''; }
		$in_reply_to = trim((string)$in_reply_to);
		if ($in_reply_to !== '') {
			if (preg_match('/<[^>]+>/', $in_reply_to, $m)) {
				return substr($m[0], 0, 255);
			}
			return substr($in_reply_to, 0, 255);
		}

		if ($message_id_header !== null && $message_id_header !== '') {
			return substr($message_id_header, 0, 255);
		}

		return null;
	}

	/**
	 * Count non-deleted store rows for a domain in the given window
	 * (seconds back from now). Used by the volume cap.
	 */
	private function countStoresInWindow($domain_id, $window_seconds) {
		$db = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT COUNT(*) AS cnt FROM iem_inbound_email_messages
				WHERE iem_ied_inbound_email_domain_id = ?
				AND iem_delete_time IS NULL
				AND iem_received_time > NOW() - INTERVAL '" . intval($window_seconds) . " seconds'";
		$stmt = $db->prepare($sql);
		$stmt->execute([$domain_id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return intval($row['cnt'] ?? 0);
	}

	/**
	 * Parse a raw email into structured data.
	 *
	 * @param string $raw_email Raw email content
	 * @return array Parsed email with: from, to, subject, headers, body
	 */
	public function parseEmail($raw_email) {
		// Handle both \r\n and \n line endings
		$normalized = str_replace("\r\n", "\n", $raw_email);

		// Split headers from body at first blank line
		$split_pos = strpos($normalized, "\n\n");
		if ($split_pos === false) {
			return array('from' => '', 'to' => '', 'subject' => '', 'headers' => array(), 'body' => $normalized);
		}

		$header_block = substr($normalized, 0, $split_pos);
		$body = substr($normalized, $split_pos + 2);

		// Parse headers, handling continuation lines
		$headers = array();
		$current_key = null;
		foreach (explode("\n", $header_block) as $line) {
			if (preg_match('/^\s+/', $line) && $current_key !== null) {
				// Continuation line — when the header repeated (Received:)
				// the value is an array; the fold belongs to its last entry.
				if (is_array($headers[$current_key])) {
					$headers[$current_key][count($headers[$current_key]) - 1] .= ' ' . trim($line);
				} else {
					$headers[$current_key] .= ' ' . trim($line);
				}
			} elseif (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
				$current_key = strtolower(trim($m[1]));
				if (isset($headers[$current_key])) {
					// Duplicate header — append (for things like Received:)
					if (!is_array($headers[$current_key])) {
						$headers[$current_key] = array($headers[$current_key]);
					}
					$headers[$current_key][] = trim($m[2]);
				} else {
					$headers[$current_key] = trim($m[2]);
				}
			}
		}

		$from = is_array($headers['from'] ?? '') ? ($headers['from'][0] ?? '') : ($headers['from'] ?? '');
		$to = is_array($headers['to'] ?? '') ? ($headers['to'][0] ?? '') : ($headers['to'] ?? '');
		$subject = is_array($headers['subject'] ?? '') ? ($headers['subject'][0] ?? '') : ($headers['subject'] ?? '');

		// Extract plain email from From header (may contain "Name <email>")
		$from_email = $from;
		if (preg_match('/<([^>]+)>/', $from, $m)) {
			$from_email = $m[1];
		}

		return array(
			'from' => $from,
			'from_email' => $from_email,
			'to' => $to,
			'subject' => $subject,
			'headers' => $headers,
			'body' => $body,
		);
	}

	/**
	 * Look up an alias for the given local part and domain.
	 *
	 * @param string $local_part Local part of the address
	 * @param InboundEmailDomain $domain Domain object
	 * @return InboundEmailAlias|null
	 */
	public function lookupAlias($local_part, $domain) {
		$results = new MultiInboundEmailAlias(array(
			'domain_id' => $domain->key,
			'alias' => strtolower($local_part),
			'deleted' => false
		));
		$results->load();

		if (count($results)) {
			$alias = $results->get(0);
			if ($alias->get('iea_is_enabled')) {
				return $alias;
			}
		}

		return null;
	}

	/**
	 * Forward the raw email to all destinations.
	 *
	 * @param string $raw_email Raw email content
	 * @param array $parsed Parsed email data
	 * @param InboundEmailAlias $alias Alias object
	 * @param InboundEmailDomain $domain Domain object
	 * @param array $destinations Array of destination email addresses
	 * @return array ['destination' => bool success]
	 */
	public function forwardEmail($raw_email, $parsed, $alias, $domain, $destinations) {
		$forwarding_domain = $domain->get('ied_domain');
		$alias_address = $alias->get('iea_alias') . '@' . $forwarding_domain;

		list($raw_mime, $envelope_sender) = $this->buildForwardMessage($raw_email, $parsed, $domain, $alias_address);

		return $this->relay($raw_mime, $envelope_sender, $destinations);
	}

	/**
	 * Relay a copy of an already-stored message to extra destinations — the
	 * filter "Forward to" action (specs/implemented/inbound_email_filters.md).
	 * Reuses the exact envelope rebuild + relay the alias forward uses, so
	 * deliverability headers (From rewrite, Reply-To, SRS) are identical, and it
	 * works for a catch-all-stored message (no alias) because it keys off the
	 * stored recipient/domain, not an alias row. Best-effort: returns the
	 * per-destination relay result and never throws.
	 *
	 * @param InboundEmailMessage $msg          a persisted message (raw resolvable)
	 * @param array               $destinations target addresses
	 * @return array ['destination' => bool]
	 */
	public function forwardStoredMessage(InboundEmailMessage $msg, array $destinations): array {
		$destinations = array_values(array_filter(array_map('trim', $destinations), 'strlen'));
		if (!count($destinations)) {
			return array();
		}
		$raw = $msg->getRawMessage();
		if ($raw === null || $raw === '') {
			error_log('InboundEmailRouter::forwardStoredMessage: no raw available for message ' . $msg->key);
			return array();
		}
		$domain = new InboundEmailDomain(intval($msg->get('iem_ied_inbound_email_domain_id')), TRUE);
		if (!$domain->key) {
			return array();
		}
		$parsed = $this->parseEmail($raw);
		list($raw_mime, $envelope_sender) = $this->buildForwardMessage(
			$raw, $parsed, $domain, (string)$msg->get('iem_recipient'));

		return $this->relay($raw_mime, $envelope_sender, $destinations);
	}

	/**
	 * Build the forwarded message and its envelope sender, shared by the alias
	 * forward and the catch-all forward. Rewrites the From header to the site's
	 * verified address (deliverability), preserves the original sender in
	 * Reply-To, stamps the X-Forwarded-* headers, and SRS-rewrites the envelope
	 * sender when SRS is enabled. The original message bytes are otherwise kept
	 * intact, so attachments and MIME structure survive the relay.
	 *
	 * @param string $original_to_address  The address mail arrived for (alias
	 *                                      address, or the envelope recipient for
	 *                                      catch-all), recorded in X-Original-To.
	 * @return array{0:string,1:string}    [raw_mime (CRLF), envelope_sender]
	 */
	private function buildForwardMessage($raw_email, $parsed, $domain, $original_to_address) {
		$forwarding_domain = $domain->get('ied_domain');

		// SRS rewrite envelope sender
		$envelope_sender = $parsed['from_email'];
		if ($this->settings->get_setting('inbound_email_srs_enabled')) {
			$srs = new SRSRewriter();
			$envelope_sender = $srs->rewrite($parsed['from_email'], $forwarding_domain);
		}

		$default_from = $this->settings->get_setting('defaultemail');
		$original_sender_name = $this->extractName($parsed['from']);
		$from_display = $this->forwardedFromDisplay($original_sender_name);

		$normalized = str_replace("\r\n", "\n", $raw_email);

		// Split into header block and body
		$split_pos = strpos($normalized, "\n\n");
		if ($split_pos === false) {
			$header_block = $normalized;
			$body_block = '';
		} else {
			$header_block = substr($normalized, 0, $split_pos);
			$body_block = substr($normalized, $split_pos + 2);
		}

		// Replace From header with verified sender (for deliverability)
		$header_block = preg_replace('/^From:.*$/mi', 'From: ' . $from_display . ' <' . $default_from . '>', $header_block);

		// Remove existing Reply-To if present, then add ours
		$header_block = preg_replace('/^Reply-To:.*$/mi', '', $header_block);

		// Add forwarding headers and Reply-To
		$extra_headers = "Reply-To: " . $parsed['from_email'] . "\n";
		$extra_headers .= "X-Original-To: " . $original_to_address . "\n";
		$extra_headers .= "X-Forwarded-For: " . $original_to_address . "\n";
		$extra_headers .= "X-Forwarded-By: Joinery Inbound Email";

		$header_block = trim($header_block) . "\n" . $extra_headers;

		// Reassemble with \r\n for SMTP / raw-MIME relay
		$modified_header = str_replace("\n", "\r\n", $header_block);
		$modified_body = str_replace("\n", "\r\n", $body_block);

		return array($modified_header . "\r\n\r\n" . $modified_body, $envelope_sender);
	}

	/**
	 * Relay a fully-formed raw message to the given destinations.
	 *
	 * When a provider raw-MIME relay is resolved it is the primary path; any
	 * destinations it fails are retried over the SMTP relay — mirroring the
	 * outbound primary→fallback in EmailSender. Only the failed destinations are
	 * retried, so a partial provider success never causes a double-send. The
	 * SMTP retry is skipped when it could not help: when no SMTP relay host is
	 * configured, or when the provider IS the SMTP relay (same transport).
	 *
	 * When no provider relay is resolved, SMTP is the primary (and only) path.
	 *
	 * Returns ['destination' => bool] per recipient.
	 */
	private function relay($raw_mime, $envelope_sender, array $destinations) {
		$provider = $this->resolveRelayProvider();
		if (!($provider instanceof RawMessageRelay)) {
			return $this->relayViaSmtpFallback($raw_mime, $envelope_sender, $destinations);
		}

		// Primary: provider raw-MIME relay.
		$results = $provider->relayRawMessage($raw_mime, $envelope_sender, $destinations);

		// Fallback: retry only the destinations the provider failed, over SMTP.
		$failed = array_keys(array_filter($results, function ($ok) { return $ok === false; }));
		if ($failed && $this->smtpFallbackAvailable($provider)) {
			error_log('InboundEmailRouter: provider relay failed for ' . implode(', ', $failed)
				. ' — retrying over SMTP fallback');
			foreach ($this->relayViaSmtpFallback($raw_mime, $envelope_sender, $failed) as $dest => $ok) {
				$results[$dest] = $ok; // SMTP outcome supersedes the provider failure
			}
		}

		return $results;
	}

	/**
	 * The SMTP forwarding fallback — raw-MIME relay through SmtpProvider configured
	 * with the forwarding SMTP coordinates (SmtpConfig::fromForwardingSettings()).
	 * The SMTP transaction itself lives once in SmtpProvider::relayRawMessage();
	 * this method only supplies the configured provider, so there is no duplicate
	 * MAIL FROM / RCPT TO / DATA copy. Returns ['destination' => bool].
	 */
	private function relayViaSmtpFallback($raw_mime, $envelope_sender, array $destinations) {
		require_once(PathHelper::getIncludePath('includes/SmtpConfig.php'));
		require_once(PathHelper::getIncludePath('includes/email_providers/SmtpProvider.php'));

		$provider = new SmtpProvider(SmtpConfig::fromForwardingSettings());
		return $provider->relayRawMessage($raw_mime, $envelope_sender, $destinations);
	}

	/**
	 * Whether retrying over the SMTP relay can meaningfully differ from the
	 * given provider relay. False when the provider IS the SMTP relay (same
	 * transport, nothing to gain) or when no base SMTP host is configured (the
	 * retry could only fail again). Note the forwarding-specific SMTP override
	 * is never set on the provider path — it would have forced the SMTP path in
	 * resolveRelayProvider() — so the base smtp_host is what the retry uses.
	 */
	private function smtpFallbackAvailable($provider) {
		$class = get_class($provider);
		if ($class::getKey() === 'smtp') {
			return false;
		}
		return (bool)$this->settings->get_setting('smtp_host');
	}

	/**
	 * Resolve the relay provider for forwarding — the single decision point.
	 *
	 * Returns a RawMessageRelay provider instance when the active outbound
	 * provider (email_service, the same resolution EmailSender uses) implements
	 * RawMessageRelay AND no explicit inbound_email_forwarding_smtp_host
	 * override is set. Otherwise returns null, meaning the SMTP fallback path
	 * (relayViaSmtpFallback, a SmtpProvider on the forwarding SmtpConfig) is used.
	 *
	 * Public so the provisioning check (InboundEmailHealth::checkForwardingRelay)
	 * verifies the exact relay the router will use.
	 *
	 * @return RawMessageRelay|null
	 */
	public function resolveRelayProvider() {
		// An explicit forwarding-SMTP host override always forces the SMTP path,
		// so an operator pointing forwarding at a dedicated relay keeps it.
		if ($this->settings->get_setting('inbound_email_forwarding_smtp_host')) {
			return null;
		}

		$service = $this->settings->get_setting('email_service') ?: 'mailgun';
		$providers = EmailSender::getDiscoveredProviders();
		$class = $providers[$service] ?? null;
		if ($class && in_array('RawMessageRelay', class_implements($class))) {
			return new $class();
		}

		return null;
	}

	/**
	 * Describe the resolved forwarding relay for status display (Setup tab).
	 * Returns ['mode' => 'provider'|'smtp', 'label' => string].
	 */
	public function describeRelay() {
		$provider = $this->resolveRelayProvider();
		if ($provider instanceof RawMessageRelay) {
			$class = get_class($provider);
			return array('mode' => 'provider', 'label' => $class::getLabel());
		}
		return array('mode' => 'smtp', 'label' => 'SMTP relay');
	}

	/**
	 * Forward to a catch-all address.
	 *
	 * Relays the original message bytes through the resolved relay (same path as
	 * the alias forward), so attachments and MIME structure are preserved — the
	 * earlier rebuild-from-parsed-parts approach was lossy.
	 */
	private function forwardToCatchAll($parsed, $raw_email, $envelope_recipient, $domain, $catch_all_address) {
		list($raw_mime, $envelope_sender) = $this->buildForwardMessage($raw_email, $parsed, $domain, $envelope_recipient);

		$results = $this->relay($raw_mime, $envelope_sender, array($catch_all_address));
		$success = !in_array(false, $results, true);
		$status = $success ? InboundEmailLog::STATUS_FORWARDED : InboundEmailLog::STATUS_ERROR;
		$this->logTransaction($parsed, null, $status, $envelope_recipient, $catch_all_address, $success ? null : 'Catch-all delivery failed', $domain->key);

		return 0;
	}

	/**
	 * Handle an SRS bounce — decode and notify the original sender.
	 *
	 * Unlike the forward paths, this is NOT a raw-MIME relay: it generates a
	 * fresh delivery-failure notification with our own From, so it is a normal
	 * transactional send. It goes through EmailSender (the same provider
	 * abstraction outbound mail uses), reusing the provider credential — no
	 * dependence on a separate SMTP password.
	 */
	private function handleSRSBounce($parsed, $raw_email, $envelope_recipient) {
		$srs = new SRSRewriter();

		if (!$srs->validate($envelope_recipient)) {
			$this->logTransaction($parsed, null, InboundEmailLog::STATUS_DISCARDED, $envelope_recipient, null, 'Invalid/expired SRS address');
			return 0;
		}

		$original_sender = $srs->decode($envelope_recipient);
		if (!$original_sender) {
			return 0;
		}

		try {
			$message = new EmailMessage();
			$message->to($original_sender)
				->subject('Delivery failure: ' . ($parsed['subject'] ?: '(no subject)'))
				->text("Your email could not be delivered.\n\n" . ($parsed['body'] ?: ''));

			$sender = new EmailSender();
			$sender->send($message);
			$this->logTransaction($parsed, null, InboundEmailLog::STATUS_BOUNCE_FORWARDED, $envelope_recipient, $original_sender);
		} catch (Exception $e) {
			error_log('InboundEmailRouter: Failed to forward bounce to ' . $original_sender . ': ' . $e->getMessage());
			$this->logTransaction($parsed, null, InboundEmailLog::STATUS_ERROR, $envelope_recipient, $original_sender, $e->getMessage());
		}

		return 0;
	}

	/**
	 * Create a SmtpMailer for the forwarding relay, configured from
	 * SmtpConfig::fromForwardingSettings() (inbound_email_forwarding_smtp_*, else
	 * base smtp_*).
	 *
	 * Used by InboundEmailHealth::checkForwardingRelay() to connection-test the
	 * exact relay the SMTP fallback would use (relayViaSmtpFallback sends through a
	 * SmtpProvider on the same SmtpConfig). When a provider raw-MIME relay is
	 * active instead, the health check verifies the provider credential rather than
	 * this mailer.
	 */
	public function createMailer() {
		require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));
		require_once(PathHelper::getIncludePath('includes/SmtpConfig.php'));

		// The forwarding relay's SMTP coordinates are a third SmtpConfig source
		// (inbound_email_forwarding_smtp_*, falling back to base smtp_*), so the
		// manual override block is gone — one construction model builds the mailer.
		return new SmtpMailer(SmtpConfig::fromForwardingSettings());
	}

	/**
	 * Resolve SPF/DKIM/DMARC verdicts for a message, in precedence order:
	 *
	 *   1. $provider_auth — verdicts a webhook provider verified upstream and
	 *      handed in (it owns the authenticated POST/SNS payload, so these are
	 *      trusted; see each provider's handleInbound and the spec's Security
	 *      section). iem_auth_source becomes the provider key (mailgun/sendgrid/ses).
	 *   2. The message's Authentication-Results header, stamped by our verifying
	 *      MTA milters (opendkim verify mode + opendmarc) and trusted only on a
	 *      line carrying our own authserv-id (== the configured mail hostname).
	 *      iem_auth_source = 'milter'.
	 *   3. Neither present → 'unverified' (never a hand-rolled 'fail').
	 *
	 * In tiers 1 and 2, a method the source did not assert reads 'none'.
	 *
	 * The app NEVER computes these verdicts itself.
	 *
	 * @param string     $raw_email
	 * @param array|null $provider_auth  Optional upstream verdicts: keys
	 *                                   spf/dkim/dmarc (each ?string) + source.
	 * @return array{dkim:string,spf:string,dmarc:string,source:string}
	 */
	private function readAuthResults($raw_email, $provider_auth = null) {
		// 1. Provider-supplied verdicts (only honored with a non-empty source).
		if (is_array($provider_auth) && !empty($provider_auth['source'])) {
			return array(
				'dkim'   => ($provider_auth['dkim']  ?? null) ?: 'none',
				'spf'    => ($provider_auth['spf']   ?? null) ?: 'none',
				'dmarc'  => ($provider_auth['dmarc'] ?? null) ?: 'none',
				'source' => (string)$provider_auth['source'],
			);
		}

		// 2. Standard Authentication-Results (Postfix milter path).
		$authserv_id = strtolower(trim((string)$this->settings->get_setting('inbound_email_mail_hostname')));
		$ar = AuthenticationResults::fromMessage($raw_email, $authserv_id);
		if ($ar) {
			return array(
				'dkim'   => $ar->dkim()  ?: 'none',
				'spf'    => $ar->spf()   ?: 'none',
				'dmarc'  => $ar->dmarc() ?: 'none',
				'source' => 'milter',
			);
		}

		// 3. Nothing trusted.
		return array(
			'dkim'   => 'unverified',
			'spf'    => 'unverified',
			'dmarc'  => 'unverified',
			'source' => 'none',
		);
	}

	/**
	 * Classify a message as 'ham' or 'spam' from its already-resolved auth verdicts
	 * (specs/inbound_email_spam_filtering.md). Returns null when filtering is off, so
	 * the stored verdict stays NULL and behavior is exactly as before.
	 *
	 *   - DMARC fail → spam (the primary rule; DMARC is alignment-based and already
	 *     subsumes SPF/DKIM, so it is the one signal worth acting on directly).
	 *   - DMARC absent (no verdict — none/unverified) AND both SPF and DKIM fail →
	 *     spam (the fallback for providers that supply SPF/DKIM but no DMARC, e.g.
	 *     Mailgun/SendGrid). BOTH must fail because raw SPF/DKIM lack DMARC's
	 *     alignment check; a single failure has too many legitimate causes
	 *     (forwarding breaks SPF; some legit mail breaks DKIM).
	 *   - otherwise → ham.
	 *
	 * This never computes verdicts — it only acts on the trusted ones already read.
	 * The strict rule is safe because the disposition is a reviewable Spam view,
	 * never rejection: a false positive costs a click, not a lost message.
	 *
	 * Content layer (specs/inbound_email_content_spam_filtering.md): the message is
	 * spam if the content scanner flagged it OR the auth rule fires —
	 *   verdict = spam  if  content_signal == 'spam'  OR  auth_rule == spam
	 * The $content_signal is already gated (resolveContentSpam returns 'none' unless
	 * content filtering is enabled), so this just OR's it in. The master gate below
	 * still governs the whole feature: with it off the verdict stays NULL regardless.
	 *
	 * @param array{dkim:string,spf:string,dmarc:string,source:string} $auth
	 * @param string $content_signal  'spam' | 'ham' | 'none' (from resolveContentSpam).
	 * @return string|null InboundEmailMessage::SPAM_VERDICT_*, or null when disabled.
	 */
	private function classifySpam(array $auth, string $content_signal = 'none'): ?string {
		if (!$this->settings->get_setting('inbound_email_spam_filtering_enabled')) {
			return null;
		}

		// Content scanner verdict, OR'd in ahead of the auth rule.
		if ($content_signal === 'spam') {
			return InboundEmailMessage::SPAM_VERDICT_SPAM;
		}

		$dmarc = strtolower(trim((string)($auth['dmarc'] ?? '')));
		if ($dmarc === 'fail') {
			return InboundEmailMessage::SPAM_VERDICT_SPAM;
		}

		// No DMARC verdict present → SPF/DKIM both-fail fallback.
		if ($dmarc === '' || $dmarc === 'none' || $dmarc === 'unverified') {
			$spf  = strtolower(trim((string)($auth['spf'] ?? '')));
			$dkim = strtolower(trim((string)($auth['dkim'] ?? '')));
			if ($spf === 'fail' && $dkim === 'fail') {
				return InboundEmailMessage::SPAM_VERDICT_SPAM;
			}
		}

		return InboundEmailMessage::SPAM_VERDICT_HAM;
	}

	/**
	 * Resolve the content-spam signal for a message, per ingest path
	 * (specs/inbound_email_content_spam_filtering.md). Returns
	 * ['signal' => 'spam'|'ham'|'none', 'score' => ?float].
	 *
	 * Gated on inbound_email_content_spam_filtering_enabled: off → ('none', null), so
	 * nothing downstream changes until the feature is turned on. (The master spam gate
	 * is still enforced separately, in classifySpam.)
	 *
	 *   - Webhook providers (Mailgun/SendGrid/SES) supply their own content/reputation
	 *     spam flag in the authenticated payload; the dispatcher hands it in as
	 *     $provider_spam. It is a content signal, NOT an auth verdict, so it arrives as
	 *     a sibling argument rather than inside $provider_auth.
	 *   - Postfix path ($provider_spam null): read the rspamd milter's X-Spam header off
	 *     the raw, trusted on the same basis as the Authentication-Results line (the
	 *     milter is ours; an external X-Spam is stripped by rspamd before it re-stamps).
	 *
	 * @param string     $raw_email
	 * @param array|null $provider_spam  ['result'=>spam|ham|none,'score'=>?float,'source'=>key]
	 * @return array{signal:string,score:?float}
	 */
	private function resolveContentSpam($raw_email, $provider_spam = null): array {
		if (!$this->settings->get_setting('inbound_email_content_spam_filtering_enabled')) {
			return array('signal' => 'none', 'score' => null);
		}

		// Webhook provider signal (only honored with a non-empty source).
		if (is_array($provider_spam) && !empty($provider_spam['source'])) {
			$result = strtolower(trim((string)($provider_spam['result'] ?? 'none')));
			$signal = in_array($result, array('spam', 'ham'), true) ? $result : 'none';
			$score  = (isset($provider_spam['score']) && is_numeric($provider_spam['score']))
				? (float)$provider_spam['score'] : null;
			return array('signal' => $signal, 'score' => $score);
		}

		// Postfix milter path.
		return $this->readSpamHeader($raw_email);
	}

	/**
	 * Parse the rspamd milter's X-Spam header off a raw message into a content-spam
	 * signal (specs/inbound_email_content_spam_filtering.md). Mirrors readAuthResults:
	 * it reads a verdict the milter stamped, never computing one.
	 *
	 * rspamd's milter_headers 'spam' routine adds 'X-Spam: Yes' (and an
	 * 'X-Spam-Flag: YES') only when it flags the message, so a present-and-affirmative
	 * header is the spam signal and absence is 'none' — the header never asserts ham.
	 * A numeric X-Spam-Score is recorded when present (display only). Only the header
	 * block (before the first blank line) is scanned, so body text cannot spoof it.
	 *
	 * @param string $raw_email
	 * @return array{signal:string,score:?float}
	 */
	private function readSpamHeader($raw_email): array {
		$none = array('signal' => 'none', 'score' => null);

		$normalized = str_replace("\r\n", "\n", (string)$raw_email);
		$split_pos = strpos($normalized, "\n\n");
		$header_block = ($split_pos === false) ? $normalized : substr($normalized, 0, $split_pos);

		$flag = $this->firstHeaderValue($header_block, self::SPAM_FLAG_HEADER);
		// Also accept the X-Spam-Flag: YES variant rspamd stamps alongside X-Spam.
		if ($flag === null) {
			$flag = $this->firstHeaderValue($header_block, self::SPAM_FLAG_HEADER . '-Flag');
		}
		if ($flag === null) {
			return $none;
		}
		$flag = strtolower(trim($flag));
		$is_spam = in_array($flag, array('yes', 'true', '1'), true);
		if (!$is_spam) {
			return $none;
		}

		$score = null;
		$score_raw = $this->firstHeaderValue($header_block, self::SPAM_SCORE_HEADER);
		if ($score_raw !== null && preg_match('/-?\d+(\.\d+)?/', $score_raw, $m)) {
			$score = (float)$m[0];
		} else {
			// rspamd-native: X-Spam-Status: Yes, score=5.20 required=5.00
			$status = $this->firstHeaderValue($header_block, self::SPAM_STATUS_HEADER);
			if ($status !== null && preg_match('/score=\s*(-?\d+(\.\d+)?)/i', $status, $m)) {
				$score = (float)$m[1];
			}
		}

		return array('signal' => 'spam', 'score' => $score);
	}

	/**
	 * First value of a header (case-insensitive) from an already-isolated header
	 * block, or null if absent. Continuation lines are not needed for the short
	 * single-token X-Spam* values, so this reads the first matching line only.
	 */
	private function firstHeaderValue($header_block, $name): ?string {
		foreach (explode("\n", $header_block) as $line) {
			if (preg_match('/^' . preg_quote($name, '/') . '\s*:\s*(.*)$/i', $line, $m)) {
				return $m[1];
			}
		}
		return null;
	}

	/**
	 * Check per-alias rate limit using the inbound email log table.
	 */
	private function checkAliasRateLimit($alias_id) {
		$db = DbConnector::get_instance()->get_db_link();
		$window = intval($this->settings->get_setting('inbound_email_forwarding_rate_limit_window')) ?: 3600;
		$max = intval($this->settings->get_setting('inbound_email_forwarding_rate_limit_per_alias')) ?: 50;

		$sql = "SELECT COUNT(*) as cnt FROM iel_inbound_email_logs
				WHERE iel_iea_inbound_email_alias_id = ?
				AND iel_status = 'forwarded'
				AND iel_create_time > NOW() - INTERVAL '" . intval($window) . " seconds'";
		$stmt = $db->prepare($sql);
		$stmt->execute([$alias_id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return ($row['cnt'] < $max);
	}

	/**
	 * Check per-domain rate limit using the inbound email log table.
	 *
	 * Uses iel_ied_inbound_email_domain_id directly — populated on every
	 * transaction since the local-mailbox change, so catch-all stores are
	 * also visible to per-domain counting without joining the alias table.
	 */
	private function checkDomainRateLimit($domain_id) {
		$db = DbConnector::get_instance()->get_db_link();
		$window = intval($this->settings->get_setting('inbound_email_forwarding_rate_limit_window')) ?: 3600;
		$max = intval($this->settings->get_setting('inbound_email_forwarding_rate_limit_per_domain')) ?: 200;

		$sql = "SELECT COUNT(*) as cnt FROM iel_inbound_email_logs
				WHERE iel_ied_inbound_email_domain_id = ?
				AND iel_status = 'forwarded'
				AND iel_create_time > NOW() - INTERVAL '" . intval($window) . " seconds'";
		$stmt = $db->prepare($sql);
		$stmt->execute([$domain_id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return ($row['cnt'] < $max);
	}

	/**
	 * Log an inbound email transaction.
	 *
	 * $domain_id is recorded directly on the log row so the Logs viewer's
	 * domain filter and the per-domain rate-limit query work without a
	 * join through the alias table — and so catch-all stores (alias null)
	 * remain visible to the domain filter.
	 */
	public function logTransaction($parsed, $alias, $status, $to_address, $destinations = null, $error = null, $domain_id = null) {
		InboundEmailLog::CreateEntry(
			$parsed['from'] ?? '',
			$to_address,
			$parsed['subject'] ?? '',
			$destinations,
			$status,
			$alias ? $alias->key : null,
			$error,
			$domain_id
		);
	}

	/**
	 * Decode an RFC 2047 encoded-word header value (Subject, display names)
	 * to readable UTF-8. Returns the input unchanged if mb_decode_mimeheader
	 * is unavailable.
	 */
	private function decodeMimeHeader($value) {
		if ($value === '' || $value === null) {
			return '';
		}
		if (function_exists('mb_decode_mimeheader')) {
			return mb_decode_mimeheader($value);
		}
		return $value;
	}

	/**
	 * Split a raw MIME message into best-effort plain and html bodies.
	 *
	 * Primary path: a full Horde_Mime_Part walk using the SAME inline-text
	 * rule as enumerateNonTextParts() — the bodies are the first inline
	 * text/plain and text/html parts at ANY nesting depth, so the standard
	 * Gmail shape (mixed → related → alternative) resolves correctly and the
	 * body/attachment enumerations can never disagree about which parts are
	 * the bodies. Falls back to the legacy hand-rolled splitter if the MIME
	 * parse throws. The original raw_email is always preserved separately
	 * (iem_raw_message), so imperfect decoding never loses data.
	 *
	 * Returns ['plain' => string, 'html' => string].
	 */
	public function extractBodies($raw_email, $parsed) {
		try {
			require_once(PathHelper::getComposerAutoloadPath());
			$message = Horde_Mime_Part::parseMessage($raw_email);

			$result = ['plain' => '', 'html' => ''];
			foreach ($message->partIterator() as $part) {
				if ($part->getPrimaryType() === 'multipart') { continue; }
				$type = strtolower((string)$part->getType());
				$name = $part->getName();
				$disp = $part->getDisposition();
				$isInlineText = ($type === 'text/plain' || $type === 'text/html')
					&& $disp !== 'attachment' && ($name === null || $name === '');
				if (!$isInlineText) { continue; }
				$text = $this->toUtf8((string)$part->getContents(), $part->getCharset());
				if ($type === 'text/html' && $result['html'] === '') {
					$result['html'] = $text;
				} elseif ($type === 'text/plain' && $result['plain'] === '') {
					$result['plain'] = $text;
				}
				if ($result['plain'] !== '' && $result['html'] !== '') { break; }
			}
			return $result;
		} catch (\Throwable $e) {
			error_log('InboundEmailRouter: MIME body walk failed, using legacy splitter: ' . $e->getMessage());
		}

		return $this->extractBodiesLegacy($raw_email, $parsed);
	}

	/**
	 * Legacy hand-rolled body splitter (multipart handled two levels deep) —
	 * the fallback when the Horde MIME parse fails on a malformed message.
	 *
	 * Returns ['plain' => string, 'html' => string].
	 */
	protected function extractBodiesLegacy($raw_email, $parsed) {
		$result = ['plain' => '', 'html' => ''];

		$headers = $parsed['headers'] ?? [];
		$ct_raw = $headers['content-type'] ?? '';
		if (is_array($ct_raw)) { $ct_raw = $ct_raw[0]; }
		$cte = $headers['content-transfer-encoding'] ?? '';
		if (is_array($cte)) { $cte = $cte[0]; }

		// Get the full message body (everything after the first blank line)
		$normalized = str_replace("\r\n", "\n", $raw_email);
		$split_pos = strpos($normalized, "\n\n");
		$body = ($split_pos !== false) ? substr($normalized, $split_pos + 2) : $normalized;

		// Single-part: no multipart, treat as html or plain by content-type
		if (stripos($ct_raw, 'multipart/') === false) {
			$decoded = $this->decodePartBody($body, $cte);
			$charset = $this->extractCharset($ct_raw);
			$decoded = $this->toUtf8($decoded, $charset);
			if (stripos($ct_raw, 'text/html') !== false) {
				$result['html'] = $decoded;
			} else {
				$result['plain'] = $decoded;
			}
			return $result;
		}

		// Multipart — extract boundary
		if (!preg_match('/boundary\s*=\s*"?([^";\s]+)"?/i', $ct_raw, $bm)) {
			$result['plain'] = $body;
			return $result;
		}
		$boundary = $bm[1];

		$parts = $this->splitMultipart($body, $boundary);
		foreach ($parts as $part) {
			$p = $this->parseMimePart($part);
			$p_ct = $p['headers']['content-type'] ?? '';
			$p_cte = $p['headers']['content-transfer-encoding'] ?? '';
			$decoded = $this->decodePartBody($p['body'], $p_cte);
			$charset = $this->extractCharset($p_ct);
			$decoded = $this->toUtf8($decoded, $charset);

			if (stripos($p_ct, 'multipart/') !== false) {
				// Nested multipart — recurse one level by re-running extract on this part
				if (preg_match('/boundary\s*=\s*"?([^";\s]+)"?/i', $p_ct, $nb)) {
					$sub_parts = $this->splitMultipart($p['body'], $nb[1]);
					foreach ($sub_parts as $sub) {
						$sp = $this->parseMimePart($sub);
						$sp_ct = $sp['headers']['content-type'] ?? '';
						$sp_cte = $sp['headers']['content-transfer-encoding'] ?? '';
						$sd = $this->toUtf8(
							$this->decodePartBody($sp['body'], $sp_cte),
							$this->extractCharset($sp_ct)
						);
						if (stripos($sp_ct, 'text/html') !== false && $result['html'] === '') {
							$result['html'] = $sd;
						} elseif (stripos($sp_ct, 'text/plain') !== false && $result['plain'] === '') {
							$result['plain'] = $sd;
						}
					}
				}
				continue;
			}

			if (stripos($p_ct, 'text/html') !== false && $result['html'] === '') {
				$result['html'] = $decoded;
			} elseif (stripos($p_ct, 'text/plain') !== false && $result['plain'] === '') {
				$result['plain'] = $decoded;
			}
		}

		return $result;
	}

	private function splitMultipart($body, $boundary) {
		$delim = '--' . $boundary;
		$end = '--' . $boundary . '--';
		// Strip off everything before the first boundary and the closing terminator
		$pos = strpos($body, $delim);
		if ($pos === false) {
			return [];
		}
		$body = substr($body, $pos);
		$end_pos = strpos($body, $end);
		if ($end_pos !== false) {
			$body = substr($body, 0, $end_pos);
		}
		// Split on the boundary delimiter
		$raw_parts = preg_split('/(^|\n)--' . preg_quote($boundary, '/') . '\r?\n/', $body);
		$parts = [];
		foreach ($raw_parts as $rp) {
			$rp = trim($rp);
			if ($rp !== '' && substr($rp, 0, 2) !== '--') {
				$parts[] = $rp;
			}
		}
		return $parts;
	}

	private function parseMimePart($part) {
		$normalized = str_replace("\r\n", "\n", $part);
		$split_pos = strpos($normalized, "\n\n");
		if ($split_pos === false) {
			return ['headers' => [], 'body' => $normalized];
		}
		$header_block = substr($normalized, 0, $split_pos);
		$body = substr($normalized, $split_pos + 2);
		$headers = [];
		$current = null;
		foreach (explode("\n", $header_block) as $line) {
			if (preg_match('/^\s+/', $line) && $current !== null) {
				$headers[$current] .= ' ' . trim($line);
			} elseif (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
				$current = strtolower(trim($m[1]));
				$headers[$current] = trim($m[2]);
			}
		}
		return ['headers' => $headers, 'body' => $body];
	}

	private function decodePartBody($body, $encoding) {
		$encoding = strtolower(trim($encoding));
		if ($encoding === 'quoted-printable') {
			return quoted_printable_decode($body);
		}
		if ($encoding === 'base64') {
			return base64_decode($body) ?: '';
		}
		// 7bit / 8bit / binary / unspecified
		return $body;
	}

	private function extractCharset($content_type) {
		if (preg_match('/charset\s*=\s*"?([^";\s]+)"?/i', $content_type, $m)) {
			return strtoupper(trim($m[1]));
		}
		return '';
	}

	private function toUtf8($text, $charset) {
		if ($text === '' || $text === null) {
			return '';
		}
		if (!function_exists('mb_convert_encoding')) {
			return $text;
		}
		$charset = $charset !== '' ? $charset : 'UTF-8';
		// mb_convert_encoding tolerates unknown charsets by falling back to UTF-8
		$converted = @mb_convert_encoding($text, 'UTF-8', $charset);
		return $converted !== false ? $converted : $text;
	}

	/**
	 * Build the From-header display name for a forwarded message. The original
	 * sender's address is replaced with the site's verified address for
	 * deliverability, so the display name is what carries who the mail is
	 * really from. The mailing-list style "via <site>" suffix can be turned
	 * off with the inbound_email_from_show_via setting.
	 */
	private function forwardedFromDisplay($original_sender_name) {
		$site = $this->settings->get_setting('defaultemailname') ?: 'Inbound Email';
		if ((string)$this->settings->get_setting('inbound_email_from_show_via') === '0') {
			return $original_sender_name ? $original_sender_name : 'Forwarded';
		}
		return $original_sender_name
			? $original_sender_name . ' via ' . $site
			: 'Forwarded via ' . $site;
	}

	/**
	 * Extract display name from a From header value.
	 */
	private function extractName($from_header) {
		if (preg_match('/^"?([^"<]+)"?\s*</', $from_header, $m)) {
			return trim($m[1]);
		}
		return '';
	}
}
?>

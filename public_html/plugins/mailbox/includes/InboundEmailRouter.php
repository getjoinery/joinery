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
 * mailbox_forwarding_smtp_* settings, else base smtp_*) for providers
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
 * Content spam (specs/mailbox_spam_filtering_simplification.md): a second verdict
 * source is OR'd into classifySpam() — a content scanner signal resolved per ingest
 * path by resolveContentSpam(). An arriving verdict is ALWAYS read, whatever this
 * box runs: the X-Spam header readSpamHeader() parses (stamped by the relay's rspamd
 * on a relay-fronted deployment, by the local milter on a colocated one) or a webhook
 * provider's own spam flag passed in as $provider_spam. On top of that, any deployment
 * with a scanner running re-scores relay- and webhook-sourced mail through its own
 * rspamd at ingest (scanContentSpam) — an upstream scanner is stateless and its header
 * may never have been stamped at all, which is indistinguishable from a clean verdict
 * until something here looks. That local verdict is OR'd into the upstream one, or
 * REPLACES it where the deployment learns from its users' corrections and so holds a
 * corpus the upstream cannot have; only replacement can rescue a false positive.
 * Colocated mail is not re-scored: its milter already did exactly this scan. A scanner
 * that is absent or down costs nothing — the upstream verdict stands and the message
 * stores normally. The scanner's numeric score is
 * recorded on the row (iem_spam_score) for transparency only — never read for
 * disposition. MailboxSpamPolicy owns every one of these decisions.
 *
 * Inbound filters (specs/implemented/inbound_email_filters.md): after a locally-received
 * message is persisted and its spam verdict set, storeMessage runs every in-scope operator
 * filter via InboundEmailFilter::runForMessage — one hook covering the Postfix and webhook
 * paths alike. Mail that did not just arrive is exempt: IMAP-polled feeds (which use
 * storeExtracted) and archive imports (which pass run_filters => false).
 * forwardStoredMessage() relays a copy for a filter's "Forward to" action, reusing the
 * alias-forward envelope rebuild + relay.
 *
 * Sender display names: iem_sender stores the From display name beside the address
 * ("Name" <addr>) so the reader can show who mail is from rather than a local part.
 * senderDisplayString() owns the decode-and-sanitize; the IMAP and archive paths get
 * the same shape from Horde's envelope.
 *
 * Byte custody (specs/mailbox_attachment_byte_custody.md): a dedup hit while
 * this path is HOLDING the message's real bytes hands them to the stored copy
 * when that copy is only a reference to a source mailbox — storeMessage's
 * dedup return adopts from the raw in hand, storeDirectMessage's from the
 * delivered parts. See AttachmentByteCustody.
 *
 * @version 1.35
 * @changelog 1.35 - the spam-held store path defers (75) on a seal-target
 *   refusal like every other path, instead of reporting delivery for a message
 *   no row holds
 * @version 1.34
 * @changelog 1.34 - one resolveSealTarget() decides sealing for every ingest path:
 *   the posture is the mailbox's, IMAP-polled mail seals like pushed mail, and a
 *   sealing mailbox with no key DECLINES the message instead of writing plaintext
 * @version 1.33
 * @changelog 1.33 - a database-raised unique violation in storeExtracted() now
 *   throws InboundStoreCollisionException instead of being reported as a dedup:
 *   it aborts the enclosing Postgres transaction, so a caller storing a message
 *   and its manifest as one unit has to roll back and retry, not carry on
 *   (D1, specs/mail_import_loss_proof.md). duplicateMessageId() made public so
 *   the archive importer can name the row it collided with.
 */

require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_log_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RawMessageStore.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/AuthenticationResults.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/SRSRewriter.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException

/**
 * A store lost the race for a (Message-ID, recipient) that another process
 * inserted first, and the database — not the pre-validate SELECT — raised it.
 *
 * It exists because that distinction is not cosmetic: a database-raised unique
 * violation aborts the enclosing Postgres transaction, so a caller storing a
 * message and its attachments as one unit cannot recover inline. Catching this
 * means "roll this message back and let it be retried", never "carry on".
 */
class InboundStoreCollisionException extends RuntimeException {}

/**
 * A mailbox that seals content could not produce a key to seal to
 * (specs/mailbox_connect_flow.md § E).
 *
 * Storing the message in plaintext would be the worst option available: it
 * breaks the mailbox's one promise and hides that it did, and the read path
 * dispatches on the row's own iem_content_sealed column, so a row that lands in
 * plaintext renders in plaintext forever. So the store is DECLINED instead, and
 * declining always means "try again later", never a bounce — every ingress
 * treats this as transient (Postfix tempfails, the webhook answers non-2xx, the
 * IMAP feed leaves the message on the source, a Direct sender keeps it). Once
 * the mailbox is repaired the held mail flows in on its own.
 *
 * With the grant invariant in InboundEmailMailboxGrant::sync_for_alias() this is
 * unreachable through every grant-writing door that exists; it is the backstop
 * that makes a future bypass — or a vault that fails to load at delivery time —
 * loud and recoverable instead of a silent leak.
 */
class MailboxSealTargetMissing extends RuntimeException {}

class InboundEmailRouter {

	// Content-spam header contract (specs/inbound_email_content_spam_filtering.md).
	// rspamd's milter_headers module stamps these on the Postfix path and
	// readSpamHeader() parses the same names — this is the single place the name is
	// pinned. The rspamd config in provisioning/provision_spam_scanner.sh stamps the
	// IDENTICAL names; keep the two in step. SPAM_FLAG_HEADER is the binary flag ('X-Spam: Yes').
	// The numeric score is read from SPAM_SCORE_HEADER when present (SpamAssassin-style
	// 'X-Spam-Score'), else from the 'score=' field of SPAM_STATUS_HEADER (rspamd's
	// native 'X-Spam-Status: Yes, score=N').
	const SPAM_FLAG_HEADER   = 'X-Spam';
	const SPAM_SCORE_HEADER  = 'X-Spam-Score';
	const SPAM_STATUS_HEADER = 'X-Spam-Status';

	// Ingest-time scan budget. Deliberately tight: the scan is an improvement on
	// a verdict already in hand, never a precondition for storing the message, so
	// a slow scanner must cost a few seconds and then be abandoned. The scanned
	// paths are the spool cron and webhook POSTs — never a live SMTP session.
	const SCAN_CONNECT_TIMEOUT = 3;
	const SCAN_TIMEOUT         = 10;

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
		// SRS bounce addresses carry a case-sensitive hash in the local part (the
		// pipe preserves it — flags=DRh), so the SRS check runs on the raw
		// recipient; everything after it lowercases (lookups are case-insensitive).
		$envelope_recipient = trim($envelope_recipient);
		$parsed = $this->parseEmail($raw_email);

		// 1. SRS bounce check
		$srs_result = $this->handleSrsBounceIfApplicable($parsed, $raw_email, $envelope_recipient);
		if ($srs_result !== null) {
			return $srs_result;
		}
		$envelope_recipient = strtolower($envelope_recipient);

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

		// Content-spam signal, resolved per ingest path (the X-Spam header a relay or
		// local milter stamped; the provider's own spam flag on webhook paths). No
		// scanner verdict on the message => signal 'none'. OR'd into the verdict by
		// classifySpam(); the score is recorded for transparency only.
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
				} catch (MailboxSealTargetMissing $e) {
					// Declining always means "try again later", on every path —
					// including this one. Returning 0 here would tell the sender's
					// queue the message was delivered while no row exists anywhere:
					// the one outcome the refusal exists to prevent. The retry's
					// re-store dedups (23505), so deferring cannot double-store.
					error_log('InboundEmailRouter: ' . $e->getMessage());
					$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_ERROR, $envelope_recipient, null, $e->getMessage(), $domain->key);
					return 75;
				} catch (\Throwable $e) {
					error_log('InboundEmailRouter: store of spam-held message failed: ' . $e->getMessage());
					$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_ERROR, $envelope_recipient, null, 'Store of spam-held message failed: ' . $e->getMessage(), $domain->key);
				}
			}
			return 0;
		}

		// 5. forward_and_store persists the retained copy BEFORE anything on the
		// forward side runs (specs/mailbox_data_loss_fixes.md, Fix 5). The copy is
		// the whole point of forward_and_store, so it must not depend on the
		// forward succeeding — nor be silently skipped when a forward-side gate
		// (rate limit, missing From) blocks the relay, which is what happened when
		// the store was a best-effort tail after the forward. On a store failure
		// we temp-fail (75 pipe / 503 webhook) so the sender retries: the forward
		// has not run on this pass, so the retry's forward is the FIRST forward
		// (no duplicate), and a re-store dedups (23505) and proceeds. If the store
		// backend is genuinely down, forwarding is delayed until it recovers
		// rather than forwarding and dropping the copy — the correct priority when
		// the copy is the point.
		if ($stores) {
			try {
				$this->storeMessage($raw_email, $parsed, $alias, $domain, $envelope_recipient, $auth, $content_spam);
			} catch (\Throwable $e) {
				error_log('InboundEmailRouter: forward_and_store copy failed, deferring for retry: ' . $e->getMessage());
				return 75; // sender retries; no forward happened this pass, so no double-forward
			}
		}

		// 6. Rate limiting — gates the FORWARD only. A rate-limited or From-less
		// forward_and_store message keeps its copy (already stored above); only
		// the relay attempt is blocked.
		if (!$this->checkAliasRateLimit($alias->key)) {
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_RATE_LIMITED, $envelope_recipient, null, null, $domain->key);
			return 0;
		}
		if (!$this->checkDomainRateLimit($domain->key)) {
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_RATE_LIMITED, $envelope_recipient, null, null, $domain->key);
			return 0;
		}

		// 7. Basic header checks (forward path requires a usable From header)
		if (empty($parsed['from'])) {
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_REJECTED, $envelope_recipient, null, 'Missing From header', $domain->key);
			return 0;
		}

		// 8. Forward
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

		return 0;
	}

	/**
	 * Pure-store path: persist the message and return success/temp-fail.
	 * Used by alias-store mode AND domain catch-all-store mode (alias=null).
	 *
	 * Exit code: 0 on success or successful dedup; 75 on transient DB
	 * failure OR when the per-domain volume cap is reached, so the sender
	 * retries (the cap defers, it never drops). The dedup mechanism is the
	 * UNIQUE constraint on (iem_message_id_header, iem_recipient).
	 */
	private function handleStoreOnly($parsed, $raw_email, $envelope_recipient, $domain, $alias, $auth = null, $content_spam = null) {
		if ($auth === null) {
			$auth = $this->readAuthResults($raw_email);
		}

		// Volume cap (per-domain stores within forwarding window). The cap
		// throttles, it never drops: over the cap we temp-fail (75) so the
		// sender retries and the message stores once the window rolls. A
		// sustained over-cap flood bounces at the sender after its retry window
		// (sender is informed) — we never silently lose a message. Logging is
		// throttled to at most one row per domain per window so the retries do
		// not spam the transaction log (the transient-DB path below stays
		// silent for the same reason).
		$cap = intval($this->settings->get_setting('mailbox_max_per_window'));
		if ($cap > 0) {
			$window = intval($this->settings->get_setting('mailbox_forwarding_rate_limit_window')) ?: 3600;
			$count = $this->countStoresInWindow($domain->key, $window);
			if ($count >= $cap) {
				if (!$this->storeCapLoggedInWindow($domain->key, $window)) {
					$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_STORE_CAPPED, $envelope_recipient, null, 'Store volume cap reached (' . $cap . '); deferring for retry', $domain->key);
				}
				return 75;
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
		} catch (MailboxSealTargetMissing $e) {
			// A protected mailbox with nobody to seal to. Deferring is right (the
			// sender retries and the mail lands once it is repaired), but unlike a
			// transient DB blip this will not fix itself, and the sender's queue
			// expires in hours to days — so it IS logged, loudly, with the reason.
			// The same condition raises the sealing_mailbox_holders health check.
			error_log('InboundEmailRouter: ' . $e->getMessage());
			$this->logTransaction($parsed, $alias, InboundEmailLog::STATUS_ERROR,
				$envelope_recipient, null, $e->getMessage(), $domain->key);
			return 75;
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
	 *
	 * $options tunes the store for callers that are not live delivery:
	 *   run_filters   (bool, default true)  run the inbound filters after the store
	 *   import_run_id (?int, default null)  stamp the archive-import run that created
	 *                                       the row, which is what Undo reverses
	 *   direction     (?string)             'outbound' for a message the user sent,
	 *                                       recovered from an archive's Sent folder
	 *   received_time (?string)             UTC 'Y-m-d H:i:s' from the message's own
	 *                                       Date header, so imported mail sorts by
	 *                                       when it was sent, not when it was read in
	 *   is_read / is_starred / is_archived (bool) source state carried across
	 */
	public function storeMessage($raw_email, $parsed, $alias, $domain, $envelope_recipient, $auth = null, $content_spam = null, array $options = array()) {
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
		$subject = substr($this->decodeMimeHeader($subject_raw), 0, 4000);
		$sender = $this->senderDisplayString($parsed);

		// Conversation grouping for the Mailbox Reader. Computed in-memory from
		// the already-parsed In-Reply-To / References headers — the raw headers
		// themselves are not persisted (recoverable from iem_raw_message).
		$thread_key = $this->computeThreadKey($parsed, $message_id_header);

		// Encryption at rest (specs/implemented/inbound_email_encryption_at_rest.md
		// § 4.1), resolved BEFORE the insert: whose key this seals to and, if they
		// hold a Sealed Vault, the key material. A sealing row is built with EMPTY
		// content columns from the start — no plaintext is ever written, even
		// transiently.
		//
		// Attachment ownership and SEALING ownership are different questions and
		// must not share an answer: mail with no mailbox seals to the DOMAIN owner
		// (specs/mailbox_unmatched_sealing.md), while its attachment Files keep the
		// system ownership they have always had.
		$owner_id = $this->attachmentOwnerId($alias);
		// Posture decides (specs/mailbox_security_levels.md § Level → mechanism-
		// branch switch): a Standard mailbox stores plaintext even when its owner
		// holds a vault, and a sealing mailbox with no key DECLINES the message
		// rather than quietly writing it in the clear — the throw unwinds before
		// any row exists, and every caller treats it as "try again later"
		// (specs/mailbox_connect_flow.md § E).
		$seal = $this->resolveSealTarget($alias, $domain);
		$sealing = $seal['sealing'];
		$vault = $seal['vault'];

		// A composed row (an archive's Sent mail arrives as one) treats iem_recipient
		// as CONTENT, not as the routing address — that is what decryptSealedField
		// does with it — so on a sealing mailbox it has to go through the seal like
		// the body. Live delivery never hits this: its rows are always inbound.
		$direction = (string)($options['direction'] ?? 'inbound');
		$seal_recipient = ($sealing && InboundEmailMessage::isComposedDirection($direction));
		$recipient_value = substr((string)$envelope_recipient, 0, 500);

		$row = [
			'iem_ied_inbound_email_domain_id' => $domain->key,
			'iem_iea_inbound_email_alias_id'  => $alias ? $alias->key : null,
			'iem_sender'      => $sealing ? '' : $sender,
			'iem_recipient'   => $recipient_value,
			'iem_subject'     => $sealing ? '' : $subject,
			'iem_body_plain'  => $sealing ? '' : $bodies['plain'],
			'iem_body_html'   => $sealing ? '' : $bodies['html'],
			'iem_raw_message' => '', // raw goes to the store after insert (see below)
			'iem_message_id_header' => $message_id_header,
			'iem_thread_key'  => $thread_key,
			'iem_direction'   => $direction,
			'iem_dkim_result'  => $auth['dkim'],
			'iem_spf_result'   => $auth['spf'],
			'iem_dmarc_result' => $auth['dmarc'],
			'iem_auth_source'  => $auth['source'],
			'iem_spam_verdict' => $this->classifySpam($auth, $content_spam['signal']),
			'iem_spam_score'   => $content_spam['score'],
			'iem_size_bytes'  => strlen($raw_email),
			// Imported mail carries its own Date header, so a decade-old message sorts
			// where it belongs rather than all of them landing at the import's clock.
			'iem_received_time' => (string)($options['received_time'] ?? '') !== ''
				? $options['received_time'] : gmdate('Y-m-d H:i:s'),
		];

		// Source state an importer carries across, and the run tag that makes the
		// import reversible. All absent for live delivery, which wants the defaults.
		if (!empty($options['is_read']))     { $row['iem_is_read'] = true; }
		if (!empty($options['is_starred']))  { $row['iem_is_starred'] = true; }
		if (!empty($options['is_archived'])) { $row['iem_is_archived'] = true; }
		if (!empty($options['import_run_id'])) {
			$row['iem_mir_mail_import_run_id'] = intval($options['import_run_id']);
		}

		// The whole store — the row insert, the content seal (when sealing), and
		// the attachment/raw persistence — is ONE transaction, committed only
		// once the message is fully materialized (specs/mailbox_data_loss_fixes.md,
		// Fix 4). If the process dies before the commit (kill, OOM, deploy
		// restart, power loss), the entire unit rolls back — no row — so the
		// sender's retry rebuilds from a clean slate. This is what makes the dedup
		// short-circuit safe: a committed row ALWAYS carries its attachments, so a
		// 23505 dedup hit genuinely means "fully stored" and can never discard a
		// retry that would have repaired a bare, attachment-less row (on the
		// sealed lean-record path the attachment Files are the only copy, so that
		// window was real content loss).
		//
		// A sealing row is doubly protected: its empty-content insert must never
		// survive a seal failure either, or the retry's dedup hit would report
		// success on a permanently empty message.
		$db = DbConnector::get_instance()->get_db_link();
		$owns_tx = !$db->inTransaction();
		if ($owns_tx) {
			$db->beginTransaction();
		}

		try {
			$msg = InboundEmailMessage::CreateEntry($row);
		} catch (\Throwable $e) {
			if ($owns_tx && $db->inTransaction()) {
				$db->rollBack();
			}
			// Dedup: the message is already stored, so treat this as a successful
			// retry. Rolled back before any file was written, so no stored object is
			// orphaned.
			//
			// It surfaces TWO ways, and both count. SystemBase::save() pre-validates
			// the unique_with (message-id, recipient, direction) and throws a
			// DisplayableUserException; a concurrent insert instead trips the database
			// UNIQUE (SQLSTATE 23505, sometimes wrapped). Recognising only the second
			// left the first as an unhandled failure — the same pairing storeExtracted
			// already uses.
			if ($this->isUniqueViolation($e)
					|| $this->duplicateMessageExists($message_id_header, $row['iem_recipient'], $direction)) {
				// Local bytes win (specs/mailbox_attachment_byte_custody.md): this
				// path is holding the message's real bytes. If the copy already
				// stored is only a reference to a source mailbox — an alias
				// delivered over SMTP while also fed over IMAP, or an archive
				// import deduping against another mailbox's IMAP row — hand the
				// bytes over before reporting the dedup. Never throws, and a
				// no-op for a self-contained copy.
				$dup_id = $this->duplicateMessageId($message_id_header, $row['iem_recipient'], $direction);
				if ($dup_id !== null) {
					AttachmentByteCustody::adopt($dup_id, $raw_email, $this);
				}
				return ['message' => null, 'dedup' => true];
			}
			throw $e;
		}

		try {
			// With key material: seal the content columns now the row has its
			// serial id (the AD row-binding needs it) and UPDATE. $dek carries
			// through to attachment sealing (persistRawAndManifest below) — one DEK
			// seals the whole message, body and attachments alike.
			$dek = null;
			if ($sealing) {
				$dek = $this->sealMessageContent(intval($msg->key), $vault, $sender, $subject,
					$bodies['plain'], $bodies['html'], $seal_recipient ? $recipient_value : null);
			}

			// Row inserted (we now have the serial id): split attachments into
			// private Files and store the lean record (or fall back to raw storage
			// on failure). Runs INSIDE the transaction so the row and its
			// attachments/raw commit together — never a bare row.
			$this->persistRawAndManifest(intval($msg->key), $raw_email, $alias, $dek);

			if ($owns_tx) {
				$db->commit();
			}
		} catch (\Throwable $e) {
			if ($owns_tx && $db->inTransaction()) {
				$db->rollBack();
			}
			// handleStoreOnly / the forward path returns 75 so the sender retries;
			// no half-materialized row survives to poison dedup. Attachment File
			// bytes / any local .eml already written are orphaned on disk —
			// reclaimable garbage, never data loss.
			throw $e;
		}

		// Inbound filters (specs/implemented/inbound_email_filters.md). storeMessage
		// is the single local-only post-persist point, reached by every locally-
		// received path (Postfix milter + provider webhook) — so this one call runs
		// filters identically for all of them.
		//
		// Two ingest paths are exempt, for the same reason: the mail did not just
		// arrive. IMAP-polled mail never reaches here (it uses storeExtracted), and
		// archive imports pass run_filters => false. An archive already reflects
		// whatever filtering its source applied, and running years-old mail through
		// live rules would fire forwards and notifications for messages nobody
		// received today (specs/mail_archive_import.md § Deliberately not doing).
		//
		// It runs AFTER the spam verdict is set, so a filter's
		// never_spam/mark_spam is the last word on disposition. Best-effort: a filter
		// failure is logged but never aborts ingest (the message is already stored).
		//
		// Filters match on the plaintext this method already has in hand, NEVER on
		// $msg's own columns (specs/implemented/inbound_email_encryption_at_rest.md
		// § 4.2): ingest runs with no unlock window, so a sealed row's iem_sender/
		// iem_subject/iem_body_* would raise VaultLockedException on read.
		if (!array_key_exists('run_filters', $options) || $options['run_filters']) {
			try {
				require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_filter_class.php'));
				InboundEmailFilter::runForMessage($msg, $parsed, $alias, [
					'sender' => $sender, 'subject' => $subject,
					'body_plain' => $bodies['plain'], 'body_html' => $bodies['html'],
				]);
			} catch (\Throwable $e) {
				error_log('InboundEmailRouter: inbound filter run failed for message ' . $msg->key . ': ' . $e->getMessage());
			}
		}

		return ['message' => $msg, 'dedup' => false];
	}

	/**
	 * Deferred ingest (specs/inbound_email_hardened_ingest_relay_executor.md § Phase 5).
	 *
	 * A Fortress message from the hardened relay lands PENDING-PARSE: the pull
	 * consumer stored operational metadata + the whole raw message sealed to the
	 * owner's vault public key (iem_relay_sealed_raw, a crypto_box_seal blob),
	 * but subject/sender/body/attachments do not exist yet. This runs at the next
	 * unlock (the owner's vault secret is in hand), unseals that blob, and folds
	 * the message into its existing row through the SAME pipeline receive-time
	 * ingest uses — parse, seal fields under a fresh per-message DEK, split
	 * attachments, run filters — then clears the pending state. The message row's
	 * identity (id, thread key, unread state) is preserved; only the parsed
	 * content is added.
	 *
	 * $secret_key is the in-window vault secret for the row's sealed owner. Returns
	 * true when the row was parsed, false when there was nothing to do (already
	 * parsed, or no sealed blob). Throws only on a genuine crypto/parse failure so
	 * the caller can leave the row pending and retry at the next unlock.
	 */
	public function parsePendingMessage(InboundEmailMessage $msg, string $secret_key): bool {
		if (!$msg->get('iem_pending_parse')) {
			return false;
		}
		$sealed_raw = (string)$msg->get('iem_relay_sealed_raw');
		if ($sealed_raw === '') {
			// Pending flag with no blob is an inconsistent row; clear the flag so
			// it stops being retried forever, but surface nothing to parse. Targeted
			// UPDATE — a full save() would clobber any sealed columns.
			InboundEmailMessage::updateColumns(intval($msg->key), array('iem_pending_parse' => false));
			return false;
		}

		// The named non-arming open, deliberately: this blob is mail held in
		// transit, and opening it is delivery arriving late — the same plaintext
		// receive-time ingest holds cold for the same message on any server. It
		// is NOT a read of stored sealed content, which is why the hot-turn rule
		// stays off here and only here. See the method's contract.
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
		$raw = (new VaultCrypto())->openHeldDeliveryBlob($sealed_raw, $secret_key);

		$parsed = $this->parseEmail($raw);
		$bodies = $this->extractBodies($raw, $parsed);
		$subject = substr($this->decodeMimeHeader($parsed['subject'] ?? ''), 0, 4000);
		$sender  = $this->senderDisplayString($parsed);

		$owner_id = intval($msg->get('iem_sealed_owner_user_id'));
		$vault = ($owner_id > 0) ? $this->loadOwnerVault($owner_id) : null;
		if ($vault === null) {
			// A Fortress row must have a vault owner; without one there is no key
			// to seal to. Leave pending — the owner may still be enrolling.
			throw new \RuntimeException('parsePendingMessage: no vault for owner ' . $owner_id . ' on message ' . $msg->key);
		}

		$alias = null;
		$alias_id = $msg->get('iem_iea_inbound_email_alias_id');
		if ($alias_id) {
			require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
			try { $alias = new InboundEmailAlias(intval($alias_id), TRUE); } catch (\Throwable $e) { $alias = null; }
		}

		// Seal the content columns under a fresh DEK and UPDATE the row (the same
		// helper receive-time ingest uses), then split attachments under that DEK.
		$dek = $this->sealMessageContent(intval($msg->key), $vault, $sender, $subject, $bodies['plain'], $bodies['html']);
		$this->persistRawAndManifest(intval($msg->key), $raw, $alias, $dek);

		// Content-spam classification now runs on the parsed plaintext, exactly as
		// storeMessage does — the relay stamps X-Spam inside the sealed raw, and the
		// auth verdicts were stored at pull time. Reuse the row's stored verdicts as
		// the auth signal so classifySpam sees the same inputs receive-time ingest
		// would. (specs/mailbox_relay_fix_pack.md § Fix 8.)
		$auth = array(
			'dkim'   => (string)$msg->get('iem_dkim_result'),
			'spf'    => (string)$msg->get('iem_spf_result'),
			'dmarc'  => (string)$msg->get('iem_dmarc_result'),
			'source' => (string)$msg->get('iem_auth_source'),
		);
		$content_spam = $this->resolveContentSpam($raw);
		$spam_verdict = $this->classifySpam($auth, $content_spam['signal']);

		// Clear the pending state, discard the sealed raw blob, and record the spam
		// verdict/score — all via a TARGETED update so the sealed content columns
		// just written behind the model's back are never clobbered by a full save().
		InboundEmailMessage::updateColumns(intval($msg->key), array(
			'iem_pending_parse'    => false,
			'iem_relay_sealed_raw' => null,
			'iem_spam_verdict'     => $spam_verdict,
			'iem_spam_score'       => $content_spam['score'],
		));

		// Reload the row so the filter run sees the fully-parsed, sealed state (never
		// the stale pre-parse object, which would also re-save it). Filters match on
		// the plaintext in hand, never on the row's now-sealed columns.
		try {
			$fresh = new InboundEmailMessage(intval($msg->key), TRUE);
			require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_filter_class.php'));
			InboundEmailFilter::runForMessage($fresh, $parsed, $alias, [
				'sender' => $sender, 'subject' => $subject,
				'body_plain' => $bodies['plain'], 'body_html' => $bodies['html'],
			]);
		} catch (\Throwable $e) {
			error_log('InboundEmailRouter: deferred-ingest filter run failed for message ' . $msg->key . ': ' . $e->getMessage());
		}

		return true;
	}

	/**
	 * Store a pulled Fortress blob as a PENDING-PARSE row (specs/…hardened_ingest_relay §
	 * Phase 5.1). The raw is sealed to the owner's vault and cannot be opened while
	 * logged out, so only operational metadata + the sealed blob are stored now;
	 * threading and unread counts work, and DeferredIngest fills in the content at the
	 * next unlock. Dedup is keyed on the spool id (idempotent re-pull) and, as a
	 * backstop, the message-id unique constraint. $owner_id is the alias's single
	 * grantee (recorded so deferred ingest knows whose vault to unseal with).
	 * $authserv_id is the relay's mail hostname — see authFromRelayMeta().
	 */
	public function storeRelayPending(array $meta, string $sealed_raw, $domain, $alias, int $owner_id, ?string $authserv_id = null): array {
		$recipient = strtolower(trim((string)($meta['recipient'] ?? '')));
		$message_id_header = trim((string)($meta['message_id'] ?? ''));
		$message_id_header = ($message_id_header !== '') ? substr($message_id_header, 0, 255) : null;

		$parsed = array('headers' => array(
			'references'  => (string)($meta['references'] ?? ''),
			'in-reply-to' => (string)($meta['in_reply_to'] ?? ''),
		));
		$thread_key = $this->computeThreadKey($parsed, $message_id_header);
		$auth = $this->authFromRelayMeta($meta, $authserv_id);

		$row = array(
			'iem_ied_inbound_email_domain_id' => $domain->key,
			'iem_iea_inbound_email_alias_id'  => $alias ? $alias->key : null,
			'iem_sender'      => '',
			'iem_recipient'   => substr($recipient, 0, 500),
			'iem_subject'     => '',
			'iem_body_plain'  => '',
			'iem_body_html'   => '',
			'iem_raw_message' => '',
			'iem_message_id_header' => $message_id_header,
			'iem_thread_key'  => $thread_key,
			'iem_dkim_result'  => $auth['dkim'],
			'iem_spf_result'   => $auth['spf'],
			'iem_dmarc_result' => $auth['dmarc'],
			'iem_auth_source'  => $auth['source'],
			'iem_size_bytes'  => intval($meta['size'] ?? 0),
			'iem_received_time' => (string)($meta['received_utc'] ?? gmdate('Y-m-d H:i:s')),
			'iem_pending_parse' => true,
			'iem_relay_sealed_raw' => $sealed_raw,
			'iem_relay_spool_id' => substr((string)($meta['spool_id'] ?? ''), 0, 255),
			'iem_sealed_owner_user_id' => $owner_id > 0 ? $owner_id : null,
		);

		try {
			$saved = InboundEmailMessage::CreateEntry($row);
			return array('message' => $saved, 'dedup' => false);
		} catch (\Throwable $e) {
			if ($this->isUniqueViolation($e) || $this->duplicateMessageExists($message_id_header, $row['iem_recipient'])) {
				return array('message' => null, 'dedup' => true);
			}
			throw $e;
		}
	}

	/**
	 * Store a message that arrived over Joinery Direct (docs/joinery_direct.md).
	 *
	 * Direct receives PARTS, not a MIME document, so this is deliberately not a
	 * detour through raw assembly: the body text, the HTML alternative and each
	 * attachment already arrive in the shape the database wants them, and
	 * reassembling a blob only to take it apart again would throw that away —
	 * along with the property that makes sender-side sealing worth having. Each
	 * part was sealed SEPARATELY to the same recipient key, so unlike PGP/MIME
	 * (which encrypts the whole tree into one opaque object) the structure
	 * survives encryption: attachments land as individual rows, listable and
	 * previewable, without anything having been readable in transit.
	 *
	 * $verified_direct is the gate's outcome, and it decides ELEVATION ONLY,
	 * never placement. A contact's message is elevated past content scoring —
	 * that is what the address book buys. A non-contact's is spam-scored and
	 * filter-sorted exactly as if it had arrived over SMTP, so the direct path
	 * bypasses the spam apparatus for no one; it is simply filed, never bounced
	 * and never returned to the sender.
	 *
	 * $vault_secret_key is present only on the deferred path (the sealed tiers,
	 * at unlock), where it opens the sealed parts. On the live path the parts
	 * arrived plaintext under TLS and it is null.
	 *
	 * @param array $meta  sender, subject, recipient, message_id, references,
	 *                     in_reply_to, received_time
	 * @param array $parts ['body_plain'=>string, 'body_html'=>string,
	 *                      'attachments'=>[['filename','content_type','content_id',
	 *                                       'is_inline','bytes']]]
	 * @return array{message:?InboundEmailMessage,dedup:bool}
	 */
	public function storeDirectMessage(array $meta, array $parts, $alias, $domain, string $envelope_recipient,
			bool $verified_direct): array {
		$sender = substr(trim((string)($meta['sender'] ?? '')), 0, 500);
		$subject = substr((string)($meta['subject'] ?? ''), 0, 4000);
		$body_plain = (string)($parts['body_plain'] ?? '');
		$body_html  = (string)($parts['body_html'] ?? '');
		$attachments = is_array($parts['attachments'] ?? null) ? $parts['attachments'] : array();

		$message_id_header = trim((string)($meta['message_id'] ?? ''));
		$message_id_header = ($message_id_header === '') ? null : substr($message_id_header, 0, 255);
		$thread_key = $this->computeThreadKey(array('headers' => array(
			'references'  => (string)($meta['references'] ?? ''),
			'in-reply-to' => (string)($meta['in_reply_to'] ?? ''),
		)), $message_id_header);

		// The instance signature IS the authentication here, and it is stronger
		// than DKIM/SPF: mandatory, over the exact bytes, and verified against a
		// key the sending domain publishes. The legacy verdict columns have
		// nothing honest to say about a message that never crossed SMTP, so they
		// record 'unverified' and the SOURCE names what actually vouched.
		$auth = array('dkim'=>'unverified', 'spf'=>'unverified', 'dmarc'=>'unverified', 'source'=>'joinery_direct');

		// Consent elevates past scoring; anything else is scored exactly as SMTP
		// mail would be. Sender-sealed content carries no relay-stamped verdict —
		// no machine in the path could read it — so this local scan is the first
		// moment the content is readable, which is inherent to the guarantee.
		$content_spam = $verified_direct
			? array('signal' => 'none', 'score' => null)
			: $this->resolveContentSpam($this->synthesizeRawForScan($meta, $body_plain, $body_html));

		$owner_id = $this->attachmentOwnerId($alias);
		// Identical posture rule and identical refusal to the SMTP store above —
		// a Direct sender gets a retryable error and keeps the message.
		$seal = $this->resolveSealTarget($alias, $domain);
		$sealing = $seal['sealing'];
		$vault = $seal['vault'];

		$size_bytes = strlen($body_plain) + strlen($body_html);
		foreach ($attachments as $attachment) {
			$size_bytes += strlen((string)($attachment['bytes'] ?? ''));
		}

		$row = array(
			'iem_ied_inbound_email_domain_id' => $domain->key,
			'iem_iea_inbound_email_alias_id'  => $alias ? $alias->key : null,
			'iem_sender'      => $sealing ? '' : $sender,
			'iem_recipient'   => substr(strtolower($envelope_recipient), 0, 500),
			'iem_subject'     => $sealing ? '' : $subject,
			'iem_body_plain'  => $sealing ? '' : $body_plain,
			'iem_body_html'   => $sealing ? '' : $body_html,
			'iem_raw_message' => '',
			'iem_message_id_header' => $message_id_header,
			'iem_thread_key'  => $thread_key,
			'iem_direction'   => 'inbound',
			'iem_dkim_result'  => $auth['dkim'],
			'iem_spf_result'   => $auth['spf'],
			'iem_dmarc_result' => $auth['dmarc'],
			'iem_auth_source'  => $auth['source'],
			'iem_spam_verdict' => $this->classifySpam($auth, $content_spam['signal']),
			'iem_spam_score'   => $content_spam['score'],
			'iem_size_bytes'   => $size_bytes,
			'iem_transport'    => 'joinery_direct',
			// The mark is applied by the RECEIVER from verified transport plus
			// contact membership, never from anything in the message, which is
			// what makes it unforgeable from content.
			'iem_direct_verified' => $verified_direct,
			'iem_received_time' => (string)($meta['received_time'] ?? '') !== ''
				? $meta['received_time'] : gmdate('Y-m-d H:i:s'),
		);

		// One transaction, exactly as the SMTP store uses: a committed row always
		// carries its attachments, so a dedup hit genuinely means "fully stored".
		$db = DbConnector::get_instance()->get_db_link();
		$owns_tx = !$db->inTransaction();
		if ($owns_tx) {
			$db->beginTransaction();
		}

		try {
			$msg = InboundEmailMessage::CreateEntry($row);
		} catch (\Throwable $e) {
			if ($owns_tx && $db->inTransaction()) {
				$db->rollBack();
			}
			if ($this->isUniqueViolation($e)
					|| $this->duplicateMessageExists($message_id_header, $row['iem_recipient'], 'inbound')) {
				// Local bytes win (specs/mailbox_attachment_byte_custody.md):
				// Direct delivered the decoded parts, so if the stored copy is an
				// IMAP reference they land as its Files. Never throws, and a
				// no-op for a self-contained copy.
				if (!empty($attachments)) {
					$dup_id = $this->duplicateMessageId($message_id_header, $row['iem_recipient'], 'inbound');
					if ($dup_id !== null) {
						AttachmentByteCustody::adoptParts($dup_id, $attachments, $this);
					}
				}
				$this->logTransaction(array('from' => $sealing ? '' : $sender), $alias,
					InboundEmailLog::STATUS_STORED, $envelope_recipient,
					'duplicate (Message-ID already stored)', null, $domain->key);
				return array('message' => null, 'dedup' => true);
			}
			throw $e;
		}

		try {
			$dek = null;
			if ($sealing) {
				$dek = $this->sealMessageContent(intval($msg->key), $vault, $sender, $subject, $body_plain, $body_html);
			}
			$this->storeDirectAttachments(intval($msg->key), $attachments, $owner_id, $dek);
			if ($owns_tx) {
				$db->commit();
			}
		} catch (\Throwable $e) {
			if ($owns_tx && $db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}

		// Filters match on the plaintext in hand, never on the row's now-sealed
		// columns — ingest runs with no unlock window on the live path.
		try {
			require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_filter_class.php'));
			InboundEmailFilter::runForMessage($msg, array('headers' => array(), 'from' => $sender), $alias, array(
				'sender' => $sender, 'subject' => $subject,
				'body_plain' => $body_plain, 'body_html' => $body_html,
			));
		} catch (\Throwable $e) {
			error_log('InboundEmailRouter: Direct filter run failed for message ' . $msg->key . ': ' . $e->getMessage());
		}

		// A Direct delivery was invisible on the Logs tab; log it exactly as an
		// SMTP store does. The stored MESSAGE ROW is what the per-domain volume cap
		// counts (countStoresInWindow), so a Direct delivery already counts toward
		// that cap; this row makes the delivery itself auditable. The sender is kept
		// out of the log for a sealed message, matching the sealed row.
		$this->logTransaction(array('from' => $sealing ? '' : $sender), $alias,
			InboundEmailLog::STATUS_STORED, $envelope_recipient, null, null, $domain->key);

		return array('message' => $msg, 'dedup' => false);
	}

	/**
	 * Write one attachment row per delivered part, minting a private File for
	 * each — the same lean-record shape the MIME split produces, reached without
	 * a MIME document to split.
	 *
	 * $dek is the message's per-item DEK, non-null only when the body was
	 * sealed; attachments seal under the SAME key, exactly as on the SMTP path.
	 */
	private function storeDirectAttachments(int $message_id, array $attachments, int $owner_id, ?string $dek): void {
		if (empty($attachments)) {
			return;
		}
		$crypto = null;
		if ($dek !== null) {
			require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
			$crypto = new VaultCrypto();
		}

		$created_files = array();
		$rows = array();
		try {
			foreach (array_values($attachments) as $index => $attachment) {
				$bytes = (string)($attachment['bytes'] ?? '');
				$name  = trim((string)($attachment['filename'] ?? ''));
				$name  = $name !== '' ? substr($name, 0, 500) : null;
				$type  = trim((string)($attachment['content_type'] ?? '')) ?: 'application/octet-stream';
				$cid   = trim((string)($attachment['content_id'] ?? ''));
				// The manifest carries a part index rather than a MIME section
				// number; it is the same job — naming which part a row describes.
				$mime_part = 'direct.' . $index;

				$original_size = strlen($bytes);
				if ($crypto !== null) {
					$bytes = $crypto->sealField($bytes, $dek, InboundEmailMessage::attachmentAd($message_id, $mime_part));
				}

				$file = File::createFromBytes(
					$bytes,
					$name !== null ? $name : 'attachment',
					$type,
					$owner_id,
					array('fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT)
				);
				if ($crypto !== null) {
					// The on-disk bytes are ciphertext, so type detection saw noise —
					// restore the real content type for the reader.
					$file->set('fil_type', substr($type, 0, 128));
					$file->save();
				}
				$created_files[] = $file;

				$rows[] = array(
					'ima_iem_inbound_email_message_id' => $message_id,
					'ima_filename'     => $name,
					'ima_content_type' => substr($type, 0, 255),
					'ima_size_bytes'   => $original_size,
					'ima_mime_part'    => substr($mime_part, 0, 40),
					'ima_encoding'     => 'binary', // no base64: parts transfer as bytes
					'ima_content_id'   => $cid !== '' ? substr(trim($cid, '<>'), 0, 255) : null,
					'ima_is_inline'    => !empty($attachment['is_inline']),
					'ima_fil_file_id'  => intval($file->key),
					'ima_is_sealed'    => ($crypto !== null),
				);
			}

			foreach ($rows as $row) {
				InboundMessageAttachment::CreateEntry($row);
			}
		} catch (\Throwable $e) {
			foreach ($created_files as $file) {
				try { $file->permanent_delete(); } catch (\Throwable $ignore) {}
			}
			$this->deleteManifestRows($message_id);
			throw $e;
		}
	}

	/**
	 * A minimal RFC 5322 rendering of a Direct message, for the content spam
	 * scanner only.
	 *
	 * The scanner wants a message; Direct never had one. Nothing here is stored
	 * — the row's fields come from the parts themselves.
	 */
	private function synthesizeRawForScan(array $meta, string $body_plain, string $body_html): string {
		$headers = "From: " . (string)($meta['sender'] ?? '') . "\r\n"
			. "To: " . (string)($meta['recipient'] ?? '') . "\r\n"
			. "Subject: " . (string)($meta['subject'] ?? '') . "\r\n"
			. "MIME-Version: 1.0\r\n";

		// Both bodies must reach the scanner. Spam routinely rides in the HTML —
		// hidden text, link farms, tracking URLs — behind an innocuous plain part,
		// so scanning only the plain body when one exists is an evasion the scanner
		// never gets to see. Present the message as the scanner would receive it off
		// the wire: multipart/alternative with BOTH parts, or the single part that
		// exists, HTML kept as HTML (rspamd reads its structure) rather than flattened.
		if ($body_html !== '' && $body_plain !== '') {
			$boundary = '=_jdscan_' . bin2hex(random_bytes(12));
			return $headers
				. "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"\r\n\r\n"
				. "--" . $boundary . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" . $body_plain . "\r\n"
				. "--" . $boundary . "\r\nContent-Type: text/html; charset=utf-8\r\n\r\n" . $body_html . "\r\n"
				. "--" . $boundary . "--\r\n";
		}
		if ($body_html !== '') {
			return $headers . "Content-Type: text/html; charset=utf-8\r\n\r\n" . $body_html;
		}
		return $headers . "Content-Type: text/plain; charset=utf-8\r\n\r\n" . $body_plain;
	}

	/**
	 * Turn the relay's forwarded Authentication-Results (carried in the .meta
	 * sidecar) into verdict columns — the relay is a trusted verdict source
	 * (specs/…hardened_ingest_relay § Phase 5.3), parsed exactly like the milter
	 * path but tagged source='relay'. Falls back to 'unverified' when the relay
	 * stamped nothing trustworthy.
	 *
	 * $authserv_id is the name whose stamps to trust, and it is the RELAY's mail
	 * hostname — the relay's milters verified this message, so the relay is the
	 * authserv-id on the line worth trusting. It pairs exactly with the relay's
	 * opendkim `RemoveARFrom <relay hostname>` (provision_relay.sh), which strips
	 * sender-supplied lines bearing that same name before the milters stamp: the
	 * one name a forger cannot smuggle in is the one name accepted here.
	 *
	 * Omitting it falls back to this deployment's own mail hostname, which is
	 * correct only where the relay is colocated (the two names are then the same
	 * host). A fronting relay — self-hosted or a fleet slot — always carries its
	 * own name, so callers holding the relay row must pass it; otherwise nothing
	 * ever matches and every relayed message records 'unverified'.
	 *
	 * @param array       $meta        The .meta sidecar the sealer wrote.
	 * @param string|null $authserv_id The relay's mail hostname; null = this box's.
	 */
	public function authFromRelayMeta(array $meta, ?string $authserv_id = null): array {
		$default = array('dkim'=>'unverified','spf'=>'unverified','dmarc'=>'unverified','source'=>'none');

		// The relay records EVERY Authentication-Results header in document order.
		// Milters prepend, so the trusted (milter-stamped) verdicts are the earliest
		// entries; a sender-forged A-R sits below. Walk top-down and take the FIRST
		// verdict per method from a header whose authserv-id is the trusted one —
		// first-wins
		// mirrors the milter prepend and never lets a lower forged line beat it.
		// (specs/mailbox_relay_fix_pack.md § Fix 2.) Back-compat: tolerate a legacy
		// single string.
		$list = $meta['authentication_results'] ?? array();
		if (is_string($list)) {
			$list = ($list === '') ? array() : array($list);
		}
		if (!is_array($list) || empty($list)) {
			return $default;
		}

		$authserv_id = strtolower(trim((string)$authserv_id));
		if ($authserv_id === '') {
			$authserv_id = strtolower(trim((string)$this->settings->get_setting('mailbox_mail_hostname')));
		}
		if ($authserv_id === '') {
			return $default; // nothing to match against; trust nothing
		}
		$verdict = array('dkim'=>null, 'spf'=>null, 'dmarc'=>null);
		$matched = false;

		foreach ($list as $ar) {
			$ar = trim((string)$ar);
			if ($ar === '') {
				continue;
			}
			$synthetic = "Authentication-Results: " . $ar . "\r\n\r\n";
			$parsed = AuthenticationResults::fromMessage($synthetic, $authserv_id);
			if ($parsed === null) {
				continue; // authserv-id mismatch — untrusted (e.g. a forged line)
			}
			$matched = true;
			foreach (array('dkim', 'spf', 'dmarc') as $method) {
				if ($verdict[$method] === null) {
					$val = $parsed->$method();
					if ($val !== null && $val !== '') {
						$verdict[$method] = $val;
					}
				}
			}
			if ($verdict['dkim'] !== null && $verdict['spf'] !== null && $verdict['dmarc'] !== null) {
				break;
			}
		}

		if (!$matched) {
			return $default;
		}
		return array(
			'dkim'   => $verdict['dkim']  ?: 'none',
			'spf'    => $verdict['spf']   ?: 'none',
			'dmarc'  => $verdict['dmarc'] ?: 'none',
			'source' => 'relay',
		);
	}

	/** The owner's Sealed Vault, or null when they have none (never sealed). */
	private function loadOwnerVault(int $owner_id) {
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		return UserEncryptionVault::loadForUser($owner_id);
	}

	/**
	 * Whose key this message seals to, resolved BEFORE any row is written — the
	 * one answer every ingress path uses so they can never disagree about
	 * whether a message is sealed or who holds it.
	 *
	 * Returns ['sealing' => bool, 'vault' => ?UserEncryptionVault,
	 *          'owner_id' => ?int].
	 *
	 * The posture comes from the MAILBOX (specs/mailbox_connect_flow.md § D) —
	 * its own level when it has one, the domain's otherwise — and mail with no
	 * mailbox asks the domain, which is whose mail it is.
	 *
	 * @throws MailboxSealTargetMissing when the mailbox seals but no key resolves.
	 */
	public function resolveSealTarget($alias, $domain): array {
		$alias_id  = ($alias && $alias->key) ? intval($alias->key) : null;
		$domain_id = ($domain && $domain->key) ? intval($domain->key) : null;

		$seals = ($alias_id !== null) ? $alias->seals_content()
			: ($domain && $domain->key && $domain->seals_content());
		if (!$seals) {
			return array('sealing' => false, 'vault' => null, 'owner_id' => null);
		}

		$owner_id = InboundEmailMessage::sealOwnerUserId($alias_id, $domain_id);
		$vault = ($owner_id !== null) ? $this->loadOwnerVault($owner_id) : null;
		if ($vault === null) {
			// Decline rather than downgrade. Naming the mailbox matters: this
			// message is being held, and the operator needs to know which mailbox
			// to repair before the sender's own retry window runs out.
			$where = ($alias_id !== null && $alias->key)
				? $alias->get_full_address()
				: (($domain && $domain->key) ? (string)$domain->get('ied_domain') : 'this mailbox');
			throw new MailboxSealTargetMissing(
				'Refusing to store mail for ' . $where . ' unprotected: it is a protected mailbox with '
				. ($owner_id === null ? 'no single member to seal to' : 'a member who holds no vault')
				. '. The message stays where it is and will be delivered once the mailbox has one member '
				. 'with a vault.');
		}
		return array('sealing' => true, 'vault' => $vault, 'owner_id' => $owner_id);
	}

	/**
	 * Seal a just-inserted message's content columns and UPDATE the row.
	 * Returns the per-message DEK (raw bytes) so the caller can also seal this
	 * message's attachments under the SAME key.
	 */
	private function sealMessageContent(int $message_id, $vault, string $sender, string $subject, string $body_plain, string $body_html, ?string $recipient = null): string {
		// On an INBOUND row iem_recipient is the receiving alias address — routing
		// metadata, not content — so it stays cleartext exactly as storeMessage wrote
		// it at insert. $recipient is non-null only for a COMPOSED row (imported Sent
		// mail), where the address list is genuinely content and the read path expects
		// ciphertext.
		return InboundEmailMessage::sealAndPersistContent($message_id, $vault, $sender,
			(string)$recipient, $subject, $body_plain, $body_html, $recipient !== null);
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
	 *
	 * $dek: the message's per-item DEK (raw bytes), non-null only when
	 * sealMessageContent() sealed the message body — attachments seal under
	 * the SAME key (specs/implemented/inbound_email_encryption_at_rest.md § 5.1).
	 * A sealed message's raw fallback is itself SEALED (one AEAD blob under the
	 * DEK, iem_raw_sealed = true) before it reaches the raw store — the raw
	 * path never writes a sealed mailbox's plaintext to disk, and the message
	 * keeps the same durability a plaintext mailbox gets.
	 */
	protected function persistRawAndManifest(int $message_id, string $raw_email, $alias = null, ?string $dek = null) {
		try {
			$this->extractAttachmentsToFiles($message_id, $raw_email, $alias, $dek);
			return; // lean record: Files written, manifest linked, no raw retained
		} catch (\Throwable $e) {
			// extractAttachmentsToFiles() rolled back its own Files + manifest rows.
			error_log('INBOUND_ATTACHMENT_EXTRACTION_FAILED message_id=' . $message_id
				. ' (falling back to raw storage): ' . $e->getMessage());
		}

		if ($dek !== null) {
			// Sealed message, extraction failed: preserve the raw WITHOUT leaking
			// plaintext by sealing the whole RFC822 as one AEAD blob under the
			// same message DEK before it hits the raw store. The section-pointer
			// manifest is parsed from the in-memory plaintext as usual;
			// getRawMessage() opens the blob in-window (iem_raw_sealed), so the
			// download/forward paths keep working exactly like the plaintext
			// fallback — just gated on the owner's unlock window. The sealed
			// mailbox loses nothing a plaintext mailbox would have kept.
			error_log('INBOUND_SEALED_ATTACHMENT_EXTRACTION_FAILED message_id=' . $message_id
				. ' — falling back to SEALED raw storage.');
			require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
			$crypto = new VaultCrypto();
			$sealed_raw = $crypto->sealField($raw_email, $dek, InboundEmailMessage::rawAd($message_id));
			$this->persistRawFallback($message_id, $sealed_raw);
			DbConnector::get_instance()->get_db_link()
				->prepare('UPDATE iem_inbound_email_messages SET iem_raw_sealed = true WHERE iem_inbound_email_message_id = ?')
				->execute([$message_id]);
			try {
				$this->writeManifestFromRaw($message_id, $raw_email);
			} catch (\Throwable $e) {
				error_log('InboundEmailRouter: attachment manifest write failed for message ' . $message_id . ': ' . $e->getMessage());
			}
			return;
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
	private function extractAttachmentsToFiles(int $message_id, string $raw_email, $alias, ?string $dek = null) {
		$owner_id = $this->attachmentOwnerId($alias);
		$parts    = $this->enumerateNonTextParts($raw_email);
		$crypto   = null;
		if ($dek !== null) {
			require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
			$crypto = new VaultCrypto();
		}

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
				$mime_part = (string)$part->getMimeId();

				// Encryption at rest: with a DEK in hand, seal this part's bytes
				// under it before the File is written — the File then stores
				// ciphertext, and fil_source is the marker the decrypt hook
				// (plugins/mailbox/includes/bootstrap.php) keys on.
				$original_size = strlen($bytes);
				if ($crypto !== null) {
					$bytes = $crypto->sealField($bytes, $dek, InboundEmailMessage::attachmentAd($message_id, $mime_part));
				}

				// No resize()/variants — email attachments are served as their
				// original; skipping resize is exactly the small-VPS relief.
				$file = File::createFromBytes(
					$bytes,
					$name !== null ? $name : 'attachment',
					$type,
					$owner_id,
					array('fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT)
				);
				if ($crypto !== null) {
					// createFromBytes()/save() detects fil_type from the on-disk bytes,
					// which are now ciphertext (never a recognizable magic-byte
					// signature) — restore the real content-type the caller supplied,
					// so the reader shows the correct type once the hook decrypts.
					$file->set('fil_type', substr($type, 0, 128));
					$file->save();
				}
				$created_files[] = $file;

				$rows[] = array(
					'ima_iem_inbound_email_message_id' => $message_id,
					'ima_filename'     => $name,
					'ima_content_type' => substr($type, 0, 255),
					'ima_size_bytes'   => $original_size,
					'ima_mime_part'    => substr((string)$part->getMimeId(), 0, 40),
					'ima_encoding'     => substr($this->partTransferEncoding($part), 0, 40),
					'ima_content_id'   => $cid ? substr(trim($cid, '<>'), 0, 255) : null,
					'ima_is_inline'    => $isInline,
					'ima_fil_file_id'  => intval($file->key),
					// Per-file sealed state — every reader of the File bytes keys
					// on this (InboundEmailMessage::openSealedAttachment).
					'ima_is_sealed'    => ($crypto !== null),
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
	 *
	 * Public for the same reason enumerateNonTextParts() is: a File adopted onto
	 * an existing message later (specs/mailbox_attachment_byte_custody.md) has to
	 * be visible to exactly the same people as one the receive path minted, and
	 * two copies of this rule would eventually disagree.
	 */
	public function attachmentOwnerId($alias): int {
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
	 * Public because the byte-custody upgrade
	 * (specs/mailbox_attachment_byte_custody.md) matches an archive's parts
	 * against manifest rows written from an IMAP BODYSTRUCTURE, and "attachment"
	 * has to mean the same thing on both paths or the two would disagree about
	 * which parts even exist.
	 *
	 * @return Horde_Mime_Part[]
	 */
	public function enumerateNonTextParts(string $raw_email): array {
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
	 * Pre-launch backfill (specs/implemented/inbound_email_encryption_at_rest.md
	 * § 9), called from logic/mailbox_backfill_seal_logic.php: re-split a
	 * still-raw message's attachments into SEALED Files under $dek. Deletes any
	 * section-pointer manifest rows the original ingest wrote (the raw-fallback
	 * shape) first, so re-extraction never duplicates the attachment list.
	 */
	public function resealBackfillAttachments(int $message_id, string $raw_email, string $dek): void {
		$this->deleteManifestRows($message_id);
		$msg = new InboundEmailMessage($message_id, TRUE);
		$alias_id = $msg->get('iem_iea_inbound_email_alias_id');
		$alias = $alias_id ? new InboundEmailAlias(intval($alias_id), TRUE) : null;
		$this->extractAttachmentsToFiles($message_id, $raw_email, $alias, $dek);
	}

	/**
	 * Pre-launch backfill: destroy a message's raw once its content and
	 * attachments are sealed — not marked done until the raw is actually gone
	 * (specs/implemented/inbound_email_encryption_at_rest.md § 9).
	 */
	public function destroyRawAfterBackfill(int $message_id): void {
		$msg = new InboundEmailMessage($message_id, TRUE);
		$driver = (string)$msg->get('iem_raw_storage_driver');
		$key = (string)$msg->get('iem_raw_storage_key');
		if ($driver === 'local' || $driver === 'cloud') {
			try {
				require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RawMessageStore.php'));
				RawMessageStore::delete($driver, $key);
			} catch (\Throwable $e) {
				error_log('InboundEmailRouter: backfill raw reclaim failed for message ' . $message_id . ': ' . $e->getMessage());
			}
		}
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare(
			"UPDATE iem_inbound_email_messages
			 SET iem_raw_message = '', iem_raw_storage_driver = 'inline', iem_raw_storage_key = NULL
			 WHERE iem_inbound_email_message_id = ?")
			->execute([$message_id]);
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

		// Pulled-in mail seals exactly like pushed mail (specs/mailbox_connect_flow.md
		// § D): the posture is the MAILBOX's, since gmail.com is somebody else's
		// domain and two people pulling their own Gmail into one deployment must be
		// able to differ. A sealing mailbox with no key declines the message — the
		// throw leaves it on the source and the feed reports why it stopped, which
		// costs nothing because the mail is still in the remote mailbox.
		$seal = $this->resolveSealTarget($alias, $domain);
		$sealing = $seal['sealing'];

		$sender  = substr((string)($msg['sender'] ?? ''), 0, 500);
		$subject = substr((string)($msg['subject'] ?? ''), 0, 1000);
		$plain   = (string)($msg['body_plain'] ?? '');
		$html    = (string)($msg['body_html'] ?? '');

		$row = array(
			'iem_ied_inbound_email_domain_id' => $domain->key,
			'iem_iea_inbound_email_alias_id'  => $alias ? $alias->key : null,
			'iem_sender'      => $sealing ? '' : $sender,
			'iem_recipient'   => substr((string)$envelope_recipient, 0, 500),
			'iem_subject'     => $sealing ? '' : $subject,
			'iem_body_plain'  => $sealing ? '' : $plain,
			'iem_body_html'   => $sealing ? '' : $html,
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
		} catch (\Throwable $e) {
			// Dedup surfaces two ways, and the difference matters because the caller
			// now stores the message and its manifest in one transaction (D1,
			// specs/mail_import_loss_proof.md).
			//
			// A DATABASE-raised unique violation (SQLSTATE 23505) aborts the whole
			// Postgres transaction: every later statement fails until it unwinds,
			// including the "does the row exist?" question below. So it cannot be
			// answered here and the message must not be reported as stored. Let it
			// out instead — the caller rolls its message unit back, and the retry
			// resolves the same collision through the pre-validate path, which reads
			// cleanly because it runs before any INSERT. A savepoint would preserve
			// the old catch-and-continue, but at the cost of the guarantee the
			// transaction exists to give.
			if ($this->isUniqueViolation($e)) {
				throw new InboundStoreCollisionException(
					'Another process stored this message first; it resolves on the next pass.', 0, $e);
			}
			// SystemBase::save() pre-validates the unique_with
			// (iem_message_id_header, iem_recipient) and throws a
			// DisplayableUserException before attempting the INSERT, so nothing has
			// aborted and the row genuinely exists: an ordinary dedup.
			if ($this->duplicateMessageExists($message_id_header, $row['iem_recipient'])) {
				return array('message' => null, 'dedup' => true);
			}
			throw $e;
		}

		// Seal outside the insert's catch: the row now exists, so a seal failure
		// caught there would find its own row and report a false dedup. Sealing
		// happens once the row has its serial id (the AD binds to it), inside the
		// ingestor's per-message transaction — a seal failure rolls the row back
		// with it and the next poll retries, so a permanently empty row can never
		// be reported as stored.
		if ($sealing) {
			$this->sealMessageContent(intval($saved->key), $seal['vault'],
				$sender, $subject, $plain, $html);
		}
		return array('message' => $saved, 'dedup' => false);
	}

	/**
	 * True if a row with this (message-id, recipient) already exists — the unique
	 * key, which is deliberately mailbox-agnostic: a message is stored once.
	 *
	 * $direction narrows it to the full key when the caller knows it. Omitting it
	 * matches the IMAP path's older, looser question, which is right there because
	 * that path only ever stores inbound.
	 */
	private function duplicateMessageExists(?string $message_id_header, string $recipient, ?string $direction = null): bool {
		return $this->duplicateMessageId($message_id_header, $recipient, $direction) !== null;
	}

	/**
	 * The id of an already-stored message with this (message-id, recipient[,
	 * direction]) identity, or null. The dedup paths use it to find the row a
	 * unique violation collided with — the copy that may be about to receive
	 * this delivery's bytes (AttachmentByteCustody).
	 *
	 * Public because the archive importer asks the same question for a different
	 * reason: to name, on the entry it is about to mark as a duplicate, WHICH
	 * message it duplicated (D2, specs/mail_import_loss_proof.md). Both callers
	 * must resolve the collision the same way or the ledger would disagree with
	 * the store about what happened.
	 */
	public function duplicateMessageId(?string $message_id_header, string $recipient, ?string $direction = null): ?int {
		if ($message_id_header === null || $message_id_header === '') {
			return null;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			 WHERE iem_message_id_header = ? AND iem_recipient = ?";
		$params = array($message_id_header, $recipient);
		if ($direction !== null) {
			$sql .= ' AND iem_direction = ?';
			$params[] = $direction;
		}
		$stmt = $db->prepare($sql . ' LIMIT 1');
		$stmt->execute($params);
		$id = $stmt->fetchColumn();
		return $id === false ? null : intval($id);
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
	 * Materialize a reference-backed ('remote') message into a self-contained
	 * local copy (specs/mailbox_data_loss_fixes.md, Fix 8): fetch the full RFC822
	 * from IMAP while the account is still connected, split its attachments into
	 * private Files (a lean record, exactly like the Postfix store), and drop the
	 * IMAP locator so the message stays fully functional after its account is
	 * deleted. The plaintext bodies are already stored on the row, so only the
	 * on-demand parts need to be brought local.
	 *
	 * The caller opens the ImapIngestor once and reuses it across the batch, then
	 * close()s it. Returns ['ok'=>bool, 'message'=>?string].
	 */
	public function materializeRemoteMessage(InboundEmailMessage $message, ImapIngestor $ingestor): array {
		if ((string)$message->get('iem_raw_storage_driver') !== 'remote') {
			return array('ok' => true, 'message' => 'already self-contained'); // idempotent no-op
		}
		if ((bool)$message->get('iem_content_sealed')) {
			// Reference-backed rows are stored plaintext; a sealed one is
			// unexpected and would need the owner's DEK to re-seal attachments.
			// Refuse rather than mishandle — surfaced to the caller as a failure.
			return array('ok' => false, 'message' => 'a sealed reference-backed message cannot be materialized automatically');
		}

		$uid = intval($message->get('iem_imap_uid'));
		$uidvalidity = ($message->get('iem_imap_uidvalidity') !== null && $message->get('iem_imap_uidvalidity') !== '')
			? intval($message->get('iem_imap_uidvalidity')) : null;
		$folder = (string)$message->get('iem_imap_folder');
		$messageId = trim((string)$message->get('iem_message_id_header'));

		$fetched = $ingestor->fetchFullRaw($uid, $uidvalidity, $folder, $messageId !== '' ? $messageId : null);
		if (empty($fetched['ok'])) {
			return array('ok' => false, 'message' => $fetched['message'] ?? 'could not fetch the message from the source mailbox');
		}
		$raw = (string)$fetched['raw'];
		$message_id = intval($message->key);

		$alias_id = intval($message->get('iem_iea_inbound_email_alias_id'));
		$alias = $alias_id > 0 ? new InboundEmailAlias($alias_id, TRUE) : null;
		if ($alias && !$alias->key) { $alias = null; }

		$db = DbConnector::get_instance()->get_db_link();
		$owns_tx = !$db->inTransaction();
		if ($owns_tx) { $db->beginTransaction(); }
		try {
			// Drop the reference-backed manifest rows (no Files) before re-persisting
			// the same parts as file-backed attachments.
			$this->deleteManifestRows($message_id);
			// Lean split — plaintext Files + manifest (dek=null: remote rows are plaintext).
			$this->persistRawAndManifest($message_id, $raw, $alias, null);
			// Flip the row to a self-contained lean record and drop the IMAP locator.
			// The lean path leaves the driver as-is, so 'remote' → 'inline'; a
			// raw-storage fallback already set its own driver, so leave that alone.
			$db->prepare(
				"UPDATE iem_inbound_email_messages
				 SET iem_raw_storage_driver = CASE WHEN iem_raw_storage_driver = 'remote' THEN 'inline' ELSE iem_raw_storage_driver END,
					 iem_iia_inbound_imap_account_id = NULL,
					 iem_imap_uid = NULL, iem_imap_uidvalidity = NULL, iem_imap_folder = NULL
				 WHERE iem_inbound_email_message_id = ?"
			)->execute(array($message_id));
			if ($owns_tx) { $db->commit(); }
		} catch (\Throwable $e) {
			if ($owns_tx && $db->inTransaction()) { $db->rollBack(); }
			error_log('InboundEmailRouter::materializeRemoteMessage failed for message ' . $message_id . ': ' . $e->getMessage());
			return array('ok' => false, 'message' => $e->getMessage());
		}
		return array('ok' => true);
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
	 * True if a store_capped transaction was already logged for this domain
	 * within the current window. Used to throttle the deferred-store log so a
	 * sustained over-cap burst (each message retried repeatedly by the sender)
	 * records at most one store_capped row per domain per window.
	 */
	private function storeCapLoggedInWindow($domain_id, $window_seconds) {
		$db = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT 1 FROM iel_inbound_email_logs
				WHERE iel_ied_inbound_email_domain_id = ?
				AND iel_status = ?
				AND iel_delete_time IS NULL
				AND iel_create_time > NOW() - INTERVAL '" . intval($window_seconds) . " seconds'
				LIMIT 1";
		$stmt = $db->prepare($sql);
		$stmt->execute([$domain_id, InboundEmailLog::STATUS_STORE_CAPPED]);
		return $stmt->fetchColumn() !== false;
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

		// Extract plain email from From header (may contain "Name <email>").
		// The addr-spec is the LAST angle-addr, not the first: a display name is
		// allowed to be a quoted string, so a sender can put angle brackets inside
		// it — From: "Support <billing@paypal.com>" <thief@evil.example> is a valid
		// header whose real address is thief@evil.example. Taking the first match
		// hands that sender the address of their choice for iem_sender, the reply
		// address, the contact lookup, filter matching and the SRS envelope.
		$from_email = $from;
		if (preg_match_all('/<([^<>]*)>/', $from, $mm) && count($mm[1])) {
			$last = trim((string)end($mm[1]));
			if ($last !== '') {
				$from_email = $last;
			}
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
		try {
			$raw = $msg->getRawMessage();
		} catch (VaultLockedException $e) {
			$raw = null; // sealed raw and no open window — treated as unavailable below
		}
		if ($raw === null || $raw === '') {
			// A Joinery Direct delivery never became a MIME document (and a lean
			// record retains no raw), so there is nothing to relay verbatim.
			// Synthesize a forward-quality raw from the message's own content so a
			// filter "Forward to" is not silently dropped for a Direct sender.
			$raw = $this->synthesizeRawForForward($msg);
		}
		if ($raw === null || $raw === '') {
			error_log('InboundEmailRouter::forwardStoredMessage: no raw available for message ' . $msg->key);
			return array();
		}
		$domain = new InboundEmailDomain(intval($msg->get('iem_ied_inbound_email_domain_id')), TRUE);
		if (!$domain->key) {
			return array();
		}
		// Reading the raw message above opens it, so this process is now hot.
		// Relaying is the one send that is allowed to carry the content itself,
		// and only because someone acknowledged in writing that it would — the
		// caller has already checked that acknowledgment (see
		// InboundEmailFilter::forwardConsentSatisfied). Naming it here means the
		// relay path is covered by the same rule as EmailSender, rather than
		// slipping past it by using a different transport.
		require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
		SealedEgressGuard::assertSendAllowed(EmailSender::EGRESS_ACKNOWLEDGED_FORWARD);

		$parsed = $this->parseEmail($raw);
		list($raw_mime, $envelope_sender) = $this->buildForwardMessage(
			$raw, $parsed, $domain, (string)$msg->get('iem_recipient'));

		return $this->relay($raw_mime, $envelope_sender, $destinations);
	}

	/**
	 * Build a forward-quality raw MIME for a message that retains none — a Joinery
	 * Direct delivery, which never crossed the wire as a MIME document, or a lean
	 * record whose raw was not kept. Rebuilt from the message's OWN content
	 * (subject, bodies, attachment Files), which reads through the same in-window
	 * Sealed Vault getters every other content read uses.
	 *
	 * Returns null when the content cannot be read RIGHT NOW — a sealed message
	 * with no open unlock window. That is not new behaviour for Direct: a sealed
	 * SMTP message's forward is the identical no-op, because you cannot forward what
	 * you cannot read, and the caller already logs the miss. The tractable win is
	 * the common case — an unencrypted mailbox (a group alias, a vaultless owner)
	 * whose columns are plaintext.
	 */
	private function synthesizeRawForForward(InboundEmailMessage $msg): ?string {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));

		try {
			$sender    = (string)$msg->get('iem_sender');
			$recipient = (string)$msg->get('iem_recipient');
			$subject   = (string)$msg->get('iem_subject');
			$plain     = (string)$msg->get('iem_body_plain');
			$html      = (string)$msg->get('iem_body_html');
			$messageId = (string)$msg->get('iem_message_id_header');
		} catch (VaultLockedException $e) {
			return null; // sealed, no window — the same no-op a sealed SMTP forward hits
		}

		require_once(PathHelper::getComposerAutoloadPath());

		// Build the MIME tree directly and serialize it. Horde_Mime_Mail::getRaw()
		// only works after a send() has populated its base part, so the raw is
		// assembled the way getRaw() does internally — a base Horde_Mime_Part plus a
		// Horde_Mime_Headers, rendered with toString().
		$body = new Horde_Mime_Part();
		if ($html !== '') {
			$body->setType('text/html');
			$body->setContents($html);
		} else {
			$body->setType('text/plain');
			$body->setContents($plain);
		}
		$body->setCharset('UTF-8');

		$att_parts = array();
		$attachments = new MultiInboundMessageAttachment(array('message_id' => intval($msg->key)));
		$attachments->load();
		foreach ($attachments as $att) {
			if (!$att->get('ima_fil_file_id')) {
				continue; // a 'remote' (IMAP) part, fetched on demand — not file-backed here
			}
			$file = new File(intval($att->get('ima_fil_file_id')), TRUE);
			if (!$file->key) {
				continue;
			}
			$bytes = $file->read_bytes('original');
			if ($bytes === null) {
				continue;
			}
			try {
				// Decrypts a sealed attachment in-window; returns bytes as-is for an
				// unsealed one. A sealed attachment we cannot open aborts the whole
				// synthesis rather than forwarding a message missing a part.
				$bytes = InboundEmailMessage::openSealedAttachment($msg, $att, $bytes, $file);
			} catch (VaultLockedException $e) {
				return null;
			}
			$part = new Horde_Mime_Part();
			$part->setType((string)$att->get('ima_content_type') ?: 'application/octet-stream');
			$part->setContents($bytes);
			$part->setName((string)$att->get('ima_filename') ?: 'attachment');
			$part->setDisposition($att->get('ima_is_inline') ? 'inline' : 'attachment');
			if ((string)$att->get('ima_content_id') !== '') {
				$part->setContentId((string)$att->get('ima_content_id'));
			}
			$att_parts[] = $part;
		}

		if (empty($att_parts)) {
			$base = $body;
		} else {
			$base = new Horde_Mime_Part();
			$base->setType('multipart/mixed');
			$base->addPart($body);
			foreach ($att_parts as $part) {
				$base->addPart($part);
			}
		}

		$headers = new Horde_Mime_Headers();
		$headers->addHeader('From', $sender !== '' ? $sender : ('postmaster@' . $this->hostDomainOf($recipient)));
		if ($recipient !== '') {
			$headers->addHeader('To', $recipient);
		}
		$headers->addHeader('Subject', $subject);
		$headers->addHeader('Date', gmdate('r'));
		if ($messageId !== '') {
			$headers->addHeader('Message-ID', $messageId);
		}

		$raw = $base->toString(array(
			'headers' => $headers,
			'encode'  => Horde_Mime_Part::ENCODE_7BIT | Horde_Mime_Part::ENCODE_8BIT | Horde_Mime_Part::ENCODE_BINARY,
		));
		return (is_string($raw) && $raw !== '') ? $raw : null;
	}

	/** The domain part of an address, for a synthesized From fallback. */
	private function hostDomainOf(string $address): string {
		$at = strrpos($address, '@');
		return $at === false ? 'localhost' : substr($address, $at + 1);
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
		// The SRS envelope leaves from the forwarding subdomain, not the bare
		// domain (specs/mailbox_outbound_send_protection.md, closure 3): under a
		// protected domain's strict alignment (aspf=s) the forwarding subdomain's
		// SPF pass can never align the bare domain, so forwarding keeps working
		// while locked without handing the box any spoofing capability. For a
		// non-protected domain with no override, forwarding_subdomain() returns
		// the bare domain — today's behavior.
		$forwarding_domain = $domain->forwarding_subdomain();

		// SRS rewrite envelope sender
		$envelope_sender = $parsed['from_email'];
		if ($this->settings->get_setting('mailbox_srs_enabled')) {
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
	 * RawMessageRelay AND no explicit mailbox_forwarding_smtp_host
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
		if ($this->settings->get_setting('mailbox_forwarding_smtp_host')) {
			return null;
		}

		$service = EmailSender::activeServiceKey();
		if ($service === '') {
			return null;
		}
		$providers = EmailSender::getDiscoveredProviders();
		$class = $providers[$service] ?? null;
		if ($class && in_array('RawMessageRelay', class_implements($class))) {
			return new $class();
		}

		return null;
	}

	/**
	 * Describe the resolved forwarding relay for status display (Setup tab).
	 * Returns ['mode' => 'provider'|'smtp', 'label' => string,
	 * 'provider_class' => string] — provider_class is the EmailServiceProvider
	 * class name behind the relay ('' on the SMTP path), which callers use for
	 * per-domain lookups like relaySpfMechanism().
	 */
	public function describeRelay() {
		$provider = $this->resolveRelayProvider();
		if ($provider instanceof RawMessageRelay) {
			$class = get_class($provider);
			return array('mode' => 'provider', 'label' => $class::getLabel(),
				'provider_class' => $class);
		}
		return array('mode' => 'smtp', 'label' => 'SMTP relay', 'provider_class' => '');
	}

	/**
	 * The SPF mechanism a sending domain must carry for mail relayed through
	 * the resolved forwarding relay to pass SPF (the provider's
	 * getSpfMechanism()), or '' when none applies — the SMTP relay path, or a
	 * provider with nothing to prescribe.
	 */
	public function relaySpfMechanism(string $domain): string {
		$relay = $this->describeRelay();
		if ($relay['provider_class'] === '') {
			return '';
		}
		$class = $relay['provider_class'];
		return (string)$class::getSpfMechanism($domain);
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
	/**
	 * If $recipient is an SRS-rewritten bounce address (and SRS is enabled), decode
	 * it and deliver a delivery-failure notice to the original sender; returns the
	 * exit code. Returns null when it is not an SRS address, so the caller continues
	 * normal routing. Shared by the receive-time router (processEmail) and the relay
	 * pull consumer, so relay-fronted bounces are handled identically to colocated
	 * ones (specs/mailbox_relay_fix_pack.md § Fix 6).
	 */
	public function handleSrsBounceIfApplicable(array $parsed, string $raw_email, string $recipient): ?int {
		if ($this->settings->get_setting('mailbox_srs_enabled') && SRSRewriter::isSRSAddress($recipient)) {
			return $this->handleSRSBounce($parsed, $raw_email, $recipient);
		}
		return null;
	}

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

			// No explicit From: EmailSender stamps its platform default — the
			// identity the ambient provider is verified and DKIM-aligned for,
			// so the notice itself is deliverable. A customer-domain From here
			// (protected or not) would fail the provider's alignment and get
			// the failure notice itself rejected. The platform From is never a
			// protected identity, so the ambient-send guard is satisfied by
			// construction.
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
	 * SmtpConfig::fromForwardingSettings() (mailbox_forwarding_smtp_*, else
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
		// (mailbox_forwarding_smtp_*, falling back to base smtp_*), so the
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
		$authserv_id = strtolower(trim((string)$this->settings->get_setting('mailbox_mail_hostname')));
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
	 * Content layer (specs/mailbox_spam_filtering_simplification.md): the message is
	 * spam if the content scanner flagged it OR the auth rule fires —
	 *   verdict = spam  if  content_signal == 'spam'  OR  auth_rule == spam
	 * The $content_signal is whichever scanner verdict resolveContentSpam() settled
	 * on, so this just OR's it in. The filing switch below governs the whole feature:
	 * with it off the verdict stays NULL regardless of what any scanner said.
	 *
	 * @param array{dkim:string,spf:string,dmarc:string,source:string} $auth
	 * @param string $content_signal  'spam' | 'ham' | 'none' (from resolveContentSpam).
	 * @return string|null InboundEmailMessage::SPAM_VERDICT_*, or null when disabled.
	 */
	private function classifySpam(array $auth, string $content_signal = 'none'): ?string {
		if (!$this->settings->get_setting('mailbox_spam_filtering_enabled')) {
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
	 * (specs/mailbox_spam_filtering_simplification.md). Returns
	 * ['signal' => 'spam'|'ham'|'none', 'score' => ?float].
	 *
	 * A verdict another system already computed is always read — reading a header
	 * costs nothing and needs no scanner here, and on a box with no scanner of its
	 * own the upstream's X-Spam is the only content signal there is. Whether the
	 * signal changes any disposition is the filing switch's call, enforced
	 * separately in classifySpam.
	 *
	 *   - Webhook providers (Mailgun/SendGrid/SES) supply their own content/reputation
	 *     spam flag in the authenticated payload; the dispatcher hands it in as
	 *     $provider_spam. It is a content signal, NOT an auth verdict, so it arrives as
	 *     a sibling argument rather than inside $provider_auth.
	 *   - Postfix path ($provider_spam null): read the rspamd milter's X-Spam header off
	 *     the raw, trusted on the same basis as the Authentication-Results line (the
	 *     milter is ours; an external X-Spam is stripped by rspamd before it re-stamps).
	 *
	 * When the arriving verdict came from something other than this box's own
	 * milter (a relay or a webhook provider) and a scanner is running here, the
	 * message is re-scored locally. How much that local verdict counts is
	 * MailboxSpamPolicy::localVerdictReplaces():
	 *
	 *   - learning off → OR'd into the upstream signal. Without a corpus the
	 *     local scan is the same static ruleset the upstream ran, minus the live
	 *     SMTP client context a milter sees, so it may add spam but must never
	 *     overturn an upstream spam verdict.
	 *   - learning on  → REPLACES it, so a user's "not spam" corrections can
	 *     actually subtract. An OR could only ever add.
	 *
	 * A scanner that is missing, down or slow simply yields the upstream verdict:
	 * no message is ever held, bounced or retried on the scanner's account.
	 *
	 * @param string     $raw_email
	 * @param array|null $provider_spam  ['result'=>spam|ham|none,'score'=>?float,'source'=>key]
	 * @return array{signal:string,score:?float}
	 */
	private function resolveContentSpam($raw_email, $provider_spam = null): array {
		$upstream = $this->readUpstreamContentSpam($raw_email, $provider_spam);

		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSpamPolicy.php'));
		if (!MailboxSpamPolicy::scanAtIngest() || (string)$raw_email === ''
			|| !MailboxSpamPolicy::scannerAvailable()) {
			return $upstream;
		}

		$local = $this->scanContentSpam((string)$raw_email);
		if ($local === null) {
			return $upstream;
		}
		if (MailboxSpamPolicy::localVerdictReplaces()) {
			return $local;
		}

		// OR: the local scan can only ADD spam. An upstream 'spam' always stands,
		// and its score is kept — it is the verdict that decided the disposition.
		if ($upstream['signal'] === 'spam') {
			return $upstream;
		}
		return ($local['signal'] === 'spam') ? $local : $upstream;
	}

	/**
	 * The content-spam verdict that arrived WITH the message — a webhook
	 * provider's flag, or the X-Spam header a relay or local milter stamped.
	 * Never computes anything.
	 *
	 * @return array{signal:string,score:?float}
	 */
	private function readUpstreamContentSpam($raw_email, $provider_spam = null): array {
		// Webhook provider signal (only honored with a non-empty source).
		if (is_array($provider_spam) && !empty($provider_spam['source'])) {
			$result = strtolower(trim((string)($provider_spam['result'] ?? 'none')));
			$signal = in_array($result, array('spam', 'ham'), true) ? $result : 'none';
			$score  = (isset($provider_spam['score']) && is_numeric($provider_spam['score']))
				? (float)$provider_spam['score'] : null;
			return array('signal' => $signal, 'score' => $score);
		}

		// Postfix milter / relay header path.
		return $this->readSpamHeader($raw_email);
	}

	/**
	 * Score a message through the local rspamd controller's /checkv2 and turn the
	 * answer into a content-spam signal.
	 *
	 * Unlike readSpamHeader() this asserts BOTH directions: a scan that comes
	 * back under the threshold returns 'ham', which is what lets a locally
	 * trained corpus rescue a message an upstream static ruleset flagged. That
	 * is the whole point of scanning here rather than trusting the header.
	 *
	 * Scoring over HTTP lacks the live SMTP client context a milter has, but the
	 * upstream Received headers ride in the raw, so header-based network rules
	 * still fire; content rules and Bayes are unaffected.
	 *
	 * Protected so a test can substitute the transport; the reading of whatever
	 * comes back is interpretScanResponse(), which is pure and tested directly.
	 *
	 * @return array{signal:string,score:?float}|null  null on any failure — the
	 *         caller then keeps the upstream verdict. Never throws.
	 */
	protected function scanContentSpam(string $raw_email): ?array {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSpamPolicy.php'));
		$url = MailboxSpamPolicy::controllerUrl() . '/checkv2';

		$body = null;
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt_array($ch, array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $raw_email,
				CURLOPT_HTTPHEADER     => array('Content-Type: text/plain'),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => self::SCAN_CONNECT_TIMEOUT,
				CURLOPT_TIMEOUT        => self::SCAN_TIMEOUT,
			));
			$body = curl_exec($ch);
			$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$err  = curl_error($ch);
			if ($body === false || $code !== 200) {
				error_log('InboundEmailRouter: ingest spam scan failed (HTTP ' . $code . '): '
					. ($err !== '' ? $err : substr((string)$body, 0, 200)));
				return null;
			}
		} else {
			$ctx = stream_context_create(array('http' => array(
				'method'        => 'POST',
				'header'        => "Content-Type: text/plain\r\n",
				'content'       => $raw_email,
				'timeout'       => self::SCAN_TIMEOUT,
				'ignore_errors' => true,
			)));
			$body = @file_get_contents($url, false, $ctx);
			if ($body === false) {
				error_log('InboundEmailRouter: ingest spam scan failed (stream).');
				return null;
			}
		}

		return $this->interpretScanResponse((string)$body);
	}

	/**
	 * Turn an rspamd /checkv2 response body into a content-spam signal.
	 *
	 * rspamd's own disposition is the primary signal; the score comparison is the
	 * fallback for a response that omits 'action'. The provisioned actions.conf
	 * disables reject and greylist, so in practice the spam actions are
	 * add-header and rewrite-subject — but reject is matched too, in case an
	 * operator re-enabled it on their own scanner.
	 *
	 * @return array{signal:string,score:?float}|null  null when the body cannot
	 *         be read as a verdict at all.
	 */
	private function interpretScanResponse(string $body): ?array {
		$decoded = json_decode($body, true);
		if (!is_array($decoded) || !isset($decoded['score']) || !is_numeric($decoded['score'])) {
			error_log('InboundEmailRouter: ingest spam scan returned an unreadable body.');
			return null;
		}

		$action = strtolower(trim((string)($decoded['action'] ?? '')));
		$score  = (float)$decoded['score'];
		if ($action !== '') {
			$is_spam = in_array($action, array('add header', 'add_header',
				'rewrite subject', 'rewrite_subject', 'reject'), true);
		} else {
			$required = isset($decoded['required_score']) ? (float)$decoded['required_score'] : 6.0;
			$is_spam = ($score >= $required);
		}

		return array('signal' => $is_spam ? 'spam' : 'ham', 'score' => $score);
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
	protected function checkAliasRateLimit($alias_id) {
		$db = DbConnector::get_instance()->get_db_link();
		$window = intval($this->settings->get_setting('mailbox_forwarding_rate_limit_window')) ?: 3600;
		$max = intval($this->settings->get_setting('mailbox_forwarding_rate_limit_per_alias')) ?: 50;

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
	protected function checkDomainRateLimit($domain_id) {
		$db = DbConnector::get_instance()->get_db_link();
		$window = intval($this->settings->get_setting('mailbox_forwarding_rate_limit_window')) ?: 3600;
		$max = intval($this->settings->get_setting('mailbox_forwarding_rate_limit_per_domain')) ?: 200;

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
	 *
	 * Never logs the subject (specs/implemented/inbound_email_encryption_at_rest.md
	 * § 7 "no sideways copies" — the log viewer is routing metadata only: sender/
	 * recipient addresses, verdicts, sizes; never subject/body content, sealed
	 * mailbox or not).
	 */
	public function logTransaction($parsed, $alias, $status, $to_address, $destinations = null, $error = null, $domain_id = null) {
		InboundEmailLog::CreateEntry(
			$parsed['from'] ?? '',
			$to_address,
			'',
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
	 * off with the mailbox_from_show_via setting.
	 */
	private function forwardedFromDisplay($original_sender_name) {
		$site = $this->settings->get_setting('defaultemailname') ?: 'Inbound Email';
		if ((string)$this->settings->get_setting('mailbox_from_show_via') === '0') {
			return $original_sender_name ? $original_sender_name : 'Forwarded';
		}
		return $original_sender_name
			? $original_sender_name . ' via ' . $site
			: 'Forwarded via ' . $site;
	}

	/**
	 * Build the sender string stored in iem_sender: the From display name beside
	 * the address as "Name" <addr>, or the bare address when the header carries no
	 * usable name.
	 *
	 * The reader shows the name and keeps the address on hover, so keeping only
	 * the address left recipients reading local parts — "no-reply", "meet",
	 * "product". Every consumer of iem_sender already accepts this shape:
	 * MailboxContacts::parseAddress(), the reader's senderName(), the reply
	 * builder's address extraction, and the filter engine's substring match on a
	 * From criterion (a term that matched the address still matches).
	 *
	 * The name is attacker-chosen text arriving over SMTP, so it is treated as
	 * hostile: encoded words are decoded for display, then the characters that
	 * would let it forge a second address or fold into another header — quotes,
	 * angle brackets, CR/LF, tabs — are stripped, and the result is quoted. The
	 * name is what gets truncated to fit the column, never the address.
	 */
	private function senderDisplayString(array $parsed): string {
		$address = trim((string)($parsed['from_email'] ?? ''));
		$from    = trim((string)($parsed['from'] ?? ''));
		if ($address === '') {
			return substr($from, 0, 500);
		}
		$address = substr($address, 0, 500);

		$name = $this->decodeMimeHeader($this->extractName($from));
		$name = preg_replace('/[\r\n\t"<>]+/', ' ', (string)$name);
		$name = trim(preg_replace('/\s+/', ' ', $name));
		// A name equal to the address (common on bulk senders) adds nothing.
		if ($name === '' || strcasecmp($name, $address) === 0) {
			return $address;
		}

		$budget = 500 - strlen($address) - 5; // ' <' . addr . '>' plus the two quotes
		if ($budget < 4) {
			return $address;
		}
		if (strlen($name) > $budget) {
			// Byte-limited but character-safe, so a truncated UTF-8 name stays valid.
			$name = rtrim(function_exists('mb_strcut')
				? mb_strcut($name, 0, $budget, 'UTF-8')
				: substr($name, 0, $budget));
			if ($name === '') {
				return $address;
			}
		}
		return '"' . $name . '" <' . $address . '>';
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

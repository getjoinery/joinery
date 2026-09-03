<?php
/**
 * ImapIngestor - Connect to one IMAP mailbox, fetch new mail, store it.
 *
 * The whole IMAP transport lives behind this one class — the Horde IMAP client
 * (bytestream/horde-imap-client) is wrapped here and nowhere else, so the library
 * choice is contained. There is no per-host class hierarchy: connection details
 * are data (InboundImapAccount's preset catalog), and authentication is a single
 * branch on iia_auth_method:
 *
 *   - password → IMAP LOGIN with the decrypted app/basic password.
 *   - oauth2   → AUTHENTICATE XOAUTH2 with a bearer token kept fresh through the
 *                OAuth2 Core (OAuth2Client::ensureFresh). This class owns no grant,
 *                refresh, or callback logic — only the XOAUTH2 SASL string and the
 *                IMAP use of the token.
 *
 * Ingest is REFERENCE-BACKED: only the headers + decoded text/plain & text/html
 * bodies are stored (via InboundEmailRouter::storeExtracted, leaving
 * iem_raw_message empty), plus an attachment MANIFEST (ima_ rows) enumerated from
 * BODYSTRUCTURE. Attachment bytes are never fetched at poll time and never land on
 * platform disk — fetchPart() pulls exactly one part on demand (the download
 * endpoint), pass-through.
 *
 * Per-message failures are surfaced to the caller; per-account connect/auth
 * failures are caught by the caller (the poller) and recorded as last_status so
 * one unreachable mailbox never stops the rest.
 *
 * Every poll that did anything leaves a durable run record (evl_event_logs,
 * event 'mailbox_imap_ingest') plus bounded error-log detail — see recordRun().
 * iia_last_status is overwritten each poll, so it cannot answer "what did the
 * backfill lose three hours ago"; the run record can.
 *
 * Spam (specs/inbound_email_spam_filtering.md): a message ingested into a folder
 * whose iif_role is 'junk' is marked iem_spam_verdict='spam' — the remote server
 * already classified it, so no auth rule runs. This gives the reader's Spam view
 * the same meaning for IMAP-polled mail as for locally-received mail.
 *
 * A message the source still holds as a DRAFT is never ingested (any folder).
 * Gmail's [Gmail]/All Mail carries drafts alongside real mail, and every autosave
 * replaces the draft with a fresh UID, so a coverage pass met each half-written
 * revision as new mail and stored it as an incoming message from yourself — one
 * per poll for as long as the compose window stayed open. The \Draft flag is the
 * source's own word for "not mail yet"; Joinery keeps its own drafts as
 * iem_direction='draft' rows and never wants the source's.
 *
 * Every cycle is timed. The ingestor keeps a ledger of where the wall clock
 * went (connect, prepare, pull, each folder's seek / fetch / store, push) that
 * the run record, iia_last_status and the cron log all carry, so a fetch that
 * took two minutes says which two minutes. A cycle may also carry a deadline
 * (setDeadline): work stops between folders and between messages once it
 * passes, the cursor stays below the first message not walked, and what is
 * left is counted as deferred — the next poll takes it. That is how an
 * interactive fetch (the reader's Refresh, the admin's Fetch now) stays inside
 * the time a browser, and the proxy in front of it, will wait.
 *
 * @version 1.18
 * @changelog 1.18 - timing ledger and deadline (specs/mailbox_refresh_budget.md):
 *   client() laps connect, ingestFolder laps seek/fetch/store per folder, the
 *   run record and status carry describeTiming(); pastDeadline() stops the walk
 *   between folders and messages, counting the rest as deferred.
 * @version 1.17
 * @changelog 1.17 - skip \Draft-flagged messages at ingest, counted in the run
 *   record's own bucket (specs/bugfix_imap_draft_ingest.md). fetchWindow asks
 *   for FLAGS so the decision can be made without a second round trip.
 * @version 1.16
 * @changelog 1.16 - inline images are adopted into Files at ingest
 *   (specs/bugfix_sealed_inline_images.md): the network half fetches each
 *   inline image part's bytes (5 MB cap) beside the body fetches, and the
 *   stored half adopts them via AttachmentByteCustody::adoptBytes — sealed iff
 *   the message is, to the owner it records. Reference-backed inline parts
 *   could never render in the reader (resolveInlineImages rewrites only
 *   file-backed rows).
 * @version 1.15
 * @changelog 1.15 - composed-copy dedup on every folder pass (specs/
 *   bugfix_promoted_sent_row_sealing.md): a sealed outbound row's recipient is
 *   ciphertext, so the (Message-ID, recipient, direction) unique key could never
 *   dedup a provider-filed copy of a locally-composed send — the All Mail pass
 *   stored a duplicate, and the Sent pass's unordered LIMIT 1 then promoted it.
 *   Every pass now dedups by Message-ID alone against the alias's outbound/draft
 *   rows first, the §9 lookup is ordered composer-copy-first, and a promotion of
 *   a sealed row records the recipient sealing debt (iem_reseal_pending) for
 *   PromotedRowRepair to pay in-window.
 * @version 1.14
 * @changelog 1.14 - a Sent-folder message already stored by another pass is
 *   promoted to outbound on the dedup paths, not only when stored fresh: Gmail's
 *   \All coverage folder sorts before Sent Mail in discovery, so it always won
 *   the race and stored every sent message as an ordinary inbound row — which
 *   the §9 dedup then left in place, showing your own sent mail in the Inbox as
 *   an incoming message from yourself. Self-addressed sends stay inbound (they
 *   belong in the Inbox), the promotion never demotes outbound/draft rows, and
 *   it stands down when a live outbound sibling already holds the dedup key.
 * @version 1.13
 * @changelog 1.13 - toUtf8 delegates to the shared DocumentText ladder: an
 *   unknown sender-declared charset degrades the conversion (raw is preserved
 *   regardless) instead of throwing under PHP 8
 * @version 1.12
 * @changelog 1.12 - mark CONDSTORE usable on the connection when the server
 *   advertises it: Horde only ENABLEs it when its own cache backend is
 *   configured (ours is not), and without the mark Horde answers every
 *   STATUS (HIGHESTMODSEQ) with 0 without asking the server — which the sync
 *   pull then stored as a baseline cursor of 0, degrading every flag pull to
 *   a full-mailbox FLAGS fetch. Client-side marking is protocol-correct with
 *   no ENABLE round trip: STATUS (HIGHESTMODSEQ) and FETCH (CHANGEDSINCE)
 *   are themselves CONDSTORE-enabling commands (RFC 7162 §3.1)
 * @version 1.11
 * @changelog 1.11 - one fetch per mailbox at a time: poll() takes a per-account
 *   advisory try-lock at the chokepoint both the scheduled poller and Fetch now
 *   pass through; a second fetch fails fast as ImapFetchBusyException instead
 *   of racing the cursors and holding a second worker
 * @version 1.11
 * @changelog 1.11 - the cursor decides where to look, the scope decides what to
 *   keep (specs/imap_seed_scope_guard.md): a day-scoped backfill skips-and-counts
 *   messages whose INTERNALDATE predates the window (out_of_scope, reconciled in
 *   the run record) instead of trusting the seek cursor alone, confined to UIDs
 *   at or below the seed-time high (iif_seed_high_uid) so later deliberate moves
 *   still ingest; the boundary seek asks the server (`UID SEARCH SINCE`) before
 *   bisecting, and the seed proof records which method answered (isp_method)
 * @version 1.10
 * @changelog 1.10 - a decades-old Gmail folder is mostly deleted UIDs, and both
 *   walkers now cross that desert instead of crawling it: the day-boundary seek
 *   doubles its advance over proven-empty bands (48 fixed-band probes reached
 *   UID 1,536 of 270,948), and the ingest walk doubles an empty window inside
 *   one poll instead of conceding 50 UIDs per 5 minutes for eighteen days
 * @version 1.9
 * @changelog 1.9 - a login failure carries the server's own reason when Horde
 *   has it (Exception->details), so 'denied authentication' can say why
 * @version 1.8
 * @changelog 1.8 - a message and its attachment manifest are stored in ONE
 *   transaction (D1, specs/mail_import_loss_proof.md), so a committed row always
 *   carries its manifest and a half-stored message never survives a crash or a
 *   concurrent reader; seekCursorForCutoff records a durable seed proof.
 * @changelog 1.7 - date-boundary seek for day-window feeds (seekCursorForCutoff):
 *   INTERNALDATE compared in UTC, unparsable dates count as in-window, and an
 *   empty probe band resolves via the bottom of the range instead of conceding
 *   unprobed UID space — every inconclusive path fails toward importing more.
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_folder_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_labels_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_label_members_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapClient.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
require_once(PathHelper::getIncludePath('data/event_logs_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailRunRecord.php'));

class ImapIngestorException extends Exception {}

/** The mailbox is being fetched by someone else right now — not a failure,
 *  a "the work is already happening". Callers show it softly. */
class ImapFetchBusyException extends ImapIngestorException {}

class ImapIngestor {

	/** Generous text-body ceiling. Bodies over this are truncated-and-marked,
	 *  never dropped — the full part is still fetchable on demand like any part. */
	const TEXT_BODY_CEILING = 2097152; // 2 MB

	/** Per-part ceiling for fetching an inline image's bytes at ingest
	 *  (specs/bugfix_sealed_inline_images.md). An inline part is body content —
	 *  the reader renders it inside the HTML — so it is adopted into a File
	 *  while the connection is open; anything bigger stays a reference like any
	 *  other attachment. */
	const INLINE_ADOPT_MAX_BYTES = 5242880; // 5 MB

	/** evl_event value for the run record. */
	const RUN_EVENT = 'mailbox_imap_ingest';

	// Date-boundary seek (seekCursorForCutoff). The band absorbs UID gaps left by
	// deletions; the probe ceiling bounds the seek at roughly log2(UID space) plus
	// slack for gap skips, so a pathological mailbox costs a bounded number of
	// round trips instead of hanging the poll.
	const SEEK_BAND = 64;
	const SEEK_MAX_PROBES = 48;
	/** Ceiling for the doubling empty-band advance: bounds the one heavy case, a
	 *  probe that lands in dense mail and returns a band's worth of dates. */
	const SEEK_BAND_MAX = 32768;

	/** Advisory-lock class for the per-account fetch lock (sibling of the grant
	 *  sync's 74211; arbitrary, fixed). */
	const FETCH_LOCK_CLASS = 74212;

	/** @var InboundImapAccount */
	private $account;
	/** @var ImapClient|null */
	private $client = null;

	/** @var array The timing ledger: phase => seconds, and 'folders' => name => phase => seconds. */
	private $timing = array('folders' => array());
	/** @var float|null Absolute microtime() the cycle must stop at; null is unbounded. */
	private $deadline = null;
	/** @var bool Set once the deadline stopped work early. */
	private $budgetExhausted = false;
	/** @var float Seconds the last ingestOne spent in its transaction (read by ingestFolder). */
	private $lastStoreSeconds = 0.0;

	// ── Timing and the deadline ────────────────────────────────────────────

	/**
	 * Bound the cycle. Work stops between folders and between messages once the
	 * deadline passes; nothing already started is cut. The scheduled poller runs
	 * unbounded (null); the interactive paths pass ImapFetch's budget.
	 */
	public function setDeadline(?float $deadline): void {
		$this->deadline = $deadline;
	}

	public function pastDeadline(): bool {
		return $this->deadline !== null && microtime(true) >= $this->deadline;
	}

	/** Did the deadline stop this cycle before it finished? */
	public function budgetExhausted(): bool {
		return $this->budgetExhausted;
	}

	/** Record seconds spent in a cycle phase (accumulating on repeats). */
	public function lap(string $phase, float $seconds): void {
		$this->timing[$phase] = ($this->timing[$phase] ?? 0.0) + $seconds;
	}

	/** Record seconds spent in one phase of one folder's ingest. */
	private function lapFolder(string $folder, string $phase, float $seconds): void {
		$this->timing['folders'][$folder][$phase] =
			($this->timing['folders'][$folder][$phase] ?? 0.0) + $seconds;
	}

	/** The ledger so far: phase => seconds, 'folders' => name => phase => seconds. */
	public function timing(): array {
		return $this->timing;
	}

	/**
	 * The ledger as one line a person reads: "took 4.2s: connect 0.5s, pull 1.1s,
	 * INBOX 2.1s (seek 0.3s, fetch 1.2s, store 0.6s), push 0.1s". Folder detail is
	 * shown when $folderDetail — the status column is 500 characters, the run
	 * record is not. Pure.
	 */
	public static function describeTiming(array $timing, float $total, bool $folderDetail = true): string {
		$fmt = function (float $s): string { return number_format($s, 1) . 's'; };
		$parts = array();
		foreach ($timing as $phase => $seconds) {
			if ($phase === 'folders' || $phase === 'ingest') { continue; }
			if ($phase === 'push') { continue; } // after the folders, below
			$parts[] = $phase . ' ' . $fmt((float)$seconds);
		}
		foreach ((array)($timing['folders'] ?? array()) as $name => $phases) {
			$phases = (array)$phases;
			$sum = 0.0; $inner = array();
			foreach ($phases as $phase => $seconds) {
				$sum += (float)$seconds;
				$inner[] = $phase . ' ' . $fmt((float)$seconds);
			}
			$parts[] = $name . ' ' . $fmt($sum)
				. ($folderDetail && count($inner) > 1 ? ' (' . implode(', ', $inner) . ')' : '');
		}
		if (isset($timing['push'])) {
			$parts[] = 'push ' . $fmt((float)$timing['push']);
		}
		return 'took ' . $fmt($total) . ($parts ? ': ' . implode(', ', $parts) : '');
	}

	/**
	 * @param InboundImapAccount $account
	 * @param ImapClient|null $client Inject a fake client for testing (the §6.2
	 *        seam); when null the real Horde-backed client is built lazily on first
	 *        use and shared with a sibling ImapSyncer via getClient().
	 */
	public function __construct(InboundImapAccount $account, ?ImapClient $client = null) {
		$this->account = $account;
		$this->client = $client;
	}

	// ── Connection / auth ──────────────────────────────────────────────────

	/**
	 * Build and log in the Horde client (lazily, once). Branches on the account's
	 * auth method. Throws ImapIngestorException with a credential-free message on
	 * any failure (refresh, connect, or login).
	 */
	private function client(): ImapClient {
		if ($this->client !== null) {
			return $this->client;
		}

		$enc = $this->account->get('iia_imap_encryption') ?: 'ssl';
		$secure = ($enc === 'ssl') ? 'ssl' : (($enc === 'tls') ? 'tls' : false);

		$params = array(
			'username' => (string)$this->account->get('iia_username'),
			'hostspec' => (string)$this->account->get('iia_imap_host'),
			'port'     => intval($this->account->get('iia_imap_port')) ?: 993,
			'secure'   => $secure,
		);

		if ($this->account->isOAuth()) {
			$accessToken = $this->freshAccessToken();
			$user = (string)$this->account->get('iia_username');
			$params['xoauth2_token'] = base64_encode('user=' . $user . "\1auth=Bearer " . $accessToken . "\1\1");
			// Horde's login() rejects an empty 'password' BEFORE it selects an auth
			// mechanism, so set it to the bearer token. XOAUTH2 is still what
			// authenticates (it is chosen because xoauth2_token is set); the password
			// is never sent unless XOAUTH2 is unavailable.
			$params['password'] = $accessToken;
		} else {
			$password = $this->account->getPassword();
			if ($password === null || $password === '') {
				throw new ImapIngestorException('No IMAP password configured for this account.');
			}
			$params['password'] = $password;
		}

		$connectStarted = microtime(true);
		try {
			$socket = new Horde_Imap_Client_Socket($params);
			$socket->login();
		} catch (Horde_Imap_Client_Exception $e) {
			$this->lap('connect', microtime(true) - $connectStarted);
			// An OAuth account whose token the server rejects needs reconnection.
			if ($this->account->isOAuth() && $e->getCode() === Horde_Imap_Client_Exception::LOGIN_AUTHENTICATIONFAILED) {
				$this->account->markNeedsReauth();
			}
			// Horde messages are credential-free (status + server text). The
			// details field, when set, is the server's own response line — e.g.
			// Gmail's Invalid credentials — which is the difference between an
			// answerable error and a shrug.
			$detail = trim((string)($e->details ?? ''));
			throw new ImapIngestorException('IMAP login failed: ' . $e->getMessage()
				. ($detail !== '' ? ' (' . $detail . ')' : ''));
		} catch (Throwable $e) {
			$this->lap('connect', microtime(true) - $connectStarted);
			throw new ImapIngestorException('IMAP connection failed: ' . $e->getMessage());
		}
		$this->lap('connect', microtime(true) - $connectStarted);

		// Let modseqs flow: Horde refuses to put HIGHESTMODSEQ in a STATUS (and
		// answers 0 without asking the server) unless CONDSTORE is marked enabled,
		// but it only sends ENABLE when its own cache backend is configured — ours
		// is not. Marking the capability client-side is protocol-correct without an
		// ENABLE round trip: the first STATUS (HIGHESTMODSEQ) or FETCH (CHANGEDSINCE)
		// it unlocks is itself a CONDSTORE-enabling command (RFC 7162 §3.1).
		try {
			if ($socket->capability->query('CONDSTORE')) {
				$socket->capability->enable('CONDSTORE');
			}
		} catch (Throwable $e) { /* capability probing must never break a login */ }

		$this->client = new HordeImapClient($socket);
		return $this->client;
	}

	/**
	 * The shared, logged-in client — forces the connection if not yet open. A
	 * sibling ImapSyncer calls this so Pull → Ingest → Push run on one connection
	 * (specs/two_way_imap_sync.md §6.2).
	 */
	public function getClient(): ImapClient {
		return $this->client();
	}

	/**
	 * Resolve a fresh access token through the OAuth2 Core, persisting it if it was
	 * refreshed. The caller formats the XOAUTH2 SASL string. Throws
	 * ImapIngestorException (wrapping OAuth2Exception) on refresh failure so the
	 * poller records it per-account.
	 */
	private function freshAccessToken(): string {
		$token = $this->account->getOAuthToken();
		if ($token === null) {
			throw new ImapIngestorException('This account is not connected (no OAuth token).');
		}

		$providerKey = $this->account->getOAuthProviderKey();
		$providerClass = $providerKey ? OAuth2ProviderRegistry::get($providerKey) : null;
		if ($providerClass === null) {
			throw new ImapIngestorException('OAuth provider "' . (string)$providerKey . '" is not available.');
		}

		try {
			$fresh = (new OAuth2Client())->ensureFresh($providerClass, $token);
		} catch (OAuth2Exception $e) {
			// The refresh token is no longer usable (revoked, or expired — Google
			// expires refresh tokens for apps in Testing after 7 days). Flag the
			// account so the UI offers Reconnect rather than claiming "Connected".
			$this->account->markNeedsReauth();
			throw new ImapIngestorException('OAuth token refresh failed: ' . $e->getMessage());
		}

		if ($fresh->getAccessToken() !== $token->getAccessToken()) {
			$this->account->setOAuthToken($fresh);
			$this->account->save();
		}

		return $fresh->getAccessToken();
	}

	public function close(): void {
		if ($this->client !== null) {
			try { $this->client->logout(); } catch (Throwable $e) { /* ignore */ }
			$this->client = null;
		}
	}

	// ── Capability detection & folder discovery (sync) ─────────────────────

	/**
	 * Detect and cache the sync-relevant capabilities on the feed: CONDSTORE (the
	 * sync gate — incremental flag/membership pull; QRESYNC implies it), QRESYNC
	 * (the fast removal-detection path, VANISHED), and X-GM-EXT-1 (Gmail →
	 * non-exclusive folders). Gmail advertises CONDSTORE but not QRESYNC, so the two
	 * are tracked separately. Saves only when a value changed; safe on every connect
	 * (so the editor can offer sync once CONDSTORE is known).
	 */
	public function detectCapabilities(): void {
		$client = $this->client();
		$qresync = $client->queryCapability('QRESYNC');
		$condstore = $qresync || $client->queryCapability('CONDSTORE');
		$exclusive = !$client->queryCapability('X-GM-EXT-1');

		$changed = false;
		if ((bool)$this->account->get('iia_supports_condstore') !== $condstore) {
			$this->account->set('iia_supports_condstore', $condstore);
			$changed = true;
		}
		if ((bool)$this->account->get('iia_supports_qresync') !== $qresync) {
			$this->account->set('iia_supports_qresync', $qresync);
			$changed = true;
		}
		if ((bool)$this->account->get('iia_folders_exclusive') !== $exclusive) {
			$this->account->set('iia_folders_exclusive', $exclusive);
			$changed = true;
		}
		if ($changed) {
			$this->account->save();
		}
	}

	/**
	 * Discover the server's folders via LIST (SPECIAL-USE) and upsert an iif_ row
	 * per folder, mapping special-use roles. Special-use folders (Sent/Trash/etc.)
	 * and the coverage \All view are pre-tracked; ordinary folders are created
	 * untracked so the operator opts them in (§8). Returns the discovered folders.
	 *
	 * Idempotent: re-running preserves the operator's per-folder tracking choices
	 * (InboundImapFolder::upsert never flips iif_is_tracked on an existing row).
	 *
	 * @return InboundImapFolder[]
	 */
	public function discoverFolders(): array {
		$client = $this->client();
		$accountId = intval($this->account->key);

		$list = array();
		try {
			$list = $client->listMailboxes('*', Horde_Imap_Client::MBOX_ALL, array(
				'attributes'  => true,
				'special_use' => true,
				'delimiter'   => true,
			));
		} catch (Throwable $e) {
			error_log('ImapIngestor::discoverFolders failed for account ' . $accountId . ': ' . $e->getMessage());
			return array();
		}

		$folders = array();
		foreach ($list as $name => $info) {
			$mailbox = is_array($info) && isset($info['mailbox']) ? (string)$info['mailbox'] : (string)$name;
			if ($mailbox === '') { continue; }
			$attributes = (is_array($info) && isset($info['attributes'])) ? (array)$info['attributes'] : array();
			// Skip \Noselect containers (e.g. the bare "[Gmail]" parent) — they hold no mail.
			$lowerAttrs = array_map(function($a){ return strtolower(ltrim((string)$a, '\\')); }, $attributes);
			if (in_array('noselect', $lowerAttrs, true) || in_array('nonexistent', $lowerAttrs, true)) {
				continue;
			}
			$role = InboundImapFolder::roleFor($attributes, $mailbox);
			// Pre-track INBOX, the coverage \All view, and the behaviorally
			// significant special-use folders; leave plain folders for the operator.
			$preTrack = in_array($role, array(
				InboundImapFolder::ROLE_INBOX, InboundImapFolder::ROLE_ALL,
				InboundImapFolder::ROLE_SENT, InboundImapFolder::ROLE_TRASH,
			), true);
			$folders[] = InboundImapFolder::upsert($accountId, $mailbox, $role, $preTrack);
		}
		return $folders;
	}

	// ── Setup-check connectivity test ──────────────────────────────────────

	/**
	 * Connect + select the folder. Returns ['ok'=>bool, 'message'=>string] — the
	 * message is always credential-free (safe to show on the Setup/Test button).
	 */
	public function testConnection(): array {
		try {
			$client = $this->client();
			$folder = $this->account->get('iia_imap_folder') ?: 'INBOX';
			$status = $client->status($folder, Horde_Imap_Client::STATUS_MESSAGES);
			$count = intval($status['messages'] ?? 0);
			// Cache sync capabilities now so the editor can offer Read-only / Two-way
			// right after a successful Test (specs/two_way_imap_sync.md §6).
			try { $this->detectCapabilities(); } catch (Throwable $e) { /* non-fatal */ }
			return array('ok' => true, 'message' => 'Connected. Folder "' . $folder . '" has ' . $count . ' message(s).');
		} catch (ImapIngestorException $e) {
			return array('ok' => false, 'message' => $e->getMessage());
		} catch (Throwable $e) {
			return array('ok' => false, 'message' => 'Connection error: ' . $e->getMessage());
		} finally {
			$this->close();
		}
	}

	// ── Polling / ingest ───────────────────────────────────────────────────

	/**
	 * Ingest new mail for this account once. The cursor and ingest are per-folder
	 * (iif_ rows), so the same path serves a sync-off feed (the single configured
	 * folder) and a sync-on feed (every tracked folder, seeding membership). Does
	 * NOT close the connection — the caller (the poller, or a sibling ImapSyncer's
	 * Pull → Ingest → Push cycle) owns the lifecycle (§6.2).
	 *
	 * Returns ['stored'=>int, 'dedup'=>int, 'seen'=>int, 'failed'=>int,
	 * 'out_of_scope'=>int, 'failed_detail'=>array, 'status'=>string].
	 * Throws ImapIngestorException on connect/auth failure (the poller records it).
	 *
	 * One fetch per mailbox at a time, enforced here at the chokepoint every
	 * fetch path passes through (the scheduled poller AND the Fetch now button):
	 * a per-account advisory try-lock, held for the whole ingest. A second
	 * caller fails fast with ImapFetchBusyException — before any IMAP
	 * connection is made — instead of racing the first on the folder cursors
	 * and holding a second worker for the duration.
	 */
	public function poll(int $maxPerRun): array {
		$account_id = intval($this->account->key);
		$db = DbConnector::get_instance()->get_db_link();
		$lock = $db->prepare('SELECT pg_try_advisory_lock(?, ?)');
		$lock->execute(array(self::FETCH_LOCK_CLASS, $account_id));
		if (!$lock->fetchColumn()) {
			throw new ImapFetchBusyException(
				'A fetch for this mailbox is already running; it finishes on its own.');
		}
		try {
			return $this->pollLocked($maxPerRun);
		} finally {
			// Session advisory locks are reentrant per connection, so this unlock
			// pairs exactly with the acquire above whatever the body did.
			$unlock = $db->prepare('SELECT pg_advisory_unlock(?, ?)');
			$unlock->execute(array(self::FETCH_LOCK_CLASS, $account_id));
		}
	}

	private function pollLocked(int $maxPerRun): array {
		$alias = $this->resolveAlias();
		$domain = new InboundEmailDomain($alias->get('iea_ied_inbound_email_domain_id'), TRUE);
		$recipient = strtolower($alias->get_full_address());

		$this->client(); // force connect
		$this->detectCapabilities();

		if ($this->account->syncEnabled()) {
			$res = $this->ingestTrackedFolders($maxPerRun, $alias, $domain, $recipient);
		} else {
			// Off feed: the single configured folder only, no membership rows — behavior
			// is identical to the pre-sync single-folder ingest, just cursored in iif_.
			$folder = $this->ensureFolderCursor($this->account->get('iia_imap_folder') ?: 'INBOX',
				InboundImapFolder::ROLE_INBOX);
			$res = $this->ingestFolder($folder, $maxPerRun, false, $alias, $domain, $recipient);
			$this->account->recordStatus($res['status']);
		}

		$res['timing'] = $this->timing;
		$this->recordRun($res);
		return $res;
	}

	/**
	 * Ingest across every tracked folder of a sync-enabled feed. A custom label folder
	 * seeds a label membership (`ilm_`) row; special-use folders set their column instead
	 * (Junk → spam verdict, Sent → outbound, Trash → soft-delete) and INBOX / the \All
	 * coverage view ingest for storage + flag pull but record neither (§6.1, §7.3). One
	 * folder's failure never aborts the rest.
	 */
	private function ingestTrackedFolders(int $maxPerRun, $alias, $domain, $recipient): array {
		$tracked = new MultiInboundImapFolder(array(
			'account_id'     => intval($this->account->key),
			'tracked'        => true,
			'pending_create' => false, // not on the server yet — created by push, ingested next cycle
		), array('iif_inbound_imap_folder_id' => 'ASC'));
		$tracked->load();

		$folders = array();
		foreach ($tracked as $row) {
			$folders[] = new InboundImapFolder($row->key, TRUE);
		}
		// A freshly-enabled feed whose folders haven't been discovered yet still
		// ingests its configured folder so it is never dark before discoverFolders.
		if (!count($folders)) {
			$folders[] = $this->ensureFolderCursor($this->account->get('iia_imap_folder') ?: 'INBOX',
				InboundImapFolder::ROLE_INBOX);
		}

		$totalStored = 0; $totalDedup = 0; $totalSeen = 0; $totalFailed = 0; $totalOutOfScope = 0;
		$totalSourceDraft = 0; $totalDeferred = 0; $deferredFolders = 0;
		$parts = array(); $failedDetail = array();
		foreach ($folders as $folder) {
			$folderName = (string)$folder->get('iif_name');
			if ($this->pastDeadline()) {
				// Out of time: this folder waits for the next poll, whole. Its
				// cursor is untouched, so nothing is skipped — only later.
				$this->budgetExhausted = true;
				$deferredFolders++;
				$parts[] = $folderName . ': deferred (time budget)';
				continue;
			}
			try {
				$res = $this->ingestFolder($folder, $maxPerRun, $folder->isMembership(), $alias, $domain, $recipient);
				$totalStored += $res['stored']; $totalDedup += $res['dedup']; $totalSeen += $res['seen'];
				$totalFailed += $res['failed'];
				$totalOutOfScope += $res['out_of_scope'];
				$totalSourceDraft += intval($res['source_draft'] ?? 0);
				$totalDeferred += intval($res['deferred'] ?? 0);
				$failedDetail = array_merge($failedDetail, $res['failed_detail']);
				$parts[] = $res['status'];
			} catch (Throwable $e) {
				// The whole folder was lost, not one message — record it as a single
				// failure so it shows up in the run record rather than only in the log.
				$totalFailed++;
				$failedDetail[] = array('uid' => '(whole folder)', 'folder' => (string)$folder->get('iif_name'),
					'reason' => $e->getMessage());
				$parts[] = $folder->get('iif_name') . ': ERROR';
			}
		}

		$statusMsg = 'Ingested ' . (count($folders) - $deferredFolders) . ' of ' . count($folders)
			. ' folder(s): ' . $totalStored . ' stored, '
			. $totalDedup . ' duplicate, ' . $totalFailed . ' failed'
			. ($totalOutOfScope ? ', ' . $totalOutOfScope . ' out of scope' : '')
			. ($totalSourceDraft ? ', ' . $totalSourceDraft . ' source draft' : '')
			. ($totalDeferred ? ', ' . $totalDeferred . ' deferred' : '')
			. ($deferredFolders ? ', ' . $deferredFolders . ' folder(s) deferred to the next poll' : '')
			. '. ' . implode(' | ', $parts);
		$this->account->recordStatus($statusMsg);
		return array('stored' => $totalStored, 'dedup' => $totalDedup, 'seen' => $totalSeen,
			'failed' => $totalFailed, 'out_of_scope' => $totalOutOfScope,
			'source_draft' => $totalSourceDraft,
			'deferred' => $totalDeferred, 'deferred_folders' => $deferredFolders,
			'failed_detail' => $failedDetail, 'status' => $statusMsg);
	}

	/**
	 * Upsert the iif_ row for $name, migrating the legacy account-level cursor into
	 * the configured folder's row on first sync-era poll so an existing feed keeps
	 * its position (the iia_ cursor is never read again afterward).
	 */
	private function ensureFolderCursor(string $name, ?string $role = null): InboundImapFolder {
		$folder = InboundImapFolder::upsert(intval($this->account->key), $name, $role, true);

		$configured = $this->account->get('iia_imap_folder') ?: 'INBOX';
		if ($folder->get('iif_uidvalidity') === null
				&& $folder->get('iif_last_seen_uid') === null
				&& strcasecmp($name, $configured) === 0
				&& $this->account->get('iia_uidvalidity') !== null) {
			$folder->set('iif_uidvalidity', intval($this->account->get('iia_uidvalidity')));
			$folder->set('iif_last_seen_uid', intval($this->account->get('iia_last_seen_uid')));
			$folder->prepare();
			$folder->save();
		}
		return $folder;
	}

	/**
	 * Ingest one folder using its iif_ cursor: seed on first connect / UIDVALIDITY
	 * change, then ingest up to $maxPerRun new messages (UID > iif_last_seen_uid).
	 * When $recordMembership, each ingested/deduped message gets an `imf_` row for
	 * this folder (local = base = true).
	 */
	private function ingestFolder(InboundImapFolder $folder, int $maxPerRun, bool $recordMembership,
			$alias, $domain, $recipient): array {
		$client = $this->client();
		$folderName = (string)$folder->get('iif_name');
		$seekStarted = microtime(true);

		$status = $client->status(
			$folderName,
			Horde_Imap_Client::STATUS_UIDVALIDITY | Horde_Imap_Client::STATUS_UIDNEXT
		);
		$serverUidValidity = intval($status['uidvalidity'] ?? 0);
		$uidNext = intval($status['uidnext'] ?? 0);
		$highUid = $uidNext > 0 ? $uidNext - 1 : 0;

		$storedUidValidity = $folder->get('iif_uidvalidity');
		$lastSeenUid = intval($folder->get('iif_last_seen_uid'));

		// First connect, or the folder was recreated (UIDVALIDITY changed, §7.6):
		// clear the sync modseq cursor and re-seed where the feed's import scope says
		// to start. "Future only" (default) seeds to the current high UID; "Last N
		// days" seeks the boundary UID; "Full history" starts at 0. The latter two
		// fall through to the windowed backfill below.
		if ($storedUidValidity === null || intval($storedUidValidity) !== $serverUidValidity) {
			$folder->set('iif_uidvalidity', $serverUidValidity);
			$folder->set('iif_last_sync_modseq', null);
			// Where the backfill ends: UIDs at or below this existed when the cursor
			// was seeded and are governed by the day-window scope guard below; UIDs
			// above it are live mail (or mail the member later moved in, which gets a
			// fresh high UID) and are never date-filtered.
			$folder->set('iif_seed_high_uid', $highUid);
			$scope = $this->account->importScope();
			if ($scope === InboundImapAccount::SCOPE_FULL) {
				$lastSeenUid = 0;
			} elseif ($scope === InboundImapAccount::SCOPE_DAYS) {
				$lastSeenUid = $this->seekCursorForCutoff($client, $folderName,
					(string)$this->account->importCutoffUtc(), $highUid);
			} else {
				$folder->set('iif_last_seen_uid', $highUid);
				$folder->prepare();
				$folder->save();
				return array('stored' => 0, 'dedup' => 0, 'seen' => 0, 'failed' => 0, 'out_of_scope' => 0,
					'source_draft' => 0,
					'failed_detail' => array(), 'status' => $folderName . ': seeded cursor');
			}
			$folder->set('iif_last_seen_uid', $lastSeenUid);
		}

		// Nothing above the cursor → done.
		if ($lastSeenUid >= $highUid) {
			$folder->set('iif_last_seen_uid', max($lastSeenUid, $highUid));
			$folder->prepare();
			$folder->save();
			return array('stored' => 0, 'dedup' => 0, 'seen' => 0, 'failed' => 0, 'out_of_scope' => 0,
				'source_draft' => 0,
				'failed_detail' => array(), 'status' => $folderName . ': no new');
		}

		// Walk forward one bounded UID window per run (oldest-first). A numeric UID
		// FETCH range (not SEARCH) avoids the ESEARCH form Gmail rejects. The
		// window search jumps deserts — see nextOccupiedWindow.
		list($lastSeenUid, $windowEnd, $uids, $metaFetch) =
			$this->nextOccupiedWindow($client, $folderName, $lastSeenUid, $highUid, max(1, $maxPerRun));
		$this->lapFolder($folderName, 'seek', microtime(true) - $seekStarted);

		$router = new InboundEmailRouter();

		// Day-window scope guard (specs/imap_seed_scope_guard.md): the seek decides
		// where to LOOK, the scope decides what to KEEP. During the backfill — UIDs
		// at or below the seed-time high — a message whose INTERNALDATE predates the
		// window is skipped and counted, with the cursor advancing past it, so a
		// conservative seek cursor costs walk time, never out-of-scope mail in the
		// mailbox. Beyond the seed-time high the guard is off: mail moved into the
		// folder later gets a fresh high UID and is ingested whatever its age.
		$scopeCutoffUtc = $this->account->importScope() === InboundImapAccount::SCOPE_DAYS
			? (string)$this->account->importCutoffUtc() : '';
		$seedHighUid = intval($folder->get('iif_seed_high_uid'));

		$stored = 0; $dedup = 0; $seen = 0; $failed = 0; $outOfScope = 0; $sourceDraft = 0;
		$deferred = 0;
		$failedDetail = array();
		$maxUid = $windowEnd;
		foreach ($uids as $uid) {
			if ($this->pastDeadline()) {
				// Out of time: hold the cursor below this UID so the next poll
				// starts here. Not walked, so not seen — the reconciliation in
				// the run record balances without them.
				$this->budgetExhausted = true;
				$deferred = count($uids) - $seen;
				$maxUid = min($maxUid, $uid - 1);
				break;
			}
			$seen++;
			$data = $metaFetch[$uid] ?? null;
			if ($data === null) {
				// The UID was in the window but the server returned nothing for it.
				// Counting it keeps stored + dup + failed reconciled against seen.
				$failed++;
				$failedDetail[] = array('uid' => $uid, 'folder' => $folderName,
					'reason' => 'The server returned no data for this message.');
				$maxUid = min($maxUid, $uid - 1);
				continue;
			}

			if ($this->outOfScopeForBackfill($uid, $data, $scopeCutoffUtc, $seedHighUid)) {
				$outOfScope++;
				continue;
			}

			// A draft the source has not sent is not mail. Skipping advances the
			// cursor past it, which is right: the next autosave replaces it with a
			// new UID, and if it is ever sent the sent copy arrives as its own
			// message. A first-class counter, never a silent skip, so the run
			// record's reconciliation still balances.
			if ($this->isSourceDraft($data)) {
				$sourceDraft++;
				continue;
			}

			$messageStarted = microtime(true);
			try {
				$result = $this->ingestOne($client, $folder, $uid, $data, $router,
					$alias, $domain, $recipient, $serverUidValidity, $recordMembership);
				if ($result['dedup']) { $dedup++; } else { $stored++; }
			} catch (Throwable $e) {
				// Logged in one bounded batch by recordRun, not one call per message.
				$failed++;
				$failedDetail[] = array('uid' => $uid, 'folder' => $folderName, 'reason' => $e->getMessage());
				$maxUid = min($maxUid, $uid - 1);
			}
			// The network half (bodies, inline images) and the stored half
			// (the transaction) are lapped separately; a failure mid-way lands
			// in whichever half it reached.
			$storeSeconds = $this->lastStoreSeconds;
			$this->lastStoreSeconds = 0.0;
			$this->lapFolder($folderName, 'fetch', max(0.0, microtime(true) - $messageStarted - $storeSeconds));
			$this->lapFolder($folderName, 'store', $storeSeconds);
		}

		$folder->set('iif_last_seen_uid', max($lastSeenUid, $maxUid));
		$folder->prepare();
		$folder->save();

		return array('stored' => $stored, 'dedup' => $dedup, 'seen' => $seen,
			'failed' => $failed, 'out_of_scope' => $outOfScope, 'source_draft' => $sourceDraft,
			'deferred' => $deferred,
			'failed_detail' => $failedDetail,
			'status' => $folderName . ': ' . $stored . ' stored, ' . $dedup . ' dup, ' . $failed . ' failed'
				. ($outOfScope ? ', ' . $outOfScope . ' out of scope' : '')
				. ($sourceDraft ? ', ' . $sourceDraft . ' source draft' : '')
				. ($deferred ? ', ' . $deferred . ' deferred (time budget)' : ''));
	}

	// ── Run record ─────────────────────────────────────────────────────────

	/**
	 * Reduce one poll's counters to the numbers a human needs: failures grouped by
	 * reason (fifty messages failing the same way is one thing to fix, not fifty),
	 * and an `unaccounted` reconciliation of stored + duplicate + failed against the
	 * UIDs actually walked. A non-zero unaccounted means messages went missing
	 * without anything reporting it, which is the failure mode a counter alone hides.
	 *
	 * Pure — no DB, no IMAP, no logging. recordRun() does the writing.
	 *
	 * @return array ['note'=>string, 'success'=>bool, 'unaccounted'=>int, 'failed_reasons'=>array]
	 */
	public static function summarizeRun(array $res, string $subject = ''): array {
		return MailRunRecord::summarize($res, $subject, MailRunRecord::DIMENSIONS_POLL);
	}

	/**
	 * Persist what this poll did: one evl_event_logs row plus bounded error-log
	 * detail. An idle poll (nothing stored, nothing failed, nothing unaccounted)
	 * writes nothing — a mailbox polled every few minutes forever would otherwise
	 * bury the runs that matter under thousands of no-op rows. A backfill therefore
	 * leaves one row per batch, which is the progress trail.
	 */
	private function recordRun(array $res): void {
		$summary = self::summarizeRun($res, 'account ' . $this->account->key . ' ' . $this->describeAccount());

		if (intval($res['stored'] ?? 0) === 0 && intval($res['failed'] ?? 0) === 0
				&& $summary['unaccounted'] === 0) {
			return;
		}

		// Where the clock went, on its own line of the note — so a run that
		// stored one message in two minutes says which two minutes.
		$timing = (array)($res['timing'] ?? array());
		$total = 0.0;
		foreach ($timing as $phase => $seconds) {
			if ($phase === 'folders') {
				foreach ((array)$seconds as $phases) { $total += array_sum((array)$phases); }
			} else {
				$total += (float)$seconds;
			}
		}
		$summary['note'] .= "\n  " . self::describeTiming($timing, $total);

		MailRunRecord::write(self::RUN_EVENT, $summary, (array)($res['failed_detail'] ?? array()),
			function (array $f): string {
				return 'failed UID ' . $f['uid'] . ' in ' . $f['folder'] . ' — ' . $f['reason'];
			});
	}

	/** Human label for the account in logs — never the password or token. */
	private function describeAccount(): string {
		$label = (string)($this->account->get('iia_label') ?: $this->account->get('iia_username'));
		return $label !== '' ? '(' . $label . ')' : '';
	}

	/**
	 * Batched fetch of structure + envelope + header text + size for the UID
	 * window [$startUid, $endUid]. We FETCH a numeric UID range rather than UID
	 * SEARCH: Gmail advertises ESEARCH but rejects the `SEARCH RETURN (...)` form
	 * Horde emits ("BAD Could not parse command"), and FETCH avoids ESEARCH
	 * entirely. A numeric (non-`*`) range also avoids the "N:* always matches the
	 * highest message" caveat. Missing UIDs in the range simply aren't returned.
	 */
	/**
	 * Find where a "last N days" feed should start reading: the cursor just below
	 * the oldest message still inside the window. Two strategies, tried in order:
	 *
	 * 1. Server-side `UID SEARCH SINCE` (seekCursorBySearch) — exact, one round
	 *    trip, when the server cooperates.
	 * 2. Bisection (seekCursorInner). IMAP assigns UIDs in strictly ascending
	 *    arrival order (RFC 3501 §2.3.1.1), so the UID space is sorted by
	 *    INTERNALDATE and can be bisected. Each probe is a FETCH of a narrow UID
	 *    band asking only for INTERNALDATE — cheap, and on the same numeric
	 *    `UID FETCH` path the ingest window uses.
	 *
	 * A band rather than a single UID because deletions leave gaps — a lone probe
	 * often lands on nothing. An empty band proves only that those UIDs are gone
	 * — nothing about where the date boundary is — so the bisection never concedes
	 * unprobed UID space over one; it asks the bottom of the remaining range
	 * instead, where the answer is definitive.
	 *
	 * Returns the seed cursor: one below the oldest in-window UID, or $highUid when
	 * the whole mailbox predates the cutoff (nothing to backfill). Fail-soft: an
	 * inconclusive seek returns the best lower bound reached, which imports somewhat
	 * more than asked rather than silently importing nothing — and the storage-time
	 * scope guard in ingestFolder() keeps the excess out of the mailbox, so the
	 * fail-soft direction costs walk time, never out-of-scope mail.
	 */
	private function seekCursorForCutoff(ImapClient $client, string $folder, string $cutoffUtc, int $highUid): int {
		if ($cutoffUtc === '' || $highUid < 1) { return max(0, $highUid); }

		// Ask the server outright before probing — exact and one round trip when
		// the server cooperates, null when it does not (§3.4 of the spec).
		$found = $this->seekCursorBySearch($client, $folder, $cutoffUtc, $highUid);
		if ($found === null) {
			$found = $this->seekCursorInner($client, $folder, $cutoffUtc, $highUid);
			$found['method'] = 'bisect';
		}
		$this->recordSeedProof($client, $folder, $cutoffUtc, $highUid, $found);
		return $found['cursor'];
	}

	/**
	 * The boundary by server-side search: one `UID SEARCH SINCE` returns exactly
	 * the in-window UIDs, so the cursor is one below their minimum — no probing,
	 * no convergence question. Not every server cooperates (Gmail advertises
	 * ESEARCH yet rejects the `UID SEARCH RETURN (...)` form Horde emits), so any
	 * failure or unusable answer returns null and the bisection decides instead.
	 *
	 * SINCE compares INTERNALDATE at day granularity, so the cursor can sit up to
	 * a day before the cutoff — the documented fail-toward-importing-more
	 * direction; the storage-time scope guard keeps the excess out of the mailbox.
	 * An empty result is a real answer (the whole mailbox predates the window)
	 * and seeds at the top; the seed proof's bracketing probes are recorded for
	 * this path exactly as for the bisection, so a server whose SEARCH lies still
	 * leaves checkable evidence behind (`isp_below_time` / `boundaryHolds()`).
	 *
	 * @return array{cursor:int,probes:int,converged:bool,method:string}|null
	 */
	private function seekCursorBySearch(ImapClient $client, string $folder, string $cutoffUtc, int $highUid): ?array {
		try {
			// Horde's dateSearch requires a DateTime — the sanctioned third-party
			// exception to the no-DateTime rule.
			$since = new DateTime($cutoffUtc, new DateTimeZone('UTC'));
			$query = new Horde_Imap_Client_Search_Query();
			$query->dateSearch($since, Horde_Imap_Client_Search_Query::DATE_SINCE);
			$res = $client->search($folder, $query);

			$match = $res['match'] ?? null;
			if (!$match instanceof Horde_Imap_Client_Ids) {
				return null;
			}
			$ids = array_map('intval', iterator_to_array($match, false));
			if (!count($ids)) {
				return array('cursor' => $highUid, 'probes' => 0, 'converged' => true, 'method' => 'search');
			}
			$min = min($ids);
			if ($min < 1) {
				return null; // a nonsense UID — distrust the whole answer
			}
			return array('cursor' => max(0, min($min - 1, $highUid)),
				'probes' => 0, 'converged' => true, 'method' => 'search');
		} catch (Throwable $e) {
			return null;
		}
	}

	/**
	 * The bisection itself. Split from its caller so that every way out of it —
	 * there are three — is recorded, rather than only the one at the bottom.
	 *
	 * @return array{cursor:int,probes:int,converged:bool}
	 */
	private function seekCursorInner(ImapClient $client, string $folder, string $cutoffUtc, int $highUid): array {
		$lo = 1;
		$hi = $highUid;
		$probes = 0;
		$stride = self::SEEK_BAND;
		while ($lo <= $hi && $probes < self::SEEK_MAX_PROBES) {
			$probes++;
			$mid = intdiv($lo + $hi, 2);
			$probe = $this->probeOldestInBand($client, $folder, $mid, min($hi, $mid + self::SEEK_BAND - 1));
			if ($probe === null) {
				// Every UID in the band is gone. Deletions cluster, so this says
				// nothing about the boundary — probe the bottom of the remaining
				// range instead. Everything below $lo is already proven pre-cutoff
				// or deleted, so the oldest existing UID at/above $lo settles it.
				if ($probes >= self::SEEK_MAX_PROBES) { break; }
				$probes++;
				$bottom = $this->probeOldestInBand($client, $folder, $lo, min($hi, $lo + $stride - 1));
				if ($bottom === null) {
					// That band is gone too — a safe advance, and a DOUBLING one:
					// at Gmail scale the deleted region below the live mail can be
					// hundreds of thousands of UIDs, and a fixed advance crawls it
					// linearly until the probe budget dies (48 probes of 64 reach
					// UID 1,536 of 270,948). Doubling makes the desert logarithmic;
					// any advance still only ever covers proven-empty bands.
					$lo = $lo + $stride;
					$stride = min($stride * 2, self::SEEK_BAND_MAX);
					continue;
				}
				$stride = self::SEEK_BAND;
				if ($bottom['date'] === '' || $bottom['date'] >= $cutoffUtc) {
					// The oldest message above the proven floor is in-window —
					// it IS the boundary.
					return array('cursor' => max(0, min($bottom['uid'] - 1, $highUid)),
						'probes' => $probes, 'converged' => true);
				}
				$lo = $bottom['uid'] + 1;
				continue;
			}
			$stride = self::SEEK_BAND;
			// An unreadable INTERNALDATE ('') counts as in-window: fail toward
			// importing more rather than silently skipping past real mail.
			if ($probe['date'] === '' || $probe['date'] >= $cutoffUtc) {
				$hi = $probe['uid'] - 1;
			} else {
				$lo = $probe['uid'] + 1;
			}
		}

		// $lo - 1 is always a safe cursor: every UID below $lo is proven either
		// pre-cutoff or deleted. On full convergence it sits exactly one below the
		// oldest in-window message; when the probe budget runs out first it
		// imports somewhat more than asked — the documented fail-soft direction —
		// never less.
		return array('cursor' => max(0, min($lo - 1, $highUid)),
			'probes' => $probes, 'converged' => ($probes < self::SEEK_MAX_PROBES));
	}

	/**
	 * Leave the evidence behind for where this folder started reading
	 * (specs/mail_import_loss_proof.md § B). Two extra probes bracket the chosen
	 * cursor — the newest message under it, which should predate the cutoff, and
	 * the oldest over it, which should not — so the decision can be checked later
	 * instead of taken on trust.
	 *
	 * Best effort throughout: this is a record of a poll, never a condition of
	 * one, so a probe or a write that fails is logged and the mail still flows.
	 */
	private function recordSeedProof(ImapClient $client, string $folder, string $cutoffUtc,
			int $highUid, array $found): void {
		try {
			$cursor = $found['cursor'];
			$below = ($cursor >= 1)
				? $this->probeNewestInBand($client, $folder, max(1, $cursor - self::SEEK_BAND + 1), $cursor)
				: null;
			$above = ($cursor < $highUid)
				? $this->probeOldestInBand($client, $folder, $cursor + 1, min($highUid, $cursor + self::SEEK_BAND))
				: null;

			$proof = InboundImapSeedProof::record(array(
				'isp_iia_inbound_imap_account_id' => intval($this->account->key),
				'isp_folder'      => substr($folder, 0, 255),
				'isp_scope'       => $this->account->importScope(),
				'isp_cutoff_time' => $cutoffUtc,
				'isp_high_uid'    => $highUid,
				'isp_cursor_uid'  => $cursor,
				'isp_method'      => (string)($found['method'] ?? 'bisect'),
				'isp_probes'      => intval($found['probes']),
				'isp_converged'   => (bool)$found['converged'],
				'isp_below_uid'   => $below !== null ? $below['uid'] : null,
				// '' is an unreadable INTERNALDATE; stored as NULL, because unknown
				// must never read as evidence either way.
				'isp_below_time'  => ($below !== null && $below['date'] !== '') ? $below['date'] : null,
				'isp_above_uid'   => $above !== null ? $above['uid'] : null,
				'isp_above_time'  => ($above !== null && $above['date'] !== '') ? $above['date'] : null,
			));

			// A broken boundary means the feed skipped mail the window claims, which
			// is the one outcome nobody should have to go looking for.
			if ($proof !== null && $proof->boundaryHolds() === false) {
				error_log('ImapIngestor: seed proof for account ' . $this->account->key
					. ' — ' . $proof->describe());
			}
		} catch (Throwable $e) {
			error_log('ImapIngestor::recordSeedProof failed for account ' . $this->account->key
				. ' folder ' . $folder . ': ' . $e->getMessage());
		}
	}

	/**
	 * INTERNALDATE of the lowest existing UID in [$from,$to], as
	 * ['uid'=>int,'date'=>'Y-m-d H:i:s' UTC], or null when the band holds nothing.
	 */
	private function probeOldestInBand(ImapClient $client, string $folder, int $from, int $to): ?array {
		return $this->probeEdgeInBand($client, $folder, $from, $to, false);
	}

	/**
	 * INTERNALDATE of the HIGHEST existing UID in [$from,$to] — the other end of
	 * the same question. The seed proof needs it: the newest message at or below
	 * the chosen cursor is the one whose date has to predate the cutoff for the
	 * boundary to hold.
	 */
	private function probeNewestInBand(ImapClient $client, string $folder, int $from, int $to): ?array {
		return $this->probeEdgeInBand($client, $folder, $from, $to, true);
	}

	/**
	 * One end or the other of a UID band, with its INTERNALDATE in UTC.
	 * Shared so the timezone handling below exists exactly once.
	 */
	private function probeEdgeInBand(ImapClient $client, string $folder, int $from, int $to,
			bool $newest): ?array {
		if ($from > $to) { return null; }

		$query = new Horde_Imap_Client_Fetch_Query();
		$query->imapDate();
		$res = $client->fetch($folder, $query, array(
			'ids' => new Horde_Imap_Client_Ids($from . ':' . $to),
		));

		$edge = null;
		foreach ($res->ids() as $uid) {
			$uid = intval($uid);
			if ($uid < $from || $uid > $to) { continue; }   // a client that ignores the range
			if ($edge === null || ($newest ? $uid > $edge : $uid < $edge)) { $edge = $uid; }
		}
		if ($edge === null) { return null; }

		$data = $res[$edge] ?? null;
		if ($data === null) { return null; }
		return array('uid' => $edge, 'date' => $this->internalDateUtc($data));
	}

	/**
	 * One fetched message's INTERNALDATE as a UTC 'Y-m-d H:i:s' string, or ''
	 * when unreadable. Shared by the boundary probes and the scope guard so the
	 * two compare against the cutoff on exactly the same clock, and so the
	 * timezone handling exists exactly once:
	 *
	 * An unparsable INTERNALDATE (Horde falls back to epoch -1 and flags
	 * error()) reports as '' — every caller treats unknown as in-window.
	 *
	 * The cutoff being compared against is a UTC string, and INTERNALDATE
	 * carries the source server's own offset — which the DateTime KEEPS (a
	 * constructed DateTime ignores its timezone argument when the string has
	 * an offset), so formatting without converting first would shift the
	 * boundary by up to ±14 hours.
	 */
	private function internalDateUtc($data): string {
		$date = $data->getImapDate();
		if (!$date || $date->error()) {
			return '';
		}
		$date = clone $date;
		$date->setTimezone(new DateTimeZone('UTC'));
		return $date->format('Y-m-d H:i:s');
	}

	/**
	 * The scope-guard decision for one walked message: skip it only when ALL of —
	 * the feed has a day window ($cutoffUtc non-empty), the UID is part of the
	 * backfill (at or below the seed-time high; a later arrival or a message the
	 * member moved in gets a higher UID and is never date-filtered), and its
	 * INTERNALDATE is known and predates the window. Unknown ('') counts as
	 * in-window — the same fail-toward-keeping direction the seek uses.
	 */
	private function outOfScopeForBackfill(int $uid, $data, string $cutoffUtc, int $seedHighUid): bool {
		if ($cutoffUtc === '' || $seedHighUid < 1 || $uid > $seedHighUid) {
			return false;
		}
		$idate = $this->internalDateUtc($data);
		return $idate !== '' && $idate < $cutoffUtc;
	}

	/**
	 * True when the source still holds this message as a draft (\Draft flag).
	 *
	 * Folder-agnostic on purpose. The Drafts special-use folder is untracked by
	 * default, but a draft is not confined to it: Gmail files every draft in
	 * [Gmail]/All Mail too, and a user's label can hold one as well. The flag
	 * travels with the message, so asking it is the only test that covers every
	 * folder a draft can appear in.
	 *
	 * Fails toward ingesting: a server that returns no flags leaves the message
	 * ordinary mail, because losing real mail is worse than storing a draft.
	 */
	private function isSourceDraft($data): bool {
		try {
			$flags = $data->getFlags();
		} catch (Throwable $e) {
			return false;
		}
		foreach ((array)$flags as $flag) {
			if (strtolower(ltrim((string)$flag, '\\')) === 'draft') {
				return true;
			}
		}
		return false;
	}

	/**
	 * The next UID window at/above $lastSeenUid that actually holds messages,
	 * as [cursor floor, window end, sorted uids, metaFetch].
	 *
	 * A window that fetches empty proves only that those UIDs are deleted —
	 * routine at Gmail scale, where archiving leaves the folder's UID space a
	 * desert — so the floor advances over it and the window doubles, inside one
	 * poll. An empty-range UID FETCH costs the server nothing; without the jump,
	 * a 270,000-UID desert takes weeks of polls to cross at $maxPerRun a time.
	 * Every advance covers only fetched-and-empty ranges, so no message is ever
	 * jumped over.
	 *
	 * A jump that lands in dense mail is trimmed back to $maxPerRun messages
	 * (window end pulled to the last uid kept), so one poll never ingests more
	 * than a normal window — the next poll continues from there.
	 */
	private function nextOccupiedWindow(ImapClient $client, string $folderName,
			int $lastSeenUid, int $highUid, int $maxPerRun): array {
		$span = $maxPerRun;
		while (true) {
			$windowEnd = min($highUid, $lastSeenUid + $span);
			$metaFetch = $this->fetchWindow($client, $folderName, $lastSeenUid + 1, $windowEnd);
			$uids = array();
			foreach ($metaFetch->ids() as $uid) {
				$uid = intval($uid);
				if ($uid > $lastSeenUid && $uid <= $windowEnd) { $uids[] = $uid; }
			}
			sort($uids, SORT_NUMERIC);
			if (!empty($uids) || $windowEnd >= $highUid) {
				if (count($uids) > $maxPerRun) {
					$uids = array_slice($uids, 0, $maxPerRun);
					$windowEnd = intval($uids[count($uids) - 1]);
				}
				return array($lastSeenUid, $windowEnd, $uids, $metaFetch);
			}
			$lastSeenUid = $windowEnd;
			$span *= 2;
		}
	}

	private function fetchWindow(ImapClient $client, string $folder, int $startUid, int $endUid) {
		$query = new Horde_Imap_Client_Fetch_Query();
		$query->structure();
		$query->envelope();
		$query->size();
		$query->imapDate(); // INTERNALDATE, for the day-window scope guard
		$query->flags();    // \Draft, so a half-written source draft is never ingested
		$query->headerText(array('peek' => true));
		return $client->fetch($folder, $query, array(
			'ids' => new Horde_Imap_Client_Ids($startUid . ':' . $endUid),
		));
	}

	/**
	 * Extract + store one message and its attachment manifest. Returns
	 * ['dedup'=>bool]. Idempotent: dedup (UNIQUE message-id+recipient) means the
	 * row already exists, so the manifest is not rewritten. When $recordMembership,
	 * an `ilm_` label membership for ($folder) is attached (present_local = present_base
	 * = true) on both the new-row and dedup paths — the dedup path is where a message
	 * already stored from another folder gains its second label (§7.3).
	 *
	 * ALL OR NOTHING. The fetches happen first and outside any transaction; every
	 * write then lands inside one, so a message never commits without its manifest
	 * and "already stored" always means "stored completely". That is what the dedup
	 * path's decision not to rewrite the manifest rests on.
	 */
	private function ingestOne($client, InboundImapFolder $folder, $uid, $data, InboundEmailRouter $router,
			$alias, $domain, $recipient, $serverUidValidity, bool $recordMembership = false): array {

		$folderName = (string)$folder->get('iif_name');

		$structure = $data->getStructure();

		// Classify parts: pick the first inline text/plain & text/html as the body;
		// everything else non-multipart is an attachment-manifest entry.
		$bodyPlainId = null; $bodyHtmlId = null; $attachParts = array();
		foreach ($structure->partIterator() as $part) {
			if ($part->getPrimaryType() === 'multipart') { continue; }
			$id = (string)$part->getMimeId();
			$type = strtolower((string)$part->getType());
			$name = $part->getName();
			$disp = $part->getDisposition();
			$isInlineText = ($type === 'text/plain' || $type === 'text/html')
				&& $disp !== 'attachment' && ($name === null || $name === '');
			if ($isInlineText && $type === 'text/plain' && $bodyPlainId === null) { $bodyPlainId = $id; continue; }
			if ($isInlineText && $type === 'text/html'  && $bodyHtmlId  === null) { $bodyHtmlId  = $id; continue; }
			$attachParts[] = $part;
		}

		// Fetch only the chosen text parts (decoded), never the attachments.
		$plain = $bodyPlainId !== null ? $this->fetchTextPart($client, $folderName, $uid, $structure, $bodyPlainId) : '';
		$html  = $bodyHtmlId  !== null ? $this->fetchTextPart($client, $folderName, $uid, $structure, $bodyHtmlId)  : '';

		// …except inline images, which ARE body content: the HTML references
		// them by cid: and the reader can only render what is file-backed
		// (specs/bugfix_sealed_inline_images.md). Their bytes are fetched here —
		// network I/O beside the body fetches, outside the transaction below —
		// and adopted into Files by the stored half. Best-effort: a failed fetch
		// leaves the part reference-backed, exactly as it was.
		$inlineBytes = $this->fetchInlineImageParts($client, $folderName, $uid, $structure, $attachParts);

		// Generous ceiling: truncate-and-mark rather than skip.
		if (strlen($plain) + strlen($html) > self::TEXT_BODY_CEILING) {
			$marker = "\n\n[Message body truncated — exceeded the inbound IMAP text-body ceiling. "
				. "Fetch the full body part on demand if needed.]";
			if ($html !== '') {
				$html = substr($html, 0, self::TEXT_BODY_CEILING) . $marker;
				$plain = ($plain !== '') ? substr($plain, 0, 4096) . $marker : '';
			} else {
				$plain = substr($plain, 0, self::TEXT_BODY_CEILING) . $marker;
			}
		}

		$headers = $this->parseHeaderText((string)$data->getHeaderText());

		// Everything from here down is ONE unit of work
		// (specs/mail_import_loss_proof.md D1). The message row and its attachment
		// manifest used to be separate statements, so a crash or a concurrent
		// reader could see — or permanently leave — a message with no attachments:
		// on retry the row already existed, storeExtracted reported dedup, and the
		// manifest write is skipped on the dedup path by design. A committed row
		// now always carries its manifest, which is what lets every other path
		// treat "the message is here" as "its attachments are here too".
		//
		// The transaction opens AFTER the body fetches above: those are network
		// round trips to the source server, and a transaction must never be held
		// open across them.
		$db = DbConnector::get_instance()->get_db_link();
		$owns_tx = !$db->inTransaction();
		if ($owns_tx) {
			$db->beginTransaction();
		}

		$storeStarted = microtime(true);
		try {
			$outcome = $this->ingestOneStored($folder, $uid, $data, $router,
				$alias, $domain, $recipient, $serverUidValidity, $recordMembership,
				$attachParts, $plain, $html, $headers, $inlineBytes);
			if ($owns_tx) {
				$db->commit();
			}
			$this->lastStoreSeconds = microtime(true) - $storeStarted;
			return $outcome;
		} catch (Throwable $e) {
			$this->lastStoreSeconds = microtime(true) - $storeStarted;
			// Roll the whole message back and let it out. ingestFolder counts it as
			// a failure and holds the cursor below this UID, so the next poll
			// retries it — which is also how an InboundStoreCollisionException
			// resolves: the retry's pre-validate SELECT reads cleanly (it runs
			// before any INSERT, so nothing has aborted the transaction) and
			// reports an ordinary dedup.
			if ($owns_tx && $db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * The stored half of ingestOne: everything that writes, run inside the
	 * caller's transaction. Split out so the transaction boundary is one
	 * try/catch rather than wrapped around two hundred lines.
	 */
	private function ingestOneStored(InboundImapFolder $folder, $uid, $data, InboundEmailRouter $router,
			$alias, $domain, $recipient, $serverUidValidity, bool $recordMembership,
			array $attachParts, string $plain, string $html, array $headers,
			array $inlineBytes = array()): array {

		$folderName = (string)$folder->get('iif_name');
		$envelope = $data->getEnvelope();

		$msg = array(
			'sender'  => $this->addrString($envelope->from),
			'subject' => (string)$envelope->subject,
			'body_plain' => $plain,
			'body_html'  => $html,
			'message_id_header' => (string)$envelope->message_id,
			'headers' => $headers,
			'size_bytes' => intval($data->getSize()),
			'received_time' => $this->envelopeDate($envelope),
			'imap_account_id' => intval($this->account->key),
			'imap_uid' => intval($uid),
			'imap_uidvalidity' => intval($serverUidValidity),
			'imap_folder' => $folderName,
		);

		// Composed-copy dedup (specs/bugfix_promoted_sent_row_sealing.md): a
		// message this platform composed already exists as an outbound (or
		// mid-send draft) row, and on a sealed mailbox that row's iem_recipient
		// is ciphertext — the (Message-ID, recipient, direction) unique key can
		// structurally never fire against it. So EVERY folder pass dedups by
		// Message-ID alone against the alias's composed rows before storing —
		// not just the Sent-role pass: Gmail's All Mail coverage pass meets the
		// sent message first and used to store its own copy. On a hit: adopt the
		// locator and this folder's membership onto the composer's copy; no new
		// row, no promotion needed (the copy is already outbound).
		//
		// A self-addressed send is no exception (specs/bugfix_self_addressed_send.md):
		// its appearance outside the Sent folder IS the delivered copy, but the
		// composer's row is still the message. Storing the delivery as a second row
		// put it in the conversation twice — once tagged Sent, once reading as a
		// reply to it. It reconciles like any other composed copy, and
		// iem_self_delivered is what carries that one row into the Inbox, which is
		// otherwise defined as "not outbound".
		$sentRole = (string)$folder->get('iif_role') === InboundImapFolder::ROLE_SENT;
		$composedId = $this->aliasMessageIdByMessageId($alias, (string)$envelope->message_id, true);
		if ($composedId > 0) {
			$this->adoptLocatorIfMissing($composedId, intval($uid), intval($serverUidValidity), $folderName);
			if (!$sentRole && $this->envelopeAddressedToSelf($envelope)) {
				InboundEmailMessage::markSelfDelivered($composedId);
			}
			if ($recordMembership) {
				$this->recordFolderMembership($folder, $composedId, intval($uid), intval($serverUidValidity));
			}
			return array('dedup' => true, 'message_id' => $composedId);
		}

		// §9 Sent dedup: a message in the Sent folder may be one Joinery already
		// stored as a local outbound row (or a provider-filed copy of it). Its
		// recipient differs from the alias, so the (Message-ID, recipient) unique
		// key won't fire — dedup by Message-ID alone against the alias's rows. On a
		// hit, adopt the locator (so attachments stay fetchable) and attach Sent
		// membership; no new row. (Composed rows were consumed above, so a hit
		// here is an inbound row — the coverage pass's copy — and gets promoted.)
		if ($sentRole) {
			$existingId = $this->aliasMessageIdByMessageId($alias, (string)$envelope->message_id);
			if ($existingId > 0) {
				$this->adoptLocatorIfMissing($existingId, intval($uid), intval($serverUidValidity), $folderName);
				if ($recordMembership) {
					$this->recordFolderMembership($folder, $existingId, intval($uid), intval($serverUidValidity));
				}
				// The existing row is usually the \All coverage pass's copy — Gmail's
				// All Mail sorts before Sent Mail in discovery, so it always stores a
				// sent message first, as an ordinary inbound row. Being filed in Sent
				// is the source's own word that the user sent it: promote the row to
				// outbound so the reader shows sent mail, not an incoming message
				// from yourself. A self-addressed send stays inbound — it belongs in
				// the Inbox exactly like it does in the source mailbox.
				if (!$this->envelopeAddressedToSelf($envelope)) {
					$this->markDirectionOutbound($existingId);
				}
				return array('dedup' => true, 'message_id' => $existingId);
			}
		}

		$auth = array('dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none');
		$result = $router->storeExtracted($msg, $alias, $domain, $recipient, $auth);

		// Resolve the stored message id: the freshly-saved row, or — on a dedup hit
		// (already stored from another folder) — the existing row by the same
		// (Message-ID, recipient) dedup key.
		$messageId = 0;
		if (!$result['dedup'] && $result['message'] !== null) {
			$messageId = intval($result['message']->key);
			// Write the manifest only for a freshly-stored row. On the dedup path the
			// existing row already has its own, and since both are written in one
			// transaction that is now a guarantee rather than an assumption — a
			// half-stored message never commits (D1).
			$this->writeManifest($messageId, $attachParts);
			// Inline images fetched by the network half become file-backed rows
			// now, so the reader's cid: rewrite can serve them
			// (specs/bugfix_sealed_inline_images.md). Sealing needs only the
			// owner's PUBLIC key, so this works exactly the same under cron.
			$this->adoptInlineParts($messageId, $inlineBytes, $router);
			// A brand-new Sent-folder message is mail the user sent — mark it outbound
			// so the reader renders it as a sent message (native-client or Gmail
			// no-local-row path, §9). Self-addressed mail stays inbound (see above).
			if ((string)$folder->get('iif_role') === InboundImapFolder::ROLE_SENT
					&& !$this->envelopeAddressedToSelf($envelope)) {
				$this->markDirectionOutbound($messageId);
			}
		} elseif ($result['dedup']) {
			$messageId = $this->existingMessageId((string)$envelope->message_id, $recipient);
			// Same promotion on the (Message-ID, recipient) dedup path — reachable
			// when the §9 lookup missed because the alias's copy is soft-deleted.
			if ($messageId > 0 && (string)$folder->get('iif_role') === InboundImapFolder::ROLE_SENT
					&& !$this->envelopeAddressedToSelf($envelope)) {
				$this->markDirectionOutbound($messageId);
			}
		}

		// Attach this folder's label (the message carries the binding's label; the ilm_
		// row is truth + an in-sync shadow). On the dedup path this is where a message
		// stored from another folder gains a second label; the new-row path seeds the
		// first.
		if ($recordMembership && $messageId > 0) {
			$this->recordFolderMembership($folder, $messageId, intval($uid), intval($serverUidValidity));
		}

		// Spam (specs/inbound_email_spam_filtering.md): a message the remote filed in
		// a junk-role folder is spam — give the verdict its meaning for IMAP mail.
		if ($messageId > 0 && (string)$folder->get('iif_role') === InboundImapFolder::ROLE_JUNK) {
			$this->markSpam($messageId);
		}

		// Trash arrival (§7.5): a message the remote moved to its Trash folder is a
		// remote delete — soft-delete the local row (the column is the truth) and point
		// the locator at this Trash copy, which is also the sync push's "already in
		// Trash" signal, so it is never re-pushed to Trash.
		if ($messageId > 0 && (string)$folder->get('iif_role') === InboundImapFolder::ROLE_TRASH) {
			$this->markDeletedInTrash($messageId, intval($uid), intval($serverUidValidity), $folderName);
		}

		return array('dedup' => (bool)$result['dedup'], 'message_id' => $messageId);
	}

	/**
	 * Record that a message carries a custom label: write the single ilm_ row that is
	 * both the truth (present_local) and the in-sync IMAP shadow (present_base + this
	 * folder's UID) for two-way reconciliation. Only called for label-binding folders.
	 * Idempotent.
	 */
	private function recordFolderMembership(InboundImapFolder $folder, int $messageId, int $uid, int $uidvalidity): void {
		if ($messageId <= 0) {
			return;
		}
		$labelId = $folder->ensureLabel();
		if ($labelId === null) {
			return; // special-use / coverage folder: a column, not a label
		}
		InboundLabelMember::setBaseline($messageId, $labelId, intval($folder->key), true, $uid, $uidvalidity);
	}

	/**
	 * Soft-delete a row that arrived in the source Trash folder and point its locator at
	 * that Trash copy. The locator doubling as the trash shadow is what keeps the sync
	 * push from MOVE-ing an already-trashed message back to Trash.
	 */
	private function markDeletedInTrash(int $messageId, int $uid, int $uidvalidity, string $folderName): void {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"UPDATE iem_inbound_email_messages
			 SET iem_delete_time = COALESCE(iem_delete_time, now()),
				 iem_iia_inbound_imap_account_id = ?, iem_imap_folder = ?, iem_imap_uid = ?, iem_imap_uidvalidity = ?
			 WHERE iem_inbound_email_message_id = ?");
		$stmt->execute(array(intval($this->account->key), $folderName, $uid, $uidvalidity, $messageId));
	}

	/** The id of an already-stored row by its (Message-ID, recipient) dedup key, or 0. */
	private function existingMessageId(string $messageIdHeader, string $recipient): int {
		$messageIdHeader = trim($messageIdHeader);
		if ($messageIdHeader === '') {
			return 0;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			 WHERE iem_message_id_header = ? AND iem_recipient = ? LIMIT 1');
		$stmt->execute(array(substr($messageIdHeader, 0, 255), $recipient));
		$id = $stmt->fetchColumn();
		return $id ? intval($id) : 0;
	}

	/** §9: any row in this alias's mailbox with the given Message-ID (any direction/recipient), or 0. */
	private function aliasMessageIdByMessageId(InboundEmailAlias $alias, string $messageIdHeader,
			bool $composedOnly = false): int {
		$messageIdHeader = trim($messageIdHeader);
		if ($messageIdHeader === '' || !$alias->key) {
			return 0;
		}
		// Composer's copy first, deterministically. The old unordered LIMIT 1
		// could bind the Sent pass's locator + promotion to whichever sibling the
		// planner returned — in practice a coverage-pass duplicate rather than
		// the local outbound row (specs/bugfix_promoted_sent_row_sealing.md).
		// $composedOnly restricts the hit to outbound/draft rows: the directions
		// where iem_recipient is sealed ciphertext, so the (Message-ID,
		// recipient, direction) unique key structurally cannot dedup and this
		// Message-ID-only lookup is the only one that can.
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			 WHERE iem_iea_inbound_email_alias_id = ? AND iem_message_id_header = ?
			   AND iem_delete_time IS NULL"
			. ($composedOnly ? " AND iem_direction IN ('outbound', 'draft')" : '')
			. " ORDER BY CASE iem_direction WHEN 'outbound' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END,
			   iem_inbound_email_message_id ASC
			 LIMIT 1");
		$stmt->execute(array(intval($alias->key), substr($messageIdHeader, 0, 255)));
		$id = $stmt->fetchColumn();
		return $id ? intval($id) : 0;
	}

	/** Adopt the IMAP locator on a row that has none (e.g. a local outbound row), so its parts become fetchable. */
	private function adoptLocatorIfMissing(int $messageId, int $uid, int $uidvalidity, string $folderName): void {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'UPDATE iem_inbound_email_messages
			 SET iem_iia_inbound_imap_account_id = ?, iem_imap_uid = ?, iem_imap_uidvalidity = ?, iem_imap_folder = ?
			 WHERE iem_inbound_email_message_id = ? AND iem_iia_inbound_imap_account_id IS NULL');
		$stmt->execute(array(intval($this->account->key), $uid, $uidvalidity, $folderName, $messageId));
	}

	/** Mark a row as spam (a message the remote filed in a junk-role folder). */
	private function markSpam(int $messageId): void {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"UPDATE iem_inbound_email_messages SET iem_spam_verdict = '" . InboundEmailMessage::SPAM_VERDICT_SPAM . "'
			 WHERE iem_inbound_email_message_id = ?");
		$stmt->execute(array($messageId));
	}

	/**
	 * Mark a row as outbound (a Sent-folder message the user sent). Only ever
	 * promotes an inbound row — outbound stays outbound and a draft is never
	 * touched. The NOT EXISTS clause mirrors the live-rows dedup index on
	 * (Message-ID, recipient, direction): if a live outbound sibling already
	 * holds that key, promoting this row would violate it mid-ingest and wedge
	 * the folder cursor on a permanent retry, so the promotion quietly stands
	 * down instead — the sibling already tells the reader the mail was sent.
	 *
	 * On a SEALED row the promotion creates a debt: iem_recipient was written in
	 * the clear when the row was inbound (routing metadata there), but on an
	 * outbound row the direction guard expects it sealed — and this runs from
	 * cron, which holds no unlock window and cannot seal under the row's
	 * existing DEK. iem_reseal_pending marks the debt for PromotedRowRepair to
	 * pay at the owner's next unlocked visit
	 * (specs/bugfix_promoted_sent_row_sealing.md).
	 */
	private function markDirectionOutbound(int $messageId): void {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"UPDATE iem_inbound_email_messages m SET iem_direction = 'outbound',
			        iem_reseal_pending = m.iem_content_sealed
			 WHERE m.iem_inbound_email_message_id = ?
			   AND m.iem_direction = 'inbound'
			   AND NOT EXISTS (
			       SELECT 1 FROM iem_inbound_email_messages o
			        WHERE o.iem_message_id_header = m.iem_message_id_header
			          AND o.iem_recipient = m.iem_recipient
			          AND o.iem_direction = 'outbound'
			          AND o.iem_delete_time IS NULL
			          AND m.iem_delete_time IS NULL)");
		$stmt->execute(array($messageId));
	}

	/**
	 * True when the message is addressed to the source mailbox itself (To or Cc
	 * carries the feed's own address). A self-send lives in the Inbox as well as
	 * Sent — in the source mailbox and here — so the Sent-folder pass must not
	 * promote it to outbound and hide it from the Inbox view. Fails toward
	 * "not self-addressed": an unreadable envelope keeps the ordinary promotion.
	 */
	private function envelopeAddressedToSelf($envelope): bool {
		$own = strtolower(trim((string)$this->account->get('iia_username')));
		if ($own === '' || strpos($own, '@') === false) {
			return false;
		}
		foreach (array('to', 'cc') as $field) {
			try {
				$list = $envelope->$field ?? null;
				if ($list === null) {
					continue;
				}
				if (is_iterable($list)) {
					foreach ($list as $addr) {
						$bare = is_object($addr) ? (string)($addr->bare_address ?? '') : (string)$addr;
						if (strtolower(trim($bare)) === $own) {
							return true;
						}
					}
					continue;
				}
				if (stripos((string)$list, $own) !== false) {
					return true;
				}
			} catch (Throwable $e) {
				continue;
			}
		}
		return false;
	}

	/**
	 * APPEND a sent copy (raw RFC822 MIME) into the source Sent folder with \Seen,
	 * for feeds whose SMTP does not auto-file (filesSent=false, §9). The connection
	 * lifecycle is the caller's. Returns ['ok'=>bool, 'message'=>string].
	 */
	public function appendToSent(string $rawMime): array {
		try {
			$client = $this->client();
			$sent = $this->resolveSentFolderName();
			if ($sent === null) {
				return array('ok' => false, 'message' => 'No Sent folder could be resolved on the source mailbox.');
			}
			$client->append($sent, array(array('data' => $rawMime, 'flags' => array('\\Seen'))));
			return array('ok' => true, 'message' => 'Filed a copy in ' . $sent . '.');
		} catch (ImapIngestorException $e) {
			return array('ok' => false, 'message' => $e->getMessage());
		} catch (Throwable $e) {
			error_log('ImapIngestor::appendToSent failed for account ' . $this->account->key . ': ' . $e->getMessage());
			return array('ok' => false, 'message' => 'Could not file the sent copy in the source Sent folder.');
		}
	}

	/** The Sent folder's name (iif_ role=sent), discovering folders once if needed, else null. */
	private function resolveSentFolderName(): ?string {
		$rows = new MultiInboundImapFolder(array(
			'account_id' => intval($this->account->key),
			'role'       => InboundImapFolder::ROLE_SENT,
		));
		$rows->load();
		if (!count($rows)) {
			$this->discoverFolders();
			$rows = new MultiInboundImapFolder(array(
				'account_id' => intval($this->account->key),
				'role'       => InboundImapFolder::ROLE_SENT,
			));
			$rows->load();
		}
		return count($rows) ? (string)$rows->get(0)->get('iif_name') : null;
	}

	/** Persist one ima_ row per non-text part (metadata only, no bytes). */
	/** The one inline predicate, shared by the manifest write and the ingest-time
	 *  inline-image fetch so the two can never disagree about which rows the
	 *  adopted bytes belong to. */
	private static function partIsInline($part): bool {
		$cid = $part->getContentId();
		$disp = $part->getDisposition();
		return ($disp === 'inline') || ($cid !== null && $cid !== '' && $disp !== 'attachment');
	}

	/**
	 * Fetch the decoded bytes of every inline image part, keyed by MIME id
	 * (specs/bugfix_sealed_inline_images.md). Network half only — runs beside
	 * the body fetches, never inside the store transaction. Best-effort
	 * throughout: any part that fails, exceeds the ceiling, or is not an image
	 * is simply absent from the result and stays reference-backed.
	 */
	private function fetchInlineImageParts($client, string $folder, int $uid, $structure, array $attachParts): array {
		$out = array();
		foreach ($attachParts as $part) {
			try {
				if (!self::partIsInline($part)) {
					continue;
				}
				if (strtolower((string)$part->getPrimaryType()) !== 'image') {
					continue;
				}
				// The declared size is the transfer-encoded one — a cheap first
				// gate; the decoded bytes are re-checked below.
				if (intval($part->getBytes()) > self::INLINE_ADOPT_MAX_BYTES) {
					continue;
				}
				$mimeId = (string)$part->getMimeId();
				if ($mimeId === '') {
					continue;
				}
				$fq = new Horde_Imap_Client_Fetch_Query();
				$fq->bodyPart($mimeId, array('decode' => true, 'peek' => true));
				$res = $client->fetch($folder, $fq, array('ids' => new Horde_Imap_Client_Ids(array($uid))));
				$data = $res[$uid] ?? null;
				if ($data === null) {
					continue;
				}
				$content = $data->getBodyPart($mimeId);
				if (!$data->getBodyPartDecode($mimeId)) {
					// Decode via a CLONE: mutating the shared structure part would
					// change what writeManifest() records for it.
					$p = clone $part;
					$p->setContents($content);
					$content = $p->getContents();
				}
				$content = (string)$content;
				if ($content !== '' && strlen($content) <= self::INLINE_ADOPT_MAX_BYTES) {
					$out[$mimeId] = $content;
				}
			} catch (Throwable $e) {
				error_log('ImapIngestor: inline image fetch failed for uid ' . intval($uid)
					. ' part ' . (string)$part->getMimeId() . ': ' . $e->getMessage());
			}
		}
		return $out;
	}

	/**
	 * Turn a freshly-manifested message's inline image rows into file-backed
	 * rows from the bytes the network half fetched. Stored half — runs inside
	 * the ingest transaction, right after writeManifest(). Failures log and
	 * leave the row reference-backed; the message itself is never at stake.
	 */
	private function adoptInlineParts(int $messageId, array $inlineBytes, InboundEmailRouter $router): void {
		if ($messageId <= 0 || !count($inlineBytes)) {
			return;
		}
		try {
			// Fresh from the row: storeExtracted sealed the message AFTER the
			// insert, so an in-hand model instance may predate the seal columns.
			$msg = new InboundEmailMessage($messageId, TRUE);
			if (!$msg->key) {
				return;
			}
			// Seal iff the MESSAGE is sealed, to the owner the message records —
			// the same rule the custody sweep applies (AttachmentByteCustody).
			$sealed = (bool)$msg->get('iem_content_sealed');
			if ($sealed) {
				$owner_id = InboundEmailMessage::sealedOwnerFor($msg);
				if ($owner_id === null || $owner_id <= 0) {
					error_log('ImapIngestor: message ' . $messageId . ' is sealed but names no owner; '
						. 'its inline images stay reference-backed rather than being stored in the clear.');
					return;
				}
			} else {
				$alias_id = $msg->get('iem_iea_inbound_email_alias_id');
				$alias = $alias_id ? new InboundEmailAlias(intval($alias_id), TRUE) : null;
				$owner_id = $router->attachmentOwnerId($alias);
			}

			$rows = new MultiInboundMessageAttachment(
				array('message_id' => $messageId, 'file_backed' => false));
			foreach ($rows as $att) {
				$mimeId = (string)$att->get('ima_mime_part');
				if (!isset($inlineBytes[$mimeId]) || !$att->get('ima_is_inline')) {
					continue;
				}
				try {
					AttachmentByteCustody::adoptBytes($att, $inlineBytes[$mimeId], $sealed, intval($owner_id));
				} catch (Throwable $e) {
					error_log('ImapIngestor: could not adopt inline image part ' . $mimeId
						. ' on message ' . $messageId . ': ' . $e->getMessage());
				}
			}
		} catch (Throwable $e) {
			error_log('ImapIngestor: inline image adoption failed for message ' . $messageId
				. ': ' . $e->getMessage());
		}
	}

	private function writeManifest($messageId, array $parts): void {
		foreach ($parts as $part) {
			$cid = $part->getContentId();
			$disp = $part->getDisposition();
			$isInline = self::partIsInline($part);
			InboundMessageAttachment::CreateEntry(array(
				'ima_iem_inbound_email_message_id' => intval($messageId),
				'ima_filename'     => $part->getName() ? substr($part->getName(), 0, 500) : null,
				'ima_content_type' => substr((string)$part->getType(), 0, 255),
				'ima_size_bytes'   => intval($part->getBytes()),
				'ima_mime_part'    => substr((string)$part->getMimeId(), 0, 40),
				'ima_encoding'     => substr($this->partEncoding($part), 0, 40),
				'ima_content_id'   => $cid ? substr(trim($cid, '<>'), 0, 255) : null,
				'ima_is_inline'    => $isInline,
			));
		}
	}

	// ── Full-message fetch (materialize on account/alias deletion) ─────────

	/**
	 * Fetch a message's complete RFC822 raw by its locator — used to MATERIALIZE
	 * a reference-backed ('remote') message into a self-contained local copy
	 * before its IMAP account is deleted (specs/mailbox_data_loss_fixes.md,
	 * Fix 8). Returns ['ok'=>true, 'raw'=>string] or ['ok'=>false,'message'=>...].
	 *
	 * Unlike fetchPart(), this does NOT close the connection: materialize walks
	 * many messages on one account, so the caller opens once and calls close()
	 * when the batch is done.
	 */
	public function fetchFullRaw(int $uid, ?int $uidvalidity, string $folder, ?string $messageId): array {
		try {
			$client = $this->client();
			$folder = $folder ?: ($this->account->get('iia_imap_folder') ?: 'INBOX');

			$resolvedUid = $this->resolveUid($client, $folder, $uid, $uidvalidity, $messageId);
			if ($resolvedUid === null) {
				return array('ok' => false, 'message' => 'This message is no longer available in the source mailbox.');
			}

			$ids = new Horde_Imap_Client_Ids(array($resolvedUid));
			$fq = new Horde_Imap_Client_Fetch_Query();
			$fq->fullText(array('peek' => true)); // whole RFC822, don't set \Seen
			$res = $client->fetch($folder, $fq, array('ids' => $ids));
			$fdata = $res[$resolvedUid] ?? null;
			if ($fdata === null) {
				return array('ok' => false, 'message' => 'This message is no longer available in the source mailbox.');
			}

			$raw = (string)$fdata->getFullMsg(false);
			if ($raw === '') {
				return array('ok' => false, 'message' => 'The source message returned no content.');
			}
			return array('ok' => true, 'raw' => $raw);
		} catch (ImapIngestorException $e) {
			return array('ok' => false, 'message' => $e->getMessage());
		} catch (Throwable $e) {
			error_log('ImapIngestor::fetchFullRaw error: ' . $e->getMessage());
			return array('ok' => false, 'message' => 'Could not retrieve the message from the source mailbox.');
		}
	}

	// ── On-demand single-part fetch (the download endpoint) ────────────────

	/**
	 * Fetch one MIME part's decoded bytes on demand, by the message's locator.
	 * Returns ['ok'=>true, 'content'=>string] or ['ok'=>false, 'message'=>string]
	 * (e.g. the message is gone from the source mailbox). Pass-through — the bytes
	 * are returned to the caller to stream and never persisted.
	 *
	 * $uid/$uidvalidity/$folder come from the iem_ locator; $messageId is the
	 * Message-ID header used as a fallback when UIDVALIDITY has changed.
	 */
	public function fetchPart(string $mimePart, int $uid, ?int $uidvalidity, string $folder, ?string $messageId): array {
		try {
			$client = $this->client();
			$folder = $folder ?: ($this->account->get('iia_imap_folder') ?: 'INBOX');

			$resolvedUid = $this->resolveUid($client, $folder, $uid, $uidvalidity, $messageId);
			if ($resolvedUid === null) {
				return array('ok' => false, 'message' => 'This message is no longer available in the source mailbox.');
			}

			$ids = new Horde_Imap_Client_Ids(array($resolvedUid));

			// Need the live structure to know the part's transfer encoding for
			// client-side decode if the server doesn't decode for us.
			$sq = new Horde_Imap_Client_Fetch_Query();
			$sq->structure();
			$structRes = $client->fetch($folder, $sq, array('ids' => $ids));
			$sdata = $structRes[$resolvedUid] ?? null;
			if ($sdata === null) {
				return array('ok' => false, 'message' => 'This message is no longer available in the source mailbox.');
			}
			$structure = $sdata->getStructure();
			$part = $structure->getPart($mimePart);
			if ($part === null) {
				return array('ok' => false, 'message' => 'That attachment part no longer exists in the message.');
			}

			$fq = new Horde_Imap_Client_Fetch_Query();
			$fq->bodyPart($mimePart, array('decode' => true, 'peek' => true));
			$res = $client->fetch($folder, $fq, array('ids' => $ids));
			$fdata = $res[$resolvedUid] ?? null;
			if ($fdata === null) {
				return array('ok' => false, 'message' => 'This message is no longer available in the source mailbox.');
			}

			$content = $fdata->getBodyPart($mimePart);
			// If the server did not transfer-decode, let Horde decode via the
			// part's own encoding (setContents with no encoding uses the part's).
			if (!$fdata->getBodyPartDecode($mimePart)) {
				$part->setContents($content);
				$content = $part->getContents();
			}

			return array('ok' => true, 'content' => $content);
		} catch (ImapIngestorException $e) {
			return array('ok' => false, 'message' => $e->getMessage());
		} catch (Throwable $e) {
			error_log('ImapIngestor::fetchPart error: ' . $e->getMessage());
			return array('ok' => false, 'message' => 'Could not retrieve the attachment from the source mailbox.');
		} finally {
			$this->close();
		}
	}

	/**
	 * Resolve the UID to fetch: the stored UID when UIDVALIDITY still matches,
	 * else a Message-ID header search (the stale-UID fallback). Returns null if
	 * the message can't be located.
	 */
	private function resolveUid($client, string $folder, int $uid, ?int $uidvalidity, ?string $messageId): ?int {
		$status = $client->status($folder, Horde_Imap_Client::STATUS_UIDVALIDITY);
		$serverUidValidity = intval($status['uidvalidity'] ?? 0);

		if ($uidvalidity !== null && intval($uidvalidity) === $serverUidValidity && $uid > 0) {
			return $uid;
		}

		// UIDVALIDITY changed (or unknown) — fall back to a Message-ID search.
		if ($messageId !== null && $messageId !== '') {
			$query = new Horde_Imap_Client_Search_Query();
			$query->headerText('message-id', $messageId);
			$res = $client->search($folder, $query, array(
				'results' => array(Horde_Imap_Client::SEARCH_RESULTS_MATCH),
			));
			$ids = $res['match']->ids;
			if (count($ids)) {
				return intval($ids[count($ids) - 1]);
			}
		}
		return null;
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/** Fetch one text part decoded + converted to UTF-8. */
	private function fetchTextPart($client, string $folder, int $uid, $structure, string $mimePart): string {
		$fq = new Horde_Imap_Client_Fetch_Query();
		$fq->bodyPart($mimePart, array('decode' => true, 'peek' => true));
		$res = $client->fetch($folder, $fq, array('ids' => new Horde_Imap_Client_Ids(array($uid))));
		$data = $res[$uid] ?? null;
		if ($data === null) { return ''; }

		$content = $data->getBodyPart($mimePart);
		$part = $structure->getPart($mimePart);
		if ($part !== null && !$data->getBodyPartDecode($mimePart)) {
			$part->setContents($content);
			$content = $part->getContents();
		}
		$charset = ($part !== null) ? (string)$part->getContentTypeParameter('charset') : '';
		return $this->toUtf8((string)$content, $charset);
	}

	/** The transfer encoding for a BODYSTRUCTURE part (best-effort, informational). */
	private function partEncoding($part): string {
		// Horde stores the transfer encoding on the part; there is no public getter,
		// so read the stable protected property reflectively. Decode never depends
		// on this value (Horde decodes via the live part on fetch); it is recorded
		// for the manifest and the future stored-raw retrieval path.
		try {
			$ref = new ReflectionProperty('Horde_Mime_Part', '_transferEncoding');
			$enc = (string)$ref->getValue($part);
			return $enc !== '' ? $enc : '7bit';
		} catch (Throwable $e) {
			return '';
		}
	}

	/** Render a Horde address list to a display string. */
	private function addrString($list): string {
		if ($list === null) { return ''; }
		try {
			$s = (string)$list;
			return substr($s, 0, 500);
		} catch (Throwable $e) {
			return '';
		}
	}

	/** Envelope date → UTC 'Y-m-d H:i:s', or now() if absent/invalid. */
	private function envelopeDate($envelope): string {
		try {
			$d = $envelope->date;
			if ($d !== null) {
				$ts = $d->getTimestamp();
				if ($ts > 0) { return gmdate('Y-m-d H:i:s', $ts); }
			}
		} catch (Throwable $e) { /* fall through */ }
		return gmdate('Y-m-d H:i:s');
	}

	/** Minimal header-block parse for thread-key inputs (references/in-reply-to). */
	private function parseHeaderText(string $headerText): array {
		$normalized = str_replace("\r\n", "\n", $headerText);
		$headers = array();
		$current = null;
		foreach (explode("\n", $normalized) as $line) {
			if (preg_match('/^\s+/', $line) && $current !== null) {
				// Folded continuation: append to the current value, or to the last
				// occurrence when the header repeated (value is an array).
				if (is_array($headers[$current])) {
					$last = count($headers[$current]) - 1;
					$headers[$current][$last] .= ' ' . trim($line);
				} else {
					$headers[$current] .= ' ' . trim($line);
				}
			} elseif (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
				$current = strtolower(trim($m[1]));
				if (isset($headers[$current])) {
					if (!is_array($headers[$current])) { $headers[$current] = array($headers[$current]); }
					$headers[$current][] = trim($m[2]);
				} else {
					$headers[$current] = trim($m[2]);
				}
			}
		}
		return $headers;
	}

	private function toUtf8(string $text, string $charset): string {
		if ($text === '') { return $text; }
		// Shared ladder: a sender-declared charset PHP does not recognise must
		// degrade the conversion, never throw (PHP 8 mb_convert_encoding raises
		// ValueError on unknown names). The raw message is preserved verbatim
		// either way.
		return DocumentText::toUtf8($text, $charset);
	}

	/** The account's bound alias, validated store-capable. */
	private function resolveAlias(): InboundEmailAlias {
		$aliasId = intval($this->account->get('iia_iea_inbound_email_alias_id'));
		if ($aliasId <= 0) {
			throw new ImapIngestorException('This IMAP account is not bound to a mailbox alias.');
		}
		$alias = new InboundEmailAlias($aliasId, TRUE);
		if (!$alias->key || $alias->get('iea_delete_time')) {
			throw new ImapIngestorException('The bound mailbox alias no longer exists.');
		}
		return $alias;
	}

	public function getAccount(): InboundImapAccount {
		return $this->account;
	}
}
?>

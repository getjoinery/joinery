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
 * Spam (specs/inbound_email_spam_filtering.md): a message ingested into a folder
 * whose iif_role is 'junk' is marked iem_spam_verdict='spam' — the remote server
 * already classified it, so no auth rule runs. This gives the reader's Spam view
 * the same meaning for IMAP-polled mail as for locally-received mail.
 *
 * @version 1.3
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_folder_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_message_folder_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/ImapClient.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));

class ImapIngestorException extends Exception {}

class ImapIngestor {

	/** Generous text-body ceiling. Bodies over this are truncated-and-marked,
	 *  never dropped — the full part is still fetchable on demand like any part. */
	const TEXT_BODY_CEILING = 2097152; // 2 MB

	/** @var InboundImapAccount */
	private $account;
	/** @var ImapClient|null */
	private $client = null;

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

		try {
			$socket = new Horde_Imap_Client_Socket($params);
			$socket->login();
		} catch (Horde_Imap_Client_Exception $e) {
			// An OAuth account whose token the server rejects needs reconnection.
			if ($this->account->isOAuth() && $e->getCode() === Horde_Imap_Client_Exception::LOGIN_AUTHENTICATIONFAILED) {
				$this->account->markNeedsReauth();
			}
			// Horde messages are credential-free (status + server text).
			throw new ImapIngestorException('IMAP login failed: ' . $e->getMessage());
		} catch (Throwable $e) {
			throw new ImapIngestorException('IMAP connection failed: ' . $e->getMessage());
		}

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
	 * Returns ['stored'=>int, 'dedup'=>int, 'seen'=>int, 'status'=>string].
	 * Throws ImapIngestorException on connect/auth failure (the poller records it).
	 */
	public function poll(int $maxPerRun): array {
		$alias = $this->resolveAlias();
		$domain = new InboundEmailDomain($alias->get('iea_ied_inbound_email_domain_id'), TRUE);
		$recipient = strtolower($alias->get_full_address());

		$this->client(); // force connect
		$this->detectCapabilities();

		if ($this->account->syncEnabled()) {
			return $this->ingestTrackedFolders($maxPerRun, $alias, $domain, $recipient);
		}

		// Off feed: the single configured folder only, no membership rows — behavior
		// is identical to the pre-sync single-folder ingest, just cursored in iif_.
		$folder = $this->ensureFolderCursor($this->account->get('iia_imap_folder') ?: 'INBOX',
			InboundImapFolder::ROLE_INBOX);
		$res = $this->ingestFolder($folder, $maxPerRun, false, $alias, $domain, $recipient);
		$this->account->recordStatus($res['status']);
		return $res;
	}

	/**
	 * Ingest across every tracked folder of a sync-enabled feed. Membership
	 * (`imf_`) rows are seeded on tracked, non-coverage folders; a coverage folder
	 * (`\All`) ingests for storage + flag pull but contributes no membership (§6.1,
	 * §7.3). One folder's failure never aborts the rest.
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

		$totalStored = 0; $totalDedup = 0; $totalSeen = 0; $parts = array();
		foreach ($folders as $folder) {
			try {
				$res = $this->ingestFolder($folder, $maxPerRun, $folder->isMembership(), $alias, $domain, $recipient);
				$totalStored += $res['stored']; $totalDedup += $res['dedup']; $totalSeen += $res['seen'];
				$parts[] = $res['status'];
			} catch (Throwable $e) {
				error_log('ImapIngestor: ingest failed for folder ' . $folder->get('iif_name')
					. ' (account ' . $this->account->key . '): ' . $e->getMessage());
				$parts[] = $folder->get('iif_name') . ': ERROR';
			}
		}

		$statusMsg = 'Ingested ' . count($folders) . ' folder(s): ' . $totalStored . ' stored, '
			. $totalDedup . ' duplicate. ' . implode(' | ', $parts);
		$this->account->recordStatus($statusMsg);
		return array('stored' => $totalStored, 'dedup' => $totalDedup, 'seen' => $totalSeen, 'status' => $statusMsg);
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
		// clear the sync modseq cursor and re-seed. "Future only" (default) seeds to
		// the current high UID; "Full history" starts at 0 and backfills in batches.
		if ($storedUidValidity === null || intval($storedUidValidity) !== $serverUidValidity) {
			$folder->set('iif_uidvalidity', $serverUidValidity);
			$folder->set('iif_last_sync_modseq', null);
			if ($this->account->get('iia_import_history')) {
				$folder->set('iif_last_seen_uid', 0);
				$lastSeenUid = 0;
				// fall through to the windowed fetch below
			} else {
				$folder->set('iif_last_seen_uid', $highUid);
				$folder->prepare();
				$folder->save();
				return array('stored' => 0, 'dedup' => 0, 'seen' => 0,
					'status' => $folderName . ': seeded cursor');
			}
		}

		// Nothing above the cursor → done.
		if ($lastSeenUid >= $highUid) {
			$folder->set('iif_last_seen_uid', max($lastSeenUid, $highUid));
			$folder->prepare();
			$folder->save();
			return array('stored' => 0, 'dedup' => 0, 'seen' => 0, 'status' => $folderName . ': no new');
		}

		// Walk forward one bounded UID window per run (oldest-first). A numeric UID
		// FETCH range (not SEARCH) avoids the ESEARCH form Gmail rejects.
		$windowEnd = min($highUid, $lastSeenUid + max(1, $maxPerRun));
		$metaFetch = $this->fetchWindow($client, $folderName, $lastSeenUid + 1, $windowEnd);
		$uids = array();
		foreach ($metaFetch->ids() as $uid) {
			$uid = intval($uid);
			if ($uid > $lastSeenUid && $uid <= $windowEnd) { $uids[] = $uid; }
		}
		sort($uids, SORT_NUMERIC);

		$router = new InboundEmailRouter();

		$stored = 0; $dedup = 0; $seen = 0; $maxUid = $windowEnd;
		foreach ($uids as $uid) {
			$seen++;
			$data = $metaFetch[$uid] ?? null;
			if ($data === null) { $maxUid = min($maxUid, $uid - 1); continue; }

			try {
				$result = $this->ingestOne($client, $folder, $uid, $data, $router,
					$alias, $domain, $recipient, $serverUidValidity, $recordMembership);
				if ($result['dedup']) { $dedup++; } else { $stored++; }
			} catch (Throwable $e) {
				error_log('ImapIngestor: failed to ingest UID ' . $uid . ' in ' . $folderName
					. ' for account ' . $this->account->key . ': ' . $e->getMessage());
				$maxUid = min($maxUid, $uid - 1);
			}
		}

		$folder->set('iif_last_seen_uid', max($lastSeenUid, $maxUid));
		$folder->prepare();
		$folder->save();

		return array('stored' => $stored, 'dedup' => $dedup, 'seen' => $seen,
			'status' => $folderName . ': ' . $stored . ' stored, ' . $dedup . ' dup');
	}

	/**
	 * Batched fetch of structure + envelope + header text + size for the UID
	 * window [$startUid, $endUid]. We FETCH a numeric UID range rather than UID
	 * SEARCH: Gmail advertises ESEARCH but rejects the `SEARCH RETURN (...)` form
	 * Horde emits ("BAD Could not parse command"), and FETCH avoids ESEARCH
	 * entirely. A numeric (non-`*`) range also avoids the "N:* always matches the
	 * highest message" caveat. Missing UIDs in the range simply aren't returned.
	 */
	private function fetchWindow(ImapClient $client, string $folder, int $startUid, int $endUid) {
		$query = new Horde_Imap_Client_Fetch_Query();
		$query->structure();
		$query->envelope();
		$query->size();
		$query->headerText(array('peek' => true));
		return $client->fetch($folder, $query, array(
			'ids' => new Horde_Imap_Client_Ids($startUid . ':' . $endUid),
		));
	}

	/**
	 * Extract + store one message and its attachment manifest. Returns
	 * ['dedup'=>bool]. Idempotent: dedup (UNIQUE message-id+recipient) means the
	 * row already exists, so the manifest is not rewritten. When $recordMembership,
	 * an `imf_` membership row for ($folder) is attached (local = base = true) on
	 * both the new-row and dedup paths — the dedup path is where a message already
	 * stored from another folder gains its second-folder membership (§7.3).
	 */
	private function ingestOne($client, InboundImapFolder $folder, $uid, $data, InboundEmailRouter $router,
			$alias, $domain, $recipient, $serverUidValidity, bool $recordMembership = false): array {

		$folderName = (string)$folder->get('iif_name');

		$structure = $data->getStructure();
		$envelope = $data->getEnvelope();

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

		// §9 Sent dedup: a message in the Sent folder may be one Joinery already
		// stored as a local outbound row (or a provider-filed copy of it). Its
		// recipient differs from the alias, so the (Message-ID, recipient) unique
		// key won't fire — dedup by Message-ID alone against the alias's rows. On a
		// hit, adopt the locator (so attachments stay fetchable) and attach Sent
		// membership; no new row.
		if ((string)$folder->get('iif_role') === InboundImapFolder::ROLE_SENT) {
			$existingId = $this->aliasMessageIdByMessageId($alias, (string)$envelope->message_id);
			if ($existingId > 0) {
				$this->adoptLocatorIfMissing($existingId, intval($uid), intval($serverUidValidity), $folderName);
				if ($recordMembership) {
					InboundMessageFolder::setPresence($existingId, intval($folder->key), true, true,
						intval($uid), intval($serverUidValidity));
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
			// Write the manifest only for a freshly-stored row (dedup ⇒ already has one).
			$this->writeManifest($messageId, $attachParts);
			// A brand-new Sent-folder message is mail the user sent — mark it outbound
			// so the reader renders it as a sent message (native-client or Gmail
			// no-local-row path, §9).
			if ((string)$folder->get('iif_role') === InboundImapFolder::ROLE_SENT) {
				$this->markDirectionOutbound($messageId);
			}
		} elseif ($result['dedup']) {
			$messageId = $this->existingMessageId((string)$envelope->message_id, $recipient);
		}

		// Attach this folder's membership (local = base = true). On the dedup path
		// this is where a message stored from another folder gains a second
		// membership; the new-row path seeds the first.
		if ($recordMembership && $messageId > 0) {
			InboundMessageFolder::setPresence($messageId, intval($folder->key), true, true,
				intval($uid), intval($serverUidValidity));
		}

		// Spam (specs/inbound_email_spam_filtering.md): a message the remote filed in
		// a junk-role folder is spam — give the verdict its meaning for IMAP mail.
		if ($messageId > 0 && (string)$folder->get('iif_role') === InboundImapFolder::ROLE_JUNK) {
			$this->markSpam($messageId);
		}

		return array('dedup' => (bool)$result['dedup'], 'message_id' => $messageId);
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
	private function aliasMessageIdByMessageId(InboundEmailAlias $alias, string $messageIdHeader): int {
		$messageIdHeader = trim($messageIdHeader);
		if ($messageIdHeader === '' || !$alias->key) {
			return 0;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			 WHERE iem_iea_inbound_email_alias_id = ? AND iem_message_id_header = ?
			   AND iem_delete_time IS NULL LIMIT 1');
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

	/** Mark a row as outbound (a Sent-folder message the user sent). */
	private function markDirectionOutbound(int $messageId): void {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"UPDATE iem_inbound_email_messages SET iem_direction = 'outbound'
			 WHERE iem_inbound_email_message_id = ?");
		$stmt->execute(array($messageId));
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
	private function writeManifest($messageId, array $parts): void {
		foreach ($parts as $part) {
			$cid = $part->getContentId();
			$disp = $part->getDisposition();
			$isInline = ($disp === 'inline') || ($cid !== null && $cid !== '' && $disp !== 'attachment');
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
			$ref->setAccessible(true);
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
		if ($text === '' || !function_exists('mb_convert_encoding')) { return $text; }
		$charset = $charset !== '' ? strtoupper($charset) : 'UTF-8';
		$converted = @mb_convert_encoding($text, 'UTF-8', $charset);
		return $converted !== false ? $converted : $text;
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

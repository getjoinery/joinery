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
 * @version 1.2
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
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
	/** @var Horde_Imap_Client_Socket|null */
	private $client = null;

	public function __construct(InboundImapAccount $account) {
		$this->account = $account;
	}

	// ── Connection / auth ──────────────────────────────────────────────────

	/**
	 * Build and log in the Horde client (lazily, once). Branches on the account's
	 * auth method. Throws ImapIngestorException with a credential-free message on
	 * any failure (refresh, connect, or login).
	 */
	private function client(): Horde_Imap_Client_Socket {
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
			$client = new Horde_Imap_Client_Socket($params);
			$client->login();
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

		$this->client = $client;
		return $client;
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
	 * Poll this account once: seed the cursor on first connect / UIDVALIDITY
	 * change, then ingest up to $maxPerRun new messages (UID > last_seen_uid).
	 *
	 * Returns ['stored'=>int, 'dedup'=>int, 'seen'=>int, 'status'=>string].
	 * Throws ImapIngestorException on connect/auth failure (the poller records it).
	 */
	public function poll(int $maxPerRun): array {
		$alias = $this->resolveAlias();
		$domain = new InboundEmailDomain($alias->get('iea_ied_inbound_email_domain_id'), TRUE);
		$recipient = strtolower($alias->get_full_address());

		$client = $this->client();
		$folder = $this->account->get('iia_imap_folder') ?: 'INBOX';

		$status = $client->status(
			$folder,
			Horde_Imap_Client::STATUS_UIDVALIDITY | Horde_Imap_Client::STATUS_UIDNEXT
		);
		$serverUidValidity = intval($status['uidvalidity'] ?? 0);
		$uidNext = intval($status['uidnext'] ?? 0);
		$highUid = $uidNext > 0 ? $uidNext - 1 : 0;

		$storedUidValidity = $this->account->get('iia_uidvalidity');
		$lastSeenUid = intval($this->account->get('iia_last_seen_uid'));

		// First connect, or the folder was recreated (UIDVALIDITY changed).
		// "Future only" (default): seed the cursor to the CURRENT high UID so we
		// ingest only mail arriving after hookup, never the back-catalogue.
		// "Full history": start the cursor at 0 and fall through to backfill the
		// whole mailbox in bounded batches over successive fetches.
		if ($storedUidValidity === null || intval($storedUidValidity) !== $serverUidValidity) {
			$this->account->set('iia_uidvalidity', $serverUidValidity);
			if ($this->account->get('iia_import_history')) {
				$this->account->set('iia_last_seen_uid', 0);
				$lastSeenUid = 0;
				// fall through to the windowed fetch below
			} else {
				$this->account->set('iia_last_seen_uid', $highUid);
				$reason = ($storedUidValidity === null) ? 'first connect' : 'UIDVALIDITY changed';
				$this->account->recordStatus('Seeded cursor to UID ' . $highUid . ' (' . $reason . '); 0 ingested.');
				$this->close();
				return array('stored' => 0, 'dedup' => 0, 'seen' => 0,
					'status' => 'Seeded cursor (' . $reason . ')');
			}
		}

		// Nothing above the cursor → done.
		if ($lastSeenUid >= $highUid) {
			$this->account->set('iia_last_seen_uid', max($lastSeenUid, $highUid));
			$this->account->recordStatus('No new messages.');
			$this->close();
			return array('stored' => 0, 'dedup' => 0, 'seen' => 0, 'status' => 'No new messages');
		}

		// Walk forward one bounded UID window per run (oldest-first). This caps
		// each fetch — so a full-history backfill of a large mailbox imports in
		// batches across successive polls rather than one enormous fetch — and
		// unifies the incremental case (the window is just small). No SEARCH:
		// Gmail rejects the ESEARCH form Horde emits; a numeric UID FETCH range
		// avoids it entirely.
		$windowEnd = min($highUid, $lastSeenUid + max(1, $maxPerRun));
		$metaFetch = $this->fetchWindow($client, $folder, $lastSeenUid + 1, $windowEnd);
		$uids = array();
		foreach ($metaFetch->ids() as $uid) {
			$uid = intval($uid);
			if ($uid > $lastSeenUid && $uid <= $windowEnd) { $uids[] = $uid; }
		}
		sort($uids, SORT_NUMERIC);

		$router = new InboundEmailRouter();

		// Claim the whole window up front; back off below the first message that
		// fails so a transient failure retries next poll, while empty gaps in the
		// window are still skipped (the cursor advances past them).
		$stored = 0; $dedup = 0; $seen = 0; $maxUid = $windowEnd;
		foreach ($uids as $uid) {
			$seen++;
			$data = $metaFetch[$uid] ?? null;
			if ($data === null) { $maxUid = min($maxUid, $uid - 1); continue; }

			try {
				$result = $this->ingestOne($client, $folder, $uid, $data, $router, $alias, $domain, $recipient, $serverUidValidity);
				if ($result['dedup']) { $dedup++; } else { $stored++; }
			} catch (Throwable $e) {
				error_log('ImapIngestor: failed to ingest UID ' . $uid . ' for account '
					. $this->account->key . ': ' . $e->getMessage());
				// Skip this message but keep going; do NOT advance the cursor past it
				// so a transient failure retries next poll.
				$maxUid = min($maxUid, $uid - 1);
			}
		}

		$this->account->set('iia_last_seen_uid', max($lastSeenUid, $maxUid));
		$statusMsg = 'Fetched: ' . $stored . ' stored, ' . $dedup . ' duplicate, ' . $seen . ' seen.';
		$this->account->recordStatus($statusMsg);
		$this->close();

		return array('stored' => $stored, 'dedup' => $dedup, 'seen' => $seen, 'status' => $statusMsg);
	}

	/**
	 * Batched fetch of structure + envelope + header text + size for the UID
	 * window [$startUid, $endUid]. We FETCH a numeric UID range rather than UID
	 * SEARCH: Gmail advertises ESEARCH but rejects the `SEARCH RETURN (...)` form
	 * Horde emits ("BAD Could not parse command"), and FETCH avoids ESEARCH
	 * entirely. A numeric (non-`*`) range also avoids the "N:* always matches the
	 * highest message" caveat. Missing UIDs in the range simply aren't returned.
	 */
	private function fetchWindow(Horde_Imap_Client_Socket $client, string $folder, int $startUid, int $endUid) {
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
	 * row already exists, so the manifest is not rewritten.
	 */
	private function ingestOne($client, $folder, $uid, $data, InboundEmailRouter $router,
			$alias, $domain, $recipient, $serverUidValidity): array {

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
		$plain = $bodyPlainId !== null ? $this->fetchTextPart($client, $folder, $uid, $structure, $bodyPlainId) : '';
		$html  = $bodyHtmlId  !== null ? $this->fetchTextPart($client, $folder, $uid, $structure, $bodyHtmlId)  : '';

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
			'imap_folder' => $folder,
		);

		$auth = array('dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none');
		$result = $router->storeExtracted($msg, $alias, $domain, $recipient, $auth);

		// Write the manifest only for a freshly-stored row (dedup ⇒ already has one).
		if (!$result['dedup'] && $result['message'] !== null) {
			$this->writeManifest($result['message']->key, $attachParts);
		}

		return array('dedup' => (bool)$result['dedup']);
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
				$headers[$current] .= ' ' . trim($line);
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

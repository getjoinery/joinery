<?php
/**
 * AddressListSweep - TEMPORARY. Reads the To / Cc lists of old messages back
 * from a connected IMAP account in bulk, for rows that have no copy of their
 * headers anywhere on this node (archive imports whose archive is gone,
 * lean push rows from before header retention).
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │ TEMPORARY — specs/mailbox_to_cc_lists.md § 5a. Delete this file, its │
 * │ registration block in bootstrap.php, its line in the progress card,  │
 * │ the iia_lists_sweep_state column spec and its test when the card on  │
 * │ Inbound Email → Accounts reads Finished. Nothing permanent depends   │
 * │ on it and it depends on nothing that is not already public.         │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * How it works. The account the mail came from still holds the messages
 * (Gmail's All Mail holds everything a Takeout of the same account held), so
 * instead of asking for each row one at a time, the sweep walks each folder
 * once by UID window, fetches only the header block of every message in the
 * window in one round trip, and matches the Message-IDs that came back
 * against the rows still without lists. A matched row gets its lists written
 * the way the Phase 1 backfill writes them (AddressListBackfill::writeLists:
 * plaintext on an unsealed row, sealed under the row's own DEK on a sealed
 * one) and is stamped, so the progress card counts it as recovered.
 *
 * Why it runs in the owner's browser session (VaultDeferredWork) and not in
 * cron: the sealed write needs the row DEK, which unwraps only inside the
 * owner's unlock window. The IMAP side decrypts nothing.
 *
 * The walk never assumes a dense UID space — a real Gmail folder after years
 * of archiving is mostly deleted UIDs — so a window that comes back empty
 * doubles the next span, and a window is only ever advanced over once it has
 * been fetched (reference: Gmail sparse UID space). The cursor per folder
 * lives on the account row (iia_lists_sweep_state, JSON) so a sweep resumes
 * across sessions, and a UIDVALIDITY change restarts that folder.
 *
 * Which folders: the \All folder plus Trash and Junk where the account has a
 * \All folder (Gmail — All Mail excludes those two); every tracked folder
 * otherwise. Done = every folder walked to its UIDNEXT. Rows still without
 * lists after that are not in the account, and the card says so.
 *
 * @version 1.1
 * @changelog 1.1 - a failed turn sits the account out for FAILURE_BACKOFF_SECONDS
 *   and the card says why; a fast-failing turn was otherwise re-offered on every
 *   chained drain
 */

require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException

class AddressListSweep {

	/** UIDs per fetch window to start with; doubles over proven-empty ranges. */
	const BASE_SPAN = 500;

	/** Never fetch more than this many messages' headers in one round trip. */
	const MAX_SPAN = 4000;

	/** Seconds an account sits out after a turn that failed (connection, server, SQL). */
	const FAILURE_BACKOFF_SECONDS = 600;

	/** Test seam: fn(InboundImapAccount): ImapIngestor. */
	public static $ingestor_factory = null;

	/** @var InboundImapAccount */
	private $account;
	/** @var int */
	private $user_id;
	/** @var ImapIngestor|null */
	private $ingestor = null;
	/** @var array the decoded iia_lists_sweep_state */
	private $state;
	/** @var int rows filled by this instance so far — survives a locked stop */
	private $turn_filled = 0;

	public function __construct(InboundImapAccount $account, int $user_id) {
		$this->account = $account;
		$this->user_id = $user_id;
		$decoded = json_decode((string)$account->get('iia_lists_sweep_state'), true);
		$this->state = is_array($decoded) ? $decoded : array();
		$this->state += array('folders' => array(), 'done' => false, 'seen' => 0, 'filled' => 0);
	}

	// ------------------------------------------------------------ consumer

	/**
	 * Any enabled IMAP account this user holds a grant on whose sweep is not
	 * finished, while the user still has rows without lists? Cheap, no
	 * decrypt, no connection.
	 */
	public static function hasWork(int $user_id): bool {
		if ($user_id <= 0 || empty(self::accountsFor($user_id))) {
			return false;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT 1 FROM iem_inbound_email_messages m WHERE ' . self::rowWhere() . ' LIMIT 1');
		$stmt->execute(array($user_id, $user_id));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * One drain turn: walk each unfinished account's folders until the
	 * deadline. Returns how many rows were filled. A closed window stops the
	 * turn (the row was not asked anything, so nothing is lost).
	 */
	public static function drainForUser(int $user_id, VaultKey $key, ?float $deadline = null): int {
		$filled = 0;
		foreach (self::accountsFor($user_id) as $account) {
			if ($deadline !== null && microtime(true) >= $deadline) {
				break;
			}
			$sweep = new static($account, $user_id);
			try {
				$sweep->run($deadline);
			} catch (VaultLockedException $e) {
				$filled += $sweep->turn_filled;
				break;
			} catch (\Throwable $e) {
				// Sit this account out: a turn that fails fast would otherwise
				// be offered again on the very next drain, and the client chains
				// drains while work is reported — a tight loop of failures.
				error_log('AddressListSweep: account ' . intval($account->key) . ': ' . $e->getMessage());
				$sweep->recordFailure($e->getMessage());
			} finally {
				$sweep->close();
			}
			$filled += $sweep->turn_filled;
		}
		return $filled;
	}

	/** Enabled, live IMAP accounts on mailboxes this user holds a grant on, sweep not finished. */
	private static function accountsFor(int $user_id): array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT a.iia_inbound_imap_account_id
			   FROM iia_inbound_imap_accounts a
			   JOIN ieg_inbound_email_mailbox_grants g
			     ON g.ieg_iea_inbound_email_alias_id = a.iia_iea_inbound_email_alias_id
			  WHERE g.ieg_usr_user_id = ?
			    AND a.iia_delete_time IS NULL
			    AND a.iia_is_enabled IS TRUE
			    AND a.iia_health_state <> 'broken'
			  ORDER BY a.iia_inbound_imap_account_id");
		$stmt->execute(array($user_id));
		$out = array();
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$account = new InboundImapAccount(intval($id), TRUE);
			if (!$account->key) {
				continue;
			}
			$state = json_decode((string)$account->get('iia_lists_sweep_state'), true);
			if (is_array($state) && !empty($state['done'])) {
				continue;
			}
			if (is_array($state) && !empty($state['failed_at'])
					&& strtotime((string)$state['failed_at'] . ' UTC') > time() - self::FAILURE_BACKOFF_SECONDS) {
				continue; // backing off after a failed turn
			}
			$out[] = $account;
		}
		return $out;
	}

	/**
	 * Rows the sweep may fill for this user: still without lists, no retained
	 * header block, sealed to this owner or unsealed on a mailbox they hold a
	 * grant on. Two user placeholders.
	 */
	private static function rowWhere(): string {
		return "(m.iem_sealed_owner_user_id = ?
		         OR (m.iem_content_sealed IS NOT TRUE
		             AND m.iem_iea_inbound_email_alias_id IN (
		                 SELECT g.ieg_iea_inbound_email_alias_id
		                   FROM ieg_inbound_email_mailbox_grants g WHERE g.ieg_usr_user_id = ?)))
		    AND m.iem_direction = 'inbound'
		    AND m.iem_delete_time IS NULL
		    AND m.iem_pending_parse IS NOT TRUE
		    AND m.iem_to IS NULL AND m.iem_cc IS NULL
		    AND COALESCE(length(m.iem_raw_headers), 0) = 0";
	}

	// ------------------------------------------------------------- the walk

	/** Walk this account's folders until done or the deadline. Returns rows filled. */
	public function run(?float $deadline): int {
		$all_done = true;
		foreach ($this->folderNames() as $folder) {
			$cursor = $this->state['folders'][$folder] ?? array('uidvalidity' => null, 'next' => 1, 'done' => false);
			if (!empty($cursor['done'])) {
				continue;
			}
			list($uidvalidity, $uidnext) = $this->status($folder);
			if ($cursor['uidvalidity'] !== null && intval($cursor['uidvalidity']) !== $uidvalidity) {
				$cursor = array('uidvalidity' => $uidvalidity, 'next' => 1, 'done' => false); // the folder was renumbered
			}
			$cursor['uidvalidity'] = $uidvalidity;
			$span = self::BASE_SPAN;
			while (intval($cursor['next']) < $uidnext) {
				if ($deadline !== null && microtime(true) >= $deadline) {
					$all_done = false;
					break 2;
				}
				$from = intval($cursor['next']);
				$to = min($uidnext - 1, $from + $span - 1);
				$headers = $this->fetchHeaders($folder, $from, $to);
				if (!empty($headers)) {
					$this->matchAndFill($headers); // may stop the turn (locked): window not advanced
					$span = self::BASE_SPAN;
				} else {
					$span = min(self::MAX_SPAN, $span * 2); // proven empty: stride further next time
				}
				$this->state['seen'] += count($headers); // counted once the window is done with
				unset($this->state['failed_at'], $this->state['error']); // a window landed: healthy again
				$cursor['next'] = $to + 1;
				$this->state['folders'][$folder] = $cursor;
				$this->save();
			}
			if (intval($cursor['next']) >= $uidnext) {
				$cursor['done'] = true;
				$this->state['folders'][$folder] = $cursor;
				$this->save();
			}
		}
		if ($all_done) {
			$this->state['done'] = true;
			$this->save();
		}
		return $this->turn_filled;
	}

	/**
	 * Match a window's header blocks to this user's rows by Message-ID and
	 * write the lists, counting each into turn_filled and the saved state as
	 * it lands — so a closed window mid-window loses nothing already written
	 * (the rest are matched again next time; the window is not advanced).
	 */
	private function matchAndFill(array $headers_by_uid): void {
		// Message-ID as stored (trimmed header value) and in its <...> form.
		$by_id = array();
		foreach ($headers_by_uid as $uid => $block) {
			$mid = self::messageIdFrom($block);
			if ($mid === '') {
				continue;
			}
			$by_id[$mid] = $block;
			if (preg_match('/<[^>]+>/', $mid, $m) && $m[0] !== $mid) {
				$by_id[$m[0]] = $block;
			}
		}
		if (empty($by_id)) {
			return;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$ids = array_keys($by_id);
		$stmt = $db->prepare(
			'SELECT m.iem_inbound_email_message_id AS msg_id, m.iem_message_id_header AS mid
			   FROM iem_inbound_email_messages m
			  WHERE m.iem_message_id_header IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
			    AND ' . self::rowWhere());
		$stmt->execute(array_merge(array_values($ids), array($this->user_id, $this->user_id)));
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$msg_id = intval($row['msg_id']);
			$block = $by_id[(string)$row['mid']] ?? null;
			if ($block === null) {
				continue;
			}
			// One row is one unit for the hot-turn rule (the sealed write opens
			// this owner's scope); a closed window propagates and stops the turn.
			try {
				$ok = SealedEgressGuard::isolate(function () use ($msg_id, $block) {
					$msg = new InboundEmailMessage($msg_id, TRUE);
					if (!$msg->key) {
						return false;
					}
					$lists = MailAddressList::fromHeaderBlock($block);
					return AddressListBackfill::writeLists($msg, $lists['to'], $lists['cc']);
				});
			} catch (VaultLockedException $e) {
				$this->save(); // keep the counts of what this window did write
				throw $e;
			}
			if ($ok) {
				AddressListBackfill::stampRows(array($msg_id));
				$this->turn_filled++;
				$this->state['filled']++;
			}
		}
	}

	/** The Message-ID header value from a header block, trimmed, '' when absent. */
	private static function messageIdFrom(string $block): string {
		$unfolded = preg_replace("/\r?\n[ \t]+/", ' ', $block);
		if (preg_match('/^Message-ID:[ \t]*(.+)$/mi', $unfolded, $m)) {
			return trim($m[1]);
		}
		return '';
	}

	/**
	 * Folders to walk: the \All folder plus Trash and Junk where there is one
	 * (Gmail), every tracked folder otherwise. From the account's discovered
	 * folder rows — no LIST round trip.
	 */
	private function folderNames(): array {
		$rows = new MultiInboundImapFolder(array('account_id' => intval($this->account->key)),
			array('iif_inbound_imap_folder_id' => 'ASC'));
		$all = null;
		$extras = array();
		$tracked = array();
		foreach ($rows as $f) {
			$name = (string)$f->get('iif_name');
			$role = (string)$f->get('iif_role');
			if ($role === InboundImapFolder::ROLE_ALL) {
				$all = $name;
			} elseif ($role === InboundImapFolder::ROLE_TRASH || $role === InboundImapFolder::ROLE_JUNK) {
				$extras[] = $name;
			}
			if ($f->get('iif_is_tracked')) {
				$tracked[] = $name;
			}
		}
		if ($all !== null) {
			return array_merge(array($all), $extras);
		}
		if (empty($tracked)) {
			$tracked[] = (string)($this->account->get('iia_imap_folder') ?: 'INBOX');
		}
		return $tracked;
	}

	/** Remember a failed turn so accountsFor() leaves this account alone for a while. */
	public function recordFailure(string $message): void {
		$this->state['failed_at'] = gmdate('Y-m-d H:i:s');
		$this->state['error'] = substr($message, 0, 300);
		$this->save();
	}

	private function save(): void {
		$this->state['updated'] = gmdate('Y-m-d H:i:s');
		InboundImapAccount::updateColumns(intval($this->account->key),
			array('iia_lists_sweep_state' => json_encode($this->state)));
	}

	public function close(): void {
		if ($this->ingestor !== null) {
			try { $this->ingestor->close(); } catch (\Throwable $e) { /* best effort */ }
			$this->ingestor = null;
		}
	}

	// ---------------------------------------------------------- the server
	// Overridden by the test's stub; everything above is server-agnostic.

	private function ingestor(): ImapIngestor {
		if ($this->ingestor === null) {
			$factory = self::$ingestor_factory;
			$this->ingestor = $factory ? $factory($this->account) : new ImapIngestor($this->account);
		}
		return $this->ingestor;
	}

	/** [uidvalidity, uidnext] of a folder. */
	protected function status(string $folder): array {
		$client = $this->ingestor()->getClient();
		$s = $client->status($folder, Horde_Imap_Client::STATUS_UIDVALIDITY | Horde_Imap_Client::STATUS_UIDNEXT);
		return array(intval($s['uidvalidity'] ?? 0), intval($s['uidnext'] ?? 1));
	}

	/** uid => header block for every message in [$from, $to]; headers only, \Seen untouched. */
	protected function fetchHeaders(string $folder, int $from, int $to): array {
		$client = $this->ingestor()->getClient();
		$fq = new Horde_Imap_Client_Fetch_Query();
		$fq->headerText(array('peek' => true));
		$res = $client->fetch($folder, $fq, array('ids' => new Horde_Imap_Client_Ids($from . ':' . $to)));
		$out = array();
		foreach ($res->ids() as $uid) {
			$fdata = $res[$uid] ?? null;
			$text = $fdata !== null ? (string)$fdata->getHeaderText() : '';
			if ($text !== '') {
				$out[intval($uid)] = $text;
			}
		}
		return $out;
	}

	// ---------------------------------------------------------- the card

	/**
	 * One line per account for the progress card: where each sweep stands.
	 * Empty when no account has started or finished a sweep.
	 */
	public static function describe(): array {
		$db = DbConnector::get_instance()->get_db_link();
		$rows = $db->query(
			"SELECT iia_inbound_imap_account_id AS id, iia_label, iia_username, iia_lists_sweep_state AS state
			   FROM iia_inbound_imap_accounts
			  WHERE iia_delete_time IS NULL AND iia_lists_sweep_state IS NOT NULL
			  ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
		$out = array();
		foreach ($rows as $r) {
			$st = json_decode((string)$r['state'], true);
			if (!is_array($st)) {
				continue;
			}
			$who = (string)($r['iia_label'] ?: $r['iia_username'] ?: ('account ' . $r['id']));
			$folders = array();
			foreach ((array)($st['folders'] ?? array()) as $name => $c) {
				$folders[] = $name . (!empty($c['done']) ? ' done' : ' at UID ' . number_format(intval($c['next'] ?? 1)));
			}
			$out[] = $who . ': ' . (!empty($st['done']) ? 'finished — ' : '')
				. number_format(intval($st['seen'] ?? 0)) . ' messages read, '
				. number_format(intval($st['filled'] ?? 0)) . ' rows recovered'
				. ($folders ? ' (' . implode('; ', $folders) . ')' : '')
				. (!empty($st['failed_at']) ? ' — last turn failed at ' . $st['failed_at'] . ' UTC: '
					. (string)($st['error'] ?? '') . '; retried after '
					. intval(self::FAILURE_BACKOFF_SECONDS / 60) . ' minutes' : '');
		}
		return $out;
	}
}

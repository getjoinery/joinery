<?php
/**
 * ImapSyncer - the two-way sync engine (sibling of ImapIngestor, sharing the open
 * connection). Reconciles flags, custom-label membership, and deletion between the
 * source mailbox and Joinery's iem_ rows + ilm_ label memberships.
 *
 * One cycle per feed, driven by the poller on the already-open connection, in the
 * order Pull → Ingest → Push (ImapIngestor does the Ingest in between). Pull and
 * Ingest run in Read-only and Two-way; Push runs only in Two-way.
 *
 *   - Flags (read/star) are a scalar three-way merge (§7.1): a row is dirty iff
 *     iem_local_state_modified > iem_synced_state_time. Pull applies remote flags
 *     to clean rows and skips dirty ones (local-wins); Push STOREs dirty rows.
 *   - Custom-label membership is a single ilm_ row carrying both the truth
 *     (ilm_present_local) and the IMAP shadow (ilm_present_base + the binding folder's
 *     UID). An element is dirty iff present_local <> present_base — a column predicate a
 *     partial index covers, so Push scans O(dirty). Adds push as COPY (non-exclusive) /
 *     MOVE (exclusive); removes as STORE \Deleted + EXPUNGE; the divergent-move case on
 *     an exclusive feed converges emergently to local-wins over ≤2 cycles.
 *   - Standard state is column-driven, not membership. Read/star are the scalar flag
 *     merge above. Deletion (gated by iia_sync_deletes) is the iem_delete_time column: a
 *     local soft-delete pushes a MOVE/COPY to the feed's Trash folder; a remote Trash
 *     arrival sets iem_delete_time at ingest (§7.5). Archive (iem_is_archived) stays
 *     local. INBOX is the default, not a label, so it is never a membership target.
 *
 * IMAP is touched only through the ImapClient seam (§6.2), shared with the ingestor so
 * the whole cycle runs on one connection.
 *
 * See specs/two_way_imap_sync.md and specs/inbound_email_labels.md.
 *
 * @version 2.2 - pull honours the ingestor's deadline (stops between folders,
 *   reporting the rest as deferred) and laps its time into the ingestor's
 *   ledger; push laps too (specs/mailbox_refresh_budget.md)
 * @version 2.1 - a modseq of 0 is "unknown", never a cursor: pull skips a folder
 *   whose server reports no HIGHESTMODSEQ, and a stored cursor <= 0 re-baselines
 *   instead of reconciling — CHANGEDSINCE 0 is dropped by the client library,
 *   which turns the flag pull into an unbounded full-mailbox FLAGS fetch
 * @version 2.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_folder_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_labels_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_label_members_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapClient.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));

class ImapSyncer {

	const FLAG_SEEN    = '\\Seen';
	const FLAG_FLAGGED = '\\Flagged';

	/** @var InboundImapAccount */
	private $account;
	/** @var ImapIngestor */
	private $ingestor;
	/** @var ImapClient */
	private $client;

	public function __construct(InboundImapAccount $account, ImapIngestor $ingestor) {
		$this->account = $account;
		$this->ingestor = $ingestor;
		$this->client = $ingestor->getClient();
	}

	private function db() {
		return DbConnector::get_instance()->get_db_link();
	}

	// ── Prepare: capabilities + folder discovery ───────────────────────────

	/**
	 * Detect + cache sync capabilities and discover folders (upserting iif_ rows
	 * with roles). Runs before Pull so the tracked-folder set and the CONDSTORE gate
	 * are current.
	 */
	public function prepare(): void {
		$this->ingestor->detectCapabilities();
		$this->ingestor->discoverFolders();
	}

	/** The feed's tracked folders as loaded InboundImapFolder objects. */
	private function trackedFolders(): array {
		$rows = new MultiInboundImapFolder(array(
			'account_id'     => intval($this->account->key),
			'tracked'        => true,
			'pending_create' => false, // not on the server yet — nothing to pull
		), array('iif_inbound_imap_folder_id' => 'ASC'));
		$rows->load();
		$out = array();
		foreach ($rows as $row) {
			$out[] = new InboundImapFolder($row->key, TRUE);
		}
		return $out;
	}

	/** The Trash-role tracked folder, or null (delete target / arrival source). */
	private function trashFolder(): ?InboundImapFolder {
		$rows = new MultiInboundImapFolder(array(
			'account_id' => intval($this->account->key),
			'role'       => InboundImapFolder::ROLE_TRASH,
		));
		$rows->load();
		return count($rows) ? new InboundImapFolder($rows->get(0)->key, TRUE) : null;
	}

	// ── Pull (Read-only + Two-way) ─────────────────────────────────────────

	/**
	 * Pull remote flag changes and folder vanishes for every tracked folder. Each
	 * folder advances its iif_last_sync_modseq to the pre-pull HIGHESTMODSEQ so a
	 * change made during the pull window is caught next cycle (loop avoidance).
	 */
	public function pull(): array {
		$started = microtime(true);
		$flags = 0; $vanished = 0; $folders = 0; $deferred = 0;
		foreach ($this->trackedFolders() as $folder) {
			if ($this->ingestor->pastDeadline()) {
				// Out of time: the folder's cursor is untouched, so the next
				// cycle reconciles from where this one would have.
				$deferred++;
				continue;
			}
			try {
				$res = $this->pullFolder($folder);
				$flags += $res['flags']; $vanished += $res['vanished']; $folders++;
			} catch (Throwable $e) {
				error_log('ImapSyncer::pull failed for folder ' . $folder->get('iif_name')
					. ' (account ' . $this->account->key . '): ' . $e->getMessage());
			}
		}
		$this->ingestor->lap('pull', microtime(true) - $started);
		return array('folders' => $folders, 'flags' => $flags, 'vanished' => $vanished, 'deferred' => $deferred);
	}

	private function pullFolder(InboundImapFolder $folder): array {
		$name = (string)$folder->get('iif_name');
		$status = $this->client->status($name,
			Horde_Imap_Client::STATUS_UIDVALIDITY | Horde_Imap_Client::STATUS_HIGHESTMODSEQ
			| Horde_Imap_Client::STATUS_UIDNEXT);
		$serverUidValidity = intval($status['uidvalidity'] ?? 0);
		$highestModseq = intval($status['highestmodseq'] ?? 0);
		$highUid = intval($status['uidnext'] ?? 0) - 1; // numeric range bound (avoids the '*' caveat)

		// UIDVALIDITY mismatch (§7.6): UID→row mappings are stale. Skip pull; ingest
		// reseeds the folder (clearing the modseq cursor) before sync resumes.
		if ($folder->get('iif_uidvalidity') !== null
				&& intval($folder->get('iif_uidvalidity')) !== $serverUidValidity) {
			return array('flags' => 0, 'vanished' => 0);
		}

		$cursor = intval($folder->get('iif_last_sync_modseq') ?? 0);
		if ($cursor <= 0) {
			// First sync of this folder: establish the baseline, reconcile nothing.
			// A cursor <= 0 is always "not established" — a modseq of 0 means the
			// server reported none (STATUS answers 0 when CONDSTORE is unavailable),
			// and reconciling from it would fetch FLAGS for the entire mailbox:
			// the fetch layer treats CHANGEDSINCE 0 as no CHANGEDSINCE at all.
			if ($highestModseq > 0) {
				$folder->set('iif_last_sync_modseq', $highestModseq);
				$folder->prepare();
				$folder->save();
			}
			// No real modseq from the server → leave the cursor unestablished and
			// sync nothing this cycle rather than sync everything.
			return array('flags' => 0, 'vanished' => 0);
		}

		$flags = $this->reconcileFlags($name, $cursor, $highUid);
		$vanished = $folder->isMembership() ? $this->reconcileVanished($folder, $cursor, $highUid) : 0;

		if ($highestModseq > 0) {
			// Advance only to a real value: overwriting a good cursor with 0 (a
			// STATUS that failed to report modseq) would un-baseline the folder.
			$folder->set('iif_last_sync_modseq', $highestModseq);
			$folder->prepare();
			$folder->save();
		}

		return array('flags' => $flags, 'vanished' => $vanished);
	}

	/**
	 * Apply remote read/star changes since $cursor to the rows this folder is the
	 * locator of, skipping dirty rows (local-wins, §7.1). Flags are a per-message
	 * scalar reconciled via the iem_ locator; iterating every tracked folder
	 * covers every message (each via its own locator folder).
	 */
	private function reconcileFlags(string $name, int $cursor, int $highUid): int {
		if ($highUid < 1) {
			return 0; // empty folder — nothing to reconcile
		}
		$query = new Horde_Imap_Client_Fetch_Query();
		$query->flags();
		$query->uid();
		// Numeric UID range, not '1:*' — Gmail/Horde mishandle the '*' form (it can
		// return nothing), the same reason the ingest path uses numeric ranges.
		$res = $this->client->fetch($name, $query, array(
			'changedsince' => $cursor,
			'ids'          => new Horde_Imap_Client_Ids('1:' . $highUid),
		));

		$applied = 0;
		foreach ($res as $uid => $data) {
			$uid = intval($uid);
			$flags = array_map('strtolower', (array)$data->getFlags());
			$seen = in_array('\seen', $flags, true);
			$flagged = in_array('\flagged', $flags, true);

			$row = $this->locatorRow($name, $uid);
			if ($row === null) {
				continue; // not the locator folder for this message
			}
			if ($this->flagRowDirty($row['local_modified'], $row['synced'])) {
				continue; // local-wins: push will reconcile
			}
			if ((bool)$row['is_read'] !== $seen || (bool)$row['is_starred'] !== $flagged) {
				$this->applyRemoteFlags(intval($row['id']), $seen, $flagged);
				$applied++;
			}
		}
		return $applied;
	}

	/**
	 * Clear membership for messages whose UID left this folder since $cursor
	 * (remote-remove, §7.2/§7.4), skipping dirty elements (push handles them).
	 * Re-points the iem_ locator off a vanished folder.
	 *
	 * Removal detection uses QRESYNC VANISHED when the server advertises it; on a
	 * CONDSTORE-only server (e.g. Gmail) it falls back to diffing this folder's
	 * known membership UIDs against the UIDs currently present in the folder.
	 */
	private function reconcileVanished(InboundImapFolder $folder, int $cursor, int $highUid): int {
		$name = (string)$folder->get('iif_name');
		$folderId = intval($folder->key);

		$vanishedUids = $this->account->supportsQresync()
			? $this->vanishedViaQresync($name, $cursor)
			: $this->vanishedViaUidDiff($name, $folderId, $highUid);

		$count = 0;
		foreach ($vanishedUids as $uid) {
			$row = InboundLabelMember::findByFolderUid($folderId, $uid);
			if ($row === null) {
				continue;
			}
			$messageId = intval($row->get('ilm_iem_inbound_email_message_id'));
			// Dirty iff the truth (present_local) diverges from the shadow (present_base).
			if ((bool)$row->get('ilm_present_local') !== (bool)$row->get('ilm_present_base')) {
				continue; // local-wins: push will reconcile
			}
			// Apply remote-remove: drop the label entirely (truth + shadow in one row).
			InboundLabelMember::deleteRow(intval($row->key));
			$this->repointLocator($messageId, $name, $uid);
			$count++;
		}
		return $count;
	}

	/** QRESYNC fast path: the UIDs the server reports vanished since $cursor. */
	private function vanishedViaQresync(string $name, int $cursor): array {
		$out = array();
		foreach ($this->client->vanished($name, $cursor) as $uid) {
			$out[] = intval($uid);
		}
		return $out;
	}

	/**
	 * CONDSTORE-only fallback: a UID left the folder iff it is in our stored
	 * membership but no longer in the folder's current UID set. (One full UID-only
	 * fetch per tracked folder per cycle — cheap, just less efficient than VANISHED.)
	 */
	private function vanishedViaUidDiff(string $name, int $folderId, int $highUid): array {
		// A folder with no messages (or whose UIDNEXT we couldn't read) is treated as
		// "unknown" — return nothing rather than risk clearing every membership.
		if ($highUid < 1) {
			return array();
		}
		// Current UIDs in the folder. Numeric range, not '1:*' (Gmail/Horde mishandle
		// the '*' form — it can return nothing), matching the ingest path.
		$query = new Horde_Imap_Client_Fetch_Query();
		$query->uid();
		$res = $this->client->fetch($name, $query, array('ids' => new Horde_Imap_Client_Ids('1:' . $highUid)));
		$present = array();
		foreach ($res as $uid => $data) {
			$present[intval($uid)] = true;
		}

		// Known shadow UIDs for this folder.
		$db = $this->db();
		$stmt = $db->prepare(
			'SELECT ilm_imap_uid FROM ilm_inbound_label_members
			 WHERE ilm_iif_inbound_imap_folder_id = ? AND ilm_imap_uid IS NOT NULL');
		$stmt->execute(array($folderId));

		$gone = array();
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
			$uid = intval($uid);
			if (!isset($present[$uid])) {
				$gone[] = $uid;
			}
		}
		return $gone;
	}

	// ── Push (Two-way only) ────────────────────────────────────────────────

	/**
	 * Push every dirty flag row and dirty membership element to the source, bounded
	 * by $maxPerRun. On confirmed write the shadow advances to match local,
	 * clearing dirty; loop avoidance then makes the re-read a value-equal no-op.
	 */
	public function push(int $maxPerRun): array {
		$started = microtime(true);
		$created = $this->createPendingFolders();
		$flags = $this->pushFlags($maxPerRun);
		$membership = $this->pushMembership($maxPerRun);
		$trashed = $this->pushTrash($maxPerRun);
		$this->ingestor->lap('push', microtime(true) - $started);
		return array('created' => $created, 'flags' => $flags, 'membership' => $membership, 'trashed' => $trashed);
	}

	/**
	 * Materialize folders created in Joinery that don't exist on the source yet:
	 * issue the IMAP CREATE, then clear the pending flag so membership push can COPY
	 * into them. Idempotent — a folder that already exists (created elsewhere) is
	 * treated as done. This is how "create a label locally" reaches the remote (§14).
	 */
	private function createPendingFolders(): int {
		$pending = new MultiInboundImapFolder(array(
			'account_id'     => intval($this->account->key),
			'pending_create' => true,
		));
		$pending->load();

		$created = 0;
		foreach ($pending as $row) {
			$folder = new InboundImapFolder($row->key, TRUE);
			$name = (string)$folder->get('iif_name');
			try {
				$this->client->createMailbox($name);
			} catch (Throwable $e) {
				// "already exists" is success; anything else is logged and retried next
				// cycle (the flag stays set), and membership into it is skipped until then.
				if (!$this->mailboxExists($name)) {
					$this->onWriteError($e, 'create folder ' . $name);
					continue;
				}
			}
			$folder->set('iif_pending_remote_create', false);
			$folder->prepare();
			$folder->save();
			$created++;
		}
		return $created;
	}

	/** Whether a mailbox exists on the server now (used to absorb a CREATE "already exists"). */
	private function mailboxExists(string $name): bool {
		try {
			$list = $this->client->listMailboxes($name, Horde_Imap_Client::MBOX_ALL, array());
			return !empty($list);
		} catch (Throwable $e) {
			return false;
		}
	}

	private function pushFlags(int $maxPerRun): int {
		$db = $this->db();
		$sql = "SELECT iem_inbound_email_message_id AS id, iem_imap_folder AS folder,
					iem_imap_uid AS uid, iem_is_read AS is_read, iem_is_starred AS is_starred
				FROM iem_inbound_email_messages
				WHERE iem_iia_inbound_imap_account_id = ?
				  AND iem_imap_folder IS NOT NULL AND iem_imap_uid IS NOT NULL
				  AND iem_local_state_modified IS NOT NULL
				  AND (iem_synced_state_time IS NULL OR iem_local_state_modified > iem_synced_state_time)
				ORDER BY iem_local_state_modified ASC
				LIMIT " . max(1, $maxPerRun);
		$stmt = $db->prepare($sql);
		$stmt->execute(array(intval($this->account->key)));
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$pushed = 0;
		foreach ($rows as $r) {
			$seen = $this->pgBool($r['is_read']);
			$flagged = $this->pgBool($r['is_starred']);
			$ids = new Horde_Imap_Client_Ids(array(intval($r['uid'])));
			$add = array(); $remove = array();
			if ($seen) { $add[] = self::FLAG_SEEN; } else { $remove[] = self::FLAG_SEEN; }
			if ($flagged) { $add[] = self::FLAG_FLAGGED; } else { $remove[] = self::FLAG_FLAGGED; }
			try {
				$this->client->store((string)$r['folder'], array('ids' => $ids, 'add' => $add, 'remove' => $remove));
				$this->markFlagsSynced(intval($r['id']));
				$pushed++;
			} catch (Throwable $e) {
				$this->onWriteError($e, 'flag push');
			}
		}
		return $pushed;
	}

	/**
	 * Push dirty membership, grouped per message so a move's add + removes are
	 * coordinated. Exclusive feeds add via MOVE (which is the relocation); a delete
	 * (Trash membership) MOVEs to Trash. Non-exclusive feeds add via COPY (the
	 * label add) and remove via EXPUNGE scoped to the folder's UID.
	 */
	private function pushMembership(int $maxPerRun): int {
		$db = $this->db();
		// A (message, label) element is dirty when its truth (ilm_present_local) differs
		// from its shadow (ilm_present_base) — a single-row column predicate the partial
		// index covers, so this scans O(dirty). The binding folder is inline on the row,
		// and only bound rows can be dirty (an unbound row keeps base = local), so the
		// join to iif_ both scopes to this feed and never widens the set.
		$accountId = intval($this->account->key);
		$sql = "SELECT ilm.ilm_iem_inbound_email_message_id AS msg_id,
					ilm.ilm_iif_inbound_imap_folder_id AS folder_id,
					ilm.ilm_ilb_inbound_email_label_id AS label_id,
					ilm.ilm_present_local AS local, ilm.ilm_present_base AS base,
					ilm.ilm_imap_uid AS f_uid,
					f.iif_name AS folder_name, f.iif_role AS folder_role
				FROM ilm_inbound_label_members ilm
				JOIN iif_inbound_imap_folders f
					ON f.iif_inbound_imap_folder_id = ilm.ilm_iif_inbound_imap_folder_id
				WHERE f.iif_iia_inbound_imap_account_id = ?
				  AND ilm.ilm_present_local <> ilm.ilm_present_base
				ORDER BY ilm.ilm_iem_inbound_email_message_id ASC, ilm.ilm_present_local DESC";
				// adds (local=true) before removes, so a COPY reads the locator before a
				// remove EXPUNGEs it.
		$stmt = $db->prepare($sql);
		$stmt->execute(array($accountId));

		$byMessage = array();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$byMessage[intval($r['msg_id'])][] = $r;
		}

		$exclusive = $this->account->foldersExclusive();
		$processed = 0;
		foreach ($byMessage as $msgId => $elements) {
			if ($processed >= max(1, $maxPerRun)) {
				break;
			}
			try {
				if ($exclusive) {
					$this->pushMembershipExclusive($msgId, $elements);
				} else {
					$this->pushMembershipNonExclusive($msgId, $elements);
				}
				$processed++;
			} catch (Throwable $e) {
				$this->onWriteError($e, 'membership push');
			}
		}
		return $processed;
	}

	/**
	 * Exclusive feed: the message lives in exactly one folder. A local label add is the
	 * move destination — MOVE the message there (which removes it from its current
	 * folder); every other label membership of the message collapses to that one folder.
	 * Trash is not a label here — a delete is the column-driven pushTrash step.
	 */
	private function pushMembershipExclusive(int $msgId, array $elements): void {
		$loc = $this->resolveLocator($msgId);
		if ($loc === null) {
			return;
		}

		$adds = array();    // base0 local1 (destination folders)
		$removes = array(); // base1 local0
		foreach ($elements as $el) {
			if (!$this->pgBool($el['base']) && $this->pgBool($el['local'])) { $adds[] = $el; }
			elseif ($this->pgBool($el['base']) && !$this->pgBool($el['local'])) { $removes[] = $el; }
		}

		if (count($adds)) {
			$dest = $adds[0];
			$destName = (string)$dest['folder_name'];
			$newUid = $this->moveMessage((string)$loc['folder'], intval($loc['uid']), $destName);
			// The message now lives only in $dest: that element becomes clean (shadow
			// advances to the new UID), every other membership of this message on this
			// feed is dropped, and the locator follows.
			InboundLabelMember::setBaseline($msgId, intval($dest['label_id']), intval($dest['folder_id']),
				true, $newUid, $newUid !== null ? intval($loc['uidvalidity']) : null);
			$this->collapseMembershipTo($msgId, intval($dest['folder_id']));
			$this->setLocator($msgId, $destName, $newUid ?? 0, $newUid !== null ? intval($loc['uidvalidity']) : null);
			return;
		}

		// Removals only, no destination: advance the shadow without a destructive op
		// (a genuine relocation always carries an add; a bare strand should not silently
		// EXPUNGE the user's mail). Drop the now-clean-absent membership rows.
		foreach ($removes as $el) {
			InboundLabelMember::clear($msgId, intval($el['label_id']));
		}
	}

	/**
	 * Non-exclusive feed (Gmail labels): each folder is an independent bit. Adds COPY the
	 * message in (adds the label); removes EXPUNGE it from the folder (removes the label).
	 */
	private function pushMembershipNonExclusive(int $msgId, array $elements): void {
		$loc = $this->resolveLocator($msgId);
		if ($loc === null) {
			return;
		}

		foreach ($elements as $el) {
			$folderId = intval($el['folder_id']);
			$labelId = intval($el['label_id']);
			$folderName = (string)$el['folder_name'];
			$base = $this->pgBool($el['base']);
			$local = $this->pgBool($el['local']);

			if (!$base && $local) {
				// Add: COPY from the locator copy into this folder (adds the label).
				$newUid = $this->copyMessage((string)$loc['folder'], intval($loc['uid']), $folderName);
				InboundLabelMember::setBaseline($msgId, $labelId, $folderId, true,
					$newUid, $newUid !== null ? intval($loc['uidvalidity']) : null);
			} elseif ($base && !$local) {
				// Remove: EXPUNGE this folder's copy (removes the label).
				$fUid = $el['f_uid'] !== null ? intval($el['f_uid']) : 0;
				if ($fUid > 0) {
					$this->expungeMessage($folderName, $fUid);
				}
				InboundLabelMember::clear($msgId, $labelId);
				// If the locator pointed at the folder we just left, re-point it.
				if (strcasecmp((string)$loc['folder'], $folderName) === 0) {
					$this->repointLocator($msgId, $folderName, $fUid);
				}
			}
		}
	}

	// ── Deletion push (Two-way, §7.5) ──────────────────────────────────────

	/**
	 * Push local soft-deletes to the source: each reference-backed row on this feed with
	 * iem_delete_time set and a locator not already in Trash is MOVEd (exclusive) / COPYd
	 * (Gmail, which treats a Trash copy as removal from every label) into the feed's Trash
	 * folder. The locator follows to Trash — doubling as the "already trashed" shadow, so
	 * the row is never re-pushed — and the message's label memberships on this feed are
	 * dropped (trashing clears every label). Gated by iia_sync_deletes; the remote→local
	 * direction is handled at ingest (ImapIngestor::markDeletedInTrash).
	 */
	private function pushTrash(int $maxPerRun): int {
		if (!$this->account->syncDeletes()) {
			return 0;
		}
		$trash = $this->trashFolder();
		if ($trash === null) {
			return 0; // no Trash folder resolved — soft-delete stays local
		}
		$trashName = (string)$trash->get('iif_name');
		$exclusive = $this->account->foldersExclusive();
		$db = $this->db();
		$sql = "SELECT iem_inbound_email_message_id AS id, iem_imap_folder AS folder,
					iem_imap_uid AS uid, iem_imap_uidvalidity AS uidvalidity
				FROM iem_inbound_email_messages
				WHERE iem_iia_inbound_imap_account_id = ?
				  AND iem_delete_time IS NOT NULL
				  AND iem_imap_folder IS NOT NULL AND iem_imap_uid IS NOT NULL
				  AND iem_imap_folder <> ?
				ORDER BY iem_delete_time ASC
				LIMIT " . max(1, $maxPerRun);
		$stmt = $db->prepare($sql);
		$stmt->execute(array(intval($this->account->key), $trashName));
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$moved = 0;
		foreach ($rows as $r) {
			$msgId = intval($r['id']);
			try {
				$newUid = $exclusive
					? $this->moveMessage((string)$r['folder'], intval($r['uid']), $trashName)
					: $this->copyMessage((string)$r['folder'], intval($r['uid']), $trashName);
				$this->setLocator($msgId, $trashName, $newUid ?? 0,
					$newUid !== null ? intval($r['uidvalidity']) : null);
				InboundLabelMember::clearForFolders($msgId, $this->feedFolderIds());
				$moved++;
			} catch (Throwable $e) {
				$this->onWriteError($e, 'trash push');
			}
		}
		return $moved;
	}

	// ── IMAP write primitives (idempotent) ─────────────────────────────────

	/** MOVE a UID from $source to $dest; returns the destination UID when the server reports it. */
	private function moveMessage(string $source, int $uid, string $dest): ?int {
		$res = $this->client->copy($source, $dest, array(
			'ids'  => new Horde_Imap_Client_Ids(array($uid)),
			'move' => true,
		));
		return $this->firstUid($res);
	}

	/** COPY a UID from $source to $dest; returns the destination UID when reported. */
	private function copyMessage(string $source, int $uid, string $dest): ?int {
		$res = $this->client->copy($source, $dest, array(
			'ids'  => new Horde_Imap_Client_Ids(array($uid)),
			'move' => false,
		));
		return $this->firstUid($res);
	}

	/** Remove a UID from a folder: \Deleted + EXPUNGE scoped to that UID. */
	private function expungeMessage(string $folder, int $uid): void {
		$ids = new Horde_Imap_Client_Ids(array($uid));
		$this->client->expunge($folder, array('ids' => $ids, 'delete' => true));
	}

	/** Horde copy/move returns dest UIDs (with UIDPLUS) or true — extract the first UID. */
	private function firstUid($res): ?int {
		if ($res instanceof Horde_Imap_Client_Ids) {
			foreach ($res as $uid) { return intval($uid); }
			return null;
		}
		if (is_array($res) && count($res)) {
			$vals = array_values($res);
			return intval($vals[0]);
		}
		return null;
	}

	// ── Row / locator helpers ──────────────────────────────────────────────

	/** The iem_ row this folder+uid is the locator of, or null. */
	private function locatorRow(string $folder, int $uid): ?array {
		$db = $this->db();
		$stmt = $db->prepare(
			"SELECT iem_inbound_email_message_id AS id, iem_is_read AS is_read,
					iem_is_starred AS is_starred, iem_local_state_modified AS local_modified,
					iem_synced_state_time AS synced
			 FROM iem_inbound_email_messages
			 WHERE iem_iia_inbound_imap_account_id = ? AND iem_imap_folder = ? AND iem_imap_uid = ?
			   AND iem_delete_time IS NULL LIMIT 1");
		$stmt->execute(array(intval($this->account->key), $folder, $uid));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	/** The message's current locator (folder/uid/uidvalidity/message-id), or null. */
	private function resolveLocator(int $msgId): ?array {
		$db = $this->db();
		$stmt = $db->prepare(
			"SELECT iem_imap_folder AS folder, iem_imap_uid AS uid,
					iem_imap_uidvalidity AS uidvalidity, iem_message_id_header AS message_id
			 FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ? LIMIT 1");
		$stmt->execute(array($msgId));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row || $row['folder'] === null || $row['uid'] === null) {
			return null;
		}
		return $row;
	}

	private function setLocator(int $msgId, string $folder, int $uid, ?int $uidvalidity): void {
		$db = $this->db();
		$stmt = $db->prepare(
			"UPDATE iem_inbound_email_messages
			 SET iem_imap_folder = ?, iem_imap_uid = ?, iem_imap_uidvalidity = ?
			 WHERE iem_inbound_email_message_id = ?");
		$stmt->execute(array($folder, $uid ?: null, $uidvalidity, $msgId));
	}

	/**
	 * If the locator pointed at ($vanishedFolder, $vanishedUid), re-point it to any other
	 * folder that still holds the message (a live ilm_ membership with a known UID).
	 * Keeps the body/attachment bytes fetchable after a move/remove (§7.4).
	 */
	private function repointLocator(int $msgId, string $vanishedFolder, int $vanishedUid): void {
		$loc = $this->resolveLocator($msgId);
		if ($loc === null || strcasecmp((string)$loc['folder'], $vanishedFolder) !== 0
				|| intval($loc['uid']) !== $vanishedUid) {
			return; // locator wasn't pointing here
		}
		$db = $this->db();
		// An alternate folder that still holds the message: a present_local membership
		// with a known UID on a different folder (a live remote copy to keep the locator on).
		$stmt = $db->prepare(
			"SELECT f.iif_name AS name, ilm.ilm_imap_uid AS uid, ilm.ilm_imap_uidvalidity AS uidvalidity
			 FROM ilm_inbound_label_members ilm
			 JOIN iif_inbound_imap_folders f ON f.iif_inbound_imap_folder_id = ilm.ilm_iif_inbound_imap_folder_id
			 WHERE ilm.ilm_iem_inbound_email_message_id = ?
			   AND ilm.ilm_present_local = true
			   AND ilm.ilm_imap_uid IS NOT NULL
			   AND f.iif_name <> ? LIMIT 1");
		$stmt->execute(array($msgId, $vanishedFolder));
		$alt = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($alt) {
			$this->setLocator($msgId, (string)$alt['name'], intval($alt['uid']),
				$alt['uidvalidity'] !== null ? intval($alt['uidvalidity']) : null);
		}
	}

	/**
	 * Collapse $msgId onto a single folder of this feed after an exclusive MOVE: drop its
	 * membership rows on every other folder of this feed (label removal). Other feeds' and
	 * unbound (local) labels are left untouched.
	 */
	private function collapseMembershipTo(int $msgId, int $keepFolderId): void {
		InboundLabelMember::clearForFolders($msgId, $this->feedFolderIds(), $keepFolderId);
	}

	/** Every iif_ folder id on this feed (the scope for collapse / trash relocation). */
	private function feedFolderIds(): array {
		$stmt = $this->db()->prepare(
			'SELECT iif_inbound_imap_folder_id FROM iif_inbound_imap_folders
			 WHERE iif_iia_inbound_imap_account_id = ?');
		$stmt->execute(array(intval($this->account->key)));
		$out = array();
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$out[] = intval($id);
		}
		return $out;
	}

	private function applyRemoteFlags(int $msgId, bool $seen, bool $flagged): void {
		// A remote apply must NOT touch iem_local_state_modified (that would mark the
		// row dirty and bounce it back); it stamps synced_state_time so the row stays
		// clean and value-equal next cycle.
		$db = $this->db();
		$stmt = $db->prepare(
			"UPDATE iem_inbound_email_messages
			 SET iem_is_read = ?, iem_is_starred = ?,
				 iem_read_time = CASE WHEN ? THEN COALESCE(iem_read_time, now()) ELSE iem_read_time END,
				 iem_synced_state_time = now()
			 WHERE iem_inbound_email_message_id = ?");
		$stmt->execute(array($seen ? 'true' : 'false', $flagged ? 'true' : 'false',
			$seen ? 'true' : 'false', $msgId));
	}

	private function markFlagsSynced(int $msgId): void {
		$db = $this->db();
		$stmt = $db->prepare(
			"UPDATE iem_inbound_email_messages SET iem_synced_state_time = now()
			 WHERE iem_inbound_email_message_id = ?");
		$stmt->execute(array($msgId));
	}

	private function flagRowDirty($localModified, $synced): bool {
		if ($localModified === null) {
			return false;
		}
		if ($synced === null) {
			return true;
		}
		return strcmp((string)$localModified, (string)$synced) > 0;
	}

	/** A write that failed on auth flags the feed for reconnection, exactly as ingest does. */
	private function onWriteError(Throwable $e, string $context): void {
		error_log('ImapSyncer ' . $context . ' failed (account ' . $this->account->key . '): ' . $e->getMessage());
		if ($e instanceof Horde_Imap_Client_Exception
				&& $e->getCode() === Horde_Imap_Client_Exception::LOGIN_AUTHENTICATIONFAILED
				&& $this->account->isOAuth()) {
			$this->account->markNeedsReauth();
		}
	}

	private function pgBool($value): bool {
		if (is_bool($value)) {
			return $value;
		}
		return ($value === 't' || $value === 'true' || $value === '1' || $value === 1 || $value === true);
	}
}
?>

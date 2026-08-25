<?php
/**
 * MailboxIndex - the sealed FTS5 search index for a vault-holding mailbox
 * owner (specs/implemented/inbound_email_encryption_at_rest.md § 6).
 *
 * Postgres full-text search (to_tsvector/GIN) can never search a sealed
 * mailbox — the columns hold ciphertext at rest. MailboxIndex is the
 * replacement for that one owner: a SQLite FTS5 database built from decrypted
 * content, held ONLY in /dev/shm (RAM-backed tmpfs, never touches disk in the
 * clear) for the lifetime of the unlock window.
 *
 * Lifecycle, all keyed to the owner's user id:
 *   - ensureOpen()  restores the /dev/shm working copy from its sealed blob
 *                    (a private File), or rebuild()s from scratch if there is
 *                    none / it fails to open.
 *   - fold()        folds messages newer than the high-water mark into the
 *                    open working copy — batched, checkpointed, and bounded by
 *                    an optional deadline, under a per-user flock so only one
 *                    fold runs at a time. Every id is delete-then-insert, so
 *                    re-folding is harmless; the mark advances per completed
 *                    batch, so an interrupted fold resumes where it stopped
 *                    instead of restarting. A large backlog (a bulk import, a
 *                    long-offline owner) is folded across many bounded calls —
 *                    the search request folds one slice and the deferred-work
 *                    drain (bootstrap.php, 'mailbox_fts_fold') folds the rest
 *                    in-window. Persisting is seal-after-fold but throttled:
 *                    always when the fold completes or processed refolds, and
 *                    every PERSIST_MIN_ADVANCE messages mid-backlog, so a
 *                    window close costs at most one chunk of re-folding. A
 *                    fold that changed nothing persists nothing: the
 *                    already-persisted blob is exactly current, so repeated
 *                    searches over unchanged mail never rewrite a
 *                    multi-megabyte file.
 *   - search()       queries the already-open working copy; the caller (
 *                    MailboxService::listThreads()) calls fold() first so a
 *                    search always sees the latest mail.
 *   - wipe()          deletes only the /dev/shm working copy (window-close /
 *                    the sweep task) — the persisted sealed blob is untouched,
 *                    so the next unlock restores instantly via ensureOpen().
 *   - purgePersisted() deletes the persisted sealed blob itself (key rotation
 *                    — sealed under the now-superseded key) and resets the
 *                    high-water mark, so the next unlock rebuild()s fresh.
 *
 * DISPOSABLE CACHE: every stored byte here is reconstructible from the sealed
 * message rows. Missing, stale, corrupt, or post-rotation — rebuild(), never
 * an error. That extends to writing the cache: persisting the sealed blob is
 * best-effort and never throws, so a storage-layer failure costs a slower next
 * unlock rather than breaking the search in flight. Index source: sender +
 * subject + both bodies + attachment filenames, with the HTML body reduced to
 * its readable text (MailboxHtmlSanitizer::toReadableText) so a sender's
 * embedded stylesheet is not searchable. Attachment CONTENTS are never indexed.
 *
 * Changing what rowContent() indexes changes what the stored index contains:
 * purgePersisted() the affected owners so the next unlock rebuilds, or the old
 * text keeps matching.
 *
 * COVERAGE: the index holds every stored message in the owner's mailboxes,
 * trashed ones included (drafts excepted — few, in flux, and opened directly).
 * The READ SCOPE decides what a search returns: MailboxService intersects index
 * hits with the caller's scope, so an inbox search never surfaces trashed mail
 * and a Trash search finds it. Filtering the fold by delete state instead would
 * break restore: the high-water mark advances past every row the pass SAW, so a
 * message trashed before its first fold would be skipped permanently and
 * restoring it could never bring it back.
 *
 * Pruning follows the row's existence, not a flag. enqueueRefold() queues an id
 * whose content changed or whose row is about to go; the refold pass deletes the
 * FTS row and re-inserts only if the message still exists. A pending-parse row
 * folds as a no-op (its content fields do not exist yet) and enters the index
 * via that same queue when parsePendingMessage() clears the pending state.
 *
 * The persisted blob records the mark it covers (imi_blob_high_water), and a
 * restore resets the live mark to it — a blob can lag the mark (checkpointed
 * folding), and restoring one under a newer mark would otherwise open a silent
 * coverage gap.
 *
 * @version 1.7 - batched checkpointed folding with a deadline and a per-user
 *                fold lock; blob coverage recording; pending-parse rows fold
 *                as no-ops (refolded after parse)
 * @version 1.6
 */

require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxHtmlSanitizer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_mailbox_search_index_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));

class MailboxIndexException extends Exception {}

class MailboxIndex {

	const SHM_DIR = '/dev/shm';

	/** Messages per checkpoint: the high-water mark advances after each fully
	 *  successful batch, so an interrupted fold resumes instead of restarting. */
	const FOLD_BATCH = 200;

	/** How far the working copy may run ahead of the persisted blob before a
	 *  mid-backlog fold re-persists. A window close wipes the working copy and
	 *  the next restore resumes from the blob's mark, so this bounds the
	 *  re-folding a close can cost — against sealing a multi-hundred-megabyte
	 *  blob on every bounded slice of a long catch-up. */
	const PERSIST_MIN_ADVANCE = 5000;

	/** SQLite busy handler wait. The fold lock makes contention abnormal; this
	 *  is the second belt so an overlap degrades to a wait, never to a
	 *  silently lost write. */
	const BUSY_TIMEOUT_MS = 5000;

	/** The /dev/shm working-copy path for a user's index. */
	public function shmPath(int $user_id): string {
		return self::SHM_DIR . '/mailfts_' . $user_id . '.sqlite';
	}

	/**
	 * Ensure the /dev/shm working copy exists and is openable — restore from
	 * the persisted sealed blob, or rebuild() from the sealed message rows
	 * when there is none (first search) or it fails to open (corrupt/missing
	 * — the disposable-cache contract).
	 */
	public function ensureOpen(int $user_id, string $secret_key, ?float $deadline = null): void {
		$path = $this->shmPath($user_id);
		if (file_exists($path) && $this->tryOpenDb($path) !== null) {
			return;
		}
		if ($this->restoreFromBlob($user_id, $secret_key)) {
			return;
		}
		$this->rebuild($user_id, $secret_key, $deadline);
	}

	/**
	 * Fold messages newer than the high-water mark into the open working copy,
	 * then seal-after-fold: persist while the key is in hand, never waiting
	 * for window close.
	 *
	 * $deadline is a microtime(true) value checked between messages (the
	 * VaultDeferredWork consumer contract); null runs to completion. One fold
	 * per user at a time: a caller finding the fold lock held touches nothing
	 * and reports the backlog — the holder is making the progress.
	 *
	 * @return array{complete: bool, folded: int, remaining: int, total: int}
	 *         complete is computed from the data (no rows above the mark, no
	 *         queued refolds), so a fold cut short by the deadline, a lost
	 *         lock race, or an aborted write all report the truth.
	 */
	public function fold(int $user_id, string $secret_key, ?float $deadline = null): array {
		$lock = $this->acquireFoldLock($user_id);
		if ($lock === null) {
			return $this->foldStatus($user_id, 0);
		}
		try {
			$this->ensureOpen($user_id, $secret_key, $deadline);
			$bookkeeping = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
			$r = $this->foldSince($user_id, intval($bookkeeping->get('imi_fts_high_water')), $deadline);

			// Persist when the fold completed or processed refolds, when the
			// working copy has run PERSIST_MIN_ADVANCE past the blob, or when no
			// blob exists yet (first index; after purgePersisted(); a persist
			// that failed best-effort last time). When nothing was folded the
			// persisted blob is exactly current, so skipping the write loses
			// nothing.
			$fresh = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
			$no_blob = intval($fresh->get('imi_fil_file_id')) <= 0;
			$blob_mark = $fresh->get('imi_blob_high_water');
			$advance = ($blob_mark === null || $blob_mark === '')
				? PHP_INT_MAX   // legacy blob with unrecorded coverage — record it now
				: intval($fresh->get('imi_fts_high_water')) - intval($blob_mark);
			$persist_ok = true;
			if ($no_blob || ($r['written']
					&& ($r['complete'] || $r['refolded'] > 0 || $advance >= self::PERSIST_MIN_ADVANCE))) {
				$persist_ok = $this->persist($user_id, $secret_key);
			}

			// Refolds leave the queue only once their result is safe against a
			// window close: the persisted blob now carries them, or there is no
			// blob to restore a stale entry from. Left queued, they simply run
			// again next fold — delete-then-insert makes that harmless.
			if (count($r['refold_done']) && $persist_ok) {
				$this->checkpointQueue($user_id, $r['refold_done']);
			}
			return $this->foldStatus($user_id, $r['folded']);
		} finally {
			$this->releaseFoldLock($lock);
		}
	}

	/** Message ids matching $query in the (already-open) working copy. */
	public function search(int $user_id, string $query): array {
		$path = $this->shmPath($user_id);
		$db = $this->tryOpenDb($path);
		if ($db === null) {
			return array();
		}
		$stmt = $db->prepare('SELECT message_id FROM mailfts WHERE mailfts MATCH :q ORDER BY rank');
		if (!$stmt) {
			return array();
		}
		$stmt->bindValue(':q', $this->sanitizeFtsQuery($query), SQLITE3_TEXT);
		$result = $stmt->execute();
		if ($result === false) {
			return array(); // malformed FTS5 query syntax after sanitizing — no match, not an error
		}
		$ids = array();
		while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
			$ids[] = intval($row['message_id']);
		}
		$db->close();
		return $ids;
	}

	/**
	 * Full rebuild from the sealed message rows: fresh FTS5 table, high-water
	 * reset to 0. The refold queue is cleared with it — a queued refold marks a
	 * stale FTS entry, and a fresh table has none; every queued id is above the
	 * reset mark and folds like any other row. A deadline may leave the rebuild
	 * partial; the checkpointed mark makes any later fold() continue it.
	 */
	public function rebuild(int $user_id, string $secret_key, ?float $deadline = null): void {
		$path = $this->shmPath($user_id);
		@unlink($path);
		if (!is_dir(self::SHM_DIR)) {
			throw new MailboxIndexException('MailboxIndex: ' . self::SHM_DIR . ' is not available.');
		}
		$db = new SQLite3($path);
		$db->busyTimeout(self::BUSY_TIMEOUT_MS);
		$db->exec('CREATE VIRTUAL TABLE mailfts USING fts5(message_id UNINDEXED, content)');
		$db->close();

		$bookkeeping = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
		$bookkeeping->set('imi_fts_high_water', 0);
		$bookkeeping->set('imi_refold_ids', null);
		$bookkeeping->save();

		$this->foldSince($user_id, 0, $deadline);
		$this->persist($user_id, $secret_key);
	}

	/** Delete only the /dev/shm working copy — window-close, the sweep task. */
	public function wipe(int $user_id): void {
		// Closing a window that never built an index is the common case, not an
		// error. Check before unlinking rather than leaning on @: a strict error
		// handler (the test harness installs one) still sees a suppressed warning
		// and turns it into a failure.
		$path = $this->shmPath($user_id);
		if (is_file($path)) {
			@unlink($path);
		}
	}

	/**
	 * Delete the persisted sealed blob (sealed under a now-superseded vault
	 * key) and reset the high-water mark, so the next unlock rebuild()s
	 * fresh. Called from the rotation re-seal callback (bootstrap.php).
	 */
	public function purgePersisted(int $user_id): void {
		$this->wipe($user_id);
		$bookkeeping = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
		$fil_id = intval($bookkeeping->get('imi_fil_file_id'));
		if ($fil_id > 0) {
			$file = new File($fil_id, TRUE);
			if ($file->key) {
				try { $file->permanent_delete(); } catch (Throwable $e) { /* best-effort */ }
			}
		}
		$bookkeeping->set('imi_fil_file_id', null);
		$bookkeeping->set('imi_sealed_key', null);
		$bookkeeping->set('imi_blob_high_water', null);
		$bookkeeping->set('imi_fts_high_water', 0);
		$bookkeeping->save();
	}

	/**
	 * Queue a message id for delete-and-reinsert at the next fold, for every
	 * grantee of its mailbox who holds an index. Two callers need it and both mean
	 * "this id's FTS row is stale":
	 *
	 *   - a draft morphing in place into its Sent row (same id, so the id > mark
	 *     main pass never revisits it);
	 *   - a purge about to delete the row (the re-insert then finds nothing, and
	 *     the entry is dropped).
	 *
	 * Needs no vault: writing the queue is bookkeeping, and the fold that consumes
	 * it happens whenever the owner next unlocks. Best-effort — a stale index entry
	 * is a search-quality problem, never a reason to fail the operation that
	 * queued it.
	 */
	public static function enqueueRefold(int $alias_id, int $message_id): void {
		try {
			foreach (InboundEmailMailboxGrant::user_ids_for_alias($alias_id) as $uid) {
				$uid = intval($uid);
				$multi = new MultiInboundMailboxSearchIndex(array('user_id' => $uid));
				$multi->load();
				if (!$multi->count()) {
					continue; // no index yet — a first search rebuilds and folds this id
				}
				$bk = $multi->get(0);
				$ids = json_decode((string)$bk->get('imi_refold_ids'), true);
				if (!is_array($ids)) {
					$ids = array();
				}
				$ids = array_map('intval', $ids);
				if (!in_array($message_id, $ids, true)) {
					$ids[] = $message_id;
				}
				$bk->set('imi_refold_ids', json_encode(array_values($ids)));
				$bk->save();
			}
		} catch (\Throwable $e) {
			error_log('MailboxIndex: refold enqueue failed for message ' . $message_id . ': ' . $e->getMessage());
		}
	}

	// ------------------------------------------------------------- internals

	private function tryOpenDb(string $path): ?SQLite3 {
		try {
			$db = new SQLite3($path, SQLITE3_OPEN_READWRITE);
			$db->busyTimeout(self::BUSY_TIMEOUT_MS);
			$check = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='mailfts'");
			if ($check !== 'mailfts') {
				$db->close();
				return null;
			}
			return $db;
		} catch (Throwable $e) {
			return null;
		}
	}

	/**
	 * Restore the /dev/shm working copy from the persisted sealed blob. False
	 * if there is none / it fails to open — the caller then rebuild()s.
	 *
	 * Streams from the blob's on-disk path straight to the /dev/shm working
	 * path (index blobs are private files on local disk), so restore memory is
	 * bounded by a chunk, never by the index. A blob that is not in the stream
	 * format — including one sealed by a build that used the whole-blob string
	 * seal — is refused here, which is just the disposable-cache contract: the
	 * caller rebuilds from the sealed message rows and the next persist writes
	 * stream-format.
	 */
	private function restoreFromBlob(int $user_id, string $secret_key): bool {
		$bookkeeping = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
		$fil_id = intval($bookkeeping->get('imi_fil_file_id'));
		$sealed_key = $bookkeeping->get('imi_sealed_key');
		if ($fil_id <= 0 || !$sealed_key) {
			return false;
		}
		$file = new File($fil_id, TRUE);
		if (!$file->key || $file->get('fil_delete_time')) {
			return false;
		}
		$blob = $file->_blob();
		$src = $blob ? $blob->filesystem_path('original') : '';
		if ($src === '' || !is_file($src) || !SealedBox::isStreamFile($src)) {
			return false;
		}
		try {
			$crypto = new VaultCrypto();
			$dek = $crypto->openItemDek((string)$sealed_key, $secret_key);
			$crypto->openFieldFile($src, $this->shmPath($user_id), $dek, $this->blobAd($user_id));
		} catch (Throwable $e) {
			error_log('MailboxIndex: restoreFromBlob failed for user ' . $user_id . ' (rebuilding): ' . $e->getMessage());
			return false;
		}
		if ($this->tryOpenDb($this->shmPath($user_id)) === null) {
			return false;
		}

		// The blob can lag the mark (folding checkpoints the mark per batch,
		// persisting per chunk), so the mark must follow the copy just restored
		// or the gap between blob-time and mark-time would never be folded. A
		// legacy blob (coverage never recorded) was persisted only after a
		// complete fold, when blob and mark agreed — the mark stands.
		$blob_mark = $bookkeeping->get('imi_blob_high_water');
		if ($blob_mark !== null && $blob_mark !== ''
				&& intval($blob_mark) !== intval($bookkeeping->get('imi_fts_high_water'))) {
			$fresh = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
			$fresh->set('imi_fts_high_water', intval($blob_mark));
			$fresh->save();
		}
		return true;
	}

	/**
	 * Seal-after-fold: seal the /dev/shm file path-to-path (memory bounded by
	 * a chunk at any index size) and persist the sealed file as a private File.
	 *
	 * Never throws. The persisted blob is a restore shortcut for the next
	 * unlock, not the index itself — the working copy in /dev/shm is already
	 * folded and searchable by the time this runs, and everything here is
	 * reconstructible from the sealed message rows. A failure to write the
	 * shortcut (storage misconfigured, disk full, quota) costs one slower
	 * unlock later; it must never take down the search that triggered it.
	 *
	 * @return bool false only on that swallowed failure — fold() keeps
	 *              processed refolds queued until a persist has carried them,
	 *              so a restore of the old blob cannot revive a stale entry.
	 */
	private function persist(int $user_id, string $secret_key): bool {
		try {
			$this->persistOrThrow($user_id, $secret_key);
			return true;
		} catch (Throwable $e) {
			error_log('MailboxIndex: persist failed for user ' . $user_id
				. ' (index still searchable, next unlock rebuilds): ' . $e->getMessage());
			return false;
		}
	}

	private function persistOrThrow(int $user_id, string $secret_key): void {
		$path = $this->shmPath($user_id);
		if (!file_exists($path)) {
			return;
		}
		$vault = UserEncryptionVault::loadForUser($user_id);
		if (!$vault) {
			return;
		}

		$crypto = new VaultCrypto();
		$dek = $crypto->newItemDek();
		$sealed_key = $crypto->sealItemDek($dek, (string)$vault->get('uev_public_key'));

		$bookkeeping = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
		$old_fil_id = intval($bookkeeping->get('imi_fil_file_id'));

		// Seal to a temp file, then hand it to the path-based ingest — the index
		// content is never held as a string anywhere on this path.
		$tmp = tempnam(sys_get_temp_dir(), 'mailfts_seal_');
		if ($tmp === false) {
			throw new MailboxIndexException('MailboxIndex: unable to create a temp file for the sealed index.');
		}
		try {
			$crypto->sealFieldFile($path, $tmp, $dek, $this->blobAd($user_id));
			$file = File::createFromUpload($tmp, 'mailfts_' . $user_id . '.bin', 'application/octet-stream', $user_id, array(
				'fil_private' => true,
				'fil_source'  => File::SOURCE_MAILBOX_SEARCH_INDEX,
			));
		} finally {
			if (is_file($tmp)) {
				@unlink($tmp);
			}
		}

		$bookkeeping->set('imi_fil_file_id', intval($file->key));
		$bookkeeping->set('imi_sealed_key', $sealed_key);
		// What the sealed file covers. The fold checkpointed the mark before
		// this ran (and holds the fold lock), so the working copy just sealed
		// contains everything at or below it.
		$bookkeeping->set('imi_blob_high_water', intval($bookkeeping->get('imi_fts_high_water')));
		$bookkeeping->save();

		if ($old_fil_id > 0 && $old_fil_id !== intval($file->key)) {
			$old = new File($old_fil_id, TRUE);
			if ($old->key) {
				try { $old->permanent_delete(); } catch (Throwable $e) { /* best-effort */ }
			}
		}
	}

	/**
	 * Fold messages newer than $since_id belonging to $user_id's single-owner
	 * mailboxes into the (already-open) /dev/shm working copy, checkpointing
	 * the high-water mark after each FOLD_BATCH-sized batch. Reads content the
	 * same way a viewer would (InboundEmailMessage::get(), routed through the
	 * sealed-field hook), so it works identically for sealed and never-sealed
	 * rows.
	 *
	 * Every id is delete-then-insert, so folding the same id twice is
	 * harmless — which is what lets the mark checkpoint mid-backlog and a cut
	 * fold resume. A write that FAILS stops the pass with the mark at the last
	 * contiguous success: the mark never advances past a message that is not
	 * actually in the index.
	 *
	 * $deadline (microtime(true) value) is checked between messages; null runs
	 * to completion. Refolds run first — their FTS entries are stale right now,
	 * new rows merely absent.
	 *
	 * @return array{written: bool, complete: bool, folded: int, refolded: int,
	 *               refold_done: array}  refold_done stays queued in
	 *               bookkeeping until fold() confirms a persist carried it —
	 *               see fold().
	 */
	private function foldSince(int $user_id, int $since_id, ?float $deadline = null): array {
		$r = array('written' => false, 'complete' => true, 'folded' => 0, 'refolded' => 0,
			'refold_done' => array());
		$alias_ids = InboundEmailMailboxGrant::alias_ids_for_user($user_id);
		if (!count($alias_ids)) {
			return $r;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$in = implode(',', array_map('intval', $alias_ids));

		// The refold queue: message ids at-or-below the mark that changed after
		// folding. A draft morphs IN PLACE into its Sent row keeping its id
		// (Fix 6), and a pending-parse row gains its content after the mark
		// passed it — neither is ever revisited by the `id > since` main pass;
		// the queue drives an explicit delete-and-reinsert for exactly those ids.
		$bookkeeping = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
		$refold = json_decode((string)$bookkeeping->get('imi_refold_ids'), true);
		$refold = is_array($refold) ? array_values(array_unique(array_map('intval', $refold))) : array();

		// New rows since the mark. Delete state is deliberately not a condition (see
		// COVERAGE above): a trashed row is indexed like any other and the read scope
		// keeps it out of results. Drafts stay OUT — few, in flux, and opened
		// directly, never searched.
		$stmt = $db->prepare(
			"SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			 WHERE iem_iea_inbound_email_alias_id IN ($in)
			 AND iem_inbound_email_message_id > ?
			 AND iem_direction IS DISTINCT FROM 'draft'
			 ORDER BY iem_inbound_email_message_id ASC");
		$stmt->execute(array($since_id));
		$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

		if (!count($ids) && !count($refold)) {
			return $r; // nothing to fold and nothing queued — the early-out
		}

		// Which refold ids are still indexable (the row exists, non-draft, in the
		// user's scope) — the rest are only deleted from the FTS table. A purged
		// message fails this check because its row is gone, which is how the stale
		// entry gets dropped.
		$refold_valid = array();
		if (count($refold)) {
			$rin = implode(',', array_map('intval', $refold));
			$rstmt = $db->prepare(
				"SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
				 WHERE iem_inbound_email_message_id IN ($rin)
				 AND iem_iea_inbound_email_alias_id IN ($in)
				 AND iem_direction IS DISTINCT FROM 'draft'");
			$rstmt->execute();
			$refold_valid = array_flip(array_map('intval', $rstmt->fetchAll(PDO::FETCH_COLUMN)));
		}

		$shm = new SQLite3($this->shmPath($user_id));
		$shm->busyTimeout(self::BUSY_TIMEOUT_MS);
		$insert = $shm->prepare('INSERT INTO mailfts (message_id, content) VALUES (:id, :content)');
		$delete = $shm->prepare('DELETE FROM mailfts WHERE message_id = :id');

		// Drop the id's FTS row (rows, in a copy damaged by a pre-checkpoint
		// build), then re-insert when it has indexable content. False on a
		// failed SQLite write — the caller stops rather than skips, because a
		// skipped id behind an advancing mark would be silently unsearchable.
		$fold_one = function (int $id, bool $reinsert) use ($insert, $delete): bool {
			$delete->bindValue(':id', $id, SQLITE3_INTEGER);
			$ok = $delete->execute() !== false;
			$delete->reset();
			if (!$ok || !$reinsert) {
				return $ok;
			}
			$content = $this->rowContent($id);
			if ($content === null) {
				return true; // row gone, sealed away, or pending parse — nothing to index
			}
			$insert->bindValue(':id', $id, SQLITE3_INTEGER);
			$insert->bindValue(':content', $content, SQLITE3_TEXT);
			$ok = $insert->execute() !== false;
			$insert->reset();
			return $ok;
		};

		// Refold pass: stale entries first.
		foreach ($refold as $id) {
			if ($deadline !== null && microtime(true) >= $deadline) {
				$r['complete'] = false;
				break;
			}
			if (!$fold_one($id, isset($refold_valid[$id]))) {
				error_log('MailboxIndex: fold stopped for user ' . $user_id
					. ' — SQLite write failed on refold id ' . $id);
				$r['complete'] = false;
				break;
			}
			$r['refold_done'][] = $id;
			$r['written'] = true;
			$r['refolded']++;
			$r['folded']++;
		}

		// Main pass: the new rows since the mark, skipping any refolded moments
		// ago. The mark checkpoints only on whole batches — every id at or
		// below it made it into the working copy.
		if ($r['complete']) {
			$refolded_now = array_flip($r['refold_done']);
			$last_ok = $since_id;
			$n = 0;
			foreach ($ids as $id) {
				if ($deadline !== null && microtime(true) >= $deadline) {
					$r['complete'] = false;
					break;
				}
				if (!isset($refolded_now[$id])) {
					if (!$fold_one($id, true)) {
						error_log('MailboxIndex: fold stopped for user ' . $user_id
							. ' — SQLite write failed on message id ' . $id);
						$r['complete'] = false;
						break;
					}
					$r['written'] = true;
					$r['folded']++;
				}
				$last_ok = $id;
				if (++$n % self::FOLD_BATCH === 0) {
					$this->checkpointMark($user_id, $last_ok);
				}
			}
			$this->checkpointMark($user_id, $last_ok);
		}
		$shm->close();
		return $r;
	}

	/**
	 * Advance the high-water mark. Fresh-loads the row and writes only the
	 * mark, so a concurrent enqueueRefold() save is not clobbered; never moves
	 * it backwards (only restoreFromBlob may, deliberately, when the restored
	 * copy covers less than the mark claimed).
	 */
	private function checkpointMark(int $user_id, int $mark): void {
		$bk = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
		if ($mark > intval($bk->get('imi_fts_high_water'))) {
			$bk->set('imi_fts_high_water', $mark);
			$bk->save();
		}
	}

	/**
	 * Remove processed ids from the stored refold queue, preserving whatever
	 * was enqueued while the fold ran.
	 */
	private function checkpointQueue(int $user_id, array $processed_ids): void {
		$bk = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
		$stored = json_decode((string)$bk->get('imi_refold_ids'), true);
		$stored = is_array($stored) ? array_map('intval', $stored) : array();
		$remaining = array_values(array_diff($stored, array_map('intval', $processed_ids)));
		$bk->set('imi_refold_ids', count($remaining) ? json_encode($remaining) : null);
		$bk->save();
	}

	/**
	 * Is there fold work owed for this user? Cheap, indexed, no decrypt — it
	 * runs on every vault heartbeat via the 'mailbox_fts_fold' deferred-work
	 * consumer (bootstrap.php). False when no bookkeeping row exists: the row
	 * is created by the first search, so an owner who never searches never
	 * pays for index building.
	 */
	public static function hasBacklog(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		$multi = new MultiInboundMailboxSearchIndex(array('user_id' => $user_id));
		$multi->load();
		if (!$multi->count()) {
			return false;
		}
		$bk = $multi->get(0);
		$refold = json_decode((string)$bk->get('imi_refold_ids'), true);
		if (is_array($refold) && count($refold)) {
			return true;
		}
		$alias_ids = InboundEmailMailboxGrant::alias_ids_for_user($user_id);
		if (!count($alias_ids)) {
			return false;
		}
		$in = implode(',', array_map('intval', $alias_ids));
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT 1 FROM iem_inbound_email_messages
			 WHERE iem_iea_inbound_email_alias_id IN ($in)
			 AND iem_inbound_email_message_id > ?
			 AND iem_direction IS DISTINCT FROM 'draft'
			 LIMIT 1");
		$stmt->execute(array(intval($bk->get('imi_fts_high_water'))));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * The fold() return value, computed from the data rather than from what
	 * this call happened to do: complete means no rows above the mark and no
	 * queued refolds, however the fold ended (deadline, lost lock race,
	 * aborted write, or clean finish).
	 */
	private function foldStatus(int $user_id, int $folded): array {
		$total = 0;
		$remaining = 0;
		$refolds = 0;
		$bk = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
		$refold = json_decode((string)$bk->get('imi_refold_ids'), true);
		$refolds = is_array($refold) ? count($refold) : 0;
		$alias_ids = InboundEmailMailboxGrant::alias_ids_for_user($user_id);
		if (count($alias_ids)) {
			$in = implode(',', array_map('intval', $alias_ids));
			$db = DbConnector::get_instance()->get_db_link();
			$stmt = $db->prepare(
				"SELECT count(*),
						count(*) FILTER (WHERE iem_inbound_email_message_id > ?)
				 FROM iem_inbound_email_messages
				 WHERE iem_iea_inbound_email_alias_id IN ($in)
				 AND iem_direction IS DISTINCT FROM 'draft'");
			$stmt->execute(array(intval($bk->get('imi_fts_high_water'))));
			$row = $stmt->fetch(PDO::FETCH_NUM);
			$total = intval($row[0]);
			$remaining = intval($row[1]);
		}
		return array(
			'complete'  => ($remaining === 0 && $refolds === 0),
			'folded'    => $folded,
			'remaining' => $remaining,
			'total'     => $total,
		);
	}

	/**
	 * The per-user fold lock: one fold at a time, non-blocking. The lock FILE
	 * is never unlinked — deleting a file another process holds an flock on
	 * would hand the next opener a fresh inode and break the mutual exclusion.
	 * It is zero bytes and rides along in /dev/shm.
	 */
	private function acquireFoldLock(int $user_id) {
		$path = self::SHM_DIR . '/mailfts_' . $user_id . '.lock';
		$fh = @fopen($path, 'c');
		if ($fh === false) {
			return null;
		}
		@chmod($path, 0666); // web and CLI both fold (search request / drain / tests)
		if (!flock($fh, LOCK_EX | LOCK_NB)) {
			fclose($fh);
			return null;
		}
		return $fh;
	}

	private function releaseFoldLock($fh): void {
		if (is_resource($fh)) {
			flock($fh, LOCK_UN);
			fclose($fh);
		}
	}

	/**
	 * The indexable text for one message id — sender + subject + both bodies +
	 * attachment filenames — read the same way a viewer would (through the
	 * sealed-field hook). Returns null when the row is gone or a decrypt fails
	 * (fold only runs in-window, but never let one row abort the whole fold).
	 *
	 * The HTML body is reduced to its readable text, not merely tag-stripped:
	 * bulk mail carries its stylesheet inside the document, so stripping tags
	 * alone would index every sender's CSS and let a search for "container" or
	 * "font" match hundreds of unrelated messages. What gets indexed is what a
	 * person can see.
	 */
	private function rowContent(int $id): ?string {
		$msg = new InboundEmailMessage($id, TRUE);
		if (!$msg->key) {
			return null;
		}
		if ($msg->get('iem_pending_parse')) {
			// The content fields do not exist yet — only the sealed raw blob
			// does. parsePendingMessage() enqueues a refold when they appear,
			// so skipping here never strands the message outside the index.
			return null;
		}
		try {
			return (string)$msg->get('iem_sender') . ' ' . (string)$msg->get('iem_subject') . ' '
				. (string)$msg->get('iem_body_plain') . ' '
				. MailboxHtmlSanitizer::toReadableText((string)$msg->get('iem_body_html'))
				. ' ' . $this->attachmentFilenames($id);
		} catch (VaultLockedException $e) {
			return null;
		}
	}

	private function attachmentFilenames(int $message_id): string {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT ima_filename FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = ?');
		$stmt->execute(array($message_id));
		return implode(' ', array_filter($stmt->fetchAll(PDO::FETCH_COLUMN)));
	}

	/** AD for the sealed FTS blob itself — bound to the owner, not a message row. */
	private function blobAd(int $user_id): string {
		return 'mail:ftsindex:' . $user_id;
	}

	/**
	 * FTS5's MATCH syntax treats *, ", (, ) etc. as query operators; a raw
	 * user search string containing an unbalanced one throws
	 * "fts5: syntax error". Quoting each token as an FTS5 string literal
	 * (doubling embedded quotes) makes the whole query literal-substring
	 * matching, tolerating arbitrary user input the way websearch_to_tsquery
	 * did for the retired Postgres search.
	 */
	private function sanitizeFtsQuery(string $query): string {
		$tokens = preg_split('/\s+/u', trim($query));
		$quoted = array();
		foreach ($tokens as $t) {
			if ($t === '') { continue; }
			$quoted[] = '"' . str_replace('"', '""', $t) . '"';
		}
		return count($quoted) ? implode(' ', $quoted) : '""';
	}
}
?>

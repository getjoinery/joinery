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
 *   - fold()        folds every message newer than the high-water mark into
 *                    the open working copy, then immediately re-seals and
 *                    persists it (seal-after-fold) — never waits for window
 *                    close, so a crash never loses folded work.
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
 * an error. Index source: sender + subject + both bodies + attachment
 * filenames. Attachment CONTENTS are never indexed.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_mailbox_search_index_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));

class MailboxIndexException extends Exception {}

class MailboxIndex {

	const SHM_DIR = '/dev/shm';

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
	public function ensureOpen(int $user_id, string $secret_key): void {
		$path = $this->shmPath($user_id);
		if (file_exists($path) && $this->tryOpenDb($path) !== null) {
			return;
		}
		if ($this->restoreFromBlob($user_id, $secret_key)) {
			return;
		}
		$this->rebuild($user_id, $secret_key);
	}

	/**
	 * Fold every message newer than the high-water mark into the open working
	 * copy, then seal-after-fold: persist immediately while the key is in
	 * hand, never waiting for window close.
	 */
	public function fold(int $user_id, string $secret_key): void {
		$this->ensureOpen($user_id, $secret_key);
		$bookkeeping = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
		$this->foldSince($user_id, intval($bookkeeping->get('imi_fts_high_water')));
		$this->persist($user_id, $secret_key);
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

	/** Full rebuild from the sealed message rows: fresh FTS5 table, high-water reset to 0. */
	public function rebuild(int $user_id, string $secret_key): void {
		$path = $this->shmPath($user_id);
		@unlink($path);
		if (!is_dir(self::SHM_DIR)) {
			throw new MailboxIndexException('MailboxIndex: ' . self::SHM_DIR . ' is not available.');
		}
		$db = new SQLite3($path);
		$db->exec('CREATE VIRTUAL TABLE mailfts USING fts5(message_id UNINDEXED, content)');
		$db->close();

		$bookkeeping = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
		$bookkeeping->set('imi_fts_high_water', 0);
		$bookkeeping->save();

		$this->foldSince($user_id, 0);
		$this->persist($user_id, $secret_key);
	}

	/** Delete only the /dev/shm working copy — window-close, the sweep task. */
	public function wipe(int $user_id): void {
		@unlink($this->shmPath($user_id));
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
		$bookkeeping->set('imi_fts_high_water', 0);
		$bookkeeping->save();
	}

	// ------------------------------------------------------------- internals

	private function tryOpenDb(string $path): ?SQLite3 {
		try {
			$db = new SQLite3($path, SQLITE3_OPEN_READWRITE);
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

	/** Restore the /dev/shm working copy from the persisted sealed blob. False if there is none / it fails to open. */
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
		$ciphertext = $file->read_bytes('original');
		if ($ciphertext === null) {
			return false;
		}
		try {
			$crypto = new VaultCrypto();
			$dek = $crypto->openItemDek((string)$sealed_key, $secret_key);
			$bytes = $crypto->openField($ciphertext, $dek, $this->blobAd($user_id));
		} catch (Throwable $e) {
			error_log('MailboxIndex: restoreFromBlob failed for user ' . $user_id . ' (rebuilding): ' . $e->getMessage());
			return false;
		}
		if (file_put_contents($this->shmPath($user_id), $bytes) === false) {
			return false;
		}
		return $this->tryOpenDb($this->shmPath($user_id)) !== null;
	}

	/** Seal-after-fold: read the /dev/shm bytes, seal fresh, persist as a private File. */
	private function persist(int $user_id, string $secret_key): void {
		$path = $this->shmPath($user_id);
		if (!file_exists($path)) {
			return;
		}
		$bytes = file_get_contents($path);
		if ($bytes === false) {
			return;
		}
		$vault = UserEncryptionVault::loadForUser($user_id);
		if (!$vault) {
			return;
		}

		$crypto = new VaultCrypto();
		$dek = $crypto->newItemDek();
		$sealed_key = $crypto->sealItemDek($dek, (string)$vault->get('uev_public_key'));
		$blob = $crypto->sealField($bytes, $dek, $this->blobAd($user_id));

		$bookkeeping = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
		$old_fil_id = intval($bookkeeping->get('imi_fil_file_id'));

		$file = File::createFromBytes($blob, 'mailfts_' . $user_id . '.bin', 'application/octet-stream', $user_id, array(
			'fil_private' => true,
			'fil_source'  => File::SOURCE_MAILBOX_SEARCH_INDEX,
		));

		$bookkeeping->set('imi_fil_file_id', intval($file->key));
		$bookkeeping->set('imi_sealed_key', $sealed_key);
		$bookkeeping->save();

		if ($old_fil_id > 0 && $old_fil_id !== intval($file->key)) {
			$old = new File($old_fil_id, TRUE);
			if ($old->key) {
				try { $old->permanent_delete(); } catch (Throwable $e) { /* best-effort */ }
			}
		}
	}

	/**
	 * Fold every message newer than $since_id belonging to $user_id's
	 * single-owner mailboxes into the (already-open) /dev/shm working copy,
	 * advancing the high-water mark as it goes. Reads content the same way a
	 * viewer would (InboundEmailMessage::get(), routed through the sealed-field
	 * hook), so it works identically for sealed and never-sealed rows.
	 */
	private function foldSince(int $user_id, int $since_id): void {
		$alias_ids = InboundEmailMailboxGrant::alias_ids_for_user($user_id);
		if (!count($alias_ids)) {
			return;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$in = implode(',', array_map('intval', $alias_ids));

		// Bookkeeping (loaded once): the high-water mark plus the refold queue —
		// message ids at-or-below the mark that changed after folding. A draft
		// morphs IN PLACE into its Sent row keeping its id (Fix 6), so it is never
		// revisited by the `id > since` main pass; the queue drives an explicit
		// delete-and-reinsert for exactly those ids.
		$bookkeeping = InboundMailboxSearchIndex::loadOrCreateForUser($user_id);
		$refold = json_decode((string)$bookkeeping->get('imi_refold_ids'), true);
		$refold = is_array($refold) ? array_values(array_unique(array_map('intval', $refold))) : array();

		// New rows since the mark (drafts stay OUT of the index — few, in flux, and
		// opened directly, never searched).
		$stmt = $db->prepare(
			"SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			 WHERE iem_iea_inbound_email_alias_id IN ($in)
			 AND iem_inbound_email_message_id > ? AND iem_delete_time IS NULL
			 AND iem_direction IS DISTINCT FROM 'draft'
			 ORDER BY iem_inbound_email_message_id ASC");
		$stmt->execute(array($since_id));
		$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

		if (!count($ids) && !count($refold)) {
			return; // nothing to fold and nothing queued — the early-out
		}

		// Which refold ids are still indexable (exist, non-deleted, non-draft, and in
		// the user's scope) — the rest are only deleted from the FTS table.
		$refold_valid = array();
		if (count($refold)) {
			$rin = implode(',', array_map('intval', $refold));
			$rstmt = $db->prepare(
				"SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
				 WHERE iem_inbound_email_message_id IN ($rin)
				 AND iem_iea_inbound_email_alias_id IN ($in)
				 AND iem_delete_time IS NULL AND iem_direction IS DISTINCT FROM 'draft'");
			$rstmt->execute();
			$refold_valid = array_flip(array_map('intval', $rstmt->fetchAll(PDO::FETCH_COLUMN)));
		}
		$refold_set = array_flip($refold);

		$shm = new SQLite3($this->shmPath($user_id));
		$insert = $shm->prepare('INSERT INTO mailfts (message_id, content) VALUES (:id, :content)');
		$delete = $shm->prepare('DELETE FROM mailfts WHERE message_id = :id');

		// Refold pass: drop any stale FTS row for the queued id, then re-insert it
		// when still indexable (the morphed Sent row's body now enters the index).
		foreach ($refold as $id) {
			$delete->bindValue(':id', $id, SQLITE3_INTEGER);
			$delete->execute();
			$delete->reset();
			if (!isset($refold_valid[$id])) {
				continue;
			}
			$content = $this->rowContent($id);
			if ($content === null) {
				continue;
			}
			$insert->bindValue(':id', $id, SQLITE3_INTEGER);
			$insert->bindValue(':content', $content, SQLITE3_TEXT);
			$insert->execute();
			$insert->reset();
		}

		// Main pass: the new rows since the mark, skipping any already handled above.
		$last_id = $since_id;
		foreach ($ids as $id) {
			$last_id = $id;
			if (isset($refold_set[$id])) {
				continue; // already delete-and-reinserted in the refold pass
			}
			$content = $this->rowContent($id);
			if ($content === null) {
				continue;
			}
			$insert->bindValue(':id', $id, SQLITE3_INTEGER);
			$insert->bindValue(':content', $content, SQLITE3_TEXT);
			$insert->execute();
			$insert->reset();
		}
		$shm->close();

		// One bookkeeping save: advance the watermark and clear the refold queue.
		$bookkeeping->set('imi_fts_high_water', $last_id);
		$bookkeeping->set('imi_refold_ids', null);
		$bookkeeping->save();
	}

	/**
	 * The indexable text for one message id — sender + subject + both bodies +
	 * attachment filenames — read the same way a viewer would (through the
	 * sealed-field hook). Returns null when the row is gone or a decrypt fails
	 * (fold only runs in-window, but never let one row abort the whole fold).
	 */
	private function rowContent(int $id): ?string {
		$msg = new InboundEmailMessage($id, TRUE);
		if (!$msg->key) {
			return null;
		}
		try {
			return (string)$msg->get('iem_sender') . ' ' . (string)$msg->get('iem_subject') . ' '
				. (string)$msg->get('iem_body_plain') . ' ' . strip_tags((string)$msg->get('iem_body_html'))
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

<?php
/**
 * InboundMailboxSearchIndex - one row per mailbox owner: bookkeeping for the
 * sealed FTS5 search index (specs/implemented/inbound_email_encryption_at_rest.md § 6,
 * MailboxIndex).
 *
 * The index itself is a disposable /dev/shm SQLite FTS5 file, seal-after-fold
 * persisted to one path per user (MailboxIndex::blobPath —
 * {site root}/cache/mailfts/{uid}.bin) so it survives across requests without
 * ever touching disk in cleartext. imi_sealed_key is the DEK that file is
 * sealed under, regenerated on every fold (it is fully disposable — losing it
 * only costs a rebuild from the sealed message rows, never data).
 * imi_fts_high_water is the last message id already folded into the working
 * copy; imi_blob_high_water is the mark the persisted blob covers, which can
 * lag it (folding checkpoints the mark per batch, persisting per chunk) — a
 * restore resets the live mark to the blob's so the two stay consistent.
 *
 * Never excluded from backup — losing this row only costs a search-index
 * rebuild, not content (the ground truth is always the sealed message rows).
 *
 * imi_fil_file_id is vestigial: the persisted index was once a File per
 * persist. Every persist writes null here and deletes the File it names, so a
 * row carrying one is an owner who has not folded since the upgrade. The
 * column goes once every node reports zero
 * (specs/mailbox_search_index_blob_leak.md WP7).
 *
 * @version 1.4 - the persisted index is a path, not a File: permanent_delete()
 *   takes the file with the row, a deleted user takes both, and the sweep
 *   collects anything left behind (specs/mailbox_search_index_blob_leak.md)
 * @version 1.3 - imi_format: the shape of the persisted blob, checked before a restore
 * @version 1.2 - imi_blob_high_water: what the persisted blob covers
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundMailboxSearchIndexException extends SystemBaseException {}

class InboundMailboxSearchIndex extends SystemBase {
	public static $prefix = 'imi';
	public static $tablename = 'imi_inbound_mailbox_search_index';
	public static $pkey_column = 'imi_inbound_mailbox_search_index_id';

	public static $api_readable = false;
	public static $api_writable = false;

	protected static $foreign_key_actions = [
		// permanent_delete, not cascade: a flat DELETE would take the row and
		// leave the owner's sealed index sitting in cache/ with nobody left to
		// name it. Going through the model runs permanent_delete() below.
		'imi_usr_user_id' => ['action' => 'permanent_delete'],
		// Vestigial (see above). A File named here is already superseded, so
		// deleting it clears the stale pointer — it never takes the owner's
		// sealed key and marks with it, and it is never blocked by them.
		'imi_fil_file_id' => ['action' => 'null'],
	];

	// Retention: files, not rows — the /dev/shm working copies of this index and
	// any persisted index left without an owner. window_setting is null because
	// the rule is unconditional: a working copy whose vault window has closed is
	// never wanted, and neither is an index nobody can ask for, so there is no
	// age for an operator to choose. See sweepWorkingCopies() for what runs.
	public static $retention_policy = array(
		'label'          => 'Mailbox index working copies and stray indexes',
		'purge_method'   => 'sweepWorkingCopies',
		'window_setting' => null,
	);

	public static $field_specifications = array(
		'imi_inbound_mailbox_search_index_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'imi_usr_user_id'      => array('type'=>'int8', 'is_nullable'=>false, 'unique'=>true,
			'foreign_key'=>array('table'=>'usr_users', 'column'=>'usr_user_id', 'on_delete'=>'CASCADE')),
		'imi_fts_high_water'   => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		// Row ids at-or-below the high-water mark that changed after folding (a draft
		// morphed into its Sent row keeps its id) — folded on the next cycle, then cleared.
		'imi_refold_ids'       => array('type'=>'text', 'is_nullable'=>true),   // JSON int array
		'imi_fil_file_id'      => array('type'=>'int8', 'is_nullable'=>true),
		'imi_sealed_key'       => array('type'=>'text', 'is_nullable'=>true),
		// The mark the persisted blob covers. Null before any recorded persist —
		// a legacy blob was only ever written after a complete fold, when it and
		// imi_fts_high_water agreed by construction.
		'imi_blob_high_water'  => array('type'=>'int8', 'is_nullable'=>true),
		// MailboxIndex::FORMAT of the persisted blob. A blob of another format
		// (or a legacy one with no stamp) is not worth decrypting — restore
		// refuses it before reading a byte and the next unlock rebuilds.
		'imi_format'           => array('type'=>'int4', 'is_nullable'=>true),
		'imi_create_time'     => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'imi_update_time'     => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	/** The one bookkeeping row for a mailbox owner, creating it if absent. */
	public static function loadOrCreateForUser(int $user_id): InboundMailboxSearchIndex {
		$multi = new MultiInboundMailboxSearchIndex(['user_id' => $user_id]);
		$multi->load();
		if ($multi->count() > 0) {
			return $multi->get(0);
		}
		$row = new InboundMailboxSearchIndex(NULL);
		$row->set('imi_usr_user_id', $user_id);
		$row->save();
		$row->load();
		return $row;
	}

	/**
	 * Delete the owner's persisted sealed index along with their bookkeeping
	 * row — the row is the only thing that names the file, so a row deleted
	 * without it leaves a stray nothing will ever ask for again.
	 *
	 * The unlink happens before the row goes, inside the caller's transaction.
	 * A rollback therefore leaves a live row and no file, which is the
	 * disposable-cache contract's ordinary case: the next fold rebuilds.
	 */
	public function permanent_delete($debug = false) {
		$user_id = (int)$this->get('imi_usr_user_id');
		if ($user_id > 0 && !$debug) {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));
			MailboxIndex::removePersisted($user_id);
		}
		return parent::permanent_delete($debug);
	}

	/**
	 * Persisted indexes nobody owns, and temp files a dead persist left behind.
	 *
	 * A persist writes {uid}.bin.tmp and renames it over {uid}.bin, so the only
	 * things this can find are an index whose bookkeeping row has gone and a
	 * temp file whose persist never finished. An hour is well past any real
	 * seal, and a persist in flight holds the owner's fold lock, so nothing
	 * live is ever the age this looks for.
	 *
	 * @param bool $dry_run  count and name, remove nothing (the health check)
	 * @return array  removed, bytes (of every persisted index present), paths
	 */
	public static function sweepPersistedIndexes($dry_run = false) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));

		$result = array('removed' => 0, 'bytes' => 0, 'paths' => array());
		$dir = MailboxIndex::blobDir();
		if (!is_dir($dir)) {
			return $result;
		}

		$owners = array();
		$db = DbConnector::get_instance()->get_db_link();
		foreach ($db->query('SELECT imi_usr_user_id FROM imi_inbound_mailbox_search_index')->fetchAll(PDO::FETCH_COLUMN) as $uid) {
			$owners[(int)$uid] = true;
		}

		$stale_before = time() - 3600;
		foreach ((array)glob($dir . '/*.bin*') as $path) {
			if (!is_file($path)) {
				continue;
			}
			$name = basename($path);
			// {uid}.bin is an index; {uid}.bin.tmp and the seal's own temp name
			// beneath it are a persist that did not finish.
			if (!preg_match('/^(\d+)\.bin(\.tmp.*)?$/', $name, $m)) {
				continue; // not one of ours — leave it alone
			}
			$is_temp = isset($m[2]) && $m[2] !== '';
			if (!$is_temp) {
				$result['bytes'] += (int)@filesize($path);
				if (isset($owners[(int)$m[1]])) {
					continue; // a live owner's index
				}
			} elseif (@filemtime($path) > $stale_before) {
				continue; // a persist may still be writing it
			}
			$result['paths'][] = $path;
			if ($dry_run || @unlink($path)) {
				$result['removed']++;
			}
		}
		return $result;
	}

	/**
	 * Passive-close safety net for the /dev/shm working copies of this index
	 * (specs/implemented/inbound_email_encryption_at_rest.md § 6.4).
	 *
	 * The wipe callback (plugins/mailbox/includes/bootstrap.php) already deletes
	 * a user's working copy on an explicit lock or credential event. This
	 * catches everything else that ends a window without firing that callback —
	 * an APCu TTL idle expiry, a php-fpm worker recycle. Worst case a working
	 * copy lingers until the next sweep.
	 *
	 * Unconditional: a copy whose vault window has closed is plaintext nobody
	 * asked for, so there is no window to wait out. $window is ignored.
	 *
	 * Sweeps sweepPersistedIndexes() in the same pass — one task, both places
	 * a file belonging to this index can outlive what named it.
	 *
	 * @return array  removed, message
	 */
	public static function sweepWorkingCopies($window = 0) {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

		$persisted = self::sweepPersistedIndexes();

		$files = glob('/dev/shm/mailfts_*.sqlite');
		if ($files === false || !count($files)) {
			return array(
				'removed' => $persisted['removed'],
				'message' => self::sweepMessage(0, $persisted['removed']),
			);
		}

		$swept = 0;
		foreach ($files as $path) {
			if (!preg_match('/mailfts_(\d+)\.sqlite$/', basename($path), $m)) {
				continue; // not one of ours — leave it alone
			}
			if (VaultUnlock::hasAnyOpenWindow((int)$m[1], UserEncryptionVault::SCOPE_USER)) {
				continue; // still in-window somewhere — not this sweep's to touch
			}
			if (@unlink($path)) {
				$swept++;
			}
		}

		return array(
			'removed' => $swept + $persisted['removed'],
			'message' => self::sweepMessage($swept, $persisted['removed']),
		);
	}

	/** What one sweep did, in the two places it can do anything. */
	private static function sweepMessage($working_copies, $persisted) {
		$parts = array();
		if ($working_copies > 0) {
			$parts[] = $working_copies . ' orphaned working cop' . ($working_copies === 1 ? 'y' : 'ies');
		}
		if ($persisted > 0) {
			$parts[] = $persisted . ' stray persisted ' . ($persisted === 1 ? 'index' : 'indexes');
		}
		return count($parts) ? implode(', ', $parts) : 'nothing to sweep';
	}
}

class MultiInboundMailboxSearchIndex extends SystemMultiBase {
	protected static $model_class = 'InboundMailboxSearchIndex';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['user_id'])) {
			$filters['imi_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}
		return $this->_get_resultsv2('imi_inbound_mailbox_search_index', $filters, $this->order_by, $only_count, $debug);
	}
}
?>

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
 * @version 1.5 - sweepLegacyBlobs(): the File era's index bytes that no File
 *   row holds any more are counted and reclaimed by the same sweep
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

	/** A File-era index's stored name: the persist uploaded mailfts_{uid}.bin
	 *  and the upload minted mailfts_{uid}_{token}.bin (a second token when the
	 *  first collided). Nothing writes that shape any more. */
	const LEGACY_BLOB_NAME = '/^mailfts_\\d+(_[A-Za-z0-9]+)+\\.bin$/';

	/**
	 * The File era's index bytes that nothing holds any more.
	 *
	 * Before the index moved to one path per owner, every persist uploaded a
	 * private File, so its bytes landed in the private upload directory
	 * (the upload_dir setting) under a FileBlob named mailfts_{uid}_{token}.bin.
	 * Deleting those File rows reclaims their bytes; this finds what that
	 * cannot reach, in the two shapes it can take:
	 *
	 *  - a blob row whose name is an index's and that no File and no file
	 *    version references (its reference count leaked). It is released
	 *    through FileBlob::release() until it reaches zero, which deletes the
	 *    bytes wherever they are, local or bucket, and the row;
	 *  - a file in the upload directory whose name is an index's and that no
	 *    blob row and no File names at all. Nothing can reclaim it through a
	 *    model, so it is unlinked.
	 *
	 * Anything younger than an hour is left alone: an upload stages its bytes
	 * before it writes the rows that hold them. A name a live row holds is
	 * never touched, whatever its age.
	 *
	 * @param bool $dry_run  count and name, remove nothing (the health check)
	 * @return array  removed, bytes (of what was or would be removed), names
	 */
	public static function sweepLegacyBlobs($dry_run = false) {
		require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));

		$result = array('removed' => 0, 'bytes' => 0, 'names' => array());
		$db = DbConnector::get_instance()->get_db_link();
		$has_table = function ($table) use ($db) {
			$q = $db->prepare('SELECT to_regclass(?)');
			$q->execute(array('public.' . $table));
			return $q->fetchColumn() !== null;
		};
		if (!$has_table('fbb_file_blobs') || !$has_table('fil_files')) {
			return $result;
		}
		$versions = $has_table('fvr_file_versions')
			? ' AND NOT EXISTS (SELECT 1 FROM fvr_file_versions v WHERE v.fvr_fbb_file_blob_id = b.fbb_file_blob_id)'
			: '';
		$referenced = $db->prepare('SELECT 1 FROM fbb_file_blobs b WHERE b.fbb_file_blob_id = ?
			AND (EXISTS (SELECT 1 FROM fil_files f WHERE f.fil_fbb_file_blob_id = b.fbb_file_blob_id)'
			. ($versions !== '' ? ' OR EXISTS (SELECT 1 FROM fvr_file_versions v WHERE v.fvr_fbb_file_blob_id = b.fbb_file_blob_id)' : '')
			. ')');

		$seen = array();

		// Blob rows nothing references. Soft-deleted File rows count as
		// references: a File in the trash still owns its bytes.
		$q = $db->query("SELECT b.fbb_file_blob_id, b.fbb_stored_name, b.fbb_size_bytes
			  FROM fbb_file_blobs b
			 WHERE b.fbb_stored_name LIKE 'mailfts\\_%'
			   AND b.fbb_create_time < now() - interval '1 hour'
			   AND NOT EXISTS (SELECT 1 FROM fil_files f WHERE f.fil_fbb_file_blob_id = b.fbb_file_blob_id)"
			. $versions . '
			 ORDER BY b.fbb_file_blob_id');
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$name = (string)$row['fbb_stored_name'];
			if (!preg_match(self::LEGACY_BLOB_NAME, $name)) {
				continue;
			}
			$seen[$name] = true;
			if ($dry_run) {
				$result['removed']++;
				$result['names'][] = $name;
				$result['bytes'] += (int)$row['fbb_size_bytes'];
				continue;
			}
			$blob_id = (int)$row['fbb_file_blob_id'];
			try {
				// One release per leaked reference; the last one reclaims. The
				// referrer check is repeated before every release, so a row that
				// takes the blob meanwhile stops the loop with its reference
				// intact. A bound, so a count that keeps climbing cannot spin.
				for ($i = 0; $i < 1000; $i++) {
					$blob = new FileBlob($blob_id, TRUE);
					if (!$blob->key || (int)$blob->get('fbb_reference_count') <= 0) {
						break;
					}
					$referenced->execute(array($blob_id));
					if ($referenced->fetchColumn()) {
						break;
					}
					FileBlob::release($blob_id);
				}
				$left = new FileBlob($blob_id, TRUE);
				if ($left->key) {
					error_log('InboundMailboxSearchIndex: legacy index blob ' . $blob_id . ' (' . $name
						. ') was not reclaimed: a row took it, or its references did not reach zero.');
					continue;
				}
				$result['removed']++;
				$result['names'][] = $name;
				$result['bytes'] += (int)$row['fbb_size_bytes'];
			} catch (Throwable $e) {
				error_log('InboundMailboxSearchIndex: could not reclaim legacy index blob ' . $blob_id
					. ' (' . $name . '): ' . $e->getMessage());
			}
		}

		// Files on disk that no row names at all.
		$dir = rtrim((string)Globalvars::get_instance()->get_setting('upload_dir'), '/');
		if ($dir === '' || !is_dir($dir)) {
			return $result;
		}
		$by_blob = $db->prepare('SELECT 1 FROM fbb_file_blobs WHERE fbb_stored_name = ? LIMIT 1');
		$by_file = $db->prepare('SELECT 1 FROM fil_files WHERE fil_name = ? LIMIT 1');
		$stale_before = time() - 3600;
		foreach ((array)glob($dir . '/mailfts_*.bin') as $path) {
			$name = basename($path);
			if (!is_file($path) || !preg_match(self::LEGACY_BLOB_NAME, $name)
					|| @filemtime($path) > $stale_before
					|| isset($seen[$name])) {
				continue;
			}
			$by_blob->execute(array($name));
			$named = (bool)$by_blob->fetchColumn();
			$by_file->execute(array($name));
			if ($named || $by_file->fetchColumn()) {
				continue; // a row holds it — the model owns its bytes
			}
			$size = (int)@filesize($path);
			if ($dry_run || @unlink($path)) {
				$result['removed']++;
				$result['names'][] = $name;
				$result['bytes'] += $size;
			} else {
				error_log('InboundMailboxSearchIndex: could not remove the legacy index file ' . $path . '.');
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
	 * Sweeps sweepPersistedIndexes() and sweepLegacyBlobs() in the same pass —
	 * one task, every place a file belonging to this index can outlive what
	 * named it.
	 *
	 * @return array  removed, message
	 */
	public static function sweepWorkingCopies($window = 0) {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

		$persisted = self::sweepPersistedIndexes();
		$legacy = self::sweepLegacyBlobs();

		$files = glob('/dev/shm/mailfts_*.sqlite');
		if ($files === false || !count($files)) {
			return array(
				'removed' => $persisted['removed'] + $legacy['removed'],
				'message' => self::sweepMessage(0, $persisted['removed'], $legacy),
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
			'removed' => $swept + $persisted['removed'] + $legacy['removed'],
			'message' => self::sweepMessage($swept, $persisted['removed'], $legacy),
		);
	}

	/** What one sweep did, in the three places it can do anything. */
	private static function sweepMessage($working_copies, $persisted, array $legacy) {
		$parts = array();
		if ($working_copies > 0) {
			$parts[] = $working_copies . ' orphaned working cop' . ($working_copies === 1 ? 'y' : 'ies');
		}
		if ($persisted > 0) {
			$parts[] = $persisted . ' stray persisted ' . ($persisted === 1 ? 'index' : 'indexes');
		}
		if ($legacy['removed'] > 0) {
			$parts[] = $legacy['removed'] . ' unheld file-era index ' . ($legacy['removed'] === 1 ? 'copy' : 'copies')
				. ' (' . round($legacy['bytes'] / 1048576, 1) . ' MiB: ' . implode(', ', $legacy['names']) . ')';
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

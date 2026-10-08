<?php
/**
 * RawMessageStore — inbound mail as a private consumer of the unified offload
 * layer.
 *
 * One class, two hats:
 *
 *   1. The offload layer's StorageProfile (visibility = 'private'), so the
 *      shared CloudOffloadEngine can offload/reverse the raw RFC822 .eml of a
 *      stored push message between the local on-disk store and the platform's
 *      verified-private bucket. The engine owns the PUT→reload→flip→delete
 *      ordering, the failure cap, batching, and the per-row lock; this profile
 *      only declares the iem_ descriptor columns and enumerates the single
 *      .eml object per row.
 *
 *   2. The consumer's request-time byte I/O (write/read/delete), which the
 *      offload layer leaves to each consumer. write() always targets LOCAL —
 *      ingest never blocks on bucket I/O; the engine offloads later, the same
 *      posture as the public-files path.
 *
 * One relative key, two tiers. iem_raw_storage_key is the relative name:
 *
 *     mailbox/{yyyy}/{mm}/{message_id}.eml
 *
 *   - local: under {site_root}/storage/  (via PathHelper::getSiteRoot())
 *   - cloud: under the file store's folder; the row records the store
 *     (iem_raw_bkt_backup_target_id) and the full key it went to
 *     (iem_raw_remote_key), and every read and delete follows them
 *
 * The local store is outside the web root; the cloud tier is a verified-private
 * bucket reached only through the driver's server-side get() — never a public
 * URL.
 *
 * Offloaded mail is in backups the way offloaded files are: backupObjects()
 * names each cloud row as the object 'mailbox/{id}.eml' (BackupObjects), and
 * backupRow() is what a restore puts it back by.
 *
 * @version 1.6 - the row records its store and full key; read()/delete() take the row's descriptor
 *                (descriptorOf()); backupObjects()/backupObject()/backupRow(): offloaded mail is in
 *                backups (specs/storage_targets.md WP6)
 * @version 1.5 - lastErrorColumn()
 * @version 1.4 - one store: the driver is resolved with no visibility argument
 * @version 1.3
 */


class RawMessageStoreException extends Exception {}

class RawMessageStore implements StorageProfile {

	const TABLE         = 'iem_inbound_email_messages';
	const CONTENT_TYPE  = 'message/rfc822';

	// =====================================================================
	// StorageProfile — identity (the iem_ descriptor columns)
	// =====================================================================
	public function table(): string            { return self::TABLE; }
	public function pkeyColumn(): string        { return 'iem_inbound_email_message_id'; }
	public function driverColumn(): string      { return 'iem_raw_storage_driver'; }
	public function failedCountColumn(): string { return 'iem_raw_sync_failed_count'; }
	public function lastAttemptColumn(): string { return 'iem_raw_sync_last_attempt'; }
	public function lastErrorColumn(): string   { return 'iem_raw_sync_last_error'; }
	public function targetColumn(): string      { return 'iem_raw_bkt_backup_target_id'; }
	public function remoteKeyColumn(): string   { return 'iem_raw_remote_key'; }

	public function visibility(): string { return 'private'; }

	/**
	 * No extra gate: any 'local' row is offload-eligible. The engine's batch
	 * SELECT already filters to (driver IS NULL OR driver = 'local'), and mail
	 * rows default to 'inline' — so inline / remote / cloud rows are excluded
	 * without an explicit clause here.
	 */
	public function eligibilityWhere(): string { return ''; }

	// =====================================================================
	// StorageProfile — per-row enumeration
	// =====================================================================
	public function rowExists(int $id): bool {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			'SELECT 1 FROM ' . self::TABLE . ' WHERE iem_inbound_email_message_id = ? LIMIT 1');
		$q->execute([$id]);
		return (bool)$q->fetchColumn();
	}

	public function isEligibleRow(int $id): bool {
		return $this->_driverFlag($id) === 'local';
	}

	/**
	 * FORWARD: the single on-disk .eml to push. Null when the file is missing
	 * (the engine records a failure and retries up to the cap).
	 */
	public function itemsForRow(int $id): ?array {
		$key = $this->_storageKey($id);
		if ($key === '') {
			return null;
		}
		$local_path = self::localPathForKey($key);
		if (!is_file($local_path)) {
			return null; // required bytes missing on disk → engine records a failure
		}
		return [[
			'local_path'   => $local_path,
			'name'         => $key,
			'content_type' => self::CONTENT_TYPE,
		]];
	}

	/**
	 * REVERSE: the same single .eml at the key the row recorded, WITHOUT
	 * needing local bytes (on pull-back none exist yet). local_path is the
	 * final on-disk destination the engine writes to before flipping to 'local'.
	 */
	public function reverseItemsForRow(int $id): array {
		$row = $this->_row($id);
		if (!$row || (string)$row['iem_raw_storage_key'] === '' || (string)$row['iem_raw_remote_key'] === '') {
			return [];
		}
		return [[
			'remote_key'   => (string)$row['iem_raw_remote_key'],
			'name'         => (string)$row['iem_raw_storage_key'],
			'local_path'   => self::localPathForKey((string)$row['iem_raw_storage_key']),
			'content_type' => self::CONTENT_TYPE,
		]];
	}

	// =====================================================================
	// Backup — offloaded mail is stored in backups like offloaded files
	// =====================================================================

	/** The backup object name for a message: namespaced so it can never be a file's stored name. */
	public static function backupName(int $message_id): string {
		return 'mailbox/' . $message_id . '.eml';
	}

	/**
	 * Every offloaded message, as the backup's object store sees it: its
	 * name, the local path its raw occupies while it waits for backup
	 * storage, and where to fetch it from the file store when no local copy
	 * is left (BlobStorageProfile::backupObjects() is the same shape).
	 */
	public function backupObjects(): array {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->query(
			"SELECT iem_inbound_email_message_id, iem_raw_storage_key, iem_raw_bkt_backup_target_id, iem_raw_remote_key
			   FROM " . self::TABLE . " WHERE iem_raw_storage_driver = 'cloud'
			  ORDER BY iem_inbound_email_message_id ASC");
		$out = [];
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$out[] = $this->describe_for_backup($row);
		}
		return $out;
	}

	/** One message in the shape backupObjects() lists, or null when it is gone or not offloaded. */
	public function backupObject(int $id): ?array {
		$row = $this->_row($id);
		if (!$row || $row['iem_raw_storage_driver'] !== 'cloud') {
			return null;
		}
		return $this->describe_for_backup($row);
	}

	private function describe_for_backup(array $row): array {
		$local = self::localPathForKey((string)$row['iem_raw_storage_key']);
		return [
			'table'        => $this->table(),
			'id'           => (int)$row['iem_inbound_email_message_id'],
			'name'         => self::backupName((int)$row['iem_inbound_email_message_id']),
			'original'     => $local,
			'paths'        => [$local],
			'target_id'    => (int)$row['iem_raw_bkt_backup_target_id'],
			'remote_key'   => (string)$row['iem_raw_remote_key'],
			'content_type' => self::CONTENT_TYPE,
			'visibility'   => $this->visibility(),
		];
	}

	/**
	 * The row a backup object name belongs to, for a restore: where its raw
	 * goes on disk and what the store holds of it. Null when the name is not
	 * mail or the restored database has no such message. Mail records no size
	 * or hash of its raw; the index's hash of the ciphertext is the check.
	 */
	public function backupRow(string $name): ?array {
		if (!preg_match('#^mailbox/(\d+)\.eml$#', $name, $m)) {
			return null;
		}
		$row = $this->_row((int)$m[1]);
		if (!$row || (string)$row['iem_raw_storage_key'] === '') {
			return null;
		}
		return [
			'id'         => (int)$row['iem_inbound_email_message_id'],
			'label'      => 'message ' . (int)$row['iem_inbound_email_message_id'],
			'driver'     => (string)$row['iem_raw_storage_driver'],
			'placement'  => self::localPathForKey((string)$row['iem_raw_storage_key']),
			'size'       => 0,
			'sha256'     => '',
			'target_id'  => (int)$row['iem_raw_bkt_backup_target_id'],
			'remote_key' => (string)$row['iem_raw_remote_key'],
		];
	}

	// =====================================================================
	// Request-time byte I/O (per-consumer; not the engine's concern)
	// =====================================================================

	/**
	 * Write the raw to the LOCAL store and return the descriptor the caller
	 * persists: ['driver' => 'local', 'key' => <relative key>]. Throws
	 * RawMessageStoreException on any filesystem failure so the ingest path can
	 * fall back to an inline write.
	 */
	public static function write(int $message_id, string $raw): array {
		$key = self::keyFor($message_id);
		$local_path = self::localPathForKey($key);

		$dir = dirname($local_path);
		if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
			throw new RawMessageStoreException('Could not create the local raw-message directory: ' . $dir);
		}
		if (@file_put_contents($local_path, $raw) === false) {
			throw new RawMessageStoreException('Could not write the raw message to: ' . $local_path);
		}
		@chmod($local_path, 0666);

		return ['driver' => 'local', 'key' => $key];
	}

	/**
	 * A message's raw-storage descriptor: driver, relative key, and for an
	 * offloaded raw the store and full key the row recorded. What read() and
	 * delete() take.
	 */
	public static function descriptorOf($msg): array {
		return [
			'driver'     => (string)$msg->get('iem_raw_storage_driver'),
			'key'        => (string)$msg->get('iem_raw_storage_key'),
			'target_id'  => (int)$msg->get('iem_raw_bkt_backup_target_id'),
			'remote_key' => (string)$msg->get('iem_raw_remote_key'),
		];
	}

	/**
	 * Read the raw bytes for a stored-raw descriptor. 'local' reads the file;
	 * 'cloud' pulls the private object from the store the row names to a
	 * unique temp, returns its bytes, and unlinks the temp. Throws
	 * RawMessageStoreException on any failure (callers — the message accessor
	 * — catch and degrade to "temporarily unavailable"). inline / remote are
	 * resolved by the accessor, not here.
	 */
	public static function read(array $raw): string {
		$driver = (string)($raw['driver'] ?? '');
		$key = (string)($raw['key'] ?? '');
		if ($driver === 'local') {
			$local_path = self::localPathForKey($key);
			if (!is_file($local_path)) {
				throw new RawMessageStoreException('Local raw message is missing: ' . $local_path);
			}
			$bytes = @file_get_contents($local_path);
			if ($bytes === false) {
				throw new RawMessageStoreException('Could not read the local raw message: ' . $local_path);
			}
			return $bytes;
		}

		if ($driver === 'cloud') {
			$cloud = CloudStorageDriverFactory::forTarget((int)($raw['target_id'] ?? 0));
			$remote_key = (string)($raw['remote_key'] ?? '');
			if (!$cloud || $remote_key === '') {
				throw new RawMessageStoreException('The file store this message is in is not reachable for a cloud raw read.');
			}
			$tmp = tempnam(sys_get_temp_dir(), 'iem_raw_');
			if ($tmp === false) {
				throw new RawMessageStoreException('Could not allocate a temp file for a cloud raw read.');
			}
			try {
				$cloud->get($remote_key, $tmp);
				$bytes = @file_get_contents($tmp);
				if ($bytes === false) {
					throw new RawMessageStoreException('Could not read the pulled cloud raw message.');
				}
				return $bytes;
			} finally {
				@unlink($tmp);
			}
		}

		throw new RawMessageStoreException('read() called for non-stored-raw driver: ' . $driver);
	}

	/**
	 * Best-effort delete of the stored object (the message hard-delete hook),
	 * for a descriptor (descriptorOf()). 'local' unlinks the file; 'cloud'
	 * deletes the private object from the store the row names. inline and
	 * remote are no-ops (no platform-owned object to reclaim). Cloud-delete
	 * failures are logged as orphans — the row is removed regardless.
	 */
	public static function delete(array $raw): void {
		$driver = (string)($raw['driver'] ?? '');
		$key = (string)($raw['key'] ?? '');
		if ($driver === 'local') {
			$local_path = self::localPathForKey($key);
			if (is_file($local_path)) {
				@unlink($local_path);
			}
			return;
		}
		if ($driver === 'cloud') {
			$target_id = (int)($raw['target_id'] ?? 0);
			$remote_key = (string)($raw['remote_key'] ?? '');
			$cloud = CloudStorageDriverFactory::forTarget($target_id);
			if (!$cloud || $remote_key === '') {
				error_log('CLOUD_STORAGE_ORPHAN: table=' . self::TABLE . ' target=' . $target_id
					. ' keys=' . ($remote_key !== '' ? $remote_key : $key) . ' (no driver for its file store at delete)');
				return;
			}
			try {
				$cloud->delete($remote_key);
			} catch (Exception $e) {
				error_log('CLOUD_STORAGE_ORPHAN: table=' . self::TABLE . ' target=' . $target_id
					. ' keys=' . $remote_key . ' (' . $e->getMessage() . ')');
			}
			return;
		}
		// inline / remote — nothing platform-owned to delete.
	}

	// =====================================================================
	// Key scheme + tier bases
	// =====================================================================

	/**
	 * The tier-invariant relative key for a message:
	 *     mailbox/{yyyy}/{mm}/{message_id}.eml
	 * sharded by the row's received-month. Used only at write time; every later
	 * tier reads the persisted iem_raw_storage_key.
	 */
	public static function keyFor(int $message_id): string {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			'SELECT COALESCE(to_char(iem_received_time, \'YYYY-MM\'), to_char(now(), \'YYYY-MM\')) AS ym
			   FROM ' . self::TABLE . ' WHERE iem_inbound_email_message_id = ?');
		$q->execute([$message_id]);
		$ym = (string)($q->fetchColumn() ?: gmdate('Y-m'));
		$yyyy = substr($ym, 0, 4);
		$mm   = substr($ym, 5, 2);
		return 'mailbox/' . $yyyy . '/' . $mm . '/' . $message_id . '.eml';
	}

	/** The LOCAL tier base — {site_root}/storage/ (sibling of uploads/, backups/). */
	public static function localBase(): string {
		return rtrim(PathHelper::getSiteRoot(), '/') . '/storage/';
	}

	/** Absolute local filesystem path for a relative key. */
	public static function localPathForKey(string $key): string {
		return self::localBase() . ltrim($key, '/');
	}

	// =====================================================================
	// internals
	// =====================================================================

	/** The row's storage columns, or null. */
	private function _row(int $id): ?array {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			'SELECT iem_inbound_email_message_id, iem_raw_storage_driver, iem_raw_storage_key,
			        iem_raw_bkt_backup_target_id, iem_raw_remote_key
			   FROM ' . self::TABLE . ' WHERE iem_inbound_email_message_id = ?');
		$q->execute([$id]);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	private function _driverFlag(int $id): ?string {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			'SELECT iem_raw_storage_driver FROM ' . self::TABLE . ' WHERE iem_inbound_email_message_id = ?');
		$q->execute([$id]);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		return $row ? ($row['iem_raw_storage_driver'] ?? null) : null;
	}

	private function _storageKey(int $id): string {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			'SELECT iem_raw_storage_key FROM ' . self::TABLE . ' WHERE iem_inbound_email_message_id = ?');
		$q->execute([$id]);
		return (string)($q->fetchColumn() ?: '');
	}
}

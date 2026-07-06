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
 * One relative key, two tier bases. iem_raw_storage_key is tier-invariant:
 *
 *     mailbox/{yyyy}/{mm}/{message_id}.eml
 *
 *   - local: base {site_root}/storage/  (via PathHelper::getSiteRoot())
 *   - cloud: the {site_template}/ prefix the shared S3 driver applies itself
 *
 * so offload is a flag flip + byte copy with NO key rewrite. The local store is
 * outside the web root; the cloud tier is the verified-private bucket reached
 * only through the shared driver's server-side get() — never a public URL.
 *
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/StorageProfile.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));

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
			'remote_key'   => $key,
			'content_type' => self::CONTENT_TYPE,
		]];
	}

	/**
	 * REVERSE: the same single .eml, computed from the key scheme WITHOUT
	 * needing local bytes (on pull-back none exist yet). local_path is the
	 * final on-disk destination the engine writes to before flipping to 'local'.
	 */
	public function reverseItemsForRow(int $id): array {
		$key = $this->_storageKey($id);
		if ($key === '') {
			return [];
		}
		return [[
			'remote_key'   => $key,
			'local_path'   => self::localPathForKey($key),
			'content_type' => self::CONTENT_TYPE,
		]];
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
	 * Read the raw bytes for a stored-raw driver. 'local' reads the file;
	 * 'cloud' pulls the private object to a unique temp, returns its bytes, and
	 * unlinks the temp. Throws RawMessageStoreException on any failure (callers
	 * — the message accessor — catch and degrade to "temporarily unavailable").
	 * inline / remote are resolved by the accessor, not here.
	 */
	public static function read(string $driver, string $key): string {
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
			$cloud = self::privateDriver();
			if (!$cloud) {
				throw new RawMessageStoreException('The private store is not reachable for a cloud raw read.');
			}
			$tmp = tempnam(sys_get_temp_dir(), 'iem_raw_');
			if ($tmp === false) {
				throw new RawMessageStoreException('Could not allocate a temp file for a cloud raw read.');
			}
			try {
				$cloud->get($key, $tmp);
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
	 * Best-effort delete of the stored object (the message hard-delete hook).
	 * 'local' unlinks the file; 'cloud' deletes the private object. inline and
	 * remote are no-ops (no platform-owned object to reclaim). Cloud-delete
	 * failures are logged as orphans — the row is removed regardless.
	 */
	public static function delete(string $driver, string $key): void {
		if ($driver === 'local') {
			$local_path = self::localPathForKey($key);
			if (is_file($local_path)) {
				@unlink($local_path);
			}
			return;
		}
		if ($driver === 'cloud') {
			$cloud = self::privateDriver();
			if (!$cloud) {
				error_log('CLOUD_STORAGE_ORPHAN: visibility=private table=' . self::TABLE
					. ' keys=' . $key . ' (private driver unconfigured at delete)');
				return;
			}
			try {
				$cloud->delete($key);
			} catch (Exception $e) {
				error_log('CLOUD_STORAGE_ORPHAN: visibility=private table=' . self::TABLE
					. ' keys=' . $key . ' (' . $e->getMessage() . ')');
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

	/**
	 * The private-store driver for reads/deletes. Uses the with-fallback
	 * resolver so a still-cloud row stays readable during a disable/drain window
	 * — not a band-aid: the binding is valid and the bytes are private either way.
	 */
	private static function privateDriver() {
		return CloudStorageDriverFactory::forVisibilityWithFallback('private');
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

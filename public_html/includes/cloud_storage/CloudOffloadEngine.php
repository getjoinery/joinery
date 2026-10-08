<?php
/**
 * CloudOffloadEngine — the one shared offload orchestration.
 *
 * The shared per-row offload logic, table-agnostic: it reaches every
 * consumer-specific detail through the StorageProfile seam. The per-row
 * logic — bounded batch, per-row advisory lock, the PUT→reload→flip→delete
 * ordering invariant, the failure-count cap — is the same in every direction.
 *
 * Three directions:
 *   - forward (syncBatch): local rows go to the file store new offloads go to
 *     (CloudStorageDriverFactory::current()); each name is stored under the
 *     store's folder, and the row records the store and the key it went to;
 *   - reverse (reverseBatch): offloaded rows come home, each from the store
 *     its row names;
 *   - move (moveBatch): the rows on an older store are carried to the current
 *     one, each object checked against its own bytes before the row is
 *     re-pointed and the old copy deleted (Move files).
 *
 * A profile whose table also holds rows that are not its own (fbb_file_blobs
 * holds public blobs that never move) scopes the reverse/drain path to its own
 * cloud rows via the optional reverseEligibilityWhere() ownership gate, probed
 * with method_exists().
 *
 * @version 1.6 - a move refuses two stores in one place, and an ETag that is not the bytes' MD5 is checked by
 *                reading the copy back; each table's row locks are a space of their own (lockSpace())
 * @version 1.5 - the file store is a target row (specs/storage_targets.md WP6): a pushed row records the
 *                store and its primary object's full key, a pull-back reads each row's own store and
 *                clears both, and moveBatch() carries a store's rows to the current one

 * @version 1.4 - a row with no bytes on this server is parked at once with the reason, not counted as a
 *                failed push; every failure records why in the profile's last-error column
 * @version 1.3 - one store: the driver is resolved with no visibility argument
 * @version 1.2 - after the flip to cloud, the backup's object store has its say before the local
 *                bytes go (BackupObjects::after_offload): it copies the original to the site's
 *                backup storage when that profile is enabled, and the bytes are unlinked only once
 *                every enabled profile holds the object. With no profile enabled, or a consumer
 *                the store cannot describe, this is the unconditional unlink it always was.
 * @version 1.1
 */


class CloudOffloadEngine {

	const FORWARD_BATCH_LIMIT = 50;
	const REVERSE_BATCH_LIMIT = 25;
	const TIME_BUDGET_SECONDS = 60;
	const FAILED_COUNT_CAP    = 5;
	/** The reason recorded on a row that has nothing on this server to move. */
	const MISSING_BYTES       = 'no bytes on this server';
	/** First key of the per-row pg advisory lock for file blobs — namespaces it
	 *  away from runner-level locks. Exposed so a test can contend on the SAME
	 *  namespace. Every other table has a space of its own (lockSpace()). */
	const ADVISORY_LOCK_NAMESPACE = -42;

	/**
	 * The first key of a table's per-row lock: blob 7 and message 7 are two
	 * rows, so they hold two locks. File blobs keep ADVISORY_LOCK_NAMESPACE;
	 * any other table gets a fixed negative number from its name.
	 */
	public static function lockSpace(string $table): int {
		if ($table === 'fbb_file_blobs') {
			return self::ADVISORY_LOCK_NAMESPACE;
		}
		return -1000 - (int)(crc32($table) % 1000000);
	}

	// ====================================================================
	// FORWARD — local -> cloud
	// ====================================================================
	public static function syncBatch(StorageProfile $profile, ?CloudFileStore $store = null): array {
		// Production resolves where new offloads go; tests may hand in a store
		// over a mock driver to exercise the orchestration without a bucket.
		if ($store === null) {
			$store = CloudStorageDriverFactory::current();
		}
		if (!$store) {
			return ['status' => 'skipped', 'message' => 'store not enabled'];
		}

		$dblink = DbConnector::get_instance()->get_db_link();

		// Eligible rows: local-stored, not failed-out, plus the profile's gates.
		// driver IS NULL is treated as 'local' so pre-existing rows are eligible
		// without a backfill; failed_count IS NULL is treated as zero.
		$gate = trim($profile->eligibilityWhere());
		$gate_sql = $gate !== '' ? "\n\t\t\t  AND ($gate)" : '';
		$sql = "SELECT {$profile->pkeyColumn()} FROM {$profile->table()}
				WHERE ({$profile->driverColumn()} IS NULL OR {$profile->driverColumn()} = 'local')
				  AND COALESCE({$profile->failedCountColumn()}, 0) < :cap{$gate_sql}
				ORDER BY {$profile->pkeyColumn()} ASC
				LIMIT :lim";
		$q = $dblink->prepare($sql);
		$q->bindValue(':cap', self::FAILED_COUNT_CAP, PDO::PARAM_INT);
		$q->bindValue(':lim', self::FORWARD_BATCH_LIMIT, PDO::PARAM_INT);
		$q->execute();
		$rows = $q->fetchAll(PDO::FETCH_COLUMN, 0);

		$pushed = 0; $failed = 0; $skipped = 0; $missing = 0;
		$started = time();

		foreach ($rows as $id) {
			if ((time() - $started) >= self::TIME_BUDGET_SECONDS) {
				break;
			}
			$id = (int)$id;

			if (!self::_lock($dblink, $profile, $id)) { $skipped++; continue; }
			try {
				$result = self::_sync_row($profile, $id, $store);
				if ($result === 'pushed')      $pushed++;
				elseif ($result === 'skipped') $skipped++;
				elseif ($result === 'missing') $missing++;
				else                           $failed++;
			} catch (Exception $e) {
				error_log('CloudOffload forward ' . get_class($profile) . ' row ' . $id . ' fatal: ' . $e->getMessage());
				$failed++;
			} finally {
				self::_unlock($dblink, $profile, $id);
			}
		}

		// A record with no bytes is parked, not failed: it is a fact about the
		// record, not a fault in the run.
		return ['status' => $failed > 0 ? 'error' : 'success',
			'message' => "pushed=$pushed failed=$failed skipped=$skipped" . ($missing > 0 ? " missing=$missing" : '')];
	}

	/**
	 * Sync a single row. Returns 'pushed' | 'failed' | 'skipped' (no work) |
	 * 'missing' (nothing on this server to move; parked with the reason).
	 */
	private static function _sync_row(StorageProfile $profile, int $id, CloudFileStore $store): string {
		$driver = $store->driver;
		if (!$profile->rowExists($id)) {
			return 'skipped';
		}
		// Re-check eligibility under the lock.
		if (!$profile->isEligibleRow($id)) {
			return 'skipped';
		}

		// Build the items to push: original + variants, filtered to what's on
		// disk, each name stored under the store's folder.
		$items = $profile->itemsForRow($id);
		if ($items === null) {
			self::_park_missing($profile, $id);
			return 'missing';
		}
		foreach ($items as $i => $item) {
			$items[$i]['remote_key'] = $store->key((string)$item['name']);
		}

		$pushed_keys = [];
		$put_failed = false;
		$put_err = null;
		foreach ($items as $item) {
			try {
				$driver->put($item['local_path'], $item['remote_key'], $item['content_type']);
				$pushed_keys[] = $item['remote_key'];
			} catch (Exception $e) {
				$put_failed = true;
				$put_err = $e->getMessage();
				break;
			}
		}

		if ($put_failed) {
			// Best-effort cleanup of partial pushes.
			foreach ($pushed_keys as $k) {
				try { $driver->delete($k); } catch (Exception $e) { /* swallow */ }
			}
			self::_record_failure($profile, $id, 'push failed: ' . $put_err);
			return 'failed';
		}

		// Reload + re-check eligibility. If the row went ineligible during our
		// push, undo the push and leave it 'local' so the consumer's
		// placement flow can correct it.
		if (!$profile->isEligibleRow($id)) {
			foreach ($pushed_keys as $k) {
				try { $driver->delete($k); } catch (Exception $e) { /* swallow */ }
			}
			return 'skipped';
		}

		// Flip flag, record where the objects went, reset failure counter, then
		// delete local copies.
		$dblink = DbConnector::get_instance()->get_db_link();
		$upd = $dblink->prepare(
			"UPDATE {$profile->table()}
			 SET {$profile->driverColumn()} = 'cloud',
			     {$profile->targetColumn()} = ?,
			     {$profile->remoteKeyColumn()} = ?,
			     {$profile->failedCountColumn()} = 0,
			     {$profile->lastErrorColumn()} = NULL,
			     {$profile->lastAttemptColumn()} = now()
			 WHERE {$profile->pkeyColumn()} = ?"
		);
		$upd->execute([$store->target_id, $items[0]['remote_key'], $id]);

		// Only now may the local bytes go — original + variants — and only if
		// every backup storage that will hold this object already does. The store
		// to the site's own backup storage happens inside this call, under the row lock
		// this tick already holds; a failure there leaves the bytes and the
		// next site run stores it. A row left `cloud` with local bytes is the
		// normal state of a file waiting for a management node's backup.
		if (BackupObjects::after_offload($profile, $id)) {
			foreach ($items as $item) {
				@unlink($item['local_path']);
			}
		}

		return 'pushed';
	}

	// ====================================================================
	// REVERSE — cloud -> local
	// ====================================================================
	public static function reverseBatch(StorageProfile $profile, ?CloudStorageDriver $driver = null): array {
		$dblink = DbConnector::get_instance()->get_db_link();

		// Ownership gate: a profile whose table also holds rows that are not
		// its own scopes the reverse/drain to the cloud rows that are. A
		// profile that owns its table outright omits the method → no gate.
		$own = (method_exists($profile, 'reverseEligibilityWhere'))
			? trim($profile->reverseEligibilityWhere()) : '';
		$own_sql = $own !== '' ? " AND ($own)" : '';

		// Total remaining cloud rows for THIS store; if zero, deactivate and exit.
		$count_q = $dblink->query(
			"SELECT COUNT(*) AS c FROM {$profile->table()} WHERE {$profile->driverColumn()} = 'cloud'{$own_sql}");
		$remaining = (int)$count_q->fetch(PDO::FETCH_ASSOC)['c'];
		if ($remaining === 0) {
			return ['status' => 'success', 'message' => 'No cloud rows remain; task deactivated.', 'deactivate' => true];
		}

		// Each row comes home from the store it names, whatever the enabled
		// latch says: pull-back follows a disable. (Tests may inject one mock
		// driver for every row.)
		$batch_q = $dblink->prepare(
			"SELECT {$profile->pkeyColumn()} FROM {$profile->table()}
			 WHERE {$profile->driverColumn()} = 'cloud'{$own_sql}
			   AND COALESCE({$profile->failedCountColumn()}, 0) < :cap
			 ORDER BY {$profile->pkeyColumn()} ASC
			 LIMIT :lim");
		$batch_q->bindValue(':cap', self::FAILED_COUNT_CAP, PDO::PARAM_INT);
		$batch_q->bindValue(':lim', self::REVERSE_BATCH_LIMIT, PDO::PARAM_INT);
		$batch_q->execute();
		$rows = $batch_q->fetchAll(PDO::FETCH_COLUMN, 0);

		$pulled = 0; $failed = 0; $skipped = 0;
		$started = time();

		foreach ($rows as $id) {
			if ((time() - $started) >= self::TIME_BUDGET_SECONDS) {
				break;
			}
			$id = (int)$id;

			if (!self::_lock($dblink, $profile, $id)) { $skipped++; continue; }
			try {
				$row_driver = $driver ?? CloudStorageDriverFactory::forTarget(self::_target_of($profile, $id));
				if (!$row_driver) {
					self::_record_failure($profile, $id, 'no driver for the file store this row names');
					$failed++;
					continue;
				}
				$result = self::_pull_row($profile, $id, $row_driver);
				if ($result === 'pulled')      $pulled++;
				elseif ($result === 'skipped') $skipped++;
				else                           $failed++;
			} catch (Exception $e) {
				error_log('CloudOffload reverse ' . get_class($profile) . ' row ' . $id . ' fatal: ' . $e->getMessage());
				$failed++;
			} finally {
				self::_unlock($dblink, $profile, $id);
			}
		}

		return ['status' => $failed > 0 ? 'error' : 'success', 'message' => "pulled=$pulled failed=$failed skipped=$skipped (remaining≈$remaining)"];
	}

	/**
	 * Pull one row back. Three phases: (1) pull all bytes to temp,
	 * (2) place into the final local dir + commit DB, (3) best-effort bucket
	 * delete. The DB commit precedes the bucket delete (inverse of forward).
	 */
	private static function _pull_row(StorageProfile $profile, int $id, CloudStorageDriver $driver): string {
		if (!$profile->rowExists($id) || self::_driver_flag($profile, $id) !== 'cloud') {
			return 'skipped';
		}

		$items = $profile->reverseItemsForRow($id);
		if (empty($items)) {
			self::_record_failure($profile, $id, 'no reverse items enumerated');
			return 'failed';
		}

		$tmp_dir = sys_get_temp_dir() . '/cloud_reverse_' . $id . '_' . uniqid();
		if (!mkdir($tmp_dir, 0777, true)) {
			self::_record_failure($profile, $id, 'Failed to create temp dir');
			return 'failed';
		}
		$temp_paths = [];
		$drop_temps = function() use (&$temp_paths, $tmp_dir) {
			foreach ($temp_paths as $p) { if (is_file($p)) @unlink($p); }
			foreach (glob($tmp_dir . '/*', GLOB_ONLYDIR) as $d) { @rmdir($d); }
			@rmdir($tmp_dir);
		};

		// PHASE 1 — pull all keys to temp.
		try {
			foreach ($items as $i => $item) {
				$tmp_path = $tmp_dir . '/' . $i . '_' . basename($item['local_path']);
				$driver->get($item['remote_key'], $tmp_path);
				$temp_paths[$i] = $tmp_path;
			}
		} catch (Exception $e) {
			$drop_temps();
			self::_record_failure($profile, $id, 'Phase 1 pull failed: ' . $e->getMessage());
			return 'failed';
		}

		// PHASE 2 — place into the final local dir + commit DB.
		try {
			foreach ($items as $i => $item) {
				$dest = $item['local_path'];
				$dest_parent = dirname($dest);
				if (!is_dir($dest_parent)) { mkdir($dest_parent, 0777, true); }
				if (!copy($temp_paths[$i], $dest)) {
					throw new RuntimeException('local copy failed for ' . $item['remote_key']);
				}
			}

			$dblink = DbConnector::get_instance()->get_db_link();
			$dblink->beginTransaction();
			try {
				$upd = $dblink->prepare(
					"UPDATE {$profile->table()}
					 SET {$profile->driverColumn()} = 'local',
					     {$profile->targetColumn()} = NULL,
					     {$profile->remoteKeyColumn()} = NULL,
					     {$profile->failedCountColumn()} = 0,
					     {$profile->lastErrorColumn()} = NULL,
					     {$profile->lastAttemptColumn()} = now()
					 WHERE {$profile->pkeyColumn()} = ?"
				);
				$upd->execute([$id]);
				$dblink->commit();
			} catch (PDOException $e) {
				$dblink->rollBack();
				throw new RuntimeException('DB commit failed: ' . $e->getMessage(), 0, $e);
			}
		} catch (Exception $e) {
			// Bucket + DB unchanged (commit rolled back). Retry next tick.
			$drop_temps();
			self::_record_failure($profile, $id, 'Phase 2 placement/commit failed: ' . $e->getMessage());
			return 'failed';
		}

		// PHASE 3 — best-effort bucket delete. Failures here are orphan logs,
		// not stuck-file entries: the row is correctly served locally now.
		$failed_keys = [];
		foreach ($items as $item) {
			$delete_ok = false;
			foreach ([0, 1, 2] as $delay) {
				if ($delay) sleep($delay);
				try {
					$driver->delete($item['remote_key']);
					$delete_ok = true;
					break;
				} catch (Exception $e) { /* retry */ }
			}
			if (!$delete_ok) {
				$failed_keys[] = $item['remote_key'];
			}
		}
		if (!empty($failed_keys)) {
			error_log('CLOUD_STORAGE_ORPHAN: table=' . $profile->table() . ' keys=' . implode(',', $failed_keys));
		}

		$drop_temps();
		return 'pulled';
	}

	// ====================================================================
	// MOVE — one file store -> the current one (Move files)
	// ====================================================================

	/**
	 * Carry a batch of the rows on store $from_id to $to. Per row, under the
	 * row lock: every object is read from the old store, written to the new
	 * one under the new folder, and checked there against the bytes read (size,
	 * and the MD5 the provider reports as its ETag; an ETag that is not a plain
	 * MD5 is checked by reading the object back). Only when every object of
	 * the row checks is the row re-pointed; only then are the old copies
	 * deleted. A row that fails keeps pointing at its old store, which still
	 * holds it, and is tried again on a later tick. Resumable: what is left is
	 * whatever still names the old store.
	 *
	 * @return array ['status', 'message', 'moved', 'failed', 'remaining']
	 */
	public static function moveBatch(StorageProfile $profile, int $from_id, CloudFileStore $to, ?CloudStorageDriver $from_driver = null): array {
		$dblink = DbConnector::get_instance()->get_db_link();
		if ($from_id === $to->target_id) {
			return ['status' => 'skipped', 'message' => 'the store to move from is the current one', 'moved' => 0, 'failed' => 0, 'remaining' => 0];
		}
		// Two stores in one place: a copy would land on the original, and the
		// delete that follows would take the only copy.
		$from_target = new BackupTarget($from_id, TRUE);
		$to_target = new BackupTarget($to->target_id, TRUE);
		if ($from_target->key && $to_target->key && $from_target->overlaps($to_target)) {
			return ['status' => 'error', 'message' => 'the two stores share a bucket and folder; nothing is moved',
				'moved' => 0, 'failed' => 0, 'remaining' => self::rowsOn($profile, $from_id)];
		}
		$from_driver = $from_driver ?? CloudStorageDriverFactory::forTarget($from_id);
		if (!$from_driver) {
			return ['status' => 'error', 'message' => 'no driver for the store to move from', 'moved' => 0, 'failed' => 0, 'remaining' => self::rowsOn($profile, $from_id)];
		}

		$q = $dblink->prepare(
			"SELECT {$profile->pkeyColumn()} FROM {$profile->table()}
			 WHERE {$profile->driverColumn()} = 'cloud' AND {$profile->targetColumn()} = :from
			 ORDER BY {$profile->pkeyColumn()} ASC
			 LIMIT :lim");
		$q->bindValue(':from', $from_id, PDO::PARAM_INT);
		$q->bindValue(':lim', self::REVERSE_BATCH_LIMIT, PDO::PARAM_INT);
		$q->execute();
		$rows = $q->fetchAll(PDO::FETCH_COLUMN, 0);

		$moved = 0; $failed = 0;
		$errors = [];
		$started = time();
		foreach ($rows as $id) {
			if ((time() - $started) >= self::TIME_BUDGET_SECONDS) {
				break;
			}
			$id = (int)$id;
			if (!self::_lock($dblink, $profile, $id)) { continue; }
			try {
				self::_move_row($profile, $id, $from_id, $from_driver, $to);
				$moved++;
			} catch (Exception $e) {
				$failed++;
				$errors[] = $e->getMessage();
				error_log('CloudOffload move ' . $profile->table() . ' id=' . $id . ': ' . $e->getMessage());
			} finally {
				self::_unlock($dblink, $profile, $id);
			}
		}
		$remaining = self::rowsOn($profile, $from_id);
		return ['status' => $failed > 0 ? 'error' : 'success',
			'message' => "moved=$moved failed=$failed remaining=$remaining" . ($errors ? ' (' . $errors[0] . ')' : ''),
			'moved' => $moved, 'failed' => $failed, 'remaining' => $remaining];
	}

	/**
	 * One row from the store it names to $to. Throws, leaving the row on its
	 * old store, when any object cannot be read, written or checked.
	 */
	private static function _move_row(StorageProfile $profile, int $id, int $from_id, CloudStorageDriver $from, CloudFileStore $to): void {
		if (self::_driver_flag($profile, $id) !== 'cloud' || self::_target_of($profile, $id) !== $from_id) {
			return; // moved, pulled back or deleted since the batch was read
		}
		$items = $profile->reverseItemsForRow($id);
		if (!$items) {
			throw new RuntimeException('no objects enumerated');
		}
		$tmp_dir = sys_get_temp_dir() . '/cloud_move_' . $id . '_' . uniqid();
		if (!mkdir($tmp_dir, 0700, true)) {
			throw new RuntimeException('could not make a temp dir');
		}
		$written = [];
		try {
			foreach ($items as $i => $item) {
				$tmp = $tmp_dir . '/' . $i;
				try {
					$from->get((string)$item['remote_key'], $tmp);
				} catch (Exception $e) {
					if ($i === 0) {
						throw $e;
					}
					// A variant the old store does not have is regenerated on
					// demand; the original is what the row is.
					unset($items[$i]);
					continue;
				}
				$new_key = $to->key((string)$item['name']);
				$to->driver->put($tmp, $new_key, (string)($item['content_type'] ?? 'application/octet-stream'));
				$written[] = $new_key;
				self::_check_copy($to->driver, $new_key, $tmp, $tmp_dir . '/' . $i . '.check');
				$items[$i]['new_key'] = $new_key;
				@unlink($tmp);
			}
			// Re-point the row only if it still names the old store.
			$dblink = DbConnector::get_instance()->get_db_link();
			$upd = $dblink->prepare(
				"UPDATE {$profile->table()}
				 SET {$profile->targetColumn()} = ?, {$profile->remoteKeyColumn()} = ?
				 WHERE {$profile->pkeyColumn()} = ? AND {$profile->driverColumn()} = 'cloud' AND {$profile->targetColumn()} = ?");
			$upd->execute([$to->target_id, $items[0]['new_key'], $id, $from_id]);
			if ($upd->rowCount() !== 1) {
				throw new RuntimeException('the row changed while it was being moved');
			}
		} catch (Exception $e) {
			foreach ($written as $k) {
				try { $to->driver->delete($k); } catch (Exception $ignored) { /* the old copy is still the row's */ }
			}
			self::_drop_dir($tmp_dir);
			throw $e;
		}
		self::_drop_dir($tmp_dir);

		// The row names the new copies; the old ones go.
		$orphans = [];
		foreach ($items as $item) {
			try { $from->delete((string)$item['remote_key']); }
			catch (Exception $e) { $orphans[] = $item['remote_key']; }
		}
		if ($orphans) {
			error_log('CLOUD_STORAGE_ORPHAN: target=' . $from_id . ' keys=' . implode(',', $orphans));
		}
	}

	/**
	 * Is the object at $key in $driver the bytes of $local? Size first; then
	 * the ETag, when it is the MD5 of the bytes (a single-part upload to most
	 * providers). An ETag that is not, whatever its shape (a multipart upload,
	 * or a 32-hex ETag from a bucket encrypted with its own keys), proves
	 * nothing either way, so the object is read back and its SHA-256 compared.
	 */
	private static function _check_copy(CloudStorageDriver $driver, string $key, string $local, string $readback): void {
		$head = $driver->head($key);
		$size = filesize($local);
		if ($head === null || (int)$head['size'] !== (int)$size) {
			throw new RuntimeException('the copy of ' . $key . ' is not the size of the original');
		}
		$etag = strtolower(trim((string)($head['etag'] ?? ''), '"'));
		if (preg_match('/^[0-9a-f]{32}$/', $etag) && hash_equals($etag, md5_file($local))) {
			return;
		}
		$driver->get($key, $readback);
		$same = hash_equals(hash_file('sha256', $local), (string)hash_file('sha256', $readback));
		@unlink($readback);
		if (!$same) {
			throw new RuntimeException('the copy of ' . $key . ' does not hash to the original');
		}
	}

	/** How many of a profile's offloaded rows name a store. */
	public static function rowsOn(StorageProfile $profile, int $target_id): int {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("SELECT COUNT(*) FROM {$profile->table()}
			WHERE {$profile->driverColumn()} = 'cloud' AND {$profile->targetColumn()} = ?");
		$q->execute([$target_id]);
		return (int)$q->fetchColumn();
	}

	// ====================================================================
	// shared helpers
	// ====================================================================

	/** The store a row names, or 0. */
	private static function _target_of(StorageProfile $profile, int $id): int {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT {$profile->targetColumn()} FROM {$profile->table()} WHERE {$profile->pkeyColumn()} = ?");
		$q->execute([$id]);
		return (int)$q->fetchColumn();
	}

	private static function _drop_dir(string $dir): void {
		foreach (glob($dir . '/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
		@rmdir($dir);
	}

	/** Read the row's raw driver flag generically. */
	private static function _driver_flag(StorageProfile $profile, int $id): ?string {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT {$profile->driverColumn()} AS d FROM {$profile->table()} WHERE {$profile->pkeyColumn()} = ?");
		$q->execute([$id]);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		return $row ? $row['d'] : null;
	}

	private static function _record_failure(StorageProfile $profile, int $id, string $message): void {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"UPDATE {$profile->table()}
			 SET {$profile->failedCountColumn()} = COALESCE({$profile->failedCountColumn()}, 0) + 1,
			     {$profile->lastErrorColumn()} = ?,
			     {$profile->lastAttemptColumn()} = now()
			 WHERE {$profile->pkeyColumn()} = ?"
		);
		$q->execute([mb_substr($message, 0, 255), $id]);
		error_log('CloudOffload ' . $profile->table() . ' id=' . $id . ': ' . $message);
	}

	/**
	 * Park a row that has nothing on this server to move: the count goes
	 * straight to the cap so no tick tries again, and the reason says why, so
	 * the page can list it apart from a failed push. Its bytes were gone
	 * before the store was set up; permanently deleting the file releases it.
	 */
	private static function _park_missing(StorageProfile $profile, int $id): void {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"UPDATE {$profile->table()}
			 SET {$profile->failedCountColumn()} = ?,
			     {$profile->lastErrorColumn()} = ?,
			     {$profile->lastAttemptColumn()} = now()
			 WHERE {$profile->pkeyColumn()} = ?"
		);
		$q->execute([self::FAILED_COUNT_CAP, self::MISSING_BYTES, $id]);
		error_log('CloudOffload ' . $profile->table() . ' id=' . $id . ': ' . self::MISSING_BYTES . '; parked');
	}

	/** Per-row advisory lock, in the table's own space (lockSpace()). */
	private static function _lock($dblink, StorageProfile $profile, int $id): bool {
		$q = $dblink->prepare("SELECT pg_try_advisory_lock(:k1, :k2) AS got");
		$q->execute([':k1' => self::lockSpace($profile->table()), ':k2' => $id]);
		$got = $q->fetch(PDO::FETCH_ASSOC);
		return !empty($got['got']);
	}

	private static function _unlock($dblink, StorageProfile $profile, int $id): void {
		$q = $dblink->prepare("SELECT pg_advisory_unlock(:k1, :k2)");
		$q->execute([':k1' => self::lockSpace($profile->table()), ':k2' => $id]);
	}
}

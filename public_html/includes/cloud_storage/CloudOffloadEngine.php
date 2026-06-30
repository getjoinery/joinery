<?php
/**
 * CloudOffloadEngine — the one shared offload orchestration.
 *
 * The shared per-row offload logic, table-agnostic and visibility-blind: it
 * resolves its driver from
 * forVisibility($profile->visibility()) and reaches every consumer-specific
 * detail through the StorageProfile seam. The per-row logic — bounded batch,
 * per-row advisory lock, the PUT→reload→flip→delete ordering invariant, the
 * failure-count cap — is preserved exactly from the standalone tasks; only
 * $file-> became $profile-> of the same shape.
 *
 * When two stores share one table (the public and private File profiles share
 * fil_files), the reverse/drain path scopes its cloud rows to one store via the
 * profile's optional reverseEligibilityWhere() ownership gate, probed with
 * method_exists() — the same capability-probe style used for putMany().
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/StorageProfile.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));

class CloudOffloadEngine {

	const FORWARD_BATCH_LIMIT = 50;
	const REVERSE_BATCH_LIMIT = 25;
	const TIME_BUDGET_SECONDS = 60;
	const FAILED_COUNT_CAP    = 5;

	// ====================================================================
	// FORWARD — local -> cloud
	// ====================================================================
	public static function syncBatch(StorageProfile $profile, ?CloudStorageDriver $driver = null): array {
		// Production resolves the driver from the store's visibility; tests may
		// inject a mock driver to exercise the orchestration without a bucket.
		if ($driver === null) {
			$driver = CloudStorageDriverFactory::forVisibility($profile->visibility());
		}
		if (!$driver) {
			return ['status' => 'skipped', 'message' => $profile->visibility() . ' store not enabled'];
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

		$pushed = 0; $failed = 0; $skipped = 0;
		$started = time();

		foreach ($rows as $id) {
			if ((time() - $started) >= self::TIME_BUDGET_SECONDS) {
				break;
			}
			$id = (int)$id;

			if (!self::_lock($dblink, $id)) { $skipped++; continue; }
			try {
				$result = self::_sync_row($profile, $id, $driver);
				if ($result === 'pushed')      $pushed++;
				elseif ($result === 'skipped') $skipped++;
				else                           $failed++;
			} catch (Exception $e) {
				error_log('CloudOffload forward ' . $profile->visibility() . '/' . get_class($profile) . ' row ' . $id . ' fatal: ' . $e->getMessage());
				$failed++;
			} finally {
				self::_unlock($dblink, $id);
			}
		}

		return ['status' => $failed > 0 ? 'error' : 'success', 'message' => "pushed=$pushed failed=$failed skipped=$skipped"];
	}

	/**
	 * Sync a single row. Returns 'pushed' | 'failed' | 'skipped' (no work).
	 */
	private static function _sync_row(StorageProfile $profile, int $id, CloudStorageDriver $driver): string {
		if (!$profile->rowExists($id)) {
			return 'skipped';
		}
		// Re-check eligibility under the lock.
		if (!$profile->isEligibleRow($id)) {
			return 'skipped';
		}

		// Build the items to push: original + variants, filtered to what's on disk.
		$items = $profile->itemsForRow($id);
		if ($items === null) {
			self::_record_failure($profile, $id, 'required bytes missing on disk');
			return 'failed';
		}

		// Concurrent PUTs — single RTT instead of N. The S3 driver exposes putMany().
		$pushed_keys = [];
		$put_failed = false;
		$put_err = null;
		if (method_exists($driver, 'putMany')) {
			$results = $driver->putMany($items);
			foreach ($items as $item) {
				$r = $results[$item['remote_key']] ?? null;
				if ($r === true) {
					$pushed_keys[] = $item['remote_key'];
				} else {
					$put_failed = true;
					$put_err = $r instanceof Throwable ? $r->getMessage() : 'unknown error';
					break;
				}
			}
		} else {
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

		// Flip flag, reset failure counter, then delete local copies.
		$dblink = DbConnector::get_instance()->get_db_link();
		$upd = $dblink->prepare(
			"UPDATE {$profile->table()}
			 SET {$profile->driverColumn()} = 'cloud',
			     {$profile->failedCountColumn()} = 0,
			     {$profile->lastAttemptColumn()} = now()
			 WHERE {$profile->pkeyColumn()} = ?"
		);
		$upd->execute([$id]);

		// Only now delete local bytes — original + variants.
		foreach ($items as $item) {
			@unlink($item['local_path']);
		}

		return 'pushed';
	}

	// ====================================================================
	// REVERSE — cloud -> local
	// ====================================================================
	public static function reverseBatch(StorageProfile $profile, ?CloudStorageDriver $driver = null): array {
		$dblink = DbConnector::get_instance()->get_db_link();

		// Ownership gate: when several stores share one table (the public and
		// private File profiles share fil_files), each store's reverse/drain
		// must touch only the cloud rows that physically live in ITS bucket.
		// A profile that owns its table outright omits the method → no gate.
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

		// Reverse runs against a *disabled* store (pull-back follows a disable),
		// so forVisibility() — which honours the enabled latch — is the wrong
		// resolver here. Fall back to the unlatched binding so a draining store
		// still has a driver with its latch off. Losing this fallback would
		// silently no-op every pull-back. (Tests may inject a mock driver.)
		if ($driver === null) {
			$driver = CloudStorageDriverFactory::forVisibility($profile->visibility());
			if (!$driver) {
				$driver = CloudStorageDriverFactory::forVisibilityUnlatched($profile->visibility());
			}
		}
		if (!$driver) {
			return ['status' => 'error', 'message' => 'driver unconfigured for ' . $profile->visibility() . ' store'];
		}

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

			if (!self::_lock($dblink, $id)) { $skipped++; continue; }
			try {
				$result = self::_pull_row($profile, $id, $driver);
				if ($result === 'pulled')      $pulled++;
				elseif ($result === 'skipped') $skipped++;
				else                           $failed++;
			} catch (Exception $e) {
				error_log('CloudOffload reverse ' . $profile->visibility() . '/' . get_class($profile) . ' row ' . $id . ' fatal: ' . $e->getMessage());
				$failed++;
			} finally {
				self::_unlock($dblink, $id);
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
					     {$profile->failedCountColumn()} = 0,
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
			error_log('CLOUD_STORAGE_ORPHAN: visibility=' . $profile->visibility()
				. ' table=' . $profile->table() . ' keys=' . implode(',', $failed_keys));
		}

		$drop_temps();
		return 'pulled';
	}

	// ====================================================================
	// shared helpers
	// ====================================================================

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
			     {$profile->lastAttemptColumn()} = now()
			 WHERE {$profile->pkeyColumn()} = ?"
		);
		$q->execute([$id]);
		error_log('CloudOffload ' . $profile->table() . ' id=' . $id . ': ' . $message);
	}

	/** Per-row advisory lock; -42 namespaces it from runner-level locks. */
	private static function _lock($dblink, int $id): bool {
		$q = $dblink->prepare("SELECT pg_try_advisory_lock(:k1, :k2) AS got");
		$q->execute([':k1' => -42, ':k2' => $id]);
		$got = $q->fetch(PDO::FETCH_ASSOC);
		return !empty($got['got']);
	}

	private static function _unlock($dblink, int $id): void {
		$q = $dblink->prepare("SELECT pg_advisory_unlock(:k1, :k2)");
		$q->execute([':k1' => -42, ':k2' => $id]);
	}
}

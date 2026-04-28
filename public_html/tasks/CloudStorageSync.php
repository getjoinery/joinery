<?php
/**
 * CloudStorageSync — forward sync task
 *
 * Walks fil_files rows where fil_storage_driver = 'local' AND is_public()
 * AND fil_sync_failed_count < 5. For each row, pushes original + variants
 * to the bucket concurrently, re-checks is_public() after the push, then
 * either flips the row to 'cloud' and deletes locals, or undoes the push
 * if the row went private mid-flight.
 *
 * Bounded per-run (BATCH_LIMIT rows or TIME_BUDGET seconds, whichever first).
 * Per-row advisory lock (sct lock is at runner level; this is a row-scoped
 * lock so the sync task and a concurrent permission flip don't race).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));

class CloudStorageSync implements ScheduledTaskInterface {

	const BATCH_LIMIT = 50;
	const TIME_BUDGET_SECONDS = 60;
	const FAILED_COUNT_CAP = 5;

	public function run(array $config) {
		$settings = Globalvars::get_instance();
		if (!$settings->get_setting('cloud_storage_enabled')) {
			return ['status' => 'skipped', 'message' => 'cloud_storage_enabled is off'];
		}

		$driver = CloudStorageDriverFactory::default();
		if (!$driver) {
			return ['status' => 'error', 'message' => 'Cloud storage driver could not be constructed'];
		}

		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();

		// Eligible rows: local-stored, not deleted, has no permission gates, not failed-out.
		// is_public() in PHP checks delete_time + min_permission + grp + evt + tier; we
		// mirror those filters here so the batch query lines up with is_public().
		// fil_storage_driver IS NULL is treated as 'local' so pre-existing
		// rows (added before this column was introduced) are eligible without
		// requiring a backfill migration. fil_sync_failed_count IS NULL is
		// likewise treated as zero.
		$sql = "SELECT fil_file_id FROM fil_files
				WHERE (fil_storage_driver IS NULL OR fil_storage_driver = 'local')
				  AND fil_delete_time IS NULL
				  AND (fil_min_permission IS NULL OR fil_min_permission = 0)
				  AND (fil_grp_group_id IS NULL OR fil_grp_group_id = 0)
				  AND (fil_evt_event_id IS NULL OR fil_evt_event_id = 0)
				  AND (fil_tier_min_level IS NULL OR fil_tier_min_level = 0)
				  AND COALESCE(fil_sync_failed_count, 0) < :cap
				ORDER BY fil_file_id ASC
				LIMIT :lim";
		$q = $dblink->prepare($sql);
		$q->bindValue(':cap', self::FAILED_COUNT_CAP, PDO::PARAM_INT);
		$q->bindValue(':lim', self::BATCH_LIMIT, PDO::PARAM_INT);
		$q->execute();
		$rows = $q->fetchAll(PDO::FETCH_ASSOC);

		$pushed = 0;
		$failed = 0;
		$skipped = 0;
		$started = time();

		foreach ($rows as $row) {
			if ((time() - $started) >= self::TIME_BUDGET_SECONDS) {
				break;
			}

			$file_id = (int)$row['fil_file_id'];

			// Per-row advisory lock so a concurrent permission flip doesn't race us.
			$lock_q = $dblink->prepare("SELECT pg_try_advisory_lock(:k1, :k2) AS got");
			$lock_q->execute([':k1' => -42, ':k2' => $file_id]); // -42 namespaces this from runner-level locks
			$got = $lock_q->fetch(PDO::FETCH_ASSOC);
			if (empty($got['got'])) {
				$skipped++;
				continue;
			}

			try {
				$result = $this->_sync_row($file_id, $driver);
				if ($result === 'pushed')      $pushed++;
				elseif ($result === 'skipped') $skipped++;
				else                           $failed++;
			} catch (Exception $e) {
				error_log('CloudStorageSync row ' . $file_id . ' fatal: ' . $e->getMessage());
				$failed++;
			} finally {
				$unlock = $dblink->prepare("SELECT pg_advisory_unlock(:k1, :k2)");
				$unlock->execute([':k1' => -42, ':k2' => $file_id]);
			}
		}

		$msg = "pushed=$pushed failed=$failed skipped=$skipped";
		return ['status' => $failed > 0 ? 'error' : 'success', 'message' => $msg];
	}

	/**
	 * Sync a single row. Returns 'pushed' | 'failed' | 'skipped' (no work).
	 */
	private function _sync_row(int $file_id, CloudStorageDriver $driver): string {
		$file = new File($file_id, true);
		if (!$file->key) {
			return 'skipped';
		}
		// Re-check eligibility under the lock. Treat NULL as 'local'.
		$driver_flag = $file->get('fil_storage_driver');
		if (!($driver_flag === null || $driver_flag === '' || $driver_flag === 'local') || !$file->is_public()) {
			return 'skipped';
		}

		// Build the items to push: original + variants for images.
		$content_type = $file->get('fil_type') ?: 'application/octet-stream';
		$items = [];
		$original_path = $file->get_filesystem_path('original');
		if (!file_exists($original_path)) {
			$this->_record_failure($file, 'original missing on disk');
			return 'failed';
		}
		$items[] = [
			'local_path'   => $original_path,
			'remote_key'   => $file->remote_key_for('original'),
			'content_type' => $content_type,
			'size_key'     => 'original',
		];
		if ($file->is_image()) {
			foreach (ImageSizeRegistry::get_sizes() as $size_key => $cfg) {
				$variant_path = $file->get_filesystem_path($size_key);
				if (file_exists($variant_path)) {
					$items[] = [
						'local_path'   => $variant_path,
						'remote_key'   => $file->remote_key_for($size_key),
						'content_type' => $content_type,
						'size_key'     => $size_key,
					];
				}
			}
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
			$this->_record_failure($file, 'push failed: ' . $put_err);
			return 'failed';
		}

		// Re-load the row and re-check is_public(). If the file went private
		// during our push, undo the push and leave the row local so the
		// permission-change flow can place it correctly.
		$file_after = new File($file_id, true);
		if (!$file_after->is_public()) {
			foreach ($pushed_keys as $k) {
				try { $driver->delete($k); } catch (Exception $e) { /* swallow */ }
			}
			return 'skipped';
		}

		// Flip flag, reset failure counter, then delete local copies.
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$upd = $dblink->prepare(
			"UPDATE fil_files
			 SET fil_storage_driver = 'cloud',
			     fil_sync_failed_count = 0,
			     fil_sync_last_attempt = now()
			 WHERE fil_file_id = ?"
		);
		$upd->execute([$file_id]);

		// Delete local bytes — both directories, original + variants.
		foreach ($items as $item) {
			@unlink($item['local_path']);
		}

		return 'pushed';
	}

	private function _record_failure(File $file, string $message) {
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$q = $dblink->prepare(
			"UPDATE fil_files
			 SET fil_sync_failed_count = fil_sync_failed_count + 1,
			     fil_sync_last_attempt = now()
			 WHERE fil_file_id = ?"
		);
		$q->execute([$file->key]);
		error_log('CloudStorageSync fil=' . $file->key . ' name=' . $file->get('fil_name') . ': ' . $message);
	}
}

<?php
/**
 * CloudStorageReverseSync — pull-back task
 *
 * Activated only when an admin clicks "Disable and Pull Files Back to Local"
 * on the cloud storage admin page. Walks fil_files where
 * fil_storage_driver = 'cloud', pulls bytes back to local, flips the row,
 * and best-effort deletes from bucket. Self-deactivates when no more cloud
 * rows remain.
 *
 * Per-row, three phases (spec §11):
 *   1. Pull all bytes to a temp dir.
 *   2. Re-evaluate is_public() and place into the right local dir; commit DB.
 *   3. Best-effort bucket delete (orphan log on failure — not stuck-files).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));

class CloudStorageReverseSync implements ScheduledTaskInterface {

	const BATCH_LIMIT = 25;
	const TIME_BUDGET_SECONDS = 60;
	const FAILED_COUNT_CAP = 5;

	public function run(array $config) {
		// Driver may be unconfigured (admin disabled cloud storage in the
		// same flow that activated this task, but settings could later be
		// cleared). We need the bucket creds to pull back.
		$driver = CloudStorageDriverFactory::default();
		if (!$driver) {
			// Try to construct from raw settings even if cloud_storage_enabled is off,
			// since the pull-back should work after the admin disables cloud.
			$settings = Globalvars::get_instance();
			try {
				$driver = CloudStorageDriverFactory::fromOptions([
					'endpoint'   => $settings->get_setting('cloud_storage_endpoint'),
					'region'     => $settings->get_setting('cloud_storage_region'),
					'bucket'     => $settings->get_setting('cloud_storage_bucket'),
					'access_key' => $settings->get_setting('cloud_storage_access_key'),
					'secret_key' => $settings->get_setting('cloud_storage_secret_key'),
				]);
			} catch (Exception $e) {
				return ['status' => 'error', 'message' => 'driver unconfigured: ' . $e->getMessage()];
			}
		}

		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();

		// Total remaining rows; if zero, deactivate self and exit.
		$count_q = $dblink->query("SELECT COUNT(*) AS c FROM fil_files WHERE fil_storage_driver = 'cloud'");
		$count_row = $count_q->fetch(PDO::FETCH_ASSOC);
		$remaining = (int)$count_row['c'];
		if ($remaining === 0) {
			return [
				'status'     => 'success',
				'message'    => 'No cloud rows remain; task deactivated.',
				'deactivate' => true,
			];
		}

		$batch_q = $dblink->prepare(
			"SELECT fil_file_id FROM fil_files
			 WHERE fil_storage_driver = 'cloud'
			   AND fil_sync_failed_count < :cap
			 ORDER BY fil_file_id ASC
			 LIMIT :lim"
		);
		$batch_q->bindValue(':cap', self::FAILED_COUNT_CAP, PDO::PARAM_INT);
		$batch_q->bindValue(':lim', self::BATCH_LIMIT, PDO::PARAM_INT);
		$batch_q->execute();
		$rows = $batch_q->fetchAll(PDO::FETCH_ASSOC);

		$pulled = 0;
		$failed = 0;
		$skipped = 0;
		$started = time();

		foreach ($rows as $row) {
			if ((time() - $started) >= self::TIME_BUDGET_SECONDS) {
				break;
			}

			$file_id = (int)$row['fil_file_id'];

			$lock_q = $dblink->prepare("SELECT pg_try_advisory_lock(:k1, :k2) AS got");
			$lock_q->execute([':k1' => -42, ':k2' => $file_id]);
			$got = $lock_q->fetch(PDO::FETCH_ASSOC);
			if (empty($got['got'])) {
				$skipped++;
				continue;
			}

			try {
				$result = $this->_pull_row($file_id, $driver);
				if ($result === 'pulled')      $pulled++;
				elseif ($result === 'skipped') $skipped++;
				else                           $failed++;
			} catch (Exception $e) {
				error_log('CloudStorageReverseSync row ' . $file_id . ' fatal: ' . $e->getMessage());
				$failed++;
			} finally {
				$unlock = $dblink->prepare("SELECT pg_advisory_unlock(:k1, :k2)");
				$unlock->execute([':k1' => -42, ':k2' => $file_id]);
			}
		}

		return [
			'status'  => $failed > 0 ? 'error' : 'success',
			'message' => "pulled=$pulled failed=$failed skipped=$skipped (remaining≈" . $remaining . ")",
		];
	}

	/**
	 * Pull one row back. Three phases per spec §11.
	 */
	private function _pull_row(int $file_id, CloudStorageDriver $driver): string {
		$file = new File($file_id, true);
		if (!$file->key || $file->get('fil_storage_driver') !== 'cloud') {
			return 'skipped';
		}

		$filename = $file->get('fil_name');
		$settings = Globalvars::get_instance();
		$restricted_dir = $settings->get_setting('upload_dir');
		$fast_dir = dirname($restricted_dir) . '/static_files/uploads';

		$keys = ['original'];
		if ($file->is_image()) {
			foreach (ImageSizeRegistry::get_sizes() as $size_key => $cfg) {
				$keys[] = $size_key;
			}
		}

		$tmp_dir = sys_get_temp_dir() . '/cloud_reverse_' . $file_id . '_' . uniqid();
		if (!mkdir($tmp_dir, 0777, true)) {
			$this->_record_failure($file, 'Failed to create temp dir');
			return 'failed';
		}
		$temp_paths = [];
		$drop_temps = function() use (&$temp_paths, $tmp_dir) {
			foreach ($temp_paths as $p) if (is_file($p)) @unlink($p);
			foreach (glob($tmp_dir . '/*', GLOB_ONLYDIR) as $d) @rmdir($d);
			@rmdir($tmp_dir);
		};

		// PHASE 1 — pull all keys to temp.
		try {
			foreach ($keys as $size_key) {
				$tmp_path = ($size_key === 'original')
					? $tmp_dir . '/' . $filename
					: $tmp_dir . '/' . $size_key . '/' . $filename;
				$driver->get($file->remote_key_for($size_key), $tmp_path);
				$temp_paths[$size_key] = $tmp_path;
			}
		} catch (Exception $e) {
			$drop_temps();
			$this->_record_failure($file, 'Phase 1 pull failed: ' . $e->getMessage());
			return 'failed';
		}

		// PHASE 2 — place into the correct local dir + commit DB. Re-evaluate
		// is_public() against the freshly-loaded row (don't trust was-public-when-pushed).
		$target_dir = $file->is_public() ? $fast_dir : $restricted_dir;
		try {
			if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
			foreach ($keys as $size_key) {
				$dest = ($size_key === 'original')
					? $target_dir . '/' . $filename
					: $target_dir . '/' . $size_key . '/' . $filename;
				$dest_parent = dirname($dest);
				if (!is_dir($dest_parent)) mkdir($dest_parent, 0777, true);
				if (!copy($temp_paths[$size_key], $dest)) {
					throw new RuntimeException("local copy failed for $size_key");
				}
			}

			$dbconnector = DbConnector::get_instance();
			$dblink = $dbconnector->get_db_link();
			$dblink->beginTransaction();
			try {
				$upd = $dblink->prepare(
					"UPDATE fil_files
					 SET fil_storage_driver = 'local',
					     fil_sync_failed_count = 0,
					     fil_sync_last_attempt = now()
					 WHERE fil_file_id = ?"
				);
				$upd->execute([$file_id]);
				$dblink->commit();
			} catch (PDOException $e) {
				$dblink->rollBack();
				throw new RuntimeException('DB commit failed: ' . $e->getMessage(), 0, $e);
			}
		} catch (Exception $e) {
			// Phase 2 failure: bucket and DB unchanged (commit was rolled back).
			// Drop temps; on retry next tick we'll pull and place again.
			$drop_temps();
			$this->_record_failure($file, 'Phase 2 placement/commit failed: ' . $e->getMessage());
			return 'failed';
		}

		// PHASE 3 — best-effort bucket delete. Failures here are orphan logs,
		// not stuck-file entries: the row is correctly served locally now.
		$failed_keys = [];
		foreach ($keys as $size_key) {
			$delete_ok = false;
			foreach ([0, 1, 2] as $delay) {
				if ($delay) sleep($delay);
				try {
					$driver->delete($file->remote_key_for($size_key));
					$delete_ok = true;
					break;
				} catch (Exception $e) { /* retry */ }
			}
			if (!$delete_ok) {
				$failed_keys[] = $file->remote_key_for($size_key);
			}
		}
		if (!empty($failed_keys)) {
			$bucket = Globalvars::get_instance()->get_setting('cloud_storage_bucket') ?: 'unknown';
			error_log('CLOUD_STORAGE_ORPHAN: bucket=' . $bucket . ' keys=' . implode(',', $failed_keys));
		}

		$drop_temps();
		return 'pulled';
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
		error_log('CloudStorageReverseSync fil=' . $file->key . ' name=' . $file->get('fil_name') . ': ' . $message);
	}

}

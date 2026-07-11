<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

/**
 * DrivePurgeStaleUploads — delete pending Drive uploads (and their scratch
 * part-files) that have gone idle beyond the window (default 24h). Catches
 * abandoned resumable uploads that never completed.
 */
class DrivePurgeStaleUploads implements ScheduledTaskInterface {
	public function run(array $config) {
		require_once(PathHelper::getIncludePath('data/file_uploads_class.php'));

		$hours = isset($config['hours_to_keep']) ? (int)$config['hours_to_keep'] : 24;
		if ($hours <= 0) {
			$hours = 24;
		}

		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT fup_file_upload_id FROM fup_file_uploads
			  WHERE COALESCE(fup_update_time, fup_create_time) < now() - (INTERVAL '1 hour' * :hours)");
		$q->execute(array(':hours' => $hours));

		$purged = 0;
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$up = new FileUpload((int)$id, true);
			if ($up->key) {
				$up->discard();
				$purged++;
			}
		}

		// Sweep orphan .part files with no matching row (belt and braces).
		$dir = FileUpload::scratch_dir();
		$orphans = 0;
		foreach (glob($dir . '/*.part') ?: array() as $path) {
			$id = (int)basename($path, '.part');
			$row = new FileUpload($id, true);
			if (!$row->key) {
				@unlink($path);
				$orphans++;
			}
		}

		if ($purged === 0 && $orphans === 0) {
			return array('status' => 'success', 'message' => 'No stale Drive uploads to purge');
		}
		return array('status' => 'success',
			'message' => 'Purged ' . $purged . ' stale upload(s) and ' . $orphans . ' orphan part-file(s)');
	}
}

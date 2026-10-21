<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

/**
 * BackupDownloadSweep — delete a backup built for download once its day is up.
 *
 * Download a backup on the Backups page builds a whole-site archive on this
 * server and keeps it for BackupDownload::KEEP_SECONDS. Reading the download's
 * state removes one past its time; this hourly pass is what reads it when
 * nobody opens the page, so the archive goes within the hour after its day.
 *
 * @version 1.0.0
 */
class BackupDownloadSweep implements ScheduledTaskInterface {
	public function run(array $config) {
		try {
			return BackupDownload::sweep();
		} catch (Throwable $e) {
			return array('status' => 'error', 'message' => $e->getMessage());
		}
	}
}

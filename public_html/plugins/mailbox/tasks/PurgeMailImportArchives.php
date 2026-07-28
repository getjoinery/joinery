<?php
/**
 * PurgeMailImportArchives - Scheduled Task
 *
 * Reclaims what finished imports leave behind. A mail archive is routinely
 * hundreds of megabytes and there is one per import, so keeping them forever is
 * a slow leak of exactly the resource the feature is most expensive in.
 *
 * A GRACE PERIOD, not deletion on completion. Undoing an import and running it
 * again is a normal thing to do — a reader improves, a folder was missed — and it
 * needs the same bytes. Deleting the archive the moment a run finishes would be
 * tidier and would quietly remove that possibility. The window is how long it
 * stays open, and it is a setting because the right answer depends on how much
 * disk a deployment has.
 *
 * Only ever deletes files the IMPORTER created (fil_source = mail_import_archive).
 * An archive picked from the user's own Drive is theirs — it counts against their
 * quota and they may want it afterwards — so it is released, never removed.
 *
 * Orphaned working directories are swept separately and unconditionally. They are
 * cleaned when a run finishes normally, but a run removed some other way leaves
 * its directory behind, and nothing else would ever collect it.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mail_import_run_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveImporter.php'));

class PurgeMailImportArchives implements ScheduledTaskInterface {

	/** Days a finished run keeps its archive when nothing is configured. */
	const DEFAULT_RETENTION_DAYS = 7;

	/** A working directory older than this with no live run is litter. */
	const ORPHAN_DIR_AGE_SECONDS = 86400;

	/** Archives purged per pass, so a backlog cannot hold the runner open. */
	const MAX_PER_RUN = 50;

	public function run(array $config) {
		$settings = Globalvars::get_instance();

		$days = isset($config['retention_days']) ? (int)$config['retention_days'] : 0;
		if ($days <= 0) {
			$days = (int)$settings->get_setting('mailbox_import_archive_retention_days');
		}
		if ($days <= 0) {
			$days = self::DEFAULT_RETENTION_DAYS;
		}

		$purged = 0; $freed = 0; $failed = 0; $released = 0;

		foreach (array_slice(MailImportRun::finishedBefore($days), 0, self::MAX_PER_RUN) as $run_id) {
			try {
				$run = new MailImportRun($run_id, TRUE);
				if (!$run->key) {
					continue;
				}
				$result = (new MailArchiveImporter($run))->discardArchive();
				if (empty($result['ok'])) {
					$failed++;
					error_log('PurgeMailImportArchives: run ' . $run_id . ' — ' . $result['message']);
					continue;
				}
				// A Drive-picked archive is released rather than deleted, and frees
				// nothing here — reporting it as purged would overstate the work.
				if ((int)$result['freed'] > 0) {
					$purged++;
					$freed += (int)$result['freed'];
				} else {
					$released++;
				}
			} catch (Throwable $e) {
				$failed++;
				error_log('PurgeMailImportArchives: run ' . $run_id . ' failed: ' . $e->getMessage());
			}
		}

		$dirs = $this->sweepOrphanedWorkDirs();

		$message = sprintf(
			'Purged %d archive(s) freeing %s, released %d user-owned file(s), removed %d orphaned working director%s, %d failure(s).',
			$purged, MailArchiveImporter::formatBytes($freed), $released,
			$dirs, $dirs === 1 ? 'y' : 'ies', $failed
		);
		return array('status' => 'success', 'message' => $message);
	}

	/**
	 * Remove working directories with no live run behind them.
	 *
	 * Keyed on the run id embedded in the directory name, so a directory is only
	 * removed once its run is genuinely gone or long finished — never one an
	 * import is still reading from. Age is a second guard for the case where the
	 * name cannot be parsed at all.
	 */
	private function sweepOrphanedWorkDirs(): int {
		$base = sys_get_temp_dir();
		$dirs = glob($base . '/joinery-mail-import-*', GLOB_ONLYDIR);
		if (!$dirs) {
			return 0;
		}

		$db = DbConnector::get_instance()->get_db_link();
		$removed = 0;

		foreach ($dirs as $dir) {
			if (!preg_match('/joinery-mail-import-(\d+)$/', $dir, $m)) {
				continue;
			}
			$run_id = (int)$m[1];

			$stmt = $db->prepare('SELECT mir_state FROM mir_mail_import_runs
				WHERE mir_mail_import_run_id = ?');
			$stmt->execute(array($run_id));
			$state = $stmt->fetchColumn();

			// A run still in flight owns its directory, whatever its age.
			if ($state !== false && in_array((string)$state, MailImportRun::ACTIVE_STATES, true)) {
				continue;
			}
			// A run waiting on the user keeps its scan working area too.
			if ((string)$state === MailImportRun::STATE_SCANNED) {
				continue;
			}
			// Nothing is racing an unknown run, but give a just-created directory
			// the benefit of the doubt in case a run is mid-insert.
			$age = time() - (int)@filemtime($dir);
			if ($state === false && $age < self::ORPHAN_DIR_AGE_SECONDS) {
				continue;
			}

			if ($this->removeTree($dir)) {
				$removed++;
			}
		}
		return $removed;
	}

	/** Delete a directory and everything under it. Best-effort. */
	private function removeTree(string $dir): bool {
		try {
			$items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($items as $item) {
				$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
			}
			return @rmdir($dir);
		} catch (Throwable $e) {
			error_log('PurgeMailImportArchives: could not remove ' . $dir . ' — ' . $e->getMessage());
			return false;
		}
	}
}
?>

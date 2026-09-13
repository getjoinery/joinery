<?php
/**
 * BackupNightly — switching the nightly BackupRun task on.
 *
 * Nightly backups are not a decision of their own: an operator who has pointed
 * backups at a bucket and proven a recovery key has already made it. So the
 * moment both halves exist, the schedule turns itself on (maybe_activate),
 * and the explicit switch (activate) remains only for the odd state where
 * everything is ready but someone has turned the task off.
 *
 * Backup verification (BackupVerify) is switched on with it: a site that
 * backs itself up proves its backups restorable, on the same decision.
 *
 * @version 1.1 - activates BackupVerify alongside BackupRun, and turns the pair on when either is off
 * @version 1.0
 */

class BackupNightly {

	/** The two tasks one decision switches on. */
	const TASKS = array('BackupRun', 'BackupVerify');

	/**
	 * Turn the nightly task on if everything it needs exists and it is not
	 * already running: a scheduled target, a proven recovery key, no active
	 * BackupRun task. Safe to call from any request that may have completed
	 * one of those halves.
	 *
	 * backup_target_id is read straight from stg_settings because the caller
	 * may have written it earlier in this same request, and the settings
	 * singleton memoizes the pre-write value.
	 *
	 * @return bool whether the task was activated by this call
	 */
	public static function maybe_activate(): bool {
		$target_id = 0;
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
			$q->execute(array('backup_target_id'));
			$target_id = (int)$q->fetchColumn();
		} catch (\Throwable $e) {
			return false;
		}
		if ($target_id <= 0 || !BackupRecoveryKey::is_ready()) {
			return false;
		}
		$all_on = true;
		foreach (self::TASKS as $class) {
			$active = new MultiScheduledTask(array('task_class' => $class, 'active' => true, 'deleted' => false));
			if ($active->count_all() === 0) { $all_on = false; }
		}
		if ($all_on) {
			return false;
		}
		self::activate();
		return true;
	}

	/**
	 * Switch the BackupRun task on — reactivating the existing row, or creating
	 * one from the task's declared defaults — and give the archive path slug a
	 * default so the first run does not stall on a blank.
	 */
	public static function activate(): void {
		$discovered = null;
		foreach (self::TASKS as $class) {
			$existing = new MultiScheduledTask(array('task_class' => $class, 'deleted' => false));
			$task = null;
			foreach ($existing as $row) {
				$task = $row;
				break;
			}
			if ($task !== null) {
				if (!$task->get('sct_is_active')) {
					$task->set('sct_is_active', true);
					$task->save();
				}
				continue;
			}
			if ($discovered === null) { $discovered = ScheduledTaskRegistry::discover(); }
			$json = $discovered[$class]['json'] ?? array();
			$task = new ScheduledTask(NULL);
			$task->set('sct_name', $json['name'] ?? $class);
			$task->set('sct_task_class', $class);
			$task->set('sct_is_active', true);
			$task->set('sct_frequency', $json['default_frequency'] ?? 'daily');
			if (isset($json['default_time'])) {
				$task->set('sct_schedule_time', $json['default_time']);
			}
			$task->save();
		}
		if (trim((string)Globalvars::get_instance()->get_setting('backup_path_slug')) === '') {
			$slug = preg_replace('/[^A-Za-z0-9_-]/', '', basename(PathHelper::getSiteRoot()));
			if ($slug !== '') {
				Setting::put('backup_path_slug', $slug);
			}
		}
	}
}

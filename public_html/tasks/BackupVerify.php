<?php
/**
 * BackupVerify — this site proving its own newest backup restorable.
 *
 * A backup that has never been opened is a hope. On the interval the site sets
 * (backup_verify_every_days, 30 by default, 0 never) this opens and reads the
 * newest backup the site took of itself: every archive a restore of it would
 * need is downloaded from the site's own target, decrypted with the site's own
 * key and read to the end, then removed. Nothing on the site is touched. The
 * proof lands on the run's own history row, where the Backups page shows
 * "verified restorable" with the date.
 *
 * Always level 2. A rehearsal (level 3: replaying into scratch and a throwaway
 * database) is the Backups page's own button, chosen by a person; no schedule
 * runs one.
 *
 * Activated alongside BackupRun (BackupNightly): a site that backs itself up
 * verifies itself. A site that takes no backups of its own has nothing to
 * verify and says so, as a skip — a management node's backups of it are
 * verified from that management node.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifyLauncher.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));

class BackupVerify implements ScheduledTaskInterface, ScheduledTaskDryRunnable {

	public function run(array $config) {
		@set_time_limit(0);
		$due = BackupVerifyLauncher::due(self::every_days());
		if (!$due['due']) {
			return array('status' => 'skipped', 'message' => $due['reason']);
		}
		try {
			$result = BackupVerifyLauncher::run($due['run'], BackupVerifier::LEVEL_READ);
		} catch (\Throwable $e) {
			return array('status' => 'error', 'message' => 'Could not verify the newest backup: ' . $e->getMessage());
		}
		$words = BackupVerifier::describe($result);
		switch ($result['result'] ?? '') {
			case BackupVerifier::RESULT_PASS:    return array('status' => 'success', 'message' => $words);
			case BackupVerifier::RESULT_SKIPPED: return array('status' => 'skipped', 'message' => $words);
			default:                             return array('status' => 'error',   'message' => $words);
		}
	}

	/** Which backup a real run would open, and how big it is; or why it would not. */
	public function dryRun(array $config) {
		$due = BackupVerifyLauncher::due(self::every_days());
		$run = $due['run'];
		if ($run === null) {
			return array('status' => 'skipped', 'message' => $due['reason']);
		}
		$which = 'the backup of ' . BackupVerifier::when_words((string)$run->get('bkh_start_time'))
			. ' (' . BackupFetch::human((int)$run->get('bkh_bytes')) . ' in its own archives, plus the full backup and '
			. 'every incremental it depends on)';
		if (!$due['due']) {
			return array('status' => 'skipped', 'message' => 'Would not verify today: ' . $due['reason']
				. ' The next one would open and read ' . $which . '.');
		}
		return array('status' => 'success', 'message' => 'Would open and read ' . $which . ' — '
			. $due['reason'] . '.');
	}

	private static function every_days() {
		$v = Globalvars::get_instance()->get_setting('backup_verify_every_days', true, true);
		return ($v === null || $v === '') ? 30 : (int)$v;
	}
}

<?php
/**
 * SiteBackupNotice — the admin-header notice when this site's own scheduled
 * backup last failed (specs/post_release_fleet_defects.md B3).
 *
 * The site profile (tasks/BackupRun.php, "Backup" in the scheduled tasks) is
 * the backup a self-hosted owner relies on, and its failure used to land only
 * in the task's last-run status and the cron log, where nobody looks. A
 * backup is the one thing that must not be quietly wrong, so the FIRST failed
 * run is named here, on every admin page, with the engine's own last line as
 * the text, until a run succeeds. A site that has never configured a backup
 * has no history row and hears nothing: zero-config means no nagging.
 *
 * Reads one stored fact: the newest finished site-profile run in
 * bkh_backup_history. A run still recorded as running is not a failure.
 *
 * @version 1.0
 */
class SiteBackupNotice {

	public static function render(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return '';
		}
		$last = self::lastFinishedRun();
		if ($last === null) {
			return '';
		}
		return self::forRun((string)$last->get('bkh_outcome'), (string)$last->get('bkh_message'),
			(string)$last->get('bkh_finish_time'), (string)$last->get('bkh_target_name'));
	}

	/**
	 * The newest site-profile run, or null when there is none or the newest is
	 * still running (a run in progress is not a failure; the next page load
	 * after it finishes decides). Public so tests read the same fact the
	 * notice does.
	 */
	public static function lastFinishedRun(): ?BackupHistory {
		if (!class_exists('BackupProfile')) {
			return null;
		}
		$rows = new MultiBackupHistory(
			array('profile' => BackupProfile::SITE, 'deleted' => false),
			array('bkh_start_time' => 'DESC'), 1, 0);
		foreach ($rows as $row) {
			return (string)$row->get('bkh_outcome') === 'running' ? null : $row;
		}
		return null;
	}

	/** The notice for one run. Public and pure so the wording can be tested. */
	public static function forRun(string $outcome, string $message, string $finish_time, string $target_name): string {
		if ($outcome !== 'failed') {
			return '';
		}
		$when = $finish_time !== '' ? gmdate('Y-m-d H:i', strtotime($finish_time . ' UTC')) . ' UTC' : 'its last run';
		$lead = 'This site\'s own backup failed at ' . $when . '.';
		$last_line = trim($message) !== '' ? trim($message) : 'The run recorded no message.';
		// The engine's message can be long (a tar listing joined with " | ", with
		// the shell's colour codes in it); the header shows its last line, the
		// Backups page has the rest.
		$last_line = (string)preg_replace('/\x1b\[[0-9;]*m/', '', $last_line);
		$lines = preg_split('/\r?\n|\s\|\s/', $last_line) ?: array($last_line);
		$last_line = trim((string)end($lines));
		if (strlen($last_line) > 300) {
			$last_line = substr($last_line, 0, 297) . '...';
		}
		$body = ($target_name !== '' ? 'Target: ' . $target_name . '. ' : '')
			. 'Nothing new is offsite until a run succeeds; the next scheduled run clears this if it does.';
		return self::css()
			. '<div class="jy-site-backup-notice" role="status">'
			. '<div class="jy-site-backup-notice__text"><strong>' . htmlspecialchars($lead, ENT_QUOTES, 'UTF-8') . '</strong> '
			. htmlspecialchars($body, ENT_QUOTES, 'UTF-8')
			. ' <code class="jy-site-backup-notice__line">' . htmlspecialchars($last_line, ENT_QUOTES, 'UTF-8') . '</code>'
			. ' <a href="/admin/admin_backups">Backups</a></div>'
			. '</div>';
	}

	private static function css(): string {
		static $sent = false;
		if ($sent) return '';
		$sent = true;
		return '<style>'
			. '.jy-site-backup-notice{margin:0 0 1rem;padding:.75rem 1rem;border:1px solid #fca5a5;border-radius:6px;background:#fef2f2;color:#7f1d1d;font-size:.95rem}'
			. '.jy-site-backup-notice__line{display:inline-block;margin-top:.25rem;padding:.15rem .4rem;border-radius:4px;background:#fee2e2;font-size:.9em;overflow-wrap:anywhere}'
			. '.jy-site-backup-notice a{color:inherit;text-decoration:underline}'
			. '</style>';
	}
}

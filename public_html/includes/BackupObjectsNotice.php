<?php
/**
 * BackupObjectsNotice — the admin-header notice when offloaded files are
 * piling up on this server waiting for a backup that is not taking them
 * (specs/backup_offloaded_files.md § Admin surfaces).
 *
 * A file the site moved to its cloud file store keeps its local bytes until
 * every backup that stores offloaded files holds it. That is what makes the
 * copy in backup storage certain — and it is also how a backup that stopped
 * running fills a small disk, a page-count at a time, with nobody looking at
 * the page. So the count leaves the page and stands on every admin page when
 * either holds:
 *
 *   - the waiting bytes exceed WAITING_BYTES (2 GB), or
 *   - anything waits for an enabled backup whose newest successful run is
 *     older than STALE_DAYS (7), or which has never succeeded.
 *
 * Text: "N files (X GB) are waiting for the management node's backup, which
 * last succeeded D days ago. They stay on this server until it does." — or
 * "...this site's backup..." for the site profile. Silent when nothing waits.
 *
 * Reads what BackupObjectsStatus reads: the blob table, one stat per cloud
 * row, held.json, history. Never a network call.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupObjectsStatus.php'));

class BackupObjectsNotice {

	/** Waiting bytes above which the notice stands whatever the run dates say. */
	const WAITING_BYTES = 2147483648;

	/** Days since a backup's last success after which anything waiting on it is a notice. */
	const STALE_DAYS = 7;

	public static function render(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return '';
		}
		return self::forStatus(BackupObjectsStatus::compute(), time());
	}

	/** The notice for one picture, or ''. Public and pure so the rule can be tested. */
	public static function forStatus(array $status, $now_ts): string {
		$text = self::text($status, $now_ts);
		if ($text === '') {
			return '';
		}
		return self::css()
			. '<div class="jy-backup-objects-notice" role="status">'
			. '<div class="jy-backup-objects-notice__text"><strong>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</strong>'
			. ' <a href="/admin/admin_backups">Backups</a></div>'
			. '</div>';
	}

	/** The sentence, or '' when the notice has nothing to say. Pure. */
	public static function text(array $status, $now_ts): string {
		$waiting = $status['waiting'] ?? array('count' => 0, 'bytes' => 0, 'for' => array());
		if ((int)$waiting['count'] === 0) {
			return '';
		}
		// The backups that lack something, and how long since each last succeeded.
		$stale_before = (int)$now_ts - self::STALE_DAYS * 86400;
		$lacking = array();
		$stale = false;
		foreach (($waiting['for'] ?? array()) as $profile => $f) {
			if ((int)$f['count'] === 0) { continue; }
			$last = $status['last_success'][$profile] ?? null;
			$last_ts = $last ? strtotime((string)$last . ' UTC') : false;
			$lacking[$profile] = $last_ts ?: null;
			if (!$last_ts || $last_ts < $stale_before) { $stale = true; }
		}
		if (!$lacking) {
			return '';
		}
		if ((int)$waiting['bytes'] <= self::WAITING_BYTES && !$stale) {
			return '';
		}

		$clauses = array();
		foreach ($lacking as $profile => $last_ts) {
			$clauses[] = BackupObjectsStatus::backup_words(array($profile)) . ', which ' . self::since_words($last_ts, (int)$now_ts);
		}
		$count = (int)$waiting['count'];
		return BackupObjectsStatus::files_words($count, (int)$waiting['bytes'])
			. ($count === 1 ? ' is' : ' are') . ' waiting for ' . implode(' and for ', $clauses)
			. '. ' . ($count === 1 ? 'It stays' : 'They stay') . ' on this server until ' . (count($clauses) === 1 ? 'it does' : 'they do') . '.';
	}

	/** "last succeeded D days ago" / "last succeeded today" / "has never succeeded". */
	public static function since_words($last_ts, $now_ts) {
		if (!$last_ts) {
			return 'has never succeeded';
		}
		$days = (int)floor(max(0, (int)$now_ts - (int)$last_ts) / 86400);
		if ($days === 0) { return 'last succeeded today'; }
		return 'last succeeded ' . $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
	}

	private static function css(): string {
		static $sent = false;
		if ($sent) return '';
		$sent = true;
		return '<style>'
			. '.jy-backup-objects-notice{margin:0 0 1rem;padding:.75rem 1rem;border:1px solid #fcd34d;border-radius:6px;background:#fffbeb;color:#78350f;font-size:.95rem}'
			. '.jy-backup-objects-notice a{color:inherit;text-decoration:underline}'
			. '</style>';
	}
}

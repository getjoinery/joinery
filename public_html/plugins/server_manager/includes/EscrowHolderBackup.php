<?php
/**
 * EscrowHolderBackup — this escrow holder's own backup follows every change
 * to the keys it holds (specs/hosted_data_promise_disk_encryption.md O11).
 *
 * A holder rebuilt from its backups has the escrow table as of its newest
 * backup. A machine enrolled after that falls to its recovery key at its next
 * boot, and a key given up after it is held again. So within the hour of a
 * change (an enrolment confirmed, a key changed, a key given up) this holder
 * backs itself up, and the node page of a machine enrolled since its newest
 * backup says so.
 *
 * The backup that counts is one a rebuild could come from: successful and
 * offsite, of either profile (this site's own, or a management node's copy of
 * it), whose start came after the change. The one this holder can start is
 * its own (the site profile); one its own management node takes comes on that
 * node's schedule.
 *
 * @version 1.0
 */
class EscrowHolderBackup {

	/** A burst of enrolments gets one backup: it waits for this much quiet... */
	const QUIET_MINUTES = 10;
	/** ...but never past this, from the oldest change not yet in a backup. */
	const LATEST_MINUTES = 45;
	/** A backup that failed is tried again after this. */
	const RETRY_MINUTES = 60;

	/** A stored time (UTC, as the database keeps it) as a timestamp. */
	private static function utc(string $t): int {
		return (int)strtotime($t . ' UTC');
	}

	/** The changes since a time (UTC 'Y-m-d H:i:s', or null for all): [oldest, newest], or nulls. */
	public static function changes_since(?string $since): array {
		$db = DbConnector::get_instance()->get_db_link();
		$sql = 'SELECT min(t), max(t) FROM (SELECT dre_enrolled_time AS t FROM dre_data_root_escrows WHERE dre_enrolled_time IS NOT NULL'
			. ' UNION ALL SELECT dre_retired_time FROM dre_data_root_escrows WHERE dre_retired_time IS NOT NULL) c'
			. ($since !== null ? ' WHERE t > ?' : '');
		$q = $db->prepare($sql);
		$q->execute($since !== null ? array($since) : array());
		$r = $q->fetch(PDO::FETCH_NUM);
		return array($r && $r[0] ? (string)$r[0] : null, $r && $r[1] ? (string)$r[1] : null);
	}

	/** When the newest backup a rebuild could come from started (UTC), or null. */
	public static function last_backup(): ?string {
		$db = DbConnector::get_instance()->get_db_link();
		$v = $db->query("SELECT max(bkh_start_time) FROM bkh_backup_history WHERE bkh_outcome = 'success'"
			. ' AND bkh_upload_time IS NOT NULL AND bkh_delete_time IS NULL')->fetchColumn();
		return $v ? (string)$v : null;
	}

	/** The newest attempt of this site's own backup (UTC start and outcome), or null. */
	private static function last_own_attempt(): ?array {
		$db = DbConnector::get_instance()->get_db_link();
		$r = $db->query("SELECT bkh_start_time, bkh_outcome FROM bkh_backup_history WHERE (bkh_profile = 'site' OR bkh_profile IS NULL)"
			. ' ORDER BY bkh_start_time DESC LIMIT 1')->fetch(PDO::FETCH_NUM);
		return $r ? array('start' => (string)$r[0], 'outcome' => (string)$r[1]) : null;
	}

	/** Whether this machine's key, held here, came after this holder's newest backup. */
	public static function enrolment_uncovered(DataRootEscrow $row, ?string $last_backup): bool {
		if ((string)$row->get('dre_status') !== 'enrolled' || !$row->get('dre_enrolled_time')) {
			return false;
		}
		return $last_backup === null || self::utc((string)$row->get('dre_enrolled_time')) > self::utc($last_backup);
	}

	/**
	 * Whether a backup is due now, and why or why not. Pure over what it
	 * reads, for the test: times are UTC 'Y-m-d H:i:s', $now a timestamp.
	 *
	 * @return array{due: bool, why: string}
	 */
	public static function decide(?string $oldest, ?string $newest, ?array $attempt, int $now): array {
		if ($newest === null) {
			return array('due' => false, 'why' => 'Every change to the keys held here is in a backup.');
		}
		if ($attempt !== null && self::utc($attempt['start']) >= self::utc((string)$oldest)) {
			if ($attempt['outcome'] === 'running') {
				return array('due' => false, 'why' => 'A backup is running.');
			}
			// It ended and does not count: it failed, or it is not offsite.
			if ($now - self::utc($attempt['start']) < self::RETRY_MINUTES * 60) {
				return array('due' => false, 'why' => 'The backup since the change did not reach backup storage; another starts an hour after it.');
			}
		}
		$quiet = $now - self::utc($newest) >= self::QUIET_MINUTES * 60;
		$waited = $now - self::utc((string)$oldest) >= self::LATEST_MINUTES * 60;
		if (!$quiet && !$waited) {
			return array('due' => false, 'why' => 'A key held here changed; a backup runs once it has been quiet for '
				. self::QUIET_MINUTES . ' minutes.');
		}
		return array('due' => true, 'why' => 'A key held here changed since the last backup.');
	}

	/** One tick: a backup started when one is due. Returns what it did, for the task's message. */
	public static function tick(): string {
		$last = self::last_backup();
		list($oldest, $newest) = self::changes_since($last);
		$d = self::decide($oldest, $newest, self::last_own_attempt(), time());
		if (!$d['due']) {
			return $d['why'];
		}
		try {
			// The same refusal as Run a backup now: no viable plan, no run.
			BackupRunner::plan();
		} catch (Exception $e) {
			return 'A key held here changed since the last backup, and this site takes no backups of its own: ' . $e->getMessage();
		}
		$runner = PathHelper::getIncludePath('utils/run_backup.php');
		exec('nohup php ' . escapeshellarg($runner) . ' > /dev/null 2>&1 &');
		return 'A key held here changed since the last backup: a backup started.';
	}
}

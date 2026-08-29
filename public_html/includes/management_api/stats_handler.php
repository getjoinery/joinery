<?php
/**
 * GET /api/v1/management/stats
 *
 * Disk, memory, load, uptime, PostgreSQL liveness, Joinery version, DB list.
 * Output shape matches what JobResultProcessor::process_check_status produces
 * from SSH output, so the two transports populate mgn_last_status_data
 * identically.
 */

function stats_handler_api() {
	return [
		'method'      => 'GET',
		'description' => 'Disk, memory, load, uptime, PostgreSQL liveness, Joinery version, DB list.',
	];
}

function stats_handler($request) {
	$result = [];

	// Disk usage for the filesystem holding the web root.
	// Prefer the web root since it's more meaningful for site operators than "/".
	$web_root = PathHelper::getIncludePath('');
	$disk_total = @disk_total_space($web_root);
	$disk_free  = @disk_free_space($web_root);
	if ($disk_total && $disk_free !== false) {
		$used = $disk_total - $disk_free;
		$result['disk_usage_percent'] = intval(round($used * 100 / $disk_total));
		$result['disk_total']         = _mgmt_stats_format_size($disk_total);
		$result['disk_used']          = _mgmt_stats_format_size($used);
		$result['disk_available']     = _mgmt_stats_format_size($disk_free);
	}

	// Memory — parse /proc/meminfo (Linux only)
	if (@is_readable('/proc/meminfo')) {
		$meminfo = @file_get_contents('/proc/meminfo');
		if ($meminfo) {
			$mem_total_kb = 0;
			$mem_avail_kb = 0;
			if (preg_match('/^MemTotal:\s+(\d+)\s*kB/m', $meminfo, $m)) {
				$mem_total_kb = intval($m[1]);
			}
			if (preg_match('/^MemAvailable:\s+(\d+)\s*kB/m', $meminfo, $m)) {
				$mem_avail_kb = intval($m[1]);
			}
			if ($mem_total_kb > 0) {
				$total_mb = intval(round($mem_total_kb / 1024));
				$free_mb  = intval(round($mem_avail_kb / 1024));
				$result['memory_total_mb'] = $total_mb;
				$result['memory_used_mb']  = max(0, $total_mb - $free_mb);
				$result['memory_free_mb']  = $free_mb;
			}
		}
	}

	// Load average
	$load = @sys_getloadavg();
	if (is_array($load) && count($load) >= 3) {
		$result['load_1m']  = floatval($load[0]);
		$result['load_5m']  = floatval($load[1]);
		$result['load_15m'] = floatval($load[2]);
	}

	// Uptime
	if (@is_readable('/proc/uptime')) {
		$uptime_raw = @file_get_contents('/proc/uptime');
		if ($uptime_raw) {
			$parts = explode(' ', trim($uptime_raw));
			$secs = floatval($parts[0] ?? 0);
			if ($secs > 0) {
				$result['uptime'] = _mgmt_stats_format_uptime($secs);
			}
		}
	}

	// Joinery version
	$version = LibraryFunctions::get_joinery_version();
	if ($version !== '') {
		$result['joinery_version'] = $version;
	}

	// Which backup recovery key this site is holding. A management node needs it
	// to see whether the site can encrypt its own scheduled backups yet, and to
	// tell its own key from one somebody else configured here. Only the
	// fingerprint and the state travel — the public key is not secret, but
	// nothing here needs it, and a hash is enough to compare.
	try {
		require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));
		$recovery = BackupRecoveryKey::key_report();
		$result['backup_recovery_state'] = $recovery['state'];
		if ($recovery['fingerprint'] !== '') {
			$result['backup_recovery_fpr'] = $recovery['fingerprint'];
		}
	} catch (Throwable $e) {
		// A site too old to have the class answers the rest of the call normally.
	}

	// What each party's backups of this site are doing.
	//
	// Both are REPORTED. The site profile is this site's own business — a site
	// that takes no copies of its own is exercising a choice, not failing — and
	// reporting it here lets a management node show why a box is busy at 3am
	// without treating it as a problem to chase. The manager profile is reported
	// so whoever runs it can see their own runs landing without reading a bucket.
	try {
		require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
		require_once(PathHelper::getIncludePath('data/backup_history_class.php'));
		require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));

		$profiles = array();
		foreach (BackupProfile::names() as $profile) {
			$profiles[$profile] = _mgmt_stats_backup_profile($profile);
		}
		$result['backups'] = $profiles;
	} catch (Throwable $e) {
		// A site too old to have profiles answers the rest of the call normally.
	}

	// Cron health — last time process_scheduled_tasks.php fired
	$settings = Globalvars::get_instance();
	$cron_last_run = $settings->get_setting('scheduled_tasks_last_cron_run');
	if ($cron_last_run) {
		$result['cron_last_run'] = $cron_last_run;
	}

	// PostgreSQL liveness + current DB name + accessible DB list.
	// Use the already-open PDO connection rather than shelling out to pg_isready.
	try {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->query("SELECT current_database() AS db");
		$row = $q ? $q->fetch(PDO::FETCH_ASSOC) : null;
		if ($row && !empty($row['db'])) {
			$result['postgres_status'] = 'accepting connections';
			$result['current_db']      = $row['db'];
		}

		$q = $dblink->query(
			"SELECT datname FROM pg_database "
			. "WHERE datistemplate = false AND datname NOT IN ('postgres') "
			. "ORDER BY datname"
		);
		if ($q) {
			$dbs = [];
			while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
				$dbs[] = $r['datname'];
			}
			if (!empty($dbs)) {
				$result['db_list'] = $dbs;
			}
		}
	} catch (Exception $e) {
		$result['postgres_status'] = 'not responding';
	}

	return $result;
}

function _mgmt_stats_format_size($bytes) {
	if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'G';
	if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . 'M';
	if ($bytes >= 1024)       return round($bytes / 1024, 1) . 'K';
	return $bytes . 'B';
}

/**
 * What one party's backups of this site are doing.
 *
 * Read from this site's own history rows, which is the only place that knows
 * about runs that FAILED — a bucket listing shows successes and says nothing
 * about the month of failures that preceded them.
 *
 * The schedule half only applies to the site's own profile: a management node's
 * backups are scheduled where they are run from, not here.
 */
function _mgmt_stats_backup_profile($profile) {
	$out = array('profile' => $profile);

	if ($profile === BackupProfile::SITE) {
		$out['scheduled'] = false;
		try {
			$tasks = new MultiScheduledTask(array('task_class' => 'BackupRun', 'deleted' => false),
				array('sct_id' => 'DESC'), 1, 0);
			$tasks->load();
			foreach ($tasks as $task) {
				$out['scheduled'] = (bool)$task->get('sct_is_active');
				$out['frequency'] = (string)$task->get('sct_frequency');
				$out['time']      = (string)$task->get('sct_schedule_time');
			}
		} catch (Throwable $e) {
			// Reported as unscheduled rather than failing the whole call.
		}
	}

	try {
		$rows = new MultiBackupHistory(array('profile' => $profile, 'deleted' => false),
			array('bkh_start_time' => 'DESC'), 1, 0);
		$rows->load();
		foreach ($rows as $row) {
			$out['last_run']      = (string)$row->get('bkh_start_time');
			$out['last_outcome']  = (string)$row->get('bkh_outcome');
			$out['last_offsite']  = (bool)$row->is_offsite();
			$out['recovery_fpr']  = (string)$row->get('bkh_recovery_fpr');
		}

		// The last one that actually reached the bucket. Distinct from the last
		// run on purpose: "when did this last work" is the question, and the
		// newest row answers "when was this last attempted".
		$ok = new MultiBackupHistory(
			array('profile' => $profile, 'outcome' => 'success', 'offsite' => true, 'deleted' => false),
			array('bkh_start_time' => 'DESC'), 1, 0);
		$ok->load();
		foreach ($ok as $row) {
			$out['last_success'] = (string)$row->get('bkh_start_time');
		}
	} catch (Throwable $e) {
		$out['error'] = 'history unreadable';
	}

	return $out;
}

function _mgmt_stats_format_uptime($secs) {
	$days    = intval($secs / 86400);
	$hours   = intval(($secs % 86400) / 3600);
	$minutes = intval(($secs % 3600) / 60);
	if ($days > 0) {
		return "{$days} day" . ($days === 1 ? '' : 's') . ", {$hours}:" . sprintf('%02d', $minutes);
	}
	return "{$hours}:" . sprintf('%02d', $minutes);
}
?>

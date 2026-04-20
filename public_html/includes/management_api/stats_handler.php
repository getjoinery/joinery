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

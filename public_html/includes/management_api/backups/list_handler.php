<?php
/**
 * GET /api/v1/management/backups/list
 *
 * Lists backup files in /backups/ (the canonical path used by all backup
 * scripts). Shape matches what JobResultProcessor::process_list_backups
 * produces from the SSH path, so the two transports populate mjb_result
 * identically and downstream display code is unchanged.
 */

function backups_list_handler_api() {
	return [
		'method'      => 'GET',
		'description' => 'List backup files in /backups/.',
	];
}

function backups_list_handler($request) {
	$dir = '/backups';
	$result = [
		'directory' => $dir,
		'readable'  => false,
		'files'     => [],
	];

	if (!@is_dir($dir) || !@is_readable($dir)) {
		return $result;
	}
	$result['readable'] = true;

	$patterns = [
		$dir . '/*.sql.gz',
		$dir . '/*.sql.gz.enc',
		$dir . '/*.tar.gz',
	];

	$files = [];
	foreach ($patterns as $pattern) {
		foreach (glob($pattern) ?: [] as $path) {
			if (!is_file($path)) continue;
			$size  = filesize($path);
			$mtime = filemtime($path);
			$files[] = [
				'filename'   => basename($path),
				'size'       => _mgmt_backups_format_size($size),
				'size_bytes' => $size,
				'date'       => gmdate('Y-m-d', $mtime),
				'mtime'      => $mtime,
				'local_path' => $path,
				'cloud_path' => null,
				'location'   => 'local',
			];
		}
	}

	usort($files, function($a, $b) { return $b['mtime'] - $a['mtime']; });
	$result['files'] = $files;
	return $result;
}

function _mgmt_backups_format_size($bytes) {
	if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'G';
	if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . 'M';
	if ($bytes >= 1024)       return round($bytes / 1024, 1) . 'K';
	return $bytes . 'B';
}
?>

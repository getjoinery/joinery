<?php
/**
 * GET /api/v1/management/errors/recent
 *
 * Returns up to N recent error-log lines containing Fatal|Exception|Error.
 * Mirrors what the SSH `check_status` step does today.
 *
 * Requires the web server user (www-data / http) to have read access to the
 * site's error.log — on managed nodes this is typically enforced by
 * install.sh. If the log is not readable, the handler returns an empty list
 * rather than failing, so the caller can distinguish "no recent errors" from
 * "endpoint broken" by consulting `log_readable`.
 *
 * Query params:
 *   limit   — max number of lines to return (default 20, hard cap 200)
 */

function errors_recent_handler_api() {
	return [
		'method'      => 'GET',
		'description' => 'Last N error.log lines matching Fatal|Exception|Error (default 20, cap 200).',
	];
}

function errors_recent_handler($request) {
	$limit = intval($request['query']['limit'] ?? 20);
	if ($limit < 1)   $limit = 20;
	if ($limit > 200) $limit = 200;

	// Log location: project_root/logs/error.log (one level up from web root).
	$web_root = rtrim(PathHelper::getIncludePath(''), '/');
	$log_path = dirname($web_root) . '/logs/error.log';

	$result = [
		'log_path'     => $log_path,
		'log_readable' => false,
		'lines'        => [],
	];

	if (!@is_readable($log_path)) {
		return $result;
	}
	$result['log_readable'] = true;

	// Read the tail of the file without slurping the whole thing.
	// For typical error logs this is plenty; for extreme sizes the admin
	// should rotate the log, not ask us to scan 10 GB over HTTP.
	$lines = _mgmt_errors_tail($log_path, 2000);
	if ($lines === null) {
		return $result;
	}

	$matched = [];
	foreach (array_reverse($lines) as $line) {
		if (preg_match('/fatal|exception|error/i', $line)) {
			$matched[] = rtrim($line);
			if (count($matched) >= $limit) break;
		}
	}

	$result['lines'] = array_reverse($matched);
	return $result;
}

/**
 * Return the last $max_lines of a file, or null on failure.
 * Reads from the end in chunks to avoid loading huge files into memory.
 */
function _mgmt_errors_tail($path, $max_lines) {
	$fh = @fopen($path, 'r');
	if (!$fh) return null;

	$buffer = '';
	$chunk_size = 8192;
	fseek($fh, 0, SEEK_END);
	$pos = ftell($fh);
	$line_count = 0;

	while ($pos > 0 && $line_count <= $max_lines) {
		$read = min($chunk_size, $pos);
		$pos -= $read;
		fseek($fh, $pos);
		$chunk = fread($fh, $read);
		$buffer = $chunk . $buffer;
		$line_count = substr_count($buffer, "\n");
	}
	fclose($fh);

	$lines = explode("\n", $buffer);
	if (count($lines) > $max_lines) {
		$lines = array_slice($lines, -$max_lines);
	}
	return $lines;
}
?>

<?php
/**
 * PHP warnings the site wrote while a run was going fail the run.
 *
 * A suite that reaches the site over HTTP sees only the response. A warning
 * PHP raises while serving it goes to the site's error log, and the response
 * can still look right: an undefined variable reads as null, and the request
 * carries on. One did for a day (ApiLogicEndpoint 1.8.4, 2026-10-24), written
 * about 130 times by each gate run while every suite passed.
 *
 * The runner notes where the log ends when it starts and reads what was added
 * when it finishes. Every warning or fatal error in a file under this site
 * root fails the run as an entry of its own, once per place, with how often it
 * was written. A request from anywhere during the run counts: the warning is a
 * bug whoever's request raised it.
 *
 * A machine with no readable log has nothing to read: every function here is
 * then a no-op.
 *
 * @version 1.0
 */

/** The longest stretch of log read at the end of a run. */
const PHP_WARNINGS_READ_CAP = 64 * 1024 * 1024;

/** The site's error log, or null where it cannot be read. */
function php_warnings_log() {
	$log = dirname(__DIR__, 3) . '/logs/error.log';
	return is_readable($log) ? $log : null;
}

/** Where the log ends now: [path, inode, size], or null with no log. */
function php_warnings_mark() {
	$log = php_warnings_log();
	if ($log === null) return null;
	clearstatcache(true, $log);
	$st = @stat($log);
	return $st ? array($log, (int)$st['ino'], (int)$st['size']) : null;
}

/**
 * The warnings written after $mark, one entry per message and place:
 * "PHP Warning: Undefined variable $x at includes/Foo.php:12 (written 3 times)".
 * A log rotated during the run is read from its new start. $site_root
 * defaults to this one.
 */
function php_warnings_since($mark, $site_root = null) {
	if ($mark === null) return array();
	list($log, $ino, $size) = $mark;
	clearstatcache(true, $log);
	$st = @stat($log);
	if (!$st) return array();
	$from = ((int)$st['ino'] === $ino && (int)$st['size'] >= $size) ? $size : 0;
	$len = min((int)$st['size'] - $from, PHP_WARNINGS_READ_CAP);
	if ($len <= 0) return array();
	$fh = @fopen($log, 'rb');
	if (!$fh) return array();
	fseek($fh, $from);
	$text = (string)fread($fh, $len);
	fclose($fh);
	return php_warnings_parse($text, $site_root ?? dirname(__DIR__, 2));
}

/** The entries for log text, counting only files under $site_root. */
function php_warnings_parse($text, $site_root) {
	$root = rtrim($site_root, '/') . '/';
	$seen = array();
	if (!preg_match_all('/PHP (Warning|Fatal error|Parse error):\s+(.*?) in (\/\S+?\.php)(?::| on line )(\d+)/', $text, $m, PREG_SET_ORDER)) {
		return array();
	}
	foreach ($m as $hit) {
		if (strpos($hit[3], $root) !== 0) continue;
		$key = 'PHP ' . $hit[1] . ': ' . $hit[2] . ' at ' . substr($hit[3], strlen($root)) . ':' . $hit[4];
		$seen[$key] = ($seen[$key] ?? 0) + 1;
	}
	$out = array();
	foreach ($seen as $key => $n) {
		$out[] = $key . ($n > 1 ? " (written $n times)" : '');
	}
	return $out;
}

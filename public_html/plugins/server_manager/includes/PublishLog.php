<?php
/**
 * PublishLog - keeps a durable record of a publish run
 * (specs/publish_integrity_guards.md).
 *
 * A publish reports through publish_output(), which streams to a terminal or a
 * browser and nothing else. Any stage that hits a problem and continues — or
 * any run that exits early — therefore explains itself exactly once, to
 * whoever happened to be watching. When the consequence of that stage only
 * surfaces days later on another machine, the explanation is long gone.
 *
 * This collects every line the run emits and writes it to
 * {site root}/logs/publish/ from a shutdown handler, so an aborted or fatal
 * run is recorded as faithfully as a clean one. Logs live outside public_html:
 * they name filesystem paths, component versions and the account that ran the
 * publish.
 *
 * @version 1.0
 */

class PublishLog {

	/** How many logs to keep. Small text files; this is generous. */
	const KEEP = 20;

	private static $lines = array();
	private static $dir = null;
	private static $version = 'unknown';
	private static $path = null;
	private static $started_utc = null;

	/**
	 * Begin recording. Safe to call more than once; the first call wins so a
	 * re-entrant include cannot discard lines already collected.
	 *
	 * @param string $log_dir e.g. {site root}/logs/publish
	 */
	public static function start($log_dir, $now_utc = null) {
		if (self::$dir === null) {
			self::$dir = rtrim($log_dir, '/');
			self::$started_utc = $now_utc ?: gmdate('Y-m-d H:i:s');
		}
	}

	/** Record one already-plain-text line. */
	public static function record($line) {
		self::$lines[] = $line;
	}

	/**
	 * Name the version being published. Known only after the request is parsed,
	 * so early exits are filed under 'unknown' rather than not at all.
	 */
	public static function setVersion($version) {
		if ($version !== null && $version !== '') {
			self::$version = $version;
		}
	}

	/** Where this run's log will be written. Stable once resolved. */
	public static function path($stamp = null) {
		if (self::$path !== null) {
			return self::$path;
		}
		if (self::$dir === null) {
			return null;
		}
		self::$path = self::$dir . '/publish-' . self::$version . '-'
			. ($stamp ?: gmdate('Ymd-His')) . '.log';
		return self::$path;
	}

	/**
	 * Write the collected lines. Called from a shutdown handler, so it must not
	 * throw and must tolerate a half-finished run.
	 *
	 * @param array|null $fatal error_get_last() output, if any
	 * @return string|null the path written, or null if there was nothing to write
	 */
	public static function write($fatal = null) {
		if (empty(self::$lines) || self::$dir === null) {
			return null;
		}
		if (!is_dir(self::$dir) && !@mkdir(self::$dir, 0777, true)) {
			return null;
		}
		$path = self::path();
		if ($path === null) {
			return null;
		}

		$body = self::header() . "\n\n" . implode("\n", self::$lines);
		if (is_array($fatal) && isset($fatal['type']) && in_array(
			$fatal['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true
		)) {
			$body .= "\n\nFATAL: " . $fatal['message']
				. ' in ' . $fatal['file'] . ':' . $fatal['line'];
		}

		if (@file_put_contents($path, $body . "\n") === false) {
			return null;
		}
		@chmod($path, 0666);
		self::prune(self::$dir);
		return $path;
	}

	/** Who ran this, when, and how — the context a bare transcript lacks. */
	private static function header() {
		$who = '?';
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
			$pw = posix_getpwuid(posix_geteuid());
			$who = $pw['name'] ?? '?';
		} elseif (function_exists('get_current_user')) {
			$who = get_current_user();
		}
		return 'Publish run ' . self::$started_utc . ' UTC'
			. ' | version ' . self::$version
			. ' | ' . (php_sapi_name() === 'cli' ? 'cli' : 'web')
			. ' as ' . $who;
	}

	/** Keep the newest $keep logs in $dir; remove the rest. */
	public static function prune($dir, $keep = self::KEEP) {
		$files = glob(rtrim($dir, '/') . '/publish-*.log') ?: array();
		if (count($files) <= $keep) {
			return array();
		}
		// Newest first. Same-second writes are ordered by name, which carries
		// the timestamp, so the ordering stays deterministic.
		usort($files, function ($a, $b) {
			$cmp = filemtime($b) <=> filemtime($a);
			return $cmp !== 0 ? $cmp : strcmp($b, $a);
		});
		$removed = array();
		foreach (array_slice($files, $keep) as $stale) {
			if (@unlink($stale)) { $removed[] = $stale; }
		}
		return $removed;
	}

	/** Test seam: forget everything collected so far. */
	public static function reset() {
		self::$lines = array();
		self::$dir = null;
		self::$version = 'unknown';
		self::$path = null;
		self::$started_utc = null;
	}
}

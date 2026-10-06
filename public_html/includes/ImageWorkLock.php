<?php
/**
 * ImageWorkLock - one image decode at a time on a site.
 *
 * Decoding a photo costs its full pixel size in memory: a 24-megapixel JPEG is
 * about 100 MB, and a rotated one briefly twice that. GD allocates that outside
 * PHP's own allocator, so memory_limit does not bound it, and the PHP pool's
 * size says nothing about it either. Two uploads decoding at once were enough
 * to have the kernel kill one inside a 256 MB site container
 * (specs/multi_tenant_docker_hosts.md WP2). Taken around every decode, this
 * keeps a site's photo memory to one image's worth however many workers it
 * runs; the next decode waits its turn, which on a site with one CPU costs no
 * time at all, since two decodes would only share it.
 *
 * The lock is flock() on cache/image_work.lock, so a process that dies holding
 * it (killed for memory, or a fatal error) releases it with its last breath.
 *
 * A wait longer than WAIT_SECONDS gives up on the lock and does the work
 * anyway, logged: the work is never lost (a photo left unrotated, or without
 * its sizes, would be), and a queue that long means something is stuck rather
 * than busy. A lock file that cannot be opened (an unwritable cache/ on a
 * development checkout) is the same: the work runs, once logged.
 *
 * Usage:  $result = ImageWorkLock::run(function () { ... decode, resize, write ... });
 *
 * @version 1.0
 */
class ImageWorkLock {

	/** How long a decode waits for the one before it. */
	const WAIT_SECONDS = 60;

	/** How often a waiting decode looks again, in microseconds. */
	const POLL_USEC = 200000;

	/** The open lock file while this process holds the lock (re-entry runs straight through). */
	private static $held = null;

	/** Where the lock lives, and how long a decode waits; tests change both. */
	private static $path_override = null;
	private static $wait_override = null;

	public static function path(): string {
		return self::$path_override ?? PathHelper::getSiteRoot() . '/cache/image_work.lock';
	}

	/** Test hook: lock a different file. Pass null to restore the site's own. */
	public static function set_path_for_tests(?string $path): void {
		self::$path_override = $path;
	}

	/** Test hook: wait this many seconds instead of WAIT_SECONDS. Pass null to restore it. */
	public static function set_wait_for_tests(?float $seconds): void {
		self::$wait_override = $seconds;
	}

	/**
	 * Run $work holding the site's image lock, and return what it returns.
	 *
	 * @param callable $work the decode and everything that holds the decoded image
	 * @return mixed $work's return value
	 */
	public static function run(callable $work) {
		if (self::$held !== null) {
			return $work();
		}
		$handle = self::open();
		if ($handle === null) {
			return $work();
		}
		$wait = self::$wait_override ?? self::WAIT_SECONDS;
		$deadline = microtime(true) + $wait;
		$locked = flock($handle, LOCK_EX | LOCK_NB);
		while (!$locked && microtime(true) < $deadline) {
			usleep(self::POLL_USEC);
			$locked = flock($handle, LOCK_EX | LOCK_NB);
		}
		if (!$locked) {
			fclose($handle);
			error_log('ImageWorkLock: waited ' . $wait . ' s for ' . self::path()
				. '; decoding without it');
			return $work();
		}
		self::$held = $handle;
		try {
			return $work();
		} finally {
			self::$held = null;
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	private static function open() {
		$path = self::path();
		$created = !file_exists($path);
		$handle = @fopen($path, 'c');
		if ($handle === false) {
			static $logged = false;
			if (!$logged) {
				error_log('ImageWorkLock: cannot open ' . $path . '; image work runs without the one-at-a-time lock');
				$logged = true;
			}
			return null;
		}
		if ($created) {
			// Root's CLI runs and www-data's requests share it.
			@chmod($path, 0666);
		}
		return $handle;
	}
}

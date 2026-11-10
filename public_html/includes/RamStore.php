<?php
/**
 * Shared memory that cannot hold an object: small JSON files in RAM.
 *
 * Every APCu read rebuilds whatever object an entry holds, and runs its
 * startup and cleanup methods before the caller can refuse it, so code that
 * ran once in a worker could plant something that runs again in a member's
 * later request. A JSON file decoded to arrays has no object in it. Every
 * shared-memory reader but the class map (ClassAutoloader, via ApcuStore)
 * belongs here.
 *
 * Layout: one directory per process user and site,
 * /dev/shm/joinery-{euid}-{hash of site root and database}/, mode 0700 and
 * owned by us, on tmpfs (anything else is refused: no store at all, never a
 * fallback to disk). A key is its own filename, so keys() reads a directory
 * and never opens an entry. A value is the file's JSON; its expiry is the
 * file's mtime, set in the future, and touch() moves it without a rewrite.
 *
 * Two kinds of entry. A plain one sits at the top of the directory and
 * outlives a PHP-FPM restart. One written with LIFETIME lives in a
 * subdirectory named for the PHP-FPM master that serves this worker, plus the
 * time its OPcache segment was made (a reload re-execs the master under the
 * same pid and start time; OPcache's start time is set only when the segment
 * is created, and opcache_reset() leaves it alone): after a restart or reload the
 * workers look in a new subdirectory and find nothing, and sweep() removes the
 * old one. A process outside PHP-FPM (cron, the runner, a script) has its own
 * generation named for itself, so it never reads a web window, and the sweep
 * removes it once the process has exited.
 *
 * Every entry is overwritten with zeros before its file is freed, whoever
 * frees it (delete(), an expired read, the sweep, a set() replacing it), so a
 * freed tmpfs page never holds a key. What it cannot stop: a delete racing a
 * set of the same key may remove the newer value (the inode is compared just
 * before unlink, which narrows that to microseconds); and when two set()s of
 * one key race, one replaced value may be freed unzeroed.
 *
 * Specified in strategy/object_free_window_store.md.
 *
 * @version 1.4 - a missing entry, or a directory already there, leaves no suppressed warning behind as the process's last error
 * @version 1.3 - sweep() removes this user's old /dev/shm/vault_window_* markers (strategy/object_free_window_store.md WP4)
 * @version 1.2 - INCLUDE_EXPIRED for keys(), so a wipe by name reaches an expired entry; info(): a live entry's identity and expiry, so a reader can keep what it read
 *                for as long as the file is the same one (VaultUnlock's request memo)
 * @version 1.1 - reviewer2 B1-B8: the generation's fourth part is OPcache's start time (APCu's moves on a
 *                clear or a full expunge); set() zeroes the value it replaces; a half-zeroed read is a
 *                quiet miss; a process whose stat is unreadable is judged by kill(0); a web SAPI other
 *                than PHP-FPM, or OPcache unreadable, is reported by lifetimeProblem() for the health
 *                row (never logged per request); one depth for encode, decode and the object scan;
 *                siteDirectories() for the CLI health row
 * @version 1.0
 */
final class RamStore {

	/** Bound to the PHP-FPM master's lifetime (or, outside PHP-FPM, this process's). */
	const LIFETIME = 1;

	/** keys() only: list expired names too, for a caller that deletes what it finds. */
	const INCLUDE_EXPIRED = 2;

	const KEY_PATTERN = '/^[A-Za-z0-9_~-][A-Za-z0-9:_.,~-]{0,199}$/';

	/** The sweep runs at most this often per site, stamped by the .sweep file. */
	const SWEEP_EVERY = 60;

	/** Nesting allowed in a value: the object scan, json_encode and json_decode agree. */
	const DEPTH = 32;

	/** Tests only: the generation name used for LIFETIME entries, null for the real one. */
	public static $generation_override = null;

	private static $dir_override = null;
	private static $checked = array();
	private static $generation = null;
	private static $logged = array();

	// ---------------------------------------------------------------- entries

	/** The value under $key, or null with $ok false on a miss. */
	public static function get(string $key, &$ok = null, int $flags = 0) {
		$ok = false;
		$path = self::path($key, $flags);
		if ($path === null) {
			return null;
		}
		for ($attempt = 0; ; $attempt++) {
			$fh = @fopen($path, 'rb');
			if ($fh === false) {
				return null;
			}
			$st = fstat($fh);
			if ($st === false || ($st['mode'] & 0170000) !== 0100000) {
				fclose($fh);
				return null;
			}
			if ($st['mtime'] <= time()) {
				fclose($fh);
				clearstatcache(true, $path);
				$now = @lstat($path);
				if ($attempt < 9 && $now !== false && $now['ino'] !== $st['ino']) {
					continue; // a set() replaced it and zeroed this copy (which moves its mtime)
				}
				self::remove($path, $st['ino']);
				return null;
			}
			$raw = stream_get_contents($fh);
			fclose($fh);
			if ($raw !== '' && $raw !== false && $raw[0] !== "\0") {
				break;
			}
			// Empty or zeroed (zeroing starts at offset 0, and no JSON value
			// begins with NUL). If a set() replaced the file meanwhile, the new
			// one is already in place: read that. Otherwise a delete is under
			// way, or a touch() raced one: a miss.
			clearstatcache(true, $path);
			$now = @lstat($path);
			if ($attempt >= 9 || $now === false || $now['ino'] === $st['ino']) {
				return null;
			}
		}
		$value = json_decode($raw, true, self::DEPTH + 1);
		if (json_last_error() !== JSON_ERROR_NONE) {
			self::remove($path, $st['ino']);
			error_log('[RamStore] refused the entry ' . json_encode($key) . ': it is not JSON; it is deleted and reads as a miss');
			return null;
		}
		$ok = true;
		return $value;
	}

	/** Store $value (scalars and arrays only; binary strings must be encoded) for $ttl seconds. */
	public static function set(string $key, $value, int $ttl, int $flags = 0): bool {
		$path = self::path($key, $flags, true);
		if ($path === null || $ttl < 1) {
			return false;
		}
		if (self::holdsObject($value)) {
			error_log('[RamStore] refused to store an object (or a value nested deeper than ' . self::DEPTH . ') under ' . json_encode($key));
			return false;
		}
		$json = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES, self::DEPTH + 1);
		if ($json === false) {
			error_log('[RamStore] could not store ' . json_encode($key) . ': ' . json_last_error_msg());
			return false;
		}
		$tmp = dirname($path) . '/.tmp-' . bin2hex(random_bytes(8));
		$fh = @fopen($tmp, 'xb');
		if ($fh === false) {
			return false;
		}
		@chmod($tmp, 0600);
		$written = fwrite($fh, $json);
		fclose($fh);
		if ($written !== strlen($json) || !@touch($tmp, time() + $ttl)) {
			self::remove($tmp);
			return false;
		}
		// The value this replaces is freed by the rename: hold it open and zero
		// it once the name points at the new one. A reader still holding the old
		// file reads zeros, a miss, as it would have a moment later.
		$old = @fopen($path, 'r+b');
		if (!@rename($tmp, $path)) {
			if ($old !== false) {
				fclose($old);
			}
			self::remove($tmp);
			return false;
		}
		if ($old !== false) {
			$st = fstat($old);
			if ($st !== false && $st['size'] > 0 && $st['nlink'] === 0) {
				fwrite($old, str_repeat("\0", $st['size']));
				fflush($old);
			}
			fclose($old);
		}
		self::maybeSweep();
		return true;
	}

	/** Extend a live entry to $ttl seconds from now. False if there is none. */
	public static function touch(string $key, int $ttl, int $flags = 0): bool {
		$path = self::path($key, $flags);
		if ($path === null || $ttl < 1 || !self::live($path)) {
			return false;
		}
		return @touch($path, time() + $ttl);
	}

	/**
	 * A live entry's identity and expiry, read from its name alone (no open):
	 * ['id' => string, 'expires' => int], or null when there is none. The id
	 * changes whenever set() writes the key again (a new file: tmpfs numbers
	 * inodes from a counter that only goes up), so a caller holding a value it
	 * read can tell whether that value is still the current one. touch() keeps
	 * the id.
	 */
	public static function info(string $key, int $flags = 0): ?array {
		$path = self::path($key, $flags);
		if ($path === null) {
			return null;
		}
		clearstatcache(true, $path);
		$st = @lstat($path);
		if ($st === false || ($st['mode'] & 0170000) !== 0100000 || $st['mtime'] <= time()) {
			return null;
		}
		return array('id' => $st['dev'] . ':' . $st['ino'], 'expires' => (int)$st['mtime']);
	}

	public static function exists(string $key, int $flags = 0): bool {
		$path = self::path($key, $flags);
		return $path !== null && self::live($path);
	}

	public static function delete(string $key, int $flags = 0): bool {
		$path = self::path($key, $flags);
		if ($path === null) {
			return false;
		}
		$removed = self::remove($path);
		self::maybeSweep();
		return $removed;
	}

	/**
	 * The live keys that begin with $prefix, by name. Never opens an entry.
	 * With INCLUDE_EXPIRED, also those past their expiry that no read or sweep
	 * has removed yet: a caller wiping by name zeroes those too.
	 * @return string[]
	 */
	public static function keys(string $prefix, int $flags = 0): array {
		$dir = self::entryDir($flags);
		if ($dir === null || ($prefix !== '' && !preg_match('/^[A-Za-z0-9:_.,~-]{1,200}$/', $prefix))) {
			return array();
		}
		$now = time();
		$out = array();
		foreach (@scandir($dir) ?: array() as $name) {
			if ($name[0] === '.' || strncmp($name, 'gen-', 4) === 0 || strncmp($name, $prefix, strlen($prefix)) !== 0) {
				continue;
			}
			$st = @lstat($dir . '/' . $name);
			if ($st !== false && ($st['mode'] & 0170000) === 0100000
					&& ($st['mtime'] > $now || ($flags & self::INCLUDE_EXPIRED))) {
				$out[] = $name;
			}
		}
		return $out;
	}

	// -------------------------------------------------------------- the sweep

	/**
	 * Remove expired entries, crashed writes, and every generation no process
	 * owns any more. Never creates the store. Returns the files removed.
	 */
	public static function sweep(): int {
		$dir = self::directory();
		if (!is_dir($dir) || self::check($dir, false) !== null) {
			return 0;
		}
		@touch($dir . '/.sweep');
		$removed = self::sweepFiles($dir);
		$removed += self::sweepOldMarkers();
		$mine = self::generation();
		foreach (@scandir($dir) ?: array() as $name) {
			if (strncmp($name, 'gen-', 4) !== 0 || is_link($dir . '/' . $name) || !is_dir($dir . '/' . $name)) {
				continue;
			}
			if ($name !== $mine && self::generationEnded($name, $mine)) {
				// The store makes only regular files in a generation; anything
				// else someone put there keeps the rmdir from succeeding, harmlessly.
				$removed += self::sweepFiles($dir . '/' . $name, true);
				@rmdir($dir . '/' . $name);
			} else {
				$removed += self::sweepFiles($dir . '/' . $name);
			}
		}
		return $removed;
	}

	/**
	 * The open-window markers VaultUnlock kept at the top of /dev/shm before
	 * the store, /dev/shm/vault_window_{uid}_{scope}: empty files, but their
	 * names say which users opened a vault, to anyone who lists /dev/shm.
	 * Nothing reads them; each process user removes its own (the sticky
	 * /dev/shm lets no one else), and a reboot removes the rest.
	 */
	private static function sweepOldMarkers(string $shm = '/dev/shm'): int {
		$euid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
		$n = 0;
		foreach (glob($shm . '/vault_window_*') ?: array() as $path) {
			$st = @lstat($path);
			if ($st === false || $st['uid'] !== $euid || ($st['mode'] & 0170000) !== 0100000
					|| !preg_match('/^vault_window_\d+_[A-Za-z0-9_]+$/', basename($path))) {
				continue;
			}
			if (@unlink($path)) {
				$n++;
			}
		}
		return $n;
	}

	private static function maybeSweep(): void {
		$dir = self::directory();
		$stamp = @filemtime($dir . '/.sweep');
		if ($stamp === false || $stamp <= time() - self::SWEEP_EVERY) {
			self::sweep();
		}
	}

	private static function sweepFiles(string $dir, bool $all = false): int {
		$now = time();
		$n = 0;
		foreach (@scandir($dir) ?: array() as $name) {
			if ($name === '.' || $name === '..' || $name === '.sweep') {
				continue;
			}
			$path = $dir . '/' . $name;
			$st = @lstat($path);
			if ($st === false || ($st['mode'] & 0170000) !== 0100000) {
				continue;
			}
			$stale = strncmp($name, '.tmp-', 5) === 0 ? $st['ctime'] <= $now - 60 : $st['mtime'] <= $now;
			if (($all || $stale) && self::remove($path, $all ? null : $st['ino'])) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Whether the process a generation is named for is gone. A web generation
	 * of this worker's own master with another OPcache segment ended at a reload.
	 */
	private static function generationEnded(string $name, string $mine): bool {
		if (!preg_match('/^gen-([0-9a-f]{12})-(\d+)-(\d+)-(c|\d+)$/', $name, $m)) {
			return false; // not ours to judge
		}
		$parts = explode('-', $mine);
		if ($m[1] !== self::bootId()) {
			return true;
		}
		$start = self::procStartTime((int)$m[2]);
		if ($start === null) {
			// No stat to read: gone, or a process we may not inspect (hidepid).
			// kill(0) tells them apart; EPERM means it is alive, and the
			// segment rule below still decides a reload.
			if (!self::processExists((int)$m[2])) {
				return true;
			}
		} elseif ($m[3] !== '0' && $start !== $m[3]) {
			return true; // the pid now belongs to another process
		}
		return $m[4] !== 'c' && ($parts[4] ?? 'c') !== 'c' && $m[2] === $parts[2] && $m[4] !== $parts[4];
	}

	// ------------------------------------------------------------ generations

	/** The subdirectory name for LIFETIME entries in this process. */
	public static function generation(): string {
		if (self::$generation_override !== null) {
			return self::$generation_override;
		}
		if (self::$generation === null) {
			$boot = self::bootId();
			if (PHP_SAPI === 'fpm-fcgi' && function_exists('posix_getppid')) {
				$master = posix_getppid();
				$segment = 0;
				$status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
				if (is_array($status)) {
					$segment = (int)($status['opcache_statistics']['start_time'] ?? 0);
				}
				self::$generation = 'gen-' . $boot . '-' . $master . '-' . (self::procStartTime($master) ?? '0') . '-' . $segment;
			} else {
				$me = getmypid();
				self::$generation = 'gen-' . $boot . '-' . $me . '-' . (self::procStartTime($me) ?? '0') . '-c';
			}
		}
		return self::$generation;
	}

	private static function processExists(int $pid): bool {
		if ($pid < 1 || !function_exists('posix_kill')) {
			return false;
		}
		return posix_kill($pid, 0) || posix_get_last_error() === 1; // EPERM
	}

	/**
	 * Why lifetime-bound entries cannot follow this web process's PHP-FPM
	 * master, or null when they can (and in the CLI, which has none). Read by
	 * the VaultHealth row, which says it once; a log line here would repeat on
	 * every request.
	 */
	public static function lifetimeProblem(): ?string {
		if (in_array(PHP_SAPI, array('cli', 'phpdbg'), true)) {
			return null;
		}
		if (PHP_SAPI !== 'fpm-fcgi') {
			return 'the web SAPI is ' . PHP_SAPI . ', not PHP-FPM: each web process keeps its own vault windows, so none lasts past one process';
		}
		if (substr(self::generation(), -2) === '-0') {
			return 'OPcache\'s start time is not readable (OPcache off, or opcache.restrict_api set): a PHP-FPM reload does not end vault windows, only a restart does';
		}
		return null;
	}

	/** Field 22 of /proc/{pid}/stat (start time in clock ticks since boot), or null. */
	public static function procStartTime(int $pid, string $proc = '/proc'): ?string {
		$stat = $pid > 0 ? @file_get_contents($proc . '/' . $pid . '/stat') : false;
		if (!is_string($stat) || ($close = strrpos($stat, ')')) === false) {
			return null;
		}
		$fields = preg_split('/\s+/', trim(substr($stat, $close + 1)));
		return isset($fields[19]) && ctype_digit($fields[19]) ? $fields[19] : null;
	}

	private static function bootId(): string {
		static $boot = null;
		if ($boot === null) {
			$raw = strtolower(str_replace('-', '', trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id'))));
			$boot = preg_match('/^[0-9a-f]{12}/', $raw) ? substr($raw, 0, 12) : '000000000000';
		}
		return $boot;
	}

	// -------------------------------------------------------------- the place

	/** The store directory this process uses. */
	public static function directory(): string {
		return self::$dir_override ?? self::defaultDirectory();
	}

	/** The site's own directory for this process user, whatever a test named. */
	public static function defaultDirectory(): string {
		$euid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
		return '/dev/shm/joinery-' . $euid . '-' . self::siteHash();
	}

	/**
	 * Every user's directory for this site that exists, as [path => the uid
	 * its name claims]: what the CLI health row checks, since it does not run
	 * as the web user.
	 */
	public static function siteDirectories(): array {
		$out = array();
		$hash = self::siteHash();
		foreach (glob('/dev/shm/joinery-*-' . $hash, GLOB_NOSORT) ?: array() as $path) {
			if (preg_match('#^/dev/shm/joinery-(\d+)-' . $hash . '$#', $path, $m)) {
				$out[$path] = (int)$m[1];
			}
		}
		return $out;
	}

	private static function siteHash(): string {
		$db = (string)Globalvars::get_instance()->get_setting('dbname');
		return substr(hash('sha256', PathHelper::getSiteRoot() . "\0" . $db), 0, 16);
	}

	/** Tests: use $dir (it must pass the same checks), or null for the default. */
	public static function useDirectory(?string $dir): void {
		self::$dir_override = $dir === null ? null : rtrim($dir, '/');
	}

	/**
	 * Whether the store is usable here, for VaultHealth: ['ok' => bool,
	 * 'dir' => string, 'reason' => ?string]. With $create false a directory not
	 * made yet is judged by its filesystem alone.
	 */
	public static function status(bool $create = true): array {
		$dir = self::directory();
		$reason = self::check($dir, $create);
		return array('ok' => $reason === null, 'dir' => $dir, 'reason' => $reason);
	}

	/**
	 * Why $dir cannot hold the store, or null when it can. It must be on tmpfs,
	 * a real directory (not a link), owned by $euid, and mode 0700: /dev/shm is
	 * world-writable, so another account could have made it first.
	 */
	public static function checkDirectory(string $dir, ?int $euid = null, string $mounts = '/proc/mounts'): ?string {
		$euid = $euid ?? (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
		$type = self::mountType($dir, $mounts);
		if ($type !== 'tmpfs') {
			return $dir . ' is on ' . ($type ?? 'an unknown filesystem') . ', not tmpfs: a key could reach a disk';
		}
		clearstatcache(true, $dir);
		$st = @lstat($dir);
		if ($st === false) {
			return $dir . ' does not exist';
		}
		if (($st['mode'] & 0170000) === 0120000) {
			return $dir . ' is a symbolic link';
		}
		if (($st['mode'] & 0170000) !== 0040000) {
			return $dir . ' is not a directory';
		}
		if ($st['uid'] !== $euid) {
			return $dir . ' is owned by uid ' . $st['uid'] . ', not ' . $euid;
		}
		if (($st['mode'] & 0777) !== 0700) {
			return $dir . ' has mode ' . sprintf('%04o', $st['mode'] & 0777) . ', not 0700';
		}
		return null;
	}

	/** The filesystem type of the mount holding $path, from /proc/mounts. */
	public static function mountType(string $path, string $mounts = '/proc/mounts'): ?string {
		$best = null;
		$type = null;
		foreach (@file($mounts, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
			$f = explode(' ', $line);
			if (count($f) < 3) {
				continue;
			}
			$point = str_replace('\040', ' ', $f[1]);
			$inside = $point === '/' || $path === $point || strncmp($path, rtrim($point, '/') . '/', strlen(rtrim($point, '/')) + 1) === 0;
			if ($inside && ($best === null || strlen($point) >= strlen($best))) {
				$best = $point;
				$type = $f[2];
			}
		}
		return $type;
	}

	/** null when usable; a refusal is logged once per process and directory. */
	private static function check(string $dir, bool $create): ?string {
		if (array_key_exists($dir, self::$checked)) {
			return self::$checked[$dir];
		}
		if (!$create && !file_exists($dir) && !is_link($dir)) {
			$type = self::mountType($dir);
			return $type === 'tmpfs' ? null : $dir . ' is on ' . ($type ?? 'an unknown filesystem') . ', not tmpfs: a key could reach a disk';
		}
		if (self::mountType($dir) === 'tmpfs' && !file_exists($dir) && !is_link($dir)) {
			@mkdir($dir, 0700);
		}
		$reason = self::checkDirectory($dir);
		self::$checked[$dir] = $reason;
		if ($reason !== null && empty(self::$logged[$dir])) {
			self::$logged[$dir] = true;
			error_log('[RamStore] refused: ' . $reason . '; no shared store in this process');
		}
		return $reason;
	}

	private static function entryDir(int $flags, bool $create = false): ?string {
		$dir = self::directory();
		if ($create && !is_dir($dir)) {
			unset(self::$checked[$dir]); // removed since it was checked: make and check it again
		}
		if (self::check($dir, true) !== null) {
			return null;
		}
		if (!($flags & self::LIFETIME)) {
			return $dir;
		}
		$gen = $dir . '/' . self::generation();
		if ($create && !is_dir($gen)) {
			@mkdir($gen, 0700);
		}
		return $gen;
	}

	private static function path(string $key, int $flags, bool $create = false): ?string {
		if (!preg_match(self::KEY_PATTERN, $key) || strncmp($key, 'gen-', 4) === 0) {
			error_log('[RamStore] refused the key ' . json_encode($key));
			return null;
		}
		$dir = self::entryDir($flags, $create);
		return $dir === null ? null : $dir . '/' . $key;
	}

	private static function live(string $path): bool {
		clearstatcache(true, $path);
		// Asked first without a warning: a suppressed lstat() failure still
		// becomes the process's last error, and whatever reports a crash
		// later (the test harness) would name it as the cause.
		if (!file_exists($path) && !is_link($path)) {
			return false;
		}
		$st = @lstat($path);
		return $st !== false && ($st['mode'] & 0170000) === 0100000 && $st['mtime'] > time();
	}

	/**
	 * Overwrite a file with zeros, then unlink it, unless $ino is given and the
	 * name has since moved to another file (a set() that just replaced it).
	 */
	private static function remove(string $path, ?int $ino = null): bool {
		$fh = @fopen($path, 'r+b');
		if ($fh === false) {
			return @unlink($path) || !file_exists($path);
		}
		$st = fstat($fh);
		if ($st === false || ($ino !== null && $st['ino'] !== $ino)) {
			fclose($fh);
			return false;
		}
		if ($st['size'] > 0) {
			fwrite($fh, str_repeat("\0", $st['size']));
			fflush($fh);
		}
		fclose($fh);
		clearstatcache(true, $path);
		$now = @lstat($path);
		if ($now === false || $now['ino'] !== $st['ino']) {
			return $now === false;
		}
		return @unlink($path);
	}

	private static function holdsObject($value, int $depth = 0): bool {
		if (is_object($value) || is_resource($value)) {
			return true;
		}
		if (is_array($value)) {
			if ($depth >= self::DEPTH) {
				return true; // deeper than json_decode will read back
			}
			foreach ($value as $item) {
				if (self::holdsObject($item, $depth + 1)) {
					return true;
				}
			}
		}
		return false;
	}
}

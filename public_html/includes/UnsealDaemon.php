<?php
/**
 * UnsealDaemon - what this site knows about its unseal daemon
 * (specs/unseal_daemon.md § Installing and supervising, § When the daemon is
 * missing or down).
 *
 * Everything here is read from what root left behind, and nothing here can
 * change it: the install record install_unseal_daemon.sh writes
 * (/etc/joinery-unseal/KEY/installed), the launcher's state
 * (/var/lib/joinery-unseal/KEY/: which build runs, the event log), and the
 * hold file (/run/joinery-unseal/KEY.hold, in a directory the web user cannot
 * write). facts() adds the daemon's own answer to status, asked over the
 * socket. VaultHealth's row and the admin notice read facts().
 *
 * KEY is the basename of the site root, made safe for a path, exactly as the
 * installer derives it.
 *
 * @version 1.2 - facts(): down_since, when the site's unlocks first found the daemon silent
 * @version 1.1 - refused(): the reason an installer left for not installing (a container that cannot
 *                lock memory, reviewer2 B8); the process table is read only when status was not asked (B10)
 * @version 1.0
 */
class UnsealDaemon {

	const CONF_ROOT  = '/etc/joinery-unseal';
	const STATE_ROOT = '/var/lib/joinery-unseal';
	const RUN_ROOT   = '/run/joinery-unseal';

	/** @var string|null test seam: a directory standing in for / */
	public static $root_override = null;

	/** This site's key: the installer's tr -c 'A-Za-z0-9._-' '_' of the root's basename, 64 at most. */
	public static function key(?string $site_root = null): string {
		$base = basename(rtrim($site_root ?? PathHelper::getSiteRoot(), '/'));
		return substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $base), 0, 64);
	}

	private static function path(string $p): string {
		return (self::$root_override !== null ? rtrim(self::$root_override, '/') : '') . $p;
	}

	/**
	 * The install record, or null where the installer has not run (or a
	 * record is malformed): ['socket' => string, 'allow_uid' => int,
	 * 'pool_user' => string, 'init' => string].
	 */
	public static function installRecord(?string $key = null): ?array {
		$key = $key ?? self::key();
		$file = self::path(self::CONF_ROOT . '/' . $key . '/installed');
		$body = @file_get_contents($file);
		if ($body === false) {
			return null;
		}
		$rec = array();
		foreach (preg_split('/\R/', $body) as $line) {
			if (preg_match('/^(socket|allow_uid|pool_user|init)=(.*)$/', $line, $m)) {
				$rec[$m[1]] = $m[2];
			}
		}
		if (($rec['socket'] ?? '') !== self::RUN_ROOT . '/' . $key . '/unseal.sock'
				|| !ctype_digit((string)($rec['allow_uid'] ?? ''))) {
			return null;
		}
		return array('socket' => self::path($rec['socket']), 'allow_uid' => (int)$rec['allow_uid'],
			'pool_user' => (string)($rec['pool_user'] ?? ''), 'init' => (string)($rec['init'] ?? ''));
	}

	/**
	 * Why the installer did not install here, when it said: 'memlock' for a
	 * site container whose memory-lock limit cannot be raised. Null otherwise.
	 */
	public static function refused(?string $key = null): ?string {
		$body = @file_get_contents(self::path(self::CONF_ROOT . '/' . ($key ?? self::key()) . '/refused'));
		if ($body !== false && preg_match('/^reason=(memlock)$/m', $body, $m)) {
			return $m[1];
		}
		return null;
	}

	/** Whether root has put the sentinel's hold on this site's daemon. */
	public static function held(?string $key = null): bool {
		return file_exists(self::path(self::RUN_ROOT . '/' . ($key ?? self::key()) . '.hold'));
	}

	/**
	 * Every fact the health row and the notice read:
	 *   installed      the install record exists
 *   refused        why the installer did not install, when it said (see refused())
	 *   socket         its socket path
	 *   binary         'current' or 'previous' (the launcher moved it after three crashes)
	 *   held           the hold file exists
	 *   crashes_hour   crash events in the last hour
	 *   running        a process serving this socket is in the process table
 *   asked          status was asked: only the pool user can ask (a shell as root or
 *                  another user cannot), so a check run elsewhere reads `running` alone
 *   answering      the daemon answered status (null when not asked)
	 *   memory_locked  as the daemon reports it
	 *   daemon         its version
	 *   error          why it did not answer
	 *   down_since     when this site's unlocks first found it silent (VaultUnlock), when not answering
	 */
	public static function facts(bool $ask = true, float $timeout = 0.5): array {
		$key = self::key();
		$rec = self::installRecord($key);
		$facts = array('installed' => $rec !== null, 'refused' => $rec === null ? self::refused($key) : null, 'socket' => $rec['socket'] ?? null, 'binary' => 'current',
			'held' => self::held($key), 'crashes_hour' => 0, 'running' => null, 'asked' => false, 'answering' => null,
			'memory_locked' => null, 'daemon' => null, 'error' => null, 'down_since' => null);
		if ($rec === null) {
			return $facts;
		}
		$state = self::path(self::STATE_ROOT . '/' . $key);
		if (trim((string)@file_get_contents($state . '/binary')) === 'previous') {
			$facts['binary'] = 'previous';
		}
		$since = time() - 3600;
		foreach (@file($state . '/events', FILE_IGNORE_NEW_LINES) ?: array() as $line) {
			$e = json_decode($line, true);
			if (is_array($e) && ($e['event'] ?? '') === 'crash' && (int)($e['time'] ?? 0) >= $since) {
				$facts['crashes_hour']++;
			}
		}
		$euid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
		if (!$ask || $euid !== $rec['allow_uid']) {
			// Only read when the daemon cannot be asked: a scan of every process.
			$facts['running'] = self::running($rec['socket']);
		}
		if ($ask && $euid === $rec['allow_uid']) {
			$facts['asked'] = true;
			$reply = (new UnsealClient($rec['socket'], $timeout))->status();
			$f = $reply['fields'];
			$facts['answering'] = ($f['ok'] ?? false) === true;
			if ($facts['answering']) {
				$facts['memory_locked'] = ($f['memory_locked'] ?? false) === true;
				$facts['daemon'] = (string)($f['daemon'] ?? '');
				$facts['held'] = $facts['held'] || (($f['held'] ?? false) === true);
			} else {
				$facts['error'] = (string)($f['error'] ?? 'unknown');
				$facts['down_since'] = VaultUnlock::daemonDownSince();
			}
		}
		return $facts;
	}

	/**
	 * Whether a process serving $socket is running: its command line names
	 * `--socket $socket`. A daemon's command line is readable by anyone (it
	 * is not dumpable, which hides its memory, not its arguments).
	 */
	public static function running(string $socket): bool {
		$want = "--socket\0" . (self::$root_override !== null ? substr($socket, strlen(rtrim(self::$root_override, '/'))) : $socket) . "\0";
		foreach (glob('/proc/[0-9]*/cmdline') ?: array() as $f) {
			$cmd = @file_get_contents($f);
			if (is_string($cmd) && strpos($cmd . "\0", $want) !== false) {
				return true;
			}
		}
		return false;
	}

	/** The one command that installs it on this machine. */
	public static function installCommand(): string {
		return 'sudo bash ' . PathHelper::getSiteRoot() . '/maintenance_scripts/install_tools/install_unseal_daemon.sh';
	}
}
?>

<?php
/**
 * HostRestrictions - what the machine this site runs on refuses to let it do
 * (specs/host_restrictions.md).
 *
 * Some machines host sites for people other than their operator: on a
 * multi-tenant host each site's superadmin is a customer. A rule that limits
 * that superadmin cannot be a setting (they can change it) or a row in the
 * site's database (it arrives with a backup they made). It is a file the HOST
 * owns:
 *
 *   /etc/joinery/host_restrictions/restrictions
 *
 * one `name=value` per line, `#` comments. On a Docker host the directory is
 * bind-mounted read-only into every site container at the same path
 * (_site_run_spec.sh); on bare metal it is a plain root-owned directory. The
 * same path is read in both shapes. install_tools/host_restrictions.sh writes
 * it; nothing on the site side ever does.
 *
 * FAIL CLOSED. No file: no restrictions (a single-tenant box, dev). A file read
 * cleanly: exactly the restrictions it names. A file present but unreadable,
 * with a line that does not parse or a value its name does not allow, or one
 * that someone other than root could have written: EVERY known restriction is
 * on, and refusal() says why. The owner may be uid 0 or the kernel's overflow
 * uid: on a host that remaps user ids, a file the host's root owns shows inside
 * the container as the overflow uid, and nothing in the container can write it.
 *
 * It reads the file and nothing else - no database, no session - so root's
 * installer, root's shell scripts (utils/host_restrictions.php) and the web
 * pages ask the same question the same way.
 *
 * Adding a restriction: a name and its allowed values in KNOWN, the same name in
 * install_tools/host_restrictions.sh's list (a test compares the two), and one
 * has() check where it bites.
 *
 * @version 1.0
 */
class HostRestrictions {

	/** The host's file. */
	const PATH = '/etc/joinery/host_restrictions/restrictions';

	/** Every restriction there is, and the values it may take. */
	const KNOWN = array(
		// Refuse every plugin or page theme that is not exactly what we
		// shipped, acknowledged or not (style themes, which run nothing, excepted).
		'unsigned_packages' => array('refuse'),
	);

	/** The sentence an unsigned package's refusal shows, page and shell alike. */
	const UNSIGNED_SENTENCE = 'This host installs only signed plugins and themes.';

	/**
	 * A test points the reader at a fixture. Only an unprivileged process may:
	 * root always reads the host's file. Through the environment
	 * (JOINERY_HOST_RESTRICTIONS_FILE) for a subprocess, or useFile() in-process.
	 */
	const ENV_FILE = 'JOINERY_HOST_RESTRICTIONS_FILE';

	/** @var string|null A fixture path set by useFile() */
	private static $override = null;

	/** @var array|null The parsed answer: ['on' => name => value, 'refusal' => string] */
	private static $cache = null;

	/** Read a fixture instead of the host's file, or the host's again with null. Tests only. */
	public static function useFile(?string $path): void {
		self::$override = $path;
		self::$cache = null;
	}

	/** The file this process reads. */
	public static function path(): string {
		if (!self::privileged()) {
			if (self::$override !== null) {
				return self::$override;
			}
			$env = getenv(self::ENV_FILE);
			if (is_string($env) && $env !== '') {
				return $env;
			}
		}
		return self::PATH;
	}

	/** Is this restriction on? */
	public static function has(string $name): bool {
		return isset(self::read()['on'][$name]);
	}

	/** Every restriction that is on: name => value. */
	public static function all(): array {
		return self::read()['on'];
	}

	/**
	 * Why the file could not be read as written - in which case every known
	 * restriction is on - or '' when it was read cleanly or is absent.
	 */
	public static function refusal(): string {
		return self::read()['refusal'];
	}

	/** The refusal for an unsigned package: the sentence, the file, and why when it was not read cleanly. */
	public static function unsignedRefusal(): string {
		$why = self::refusal();
		return rtrim(self::UNSIGNED_SENTENCE, '.') . ' (' . self::path() . ($why !== '' ? ': ' . $why : '') . ').';
	}

	private static function privileged(): bool {
		return function_exists('posix_geteuid') && posix_geteuid() === 0;
	}

	private static function read(): array {
		if (self::$cache !== null) {
			return self::$cache;
		}
		$path = self::path();
		$dir = dirname($path);
		clearstatcache();
		if (!file_exists($path) && !is_link($path)) {
			if (is_dir($dir) && $path === self::PATH) {
				return self::$cache = self::allOn('the directory ' . $dir . ' is there but its restrictions file is not');
			}
			return self::$cache = array('on' => array(), 'refusal' => '');
		}
		foreach (array($dir, $path) as $p) {
			$why = self::ownerRefusal($p);
			if ($why !== '') {
				return self::$cache = self::allOn($why);
			}
		}
		if (is_link($path) || !is_file($path)) {
			return self::$cache = self::allOn($path . ' is not a plain file');
		}
		$body = @file_get_contents($path, false, null, 0, 65536);
		if ($body === false) {
			return self::$cache = self::allOn($path . ' could not be read');
		}
		$on = array();
		foreach (preg_split('/\r\n|\r|\n/', $body) as $n => $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#') {
				continue;
			}
			if (!preg_match('/^([a-z][a-z0-9_]*)\s*=\s*(\S+)$/', $line, $m)) {
				return self::$cache = self::allOn('line ' . ($n + 1) . ' is not name=value');
			}
			if (!isset(self::KNOWN[$m[1]])) {
				// A name this release does not know limits nothing here; a newer
				// release may. install_tools/host_restrictions.sh never writes one.
				continue;
			}
			if (!in_array($m[2], self::KNOWN[$m[1]], true)) {
				return self::$cache = self::allOn('line ' . ($n + 1) . ' gives ' . $m[1] . ' a value it does not take (' . $m[2] . ')');
			}
			$on[$m[1]] = $m[2];
		}
		return self::$cache = array('on' => $on, 'refusal' => '');
	}

	/** Every known restriction on, with the reason. */
	private static function allOn(string $why): array {
		$on = array();
		foreach (self::KNOWN as $name => $values) {
			$on[$name] = $values[0];
		}
		return array('on' => $on, 'refusal' => $why . ', so every restriction is on');
	}

	/**
	 * '' when $p is root's alone; otherwise why not. Root here is uid 0, the
	 * kernel's overflow uid (the host's root, seen from a container that remaps
	 * ids), or - for a fixture an unprivileged test points at - this process.
	 */
	private static function ownerRefusal(string $p): string {
		$owner = @fileowner($p);
		$mode = @fileperms($p);
		if ($owner === false || $mode === false) {
			return 'the owner of ' . $p . ' could not be read';
		}
		$allowed = array(0, self::overflowUid());
		if (!self::privileged() && $p !== self::PATH && $p !== dirname(self::PATH) && function_exists('posix_geteuid')) {
			$allowed[] = posix_geteuid();
		}
		if (!in_array($owner, $allowed, true)) {
			return $p . ' is owned by uid ' . $owner . ', not root';
		}
		if (($mode & 0022) !== 0) {
			return $p . ' can be written by others (mode ' . substr(sprintf('%o', $mode), -4) . ')';
		}
		return '';
	}

	private static function overflowUid(): int {
		$v = trim((string)@file_get_contents('/proc/sys/kernel/overflowuid'));
		return ctype_digit($v) ? (int)$v : 65534;
	}
}

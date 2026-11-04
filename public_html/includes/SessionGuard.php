<?php
/**
 * The session files never turn into objects of a class the session did not
 * put there.
 *
 * The web user writes /var/lib/php/sessions, and PHP's session decoder builds
 * every object a session file names, with every class allowed, before any
 * code of ours runs. So code that ran once in a worker could plant an object
 * in a member's session file, and its magic methods would run in that
 * member's next request, the vault unlock included, with nothing on disk to
 * see (specs/unseal_daemon.md C4). A planted object of a class whose file
 * loads Composer would also switch on the whole vendor tree mid-decode.
 *
 * GuardedSessionHandler reads the file through PHP's own handler, then lists
 * the classes it names with a parser that builds nothing (classesIn()). A
 * session naming any class outside ALLOWED_CLASSES, or that does not parse,
 * is never decoded: the request starts an empty session, so the member signs
 * in again, and the refusal is logged with the class names.
 *
 * Every session in the platform starts through SessionGuard::start(); a test
 * fails on a bare session_start() in production code
 * (tests/security/stored_object_guards_test.php).
 *
 * @version 1.0
 */
final class SessionGuard {

	/** The only objects a session holds: the flash messages (SessionControl::save_message()). */
	const ALLOWED_CLASSES = array('DisplayMessage');

	private static $installed = false;

	/**
	 * Start the session with the guard installed. Takes session_start()'s
	 * options and returns what it returns.
	 */
	public static function start(array $options = array()): bool {
		self::install();
		return session_start($options);
	}

	/** Put the guarded handler in front of PHP's own, once per process. */
	public static function install(): void {
		if (self::$installed || session_status() === PHP_SESSION_ACTIVE) {
			return;
		}
		if (!in_array(ini_get('session.serialize_handler'), array('php', 'php_serialize'), true)) {
			// classesIn() reads those two formats only; a box on another one
			// keeps PHP's plain decode, and says so.
			error_log('[SessionGuard] session.serialize_handler is ' . ini_get('session.serialize_handler') . '; sessions are not guarded');
			self::$installed = true;
			return;
		}
		session_set_save_handler(new GuardedSessionHandler(), true);
		self::$installed = true;
	}

	/**
	 * Whether a stored session may be decoded: it parses, and every class it
	 * names is allowed. $why says what was wrong.
	 */
	public static function mayDecode(string $data, ?string &$why = null): bool {
		$classes = self::classesIn($data);
		if ($classes === null) {
			$why = 'it does not parse as a session';
			return false;
		}
		$foreign = array_values(array_diff($classes, self::ALLOWED_CLASSES));
		if ($foreign !== array()) {
			$why = 'it names ' . implode(', ', array_unique($foreign));
			return false;
		}
		return true;
	}

	/**
	 * Every class name a session string names (PHP's 'php' session format,
	 * name|value..., or 'php_serialize', one serialized array; $format
	 * defaults to session.serialize_handler), in objects (O:), custom-serialized objects (C:)
	 * and enums (E:), or null when it does not parse. Reads the bytes only.
	 */
	public static function classesIn(string $data, ?string $format = null): ?array {
		$classes = array();
		$pos = 0;
		$len = strlen($data);
		if (($format ?? ini_get('session.serialize_handler')) === 'php_serialize') {
			// One serialized array holds the whole session.
			return self::value($data, $pos, $classes, 0) && $pos === $len ? $classes : null;
		}
		while ($pos < $len) {
			$bar = strpos($data, '|', $pos);
			if ($bar === false) {
				return null;
			}
			$name = substr($data, $pos, $bar - $pos);
			$pos = $bar + 1;
			if ($name !== '' && $name[0] === '!') {
				continue;   // an undefined variable carries no value
			}
			if (!self::value($data, $pos, $classes, 0)) {
				return null;
			}
		}
		return $classes;
	}

	/** Walk one serialized value at $pos, advancing past it. */
	private static function value(string $d, int &$pos, array &$classes, int $depth): bool {
		if ($depth > 64 || $pos >= strlen($d)) {
			return false;
		}
		$type = $d[$pos];
		switch ($type) {
			case 'N':
				return self::expect($d, $pos, 'N;');
			case 'b':
				return self::scalar($d, $pos, '/\Gb:[01];/');
			case 'i':
				return self::scalar($d, $pos, '/\Gi:[+-]?\d+;/');
			case 'd':
				return self::scalar($d, $pos, '/\Gd:(?:[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?|INF|-INF|NAN);/');
			case 'r':
			case 'R':
				return self::scalar($d, $pos, '/\G[rR]:\d+;/');
			case 's':
				return self::string($d, $pos, 's') !== null && self::expect($d, $pos, ';');
			case 'E':
				$enum = self::string($d, $pos, 'E');
				if ($enum === null || !self::expect($d, $pos, ';')) return false;
				$classes[] = strstr($enum, ':', true) ?: $enum;
				return true;
			case 'a':
				if (!preg_match('/\Ga:(\d+):\{/', $d, $m, 0, $pos)) return false;
				$pos += strlen($m[0]);
				return self::members($d, $pos, (int)$m[1], $classes, $depth);
			case 'O':
				$class = self::string($d, $pos, 'O');
				if ($class === null || !preg_match('/\G:(\d+):\{/', $d, $m, 0, $pos)) return false;
				$classes[] = $class;
				$pos += strlen($m[0]);
				return self::members($d, $pos, (int)$m[1], $classes, $depth);
			case 'C':
				$class = self::string($d, $pos, 'C');
				if ($class === null || !preg_match('/\G:(\d+):\{/', $d, $m, 0, $pos)) return false;
				$classes[] = $class;
				$pos += strlen($m[0]) + (int)$m[1];
				return self::expect($d, $pos, '}');
			default:
				return false;   // S: and anything else is refused
		}
	}

	/** n key/value pairs, then the closing brace. */
	private static function members(string $d, int &$pos, int $n, array &$classes, int $depth): bool {
		for ($i = 0; $i < $n; $i++) {
			if (!self::value($d, $pos, $classes, $depth + 1) || !self::value($d, $pos, $classes, $depth + 1)) {
				return false;
			}
		}
		return self::expect($d, $pos, '}');
	}

	/** T:len:"bytes" at $pos; returns the bytes and leaves $pos after the closing quote. */
	private static function string(string $d, int &$pos, string $type): ?string {
		if (!preg_match('/\G' . $type . ':(\d+):"/', $d, $m, 0, $pos)) return null;
		$start = $pos + strlen($m[0]);
		$n = (int)$m[1];
		if ($start + $n + 1 > strlen($d) || $d[$start + $n] !== '"') return null;
		$pos = $start + $n + 1;
		return substr($d, $start, $n);
	}

	private static function scalar(string $d, int &$pos, string $pattern): bool {
		if (!preg_match($pattern, $d, $m, 0, $pos)) return false;
		$pos += strlen($m[0]);
		return true;
	}

	private static function expect(string $d, int &$pos, string $token): bool {
		if (substr($d, $pos, strlen($token)) !== $token) return false;
		$pos += strlen($token);
		return true;
	}
}

/**
 * PHP's own session handler (files on every box), with each read checked by
 * SessionGuard::mayDecode() before PHP decodes it.
 */
class GuardedSessionHandler extends SessionHandler {
	#[\ReturnTypeWillChange]
	public function read($id) {
		$data = parent::read($id);
		if (!is_string($data) || $data === '') {
			return $data;
		}
		if (!SessionGuard::mayDecode($data, $why)) {
			error_log('[SessionGuard] refused a stored session (' . substr(hash('sha256', (string)$id), 0, 12) . '): ' . $why . '; it starts empty');
			return '';
		}
		return $data;
	}
}

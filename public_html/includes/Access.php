<?php
/**
 * Who may do what: the one decision every permission check makes
 * (spec declared_permissions 3.6).
 *
 * Levels are a ladder from 0 (a signed-in member) to 10: each includes the one
 * below. Code declares what its data is, a class; `permissions.json` at the
 * public_html root names the levels and gives each class the level its four
 * verbs need. The file is read at runtime and is the only place a class's
 * numbers live.
 *
 * A requirement is one of:
 *
 *   8                  a level: work with no row behind it (restart, send, publish)
 *   'personal:write'   a class and a verb: read, write, soft_delete, permanent_delete
 *
 * A malformed requirement throws: a typo never reads as level 0.
 *
 * decide() compares a requirement with the session's level, the level the
 * request was authorized at (SessionControl::get_permission(); for an API call,
 * the acting level set_api_user() simulated), never the user's stored one.
 * allows() is the same comparison for a caller that already holds that level.
 * demand() is decide() for an action: it throws the page's 401 refusal.
 * permits() asks it of the session without recording anything: for what a page
 * shows (a column, a button, deleted rows in a list) or a choice that refuses
 * nothing; a refusal is decide() or demand(), so it is recorded.
 * staffLevel() is the lowest level above a member's.
 *
 * decide() is where the audit lives. Each of these lands in the event log
 * (evl_event_logs) with who, what, the level asked for and the request:
 *
 *   access_refused   a signed-in user refused (an anonymous refusal is a sign-in
 *                    page, not an event, and is not recorded)
 *   access_granted   allowed work that needs 9 or above, and any allowed use
 *                    of the keys class, reads included
 *
 * The model write paths (SystemBase::enforce_access(), spec 3.2) report here
 * too, through writeRefused() and writeGranted(). A refused write is an
 * access_refused event, a [WRITE_REFUSED] line naming the code that wrote, and
 * SystemAuthenticationError.
 *
 * @version 1.3
 */
final class Access {

	const VERBS = array('read', 'write', 'soft_delete', 'permanent_delete');

	/** Allowed work at or above this level is recorded. */
	const AUDIT_LEVEL = 9;

	/**
	 * The test harness sets this: on the CLI, a write made while a person is
	 * signed in (a suite's simulated session) is checked as a web request's
	 * would be. A real CLI run (cron, root, update_database) has no one signed
	 * in and stays exempt.
	 */
	public static $check_cli_persons = false;

	/** The SAPI the write-path check sees; CLI is exempt. A test stands in a web one. */
	public static $sapi = PHP_SAPI;

	/** @var array|null ['levels' => int => name, 'classes' => name => verb => int] */
	private static $table = null;

	/** The class table, read from permissions.json once per request. */
	public static function table(): array {
		if (self::$table !== null) return self::$table;
		$path = PathHelper::getIncludePath('permissions.json');
		if (!is_readable($path)) {
			throw new RuntimeException("permissions.json: missing or unreadable at $path");
		}
		$data = json_decode((string)file_get_contents($path), true);
		$errors = self::schemaErrors($data);
		if ($errors) {
			throw new RuntimeException('permissions.json: ' . implode('; ', $errors));
		}
		$levels = array();
		foreach ($data['levels'] as $level => $name) {
			$levels[(int)$level] = (string)$name;
		}
		ksort($levels);
		self::$table = array('levels' => $levels, 'classes' => $data['classes']);
		return self::$table;
	}

	/** Level => name, lowest first. */
	public static function levels(): array {
		return self::table()['levels'];
	}

	/** The class names. */
	public static function classes(): array {
		return array_keys(self::table()['classes']);
	}

	/** A level's name, or the number when the table does not name it. */
	public static function levelName(int $level): string {
		return self::levels()[$level] ?? (string)$level;
	}

	/** The level a requirement needs. Throws on a malformed one. */
	public static function level($requirement): int {
		if (is_int($requirement)) {
			if ($requirement < 0 || $requirement > 10) {
				throw new InvalidArgumentException("Access: level $requirement is outside 0 to 10");
			}
			return $requirement;
		}
		if (is_string($requirement) && preg_match('/^(\w+):(\w+)$/', $requirement, $m)) {
			$classes = self::table()['classes'];
			if (!isset($classes[$m[1]])) {
				throw new InvalidArgumentException("Access: unknown class '{$m[1]}' in '$requirement'");
			}
			if (!in_array($m[2], self::VERBS, true)) {
				throw new InvalidArgumentException("Access: unknown verb '{$m[2]}' in '$requirement'");
			}
			return (int)$classes[$m[1]][$m[2]];
		}
		throw new InvalidArgumentException('Access: a requirement is a level or class:verb, not ' . var_export($requirement, true));
	}

	/** Whether $permission meets the requirement. No audit. */
	public static function allows($requirement, int $permission): bool {
		return $permission >= self::level($requirement);
	}

	/**
	 * Whether the session meets the requirement, not recorded: what a page
	 * shows. $session defaults to the request's.
	 */
	public static function permits($requirement, $session = null): bool {
		$session = $session ?? SessionControl::get_instance();
		return self::allows($requirement, (int)$session->get_permission());
	}

	/**
	 * decide(), throwing the refusal a page gives when it says no: a 401 that
	 * names the level. For one action that needs more than its page's level.
	 */
	public static function demand($requirement, $session = null): void {
		if (!self::decide($requirement, $session)) {
			$level = self::level($requirement);
			throw new SystemAuthenticationError('That needs level ' . $level . ' (' . self::levelName($level) . ').');
		}
	}

	/** The lowest level above a member's (0): the first staff level. */
	public static function staffLevel(): int {
		foreach (array_keys(self::levels()) as $level) {
			if ($level > 0) return $level;
		}
		return 10;
	}

	/**
	 * Whether the session meets the requirement, recorded as the class
	 * comment says. $session defaults to the request's.
	 */
	public static function decide($requirement, $session = null): bool {
		$needed = self::level($requirement);
		$session = $session ?? SessionControl::get_instance();
		$permission = (int)$session->get_permission();
		$allowed = $permission >= $needed;

		$user_id = (int)$session->get_user_id();
		$what = is_int($requirement) ? 'level ' . $requirement : $requirement;
		if (!$allowed && $user_id > 0) {
			self::record('access_refused', false, $what, $needed, $permission, $user_id);
		} elseif ($allowed && self::audited($requirement, $needed)) {
			self::record('access_granted', true, $what, $needed, $permission, $user_id);
		}
		return $allowed;
	}

	/**
	 * A model write path refused a write ($op on $what, e.g. 'User#12'): an
	 * access_refused event for a signed-in user, a [WRITE_REFUSED] line with
	 * the request and the code that wrote (so a server write nobody marked is
	 * found from the log), and SystemAuthenticationError.
	 */
	public static function writeRefused(string $op, string $what, string $why, int $user_id, int $permission, int $needed): void {
		if ($user_id > 0) {
			self::record('access_refused', false, "$op $what", $needed, $permission, $user_id);
		}
		$trail = GuardedPdo::writer(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
		error_log(sprintf('[WRITE_REFUSED] %s for user %d (level %d, needs %d): %s %s %s from %s',
			"$op $what", $user_id, $permission, $needed, $why,
			$_SERVER['REQUEST_METHOD'] ?? 'CLI', self::path(), $trail ? implode(' < ', $trail) : '?'));
		throw new SystemAuthenticationError($why);
	}

	/** A model write path allowed a write the audit records (a permanent delete). */
	public static function writeGranted(string $op, string $what, int $user_id, int $permission, int $needed): void {
		if ($needed >= self::AUDIT_LEVEL) {
			self::record('access_granted', true, "$op $what", $needed, $permission, $user_id);
		}
	}

	/** The request's path; never its query string, which can carry a signature (signed file URLs). */
	private static function path(): string {
		return (string)strtok((string)($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? '')), '?');
	}

	/** Whether allowed work under this requirement is recorded. */
	private static function audited($requirement, int $needed): bool {
		if (is_string($requirement)) {
			[$class, $verb] = explode(':', $requirement, 2);
			if ($class === 'keys') return true;
			if ($verb === 'read') return false;
		}
		return $needed >= self::AUDIT_LEVEL;
	}

	/**
	 * The audit row. The server's own write, so a GET may make it; a failed
	 * write is logged and never changes the decision.
	 */
	private static function record(string $event, bool $success, string $what, int $needed, int $permission, int $user_id): void {
		$note = sprintf('%s (needs %d, has %d) %s %s',
			$what, $needed, $permission, $_SERVER['REQUEST_METHOD'] ?? 'CLI', self::path());
		try {
			SystemBase::server_initiated_write(function () use ($event, $success, $note, $user_id) {
				$log = new EventLog(NULL);
				$log->set('evl_event', $event);
				$log->set('evl_usr_user_id', $user_id > 0 ? $user_id : null);
				$log->set('evl_was_success', $success);
				$log->set('evl_note', $note);
				$log->save();
			});
		} catch (\Throwable $e) {
			error_log("Access: could not record $event for user $user_id ($note): " . $e->getMessage());
		}
	}

	/**
	 * What is wrong with a decoded permissions.json, or nothing. Every level is
	 * 0 to 10 with a name, 0 and 10 among them; every class gives all four verbs
	 * a named level, each verb at least the one before it.
	 */
	public static function schemaErrors($data): array {
		if (!is_array($data)) return array('not valid JSON');
		$errors = array();
		$levels = $data['levels'] ?? null;
		if (!is_array($levels) || !$levels) {
			$errors[] = 'no levels';
			$levels = array();
		}
		foreach ($levels as $level => $name) {
			if (!preg_match('/^(10|[0-9])$/', (string)$level)) {
				$errors[] = "level '$level' is not 0 to 10";
			}
			if (!is_string($name) || trim($name) === '') {
				$errors[] = "level $level has no name";
			}
		}
		foreach (array('0', '10') as $required) {
			if (!array_key_exists($required, $levels)) {
				$errors[] = "level $required is missing";
			}
		}
		$classes = $data['classes'] ?? null;
		if (!is_array($classes) || !$classes) {
			return array_merge($errors, array('no classes'));
		}
		foreach ($classes as $class => $verbs) {
			if (!preg_match('/^[a-z_]+$/', (string)$class)) {
				$errors[] = "class '$class' is not a lower-case name";
			}
			$previous = 0;
			foreach (self::VERBS as $verb) {
				$n = $verbs[$verb] ?? null;
				if (!is_int($n) || !array_key_exists((string)$n, $levels)) {
					$errors[] = "$class:$verb is not a declared level";
					continue;
				}
				if ($n < $previous) {
					$errors[] = "$class:$verb ($n) is below the verb before it ($previous)";
				}
				$previous = $n;
			}
			foreach (array_diff(array_keys((array)$verbs), self::VERBS) as $extra) {
				$errors[] = "$class has an unknown verb '$extra'";
			}
		}
		return $errors;
	}

	/** Forget the cached table (tests). */
	public static function reset(): void {
		self::$table = null;
	}
}

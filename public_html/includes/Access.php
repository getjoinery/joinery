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
 *
 * decide() is where the audit lives. Each of these lands in the event log
 * (evl_event_logs) with who, what, the level asked for and the request:
 *
 *   access_refused   a signed-in user refused (an anonymous refusal is a sign-in
 *                    page, not an event, and is not recorded)
 *   access_granted   allowed work that needs 9 or above, and any allowed use
 *                    of the keys class, reads included
 *
 * @version 1.0
 */
final class Access {

	const VERBS = array('read', 'write', 'soft_delete', 'permanent_delete');

	/** Allowed work at or above this level is recorded. */
	const AUDIT_LEVEL = 9;

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
	 * Whether the session meets the requirement, recorded as the class
	 * comment says. $session defaults to the request's.
	 */
	public static function decide($requirement, $session = null): bool {
		$needed = self::level($requirement);
		$session = $session ?? SessionControl::get_instance();
		$permission = (int)$session->get_permission();
		$allowed = $permission >= $needed;

		$user_id = (int)$session->get_user_id();
		if (!$allowed && $user_id > 0) {
			self::record('access_refused', false, $requirement, $needed, $permission, $user_id);
		} elseif ($allowed && self::audited($requirement, $needed)) {
			self::record('access_granted', true, $requirement, $needed, $permission, $user_id);
		}
		return $allowed;
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
	private static function record(string $event, bool $success, $requirement, int $needed, int $permission, int $user_id): void {
		$note = sprintf('%s (needs %d, has %d) %s %s',
			is_int($requirement) ? 'level ' . $requirement : $requirement,
			$needed, $permission,
			$_SERVER['REQUEST_METHOD'] ?? 'CLI',
			// The path only: a query string can carry a signature (signed file URLs).
			strtok((string)($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? '')), '?'));
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

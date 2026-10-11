<?php
/**
 * BoardsAction — the wrapper every Boards API action runs inside.
 *
 * An action finds out who is asking, does its work through the services, and
 * returns the array the browser needs. A refusal with a message the member can
 * read (BoardsRefusal), or a validation failure from a model, becomes an error
 * result with that message; anything else is a real fault and propagates to the
 * API's own error handling, which records it.
 *
 * @version 1.0.0
 */

class BoardsAction {

	/**
	 * @param callable $work function (int $user_id): array
	 */
	public static function run(callable $work): LogicResult {
		try {
			$user_id = Boards::requireMember();
			return LogicResult::render($work($user_id));
		} catch (BoardsRefusal $e) {
			return LogicResult::error($e->getMessage());
		} catch (SystemBaseException $e) {
			return LogicResult::error($e->getMessage());
		}
	}

	/** A list of positive integer ids from a request value (anything else is dropped). */
	public static function ids($value): array {
		$out = array();
		foreach ((array)$value as $v) {
			if (is_numeric($v) && (int)$v > 0) {
				$out[(int)$v] = (int)$v;
			}
		}
		return array_values($out);
	}

	/** A positive integer id, or null when absent or empty. */
	public static function idOrNull($input, string $key): ?int {
		return isset($input[$key]) && is_numeric($input[$key]) && (int)$input[$key] > 0 ? (int)$input[$key] : null;
	}

	/** A value that must be one of a set. @throws BoardsRefusal */
	public static function oneOf($value, array $allowed, string $what): string {
		$value = (string)$value;
		if (!in_array($value, $allowed, true)) {
			throw new BoardsRefusal('Unknown ' . $what . '.');
		}
		return $value;
	}
}

<?php
/**
 * ForumAction — the wrapper every forum API action runs inside.
 *
 * An action finds out who is asking, does its work through the forum's
 * services, and returns what the page needs. A refusal the member can read
 * (ForumRefusal) or a model's validation failure becomes an error result with
 * that message; anything else is a real fault for the API's own handling.
 *
 * @version 1.0.0
 */

class ForumAction {

	/** @param callable $work function (int $user_id): array */
	public static function run(callable $work): LogicResult {
		try {
			$user_id = Forum::requireMember();
			return LogicResult::render($work($user_id));
		} catch (ForumRefusal $e) {
			return LogicResult::error($e->getMessage());
		} catch (SystemBaseException $e) {
			return LogicResult::error($e->getMessage());
		}
	}

	/** Positive integer ids from a request value; anything else is dropped. */
	public static function ids($value): array {
		$out = array();
		foreach ((array)$value as $v) {
			if (is_numeric($v) && (int)$v > 0) {
				$out[(int)$v] = (int)$v;
			}
		}
		return array_values($out);
	}

	/** A value that must be one of a set. @throws ForumRefusal */
	public static function oneOf($value, array $allowed, string $what): string {
		$value = (string)$value;
		if (!in_array($value, $allowed, true)) {
			throw new ForumRefusal('Unknown ' . $what . '.');
		}
		return $value;
	}

	/** The descriptor keys every member action shares. */
	public static function memberDescriptor(array $descriptor): array {
		return array_merge(array(
			'requires_session' => true,
			'requires_setting' => 'forum_active',
			'auth'             => array('capability' => 'write', 'min_user_permission' => 0),
		), $descriptor);
	}
}

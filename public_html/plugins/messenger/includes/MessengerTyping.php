<?php
/**
 * MessengerTyping — who is typing, right now, and nowhere else.
 *
 * "Alice is typing…" is worthless the moment it is stale, so it is never
 * written to the database. It lives in APCu for a few seconds under one key per
 * conversation, holding {user_id => last-typed-at}. The poll writes the caller's
 * own state as it asks, so typing needs no endpoint of its own, and the whole
 * thing evaporates on its own when a member stops typing, closes the tab, or the
 * process restarts. APCu is per-host, which is the right scope here: a Joinery
 * deployment is one box.
 *
 * With APCu unavailable the indicator simply never appears — the feature
 * degrades to nothing rather than falling back to a table nobody wants.
 *
 * @version 1.0.0
 */

class MessengerTyping {

	/** How long a keystroke keeps someone "typing" without another one. */
	const TTL_SECONDS = 8;

	/** Cap on how many typists one key remembers — a group is capped anyway. */
	const MAX_TYPISTS = 64;

	protected static function available(): bool {
		return function_exists('apcu_enabled') && apcu_enabled()
			&& function_exists('apcu_fetch') && function_exists('apcu_store');
	}

	protected static function key($conversation_id): string {
		return 'messenger:typing:' . (int)$conversation_id;
	}

	/**
	 * Record (or clear) one member's typing state.
	 *
	 * @param bool $is_typing FALSE clears the member out immediately — sending a
	 *                        message should stop the indicator, not wait it out.
	 */
	public static function set($conversation_id, $user_id, bool $is_typing): void {
		if (!self::available()) {
			return;
		}

		$key   = self::key($conversation_id);
		$state = self::read($key);
		$now   = time();

		if ($is_typing) {
			if (!isset($state[(int)$user_id]) && count($state) >= self::MAX_TYPISTS) {
				return;
			}
			$state[(int)$user_id] = $now;
		} else {
			unset($state[(int)$user_id]);
		}

		if (!$state) {
			apcu_delete($key);
			return;
		}
		apcu_store($key, $state, self::TTL_SECONDS * 2);
	}

	/**
	 * Who is typing in this conversation, excluding the caller.
	 *
	 * @return int[] user ids
	 */
	public static function who($conversation_id, $exclude_user_id = null): array {
		if (!self::available()) {
			return array();
		}

		$state  = self::read(self::key($conversation_id));
		$cutoff = time() - self::TTL_SECONDS;
		$out    = array();

		foreach ($state as $user_id => $at) {
			if ($at < $cutoff) {
				continue;
			}
			if ($exclude_user_id !== null && (int)$user_id === (int)$exclude_user_id) {
				continue;
			}
			$out[] = (int)$user_id;
		}
		return $out;
	}

	/** Forget everything about one conversation (it was deleted, or left). */
	public static function clear($conversation_id): void {
		if (self::available()) {
			apcu_delete(self::key($conversation_id));
		}
	}

	protected static function read($key): array {
		$ok    = false;
		$state = apcu_fetch($key, $ok);
		return ($ok && is_array($state)) ? $state : array();
	}
}

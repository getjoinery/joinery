<?php
/**
 * MessengerTyping — who is typing, right now, and nowhere else.
 *
 * "Alice is typing…" is worthless the moment it is stale, so it is never
 * written to the database. It lives in RamStore for a few seconds under one key
 * per conversation, holding {user_id => last-typed-at}. The poll writes the
 * caller's own state as it asks, so typing needs no endpoint of its own, and the
 * whole thing evaporates on its own when a member stops typing or closes the
 * tab. The store is per-host, which is the right scope here: a Joinery
 * deployment is one box.
 *
 * With the store unavailable the indicator simply never appears — the feature
 * degrades to nothing rather than falling back to a table nobody wants.
 *
 * @version 1.1.0 - typing state lives in RamStore, not APCu (strategy/object_free_window_store.md WP3)
 * @version 1.0.1 - typing state is read through ApcuStore::fetch() (unseal_daemon.md C4)
 * @version 1.0.0
 */

class MessengerTyping {

	/** How long a keystroke keeps someone "typing" without another one. */
	const TTL_SECONDS = 8;

	/** Cap on how many typists one key remembers — a group is capped anyway. */
	const MAX_TYPISTS = 64;

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
			RamStore::delete($key);
			return;
		}
		RamStore::set($key, $state, self::TTL_SECONDS * 2);
	}

	/**
	 * Who is typing in this conversation, excluding the caller.
	 *
	 * @return int[] user ids
	 */
	public static function who($conversation_id, $exclude_user_id = null): array {
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
		RamStore::delete(self::key($conversation_id));
	}

	protected static function read($key): array {
		$state = RamStore::get($key, $ok);
		if (!$ok || !is_array($state)) {
			return array();
		}
		// JSON keeps the user ids as object keys; anything but id => time is dropped.
		$out = array();
		foreach ($state as $user_id => $at) {
			if (is_int($at) && (int)$user_id > 0) {
				$out[(int)$user_id] = $at;
			}
		}
		return $out;
	}
}

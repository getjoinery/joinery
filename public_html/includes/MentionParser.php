<?php
/**
 * MentionParser — the @mentions in member-written markdown.
 *
 * Users have no unique username, so a typed "@sam" cannot name one person. A
 * mention is a token the editor's picker writes into the markdown source:
 *
 *     @[Sam Lee](user:42)
 *
 * MarkdownRenderer turns each token into a chip showing the name. Text inside
 * inline or fenced code is code, never a mention, here as in the renderer.
 *
 * A consumer saving a post calls normalize() first, with a callback that says
 * whether each mentioned user may read the space the post is in: a mention of
 * someone who may not is written back as plain text (no chip, no alert), so a
 * post can never tell an outsider that a space exists; a kept mention gets the
 * person's real name, so an author cannot dress a token up as someone else.
 * userIds() then lists who to alert.
 *
 * Who can be mentioned is one rule, mentionableName(): a live, enabled,
 * activated account that may read the space — the picker offers exactly these,
 * and a save keeps exactly these. A post keeps at most MAX_PER_POST mentions;
 * the rest become plain text, so a post cannot turn hand-typed ids into a list
 * of names.
 *
 * @version 1.1.0 - mentionableName(), MAX_PER_POST, and code found by the renderer's own patterns
 * @version 1.0.0
 */

class MentionParser {

	/** One mention token. Group 1 the name as written, group 2 the user id. */
	const TOKEN_PATTERN = '/@\[([^\[\]\n]{1,100})\]\(user:([1-9][0-9]{0,9})\)/';

	/** Mentions one post keeps; any beyond are written as plain text. */
	const MAX_PER_POST = 20;

	/**
	 * The name a mention of this person in this space shows, or null when they
	 * cannot be mentioned there: no such account, deleted, disabled, never
	 * activated, or unable to read the space.
	 */
	public static function mentionableName(int $user_id, SystemBase $space): ?string {
		if ($user_id <= 0 || !User::check_if_exists($user_id)) {
			return null;
		}
		$user = new User($user_id, TRUE);
		if ($user->get('usr_delete_time') || $user->get('usr_is_disabled') || !$user->get('usr_is_activated')) {
			return null;
		}
		return SpaceAccess::mayRead($space, $user_id) ? self::nameFor($user) : null;
	}

	/**
	 * Normalize for a space: mentionableName() decides each person, and only
	 * the first MAX_PER_POST distinct people stay mentions.
	 */
	public static function normalizeFor(string $markdown, SystemBase $space): string {
		$kept = 0;
		return self::normalize($markdown, function (int $id) use ($space, &$kept) {
			if ($kept >= self::MAX_PER_POST) {
				return null;
			}
			$name = self::mentionableName($id, $space);
			if ($name !== null) {
				$kept++;
			}
			return $name;
		});
	}

	/**
	 * The distinct user ids mentioned outside code, in order of first mention.
	 *
	 * @return int[]
	 */
	public static function userIds(string $markdown): array {
		$ids = array();
		self::eachProse($markdown, function (string $prose) use (&$ids) {
			if (preg_match_all(self::TOKEN_PATTERN, $prose, $m)) {
				foreach ($m[2] as $id) {
					$ids[(int)$id] = (int)$id;
				}
			}
			return $prose;
		});
		return array_values($ids);
	}

	/**
	 * Rewrite every mention token outside code. $resolve(int $user_id) returns
	 * the name to show for a mention that stays, or null for one that must go;
	 * a dropped mention becomes the plain text "@Name as written".
	 *
	 * @param callable $resolve function (int $user_id): ?string
	 */
	public static function normalize(string $markdown, callable $resolve): string {
		$cache = array();
		return self::eachProse($markdown, function (string $prose) use ($resolve, &$cache) {
			return preg_replace_callback(self::TOKEN_PATTERN, function ($m) use ($resolve, &$cache) {
				$id = (int)$m[2];
				if (!array_key_exists($id, $cache)) {
					$cache[$id] = $resolve($id);
				}
				$name = $cache[$id];
				if ($name === null) {
					return '@' . $m[1];
				}
				return self::token($id, $name);
			}, $prose);
		});
	}

	/** The token for one person, with a name safe to sit inside the brackets. */
	public static function token(int $user_id, string $name): string {
		$name = trim(preg_replace('/[\[\]\n\r]+/', ' ', $name));
		if ($name === '') {
			$name = 'Member';
		}
		if (function_exists('mb_substr')) {
			$name = mb_substr($name, 0, 100);
		} else {
			$name = substr($name, 0, 100);
		}
		return '@[' . $name . '](user:' . $user_id . ')';
	}

	/**
	 * The name a mention of this person shows: their first and last name, else
	 * their nickname. Never the email address.
	 */
	public static function nameFor(User $user): string {
		$name = trim($user->get('usr_first_name') . ' ' . $user->get('usr_last_name'));
		if ($name === '') {
			$name = trim((string)$user->get('usr_nickname'));
		}
		return $name !== '' ? $name : 'Member';
	}

	/**
	 * Run $fn over the text outside code, keeping code as it was. Code is what
	 * MarkdownRenderer calls code — its own patterns, in its own order — so a
	 * span is never prose here and code there or the other way round.
	 */
	private static function eachProse(string $markdown, callable $fn): string {
		$markdown = str_replace(MarkdownRenderer::SENTINEL, '', $markdown);
		$held = array();
		$hold = function ($m) use (&$held) {
			$key = MarkdownRenderer::SENTINEL . 'MPCODE' . count($held) . MarkdownRenderer::SENTINEL;
			$held[$key] = $m[0];
			return $key;
		};
		foreach (array(MarkdownRenderer::FENCE_PATTERN, MarkdownRenderer::DOUBLE_TICK_PATTERN, MarkdownRenderer::SINGLE_TICK_PATTERN) as $pattern) {
			$next = preg_replace_callback($pattern, $hold, $markdown);
			if ($next === null) {
				// A pattern failed: treat everything as prose. Checking a token
				// that sat in code is harmless; leaving one unchecked is not.
				return $fn(strtr($markdown, $held));
			}
			$markdown = $next;
		}
		$markdown = $fn($markdown);
		return $held ? strtr($markdown, $held) : $markdown;
	}
}

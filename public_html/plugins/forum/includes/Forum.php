<?php
/**
 * Forum — the forum's shared rules: who may see and do what, settings, and the
 * small helpers every action and page uses.
 *
 * Reading follows the category's space rule (SpaceAccess), plus one forum rule:
 * a post held for approval — and a topic whose opening post is held — is seen
 * only by its author and the moderators. A moderator is SpaceAccess's: a user at
 * ForumPost::$write_permission.
 *
 * @version 1.0.0
 */

class ForumRefusal extends Exception {}

class Forum {

	/** Category slugs the forum's own pages use. */
	const RESERVED_SLUGS = array('t', 'search', 'new', 'admin', 'profile', 'api', 'assets');

	// ------------------------------------------------------------------
	// Session and settings
	// ------------------------------------------------------------------

	/** The signed-in member, or a refusal. */
	public static function requireMember(): int {
		$user_id = (int)SessionControl::get_instance()->get_user_id();
		if ($user_id <= 0) {
			throw new ForumRefusal('Sign in to do that.');
		}
		self::requireActive();
		return $user_id;
	}

	public static function requireActive(): void {
		if (!self::isActive()) {
			throw new ForumRefusal('The forum is turned off on this site.');
		}
	}

	public static function isActive(): bool {
		return (bool)Globalvars::get_instance()->get_setting('forum_active', true, true);
	}

	/** The signed-in user id, or 0. */
	public static function viewerId(): int {
		return (int)SessionControl::get_instance()->get_user_id();
	}

	/** One integer setting, with its factory default when unset or not a number. */
	public static function settingInt(string $name, int $default, int $min = 0): int {
		$raw = Globalvars::get_instance()->get_setting($name, true, true);
		if ($raw === null || $raw === '' || !is_numeric($raw)) {
			return $default;
		}
		return max($min, (int)$raw);
	}

	public static function pageSize(): int {
		return self::settingInt('forum_page_size', 20, 1);
	}

	public static function topicsPageSize(): int {
		return self::settingInt('forum_topics_page_size', 30, 1);
	}

	// ------------------------------------------------------------------
	// Access
	// ------------------------------------------------------------------

	public static function mayModerate(ForumCategory $category, int $user_id): bool {
		return SpaceAccess::mayModerate($category, $user_id);
	}

	/** Is this user a forum moderator (in any category: the level is forum-wide)? */
	public static function isModerator(int $user_id): bool {
		return SpaceAccess::mayModerate(new ForumCategory(NULL), $user_id);
	}

	public static function mayReadTopic(ForumTopic $topic, int $user_id): bool {
		if (!$topic->key || $topic->get('ftp_delete_time')) {
			return $topic->key && self::isModerator($user_id) && self::topicCategoryLive($topic);
		}
		$category = self::liveCategory($topic->categoryId());
		if (!$category || !SpaceAccess::mayRead($category, $user_id)) {
			return false;
		}
		if (!$topic->get('ftp_is_approved')) {
			return ($user_id > 0 && (int)$topic->get('ftp_usr_user_id') === $user_id) || self::mayModerate($category, $user_id);
		}
		return true;
	}

	public static function mayReadPost(ForumPost $post, int $user_id): bool {
		if (!$post->key || $post->get('fpo_delete_time')) {
			return false;
		}
		if (!ForumTopic::check_if_exists($post->topicId())) {
			return false;
		}
		$topic = new ForumTopic($post->topicId(), TRUE);
		if (!self::mayReadTopic($topic, $user_id)) {
			return false;
		}
		if (!$post->get('fpo_is_approved')) {
			return ($user_id > 0 && (int)$post->get('fpo_usr_user_id') === $user_id)
				|| self::mayModerate($topic->category(), $user_id);
		}
		return true;
	}

	/** A live category by id, or null. */
	public static function liveCategory(int $category_id): ?ForumCategory {
		if ($category_id <= 0 || !ForumCategory::check_if_exists($category_id)) {
			return null;
		}
		$category = new ForumCategory($category_id, TRUE);
		return $category->get('fcg_delete_time') ? null : $category;
	}

	private static function topicCategoryLive(ForumTopic $topic): bool {
		return self::liveCategory($topic->categoryId()) !== null;
	}

	/** A live category by slug, or null. */
	public static function categoryBySlug(string $slug): ?ForumCategory {
		if ($slug === '' || !preg_match('/^[a-z0-9-]{1,100}$/', $slug)) {
			return null;
		}
		foreach (new MultiForumCategory(array('fcg_slug' => $slug, 'deleted' => false), array(), 1) as $category) {
			return $category;
		}
		return null;
	}

	/**
	 * A category the user may read, or a refusal that does not say whether it
	 * exists.
	 */
	public static function categoryFor($category_id, int $user_id): ForumCategory {
		$category = self::liveCategory((int)$category_id);
		if (!$category || !SpaceAccess::mayRead($category, $user_id)) {
			throw new ForumRefusal('That category is not available.');
		}
		return $category;
	}

	/** A topic the user may read, with its category, or a refusal. */
	public static function topicFor($topic_id, int $user_id): array {
		$topic_id = (int)$topic_id;
		if ($topic_id <= 0 || !ForumTopic::check_if_exists($topic_id)) {
			throw new ForumRefusal('That topic is not available.');
		}
		$topic = new ForumTopic($topic_id, TRUE);
		if ($topic->get('ftp_delete_time') || !self::mayReadTopic($topic, $user_id)) {
			throw new ForumRefusal('That topic is not available.');
		}
		return array($topic, $topic->category());
	}

	/** A post the user may read, with its topic and category, or a refusal. */
	public static function postFor($post_id, int $user_id): array {
		$post_id = (int)$post_id;
		if ($post_id <= 0 || !ForumPost::check_if_exists($post_id)) {
			throw new ForumRefusal('That post is not available.');
		}
		$post = new ForumPost($post_id, TRUE);
		if (!self::mayReadPost($post, $user_id)) {
			throw new ForumRefusal('That post is not available.');
		}
		$topic = $post->topic();
		return array($post, $topic, $topic->category());
	}

	// ------------------------------------------------------------------
	// Text
	// ------------------------------------------------------------------

	/** A title on one line, trimmed and cut to its column. @throws ForumRefusal */
	public static function cleanTitle($value): string {
		$title = trim(preg_replace('/\s+/u', ' ', (string)$value));
		if ($title === '') {
			throw new ForumRefusal('A topic needs a title.');
		}
		return mb_substr($title, 0, 200);
	}

	/** The words of a title as a URL slug ("Hello, World!" -> "hello-world"). */
	public static function slugify(string $text, int $max = 80): string {
		$text = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : $text;
		$slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $text));
		$slug = trim(substr($slug, 0, $max), '-');
		return $slug !== '' ? $slug : 'topic';
	}

	/** Longest post accepted, in bytes. */
	const MAX_BODY_BYTES = 65536;

	/** A post body, checked. @throws ForumRefusal */
	public static function cleanBody($value): string {
		$body = str_replace("\r\n", "\n", (string)$value);
		if (trim($body) === '') {
			throw new ForumRefusal('A post needs some text.');
		}
		if (strlen($body) > self::MAX_BODY_BYTES) {
			throw new ForumRefusal('That post is too long.');
		}
		return $body;
	}

	/** The HTML a post's markdown makes: the restricted profile, soft breaks. */
	public static function renderBody(string $markdown): string {
		return MarkdownRenderer::render($markdown, true, true);
	}

	/** A post's text as one plain line, for a notification or a description. */
	public static function plainExcerpt(string $markdown, int $length = 200): string {
		$text = preg_replace(MentionParser::TOKEN_PATTERN, '@$1', $markdown);
		$text = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text);
		$text = preg_replace('/[`*_>#~|-]+/', ' ', $text);
		$text = trim(preg_replace('/\s+/u', ' ', $text));
		return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
	}

	/** A person as a page shows them. */
	public static function person($user_id): array {
		$user_id = (int)$user_id;
		static $cache = array();
		if (isset($cache[$user_id])) {
			return $cache[$user_id];
		}
		$name = 'Former member';
		if ($user_id > 0 && $user_id !== User::USER_DELETED && User::check_if_exists($user_id)) {
			$user = new User($user_id, TRUE);
			if (!$user->get('usr_delete_time')) {
				$name = MentionParser::nameFor($user);
			}
		}
		return $cache[$user_id] = array('id' => $user_id, 'name' => $name);
	}

	// ------------------------------------------------------------------
	// Database
	// ------------------------------------------------------------------

	public static function transaction(callable $work) {
		$db = DbConnector::get_instance()->get_db_link();
		$own = !$db->inTransaction();
		if ($own) {
			$db->beginTransaction();
		}
		try {
			$result = $work();
			if ($own) {
				$db->commit();
			}
			return $result;
		} catch (Throwable $e) {
			if ($own && $db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}
	}

	/** Lock a topic row for the rest of the transaction (counters, replies). */
	public static function lockTopic(int $topic_id): void {
		$q = DbConnector::GetPreparedStatement('SELECT ftp_forum_topic_id FROM ftp_forum_topics WHERE ftp_forum_topic_id = ? FOR UPDATE');
		$q->execute(array($topic_id));
	}
}

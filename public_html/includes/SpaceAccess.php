<?php
/**
 * SpaceAccess — who may read a space, who may post in it, who may moderate it.
 *
 * A "space" is any row that holds posts other people read: a forum category, a
 * chat channel. It declares four columns under its own prefix:
 *
 *   {p}_read_rule       public | members | tier | group | invite | staff
 *   {p}_post_rule       members | tier | group | invite | staff  (never public)
 *   {p}_tier_min_level  the level the `tier` rule asks for
 *   {p}_grp_group_id    the group the `group` rule asks for
 *
 * and two static properties on its model class:
 *
 *   $post_model     the class of the posts it holds; its $write_permission is the
 *                   moderator level (see mayModerate)
 *   $space_members  optional SpaceMembers class answering the `invite` rule
 *
 * A consumer names its space types once, from its plugin bootstrap:
 *
 *   SpaceAccess::registerType('forum_category', 'ForumCategory');
 *
 * so a shared surface (the mention picker) can take a reference like
 * 'forum_category:12' and find the row through resolve().
 *
 * Every surface that shows a space's content asks here — a page view, a search,
 * a sitemap, a mention picker, an alert — and a list or a search asks the same
 * question in SQL through readableSql(), so nothing loads rows to check them one
 * by one. An unknown rule is refused, never treated as open.
 *
 * Permissions here are the member-facing kind. The admin scale (usr_permission)
 * enters at one point only: a moderator — whose session permission reaches the
 * post model's $write_permission — reads, posts and moderates every space, and
 * `staff` means moderators only.
 *
 * A user id that is not a live, enabled account (deleted, disabled, or no such
 * row) is refused everything — the public rule included — so a watch or a
 * mention can never reach, or name, an account that is gone.
 *
 * @version 1.1.0 - a non-live account is refused even public spaces; a tier rule with no level is closed
 * @version 1.0.0
 */

class SpaceAccess {

	const RULE_PUBLIC  = 'public';
	const RULE_MEMBERS = 'members';
	const RULE_TIER    = 'tier';
	const RULE_GROUP   = 'group';
	const RULE_INVITE  = 'invite';
	const RULE_STAFF   = 'staff';

	const READ_RULES = array('public', 'members', 'tier', 'group', 'invite', 'staff');
	const POST_RULES = array('members', 'tier', 'group', 'invite', 'staff');

	/** The level a post model that declares none is moderated at. */
	const DEFAULT_MODERATOR_LEVEL = 5;

	/** @var array<int,array{permission:int,tier_level:int,groups:int[],active:bool}> */
	private static $facts = array();

	/** @var array<string,string> space type => model class */
	private static $types = array();

	/** Name a space type, so a 'type:id' reference resolves to its model class. */
	public static function registerType(string $type, string $class): void {
		self::$types[$type] = $class;
	}

	/**
	 * The live space a 'type:id' reference names, or null (unknown type, no such
	 * row, or a deleted one).
	 */
	public static function resolve(string $ref): ?SystemBase {
		if (!preg_match('/^([a-z][a-z0-9_]{0,63}):([1-9][0-9]{0,18})$/', $ref, $m)) {
			return null;
		}
		if (!isset(self::$types[$m[1]]) && class_exists('PluginBootstraps')) {
			PluginBootstraps::load();
		}
		$class = self::$types[$m[1]] ?? null;
		if ($class === null || !class_exists($class) || !$class::check_if_exists((int)$m[2])) {
			return null;
		}
		$space = new $class((int)$m[2], TRUE);
		if (!$space->key || $space->get($class::$prefix . '_delete_time')) {
			return null;
		}
		return $space;
	}

	/** May this user (0 = signed out) read this space? */
	public static function mayRead(SystemBase $space, int $user_id): bool {
		return self::allows($space, self::column($space, 'read_rule'), $user_id);
	}

	/** May this user post in this space? Signed-out visitors never may. */
	public static function mayPost(SystemBase $space, int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		$rule = self::column($space, 'post_rule');
		if ($rule === self::RULE_PUBLIC) {
			return false;   // public is a read rule only
		}
		return self::allows($space, $rule, $user_id) && self::mayRead($space, $user_id);
	}

	/**
	 * May this user moderate this space (edit or remove anyone's post, pin, lock,
	 * move, act on reports)? A moderator is a user whose permission reaches the
	 * post model's declared $write_permission. Per-space moderators, when they
	 * exist, change only this method.
	 */
	public static function mayModerate(SystemBase $space, int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		$facts = self::facts($user_id);
		return $facts['active'] && $facts['permission'] >= self::moderatorLevel($space);
	}

	/**
	 * The permission level that moderates a space.
	 *
	 * @param SystemBase|string $space a space row, or its model class
	 */
	public static function moderatorLevel($space): int {
		$class = is_string($space) ? $space : get_class($space);
		$post_model = property_exists($class, 'post_model') ? $class::$post_model : null;
		if ($post_model && class_exists($post_model) && property_exists($post_model, 'write_permission')) {
			return (int)$post_model::$write_permission;
		}
		return self::DEFAULT_MODERATOR_LEVEL;
	}

	/**
	 * The read decision as a SQL condition over a space table, for lists, search
	 * and the sitemap. $alias is the table alias (or the table name) the columns
	 * are read through. Every value in the condition is an integer this class
	 * computed, so it is built inline and needs no bound parameters.
	 *
	 * The `invite` rule needs the consumer's own member table, which SQL here
	 * cannot know; a consumer whose spaces use it passes $invite_sql, a condition
	 * true when the user is a member (it is ANDed with the rule). Without one,
	 * invite spaces are excluded — fail closed.
	 *
	 * @param string $space_class the space's model class (its prefix names the columns)
	 */
	public static function readableSql(string $space_class, string $alias, int $user_id, ?string $invite_sql = null): string {
		$p = $alias . '.' . $space_class::$prefix . '_';
		$public = "{$p}read_rule = 'public'";
		if ($user_id <= 0) {
			return '(' . $public . ')';
		}
		$facts = self::facts($user_id);
		if (!$facts['active']) {
			return '(FALSE)';
		}
		if ($facts['permission'] >= self::moderatorLevel($space_class)) {
			return "({$p}read_rule IN ('" . implode("','", self::READ_RULES) . "'))";
		}
		$parts = array(
			$public,
			"{$p}read_rule = 'members'",
			"({$p}read_rule = 'tier' AND {$p}tier_min_level > 0 AND {$p}tier_min_level <= " . (int)$facts['tier_level'] . ')',
		);
		if (!empty($facts['groups'])) {
			$parts[] = "({$p}read_rule = 'group' AND {$p}grp_group_id IN (" . implode(',', array_map('intval', $facts['groups'])) . '))';
		}
		if ($invite_sql !== null) {
			$parts[] = "({$p}read_rule = 'invite' AND (" . $invite_sql . '))';
		}
		return '(' . implode(' OR ', $parts) . ')';
	}

	/**
	 * Which of these users may read this space — the same rule as mayRead(),
	 * answered in one query for a list (the recipients of an alert). Permission
	 * is the stored one: these are other people, not the signed-in user.
	 *
	 * @param int[] $user_ids
	 * @return int[] the readers among them
	 */
	public static function readableUserIds(SystemBase $space, array $user_ids): array {
		$ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), function ($id) { return $id > 0; })));
		if (!$ids) {
			return array();
		}
		$rule = self::column($space, 'read_rule');
		if ($rule === self::RULE_INVITE) {
			return array_values(array_filter($ids, function ($id) use ($space) { return self::mayRead($space, $id); }));
		}
		if (!in_array($rule, self::READ_RULES, true)) {
			return array();
		}
		$mod = self::moderatorLevel($space);
		$where = 'usr.usr_user_id IN (' . implode(',', $ids) . ') AND usr.usr_delete_time IS NULL AND usr.usr_is_disabled IS NOT TRUE';
		$params = array();
		switch ($rule) {
			case self::RULE_PUBLIC:
			case self::RULE_MEMBERS:
				break;
			case self::RULE_STAFF:
				$where .= ' AND usr.usr_permission >= ' . $mod;
				break;
			case self::RULE_TIER:
				$min = (int)self::column($space, 'tier_min_level');
				$where .= ' AND (usr.usr_permission >= ' . $mod . ($min > 0 ? " OR EXISTS (SELECT 1 FROM grm_group_members grm
					JOIN grp_groups grp ON grp.grp_group_id = grm.grm_grp_group_id AND grp.grp_category = 'subscription_tier' AND grp.grp_delete_time IS NULL
					JOIN sbt_subscription_tiers sbt ON sbt.sbt_grp_group_id = grp.grp_group_id AND sbt.sbt_delete_time IS NULL
					WHERE grm.grm_foreign_key_id = usr.usr_user_id AND sbt.sbt_tier_level >= " . $min . ')' : '') . ')';
				break;
			case self::RULE_GROUP:
				$group_id = (int)self::column($space, 'grp_group_id');
				$where .= ' AND (usr.usr_permission >= ' . $mod . ($group_id > 0 ? " OR EXISTS (SELECT 1 FROM grm_group_members grm
					JOIN grp_groups grp ON grp.grp_group_id = grm.grm_grp_group_id AND grp.grp_category = 'user' AND grp.grp_delete_time IS NULL
					WHERE grm.grm_foreign_key_id = usr.usr_user_id AND grm.grm_grp_group_id = " . $group_id . ')' : '') . ')';
				break;
		}
		$q = DbConnector::GetPreparedStatement('SELECT usr.usr_user_id FROM usr_users usr WHERE ' . $where);
		$q->execute($params);
		return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
	}

	/** Forget what is known about users (a membership or tier just changed; tests). */
	public static function forget(?int $user_id = null): void {
		if ($user_id === null) {
			self::$facts = array();
		} else {
			unset(self::$facts[$user_id]);
		}
	}

	// ------------------------------------------------------------------ internals

	private static function allows(SystemBase $space, string $rule, int $user_id): bool {
		if (!in_array($rule, self::READ_RULES, true)) {
			return false;   // unknown rule: closed
		}
		if ($user_id <= 0) {
			return $rule === self::RULE_PUBLIC;
		}
		$facts = self::facts($user_id);
		if (!$facts['active']) {
			return false;   // not a live account: nothing, not even public
		}
		if ($rule === self::RULE_PUBLIC) {
			return true;
		}
		if ($facts['permission'] >= self::moderatorLevel($space)) {
			return true;    // moderators read and post everywhere
		}
		switch ($rule) {
			case self::RULE_MEMBERS:
				return true;
			case self::RULE_TIER:
				// A tier rule with no level set is a misconfiguration: closed.
				$min = (int)self::column($space, 'tier_min_level');
				return $min > 0 && $facts['tier_level'] >= $min;
			case self::RULE_GROUP:
				$group_id = (int)self::column($space, 'grp_group_id');
				return $group_id > 0 && in_array($group_id, $facts['groups'], true);
			case self::RULE_INVITE:
				$members = property_exists($space, 'space_members') ? $space::$space_members : null;
				return $members && class_exists($members) && $members::isMember($space, $user_id);
			case self::RULE_STAFF:
				return false;   // staff = moderators, already answered above
		}
		return false;
	}

	private static function column(SystemBase $space, string $suffix) {
		return (string)$space->get($space::$prefix . '_' . $suffix);
	}

	/**
	 * What decides a user's access, read once per user per request: their
	 * permission (the session's own, for the signed-in user — never a stored
	 * value that a step-down has not reached), their subscription tier level and
	 * their user groups.
	 */
	private static function facts(int $user_id): array {
		if (isset(self::$facts[$user_id])) {
			return self::$facts[$user_id];
		}
		$facts = array('permission' => 0, 'tier_level' => 0, 'groups' => array(), 'active' => false);

		$user = User::check_if_exists($user_id) ? new User($user_id, TRUE) : null;
		if ($user && $user->key && !$user->get('usr_delete_time') && !$user->get('usr_is_disabled')) {
			$facts['active'] = true;
			$session = SessionControl::get_instance();
			$facts['permission'] = ((int)$session->get_user_id() === $user_id)
				? (int)$session->get_permission()
				: (int)$user->get('usr_permission');

			$tier = SubscriptionTier::GetUserTier($user_id);
			$facts['tier_level'] = $tier ? (int)$tier->get('sbt_tier_level') : 0;

			$q = DbConnector::GetPreparedStatement(
				"SELECT grm.grm_grp_group_id FROM grm_group_members grm
				 JOIN grp_groups grp ON grp.grp_group_id = grm.grm_grp_group_id
				 WHERE grm.grm_foreign_key_id = ? AND grp.grp_category = 'user' AND grp.grp_delete_time IS NULL");
			$q->execute(array($user_id));
			$facts['groups'] = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
		}
		return self::$facts[$user_id] = $facts;
	}
}

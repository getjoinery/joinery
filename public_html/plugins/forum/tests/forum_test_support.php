<?php
/**
 * Shared fixtures for the forum suites. Not a test (no header, not *_test.php):
 * each suite requires it after harness_boot() and harness_test_mode().
 *
 * forum_world() builds the people the access rules tell apart and one category
 * per read rule. forum_call() runs an API action's logic the way the API does,
 * under a simulated session for the given person (0 = signed out).
 */

/** The first column of the first row of a query (or null). */
function forum_scalar(string $sql, array $params = array()) {
	$q = DbConnector::GetPreparedStatement($sql);
	$q->execute($params);
	$value = $q->fetchColumn();
	return $value === false ? null : $value;
}

/**
 * Run a forum (or core) API action as a person and return its LogicResult.
 * $action names a forum action ('forum_like') or, with a 'core:' prefix, a core
 * one ('core:mention_search').
 */
function forum_call(string $action, int $user_id, array $input): LogicResult {
	if (strpos($action, 'core:') === 0) {
		$action = substr($action, 5);
		require_once(PathHelper::getIncludePath('logic/' . $action . '_logic.php'));
	} else {
		require_once(PathHelper::getIncludePath('plugins/forum/logic/' . $action . '_logic.php'));
	}
	$session = SessionControl::get_instance();
	if ($user_id > 0) {
		$session->set_api_user($user_id, null);
	}
	SpaceAccess::forget();
	try {
		return call_user_func($action . '_logic', $input);
	} finally {
		if ($user_id > 0) {
			$session->clear_api_user();
		}
		SpaceAccess::forget();
	}
}

/** The data of a successful result; fails the check if the action refused. */
function forum_ok(LogicResult $result, string $label): array {
	check($result->error === null, $label, (string)$result->error);
	return is_array($result->data) ? $result->data : array();
}

/** Did the action refuse (with $needle in its message, when given)? */
function forum_refused(LogicResult $result, string $label, string $needle = ''): void {
	$refused = $result->error !== null && ($needle === '' || stripos((string)$result->error, $needle) !== false);
	check($refused, $label, 'error=' . var_export($result->error, true));
}

/** Turn holding and the post limit off for a suite (in memory only). */
function forum_relax_limits(): void {
	harness_set_setting_mem('forum_hold_first_posts', '0');
	harness_set_setting_mem('forum_post_rate_limit', '1000');
	harness_set_setting_mem('forum_active', '1');
}

/** A user-category group with these members; removed at teardown. */
function forum_make_group(string $name, array $user_ids): Group {
	$group = new Group(NULL);
	$group->set('grp_name', $name);
	$group->set('grp_category', 'user');
	$group->save();
	$group->load();
	harness_register_model('Group', (int)$group->key);
	foreach ($user_ids as $uid) {
		$group->add_member($uid);
	}
	return $group;
}

/** A category; removed at teardown. */
function forum_make_category(string $slug, string $read_rule, string $post_rule = 'members', array $extra = array()): ForumCategory {
	$category = new ForumCategory(NULL);
	$category->set('fcg_name', 'Cat ' . $slug);
	$category->set('fcg_slug', $slug);
	$category->set('fcg_read_rule', $read_rule);
	$category->set('fcg_post_rule', $post_rule);
	foreach ($extra as $k => $v) {
		$category->set($k, $v);
	}
	$category->prepare();
	$category->save();
	$category->load();
	harness_register_model('ForumCategory', (int)$category->key);
	return $category;
}

/**
 * The people and spaces.
 *
 *  author, member, other   plain members (permission 0)
 *  grouped                 a member of the group the `group` category asks for
 *  moderator               permission 5 (ForumPost::$write_permission)
 *  public, members, staff, group  one category per read rule
 */
function forum_world(string $suffix): array {
	$w = array();
	$w['author']    = make_user('FrmAut' . $suffix);
	$w['member']    = make_user('FrmMem' . $suffix);
	$w['other']     = make_user('FrmOth' . $suffix);
	$w['grouped']   = make_user('FrmGrp' . $suffix);
	$w['moderator'] = make_user('FrmMod' . $suffix, 5);
	$s = strtolower($suffix);
	$group = forum_make_group('Forum group ' . $suffix, array((int)$w['grouped']->key, (int)$w['author']->key));
	$w['group_row'] = $group;
	$w['public']  = forum_make_category('pub-' . $s, 'public');
	$w['members'] = forum_make_category('mem-' . $s, 'members');
	$w['staff']   = forum_make_category('stf-' . $s, 'staff', 'staff');
	$w['group']   = forum_make_category('grp-' . $s, 'group', 'group', array('fcg_grp_group_id' => (int)$group->key));
	return $w;
}

/** Start a topic as someone; registered for teardown. */
function forum_topic(ForumCategory $category, int $user_id, string $title, string $body = 'Opening post.'): array {
	SpaceAccess::forget();
	$r = ForumPosting::createTopic($category, $user_id, $title, $body);
	harness_register_model('ForumTopic', (int)$r['topic']->key);
	return $r;
}

/** The notifications a person has, oldest first. */
function forum_notes(int $user_id): array {
	$q = DbConnector::GetPreparedStatement('SELECT ntf_title, ntf_body, ntf_link FROM ntf_notifications WHERE ntf_usr_user_id = ? ORDER BY ntf_notification_id');
	$q->execute(array($user_id));
	return $q->fetchAll(PDO::FETCH_ASSOC);
}

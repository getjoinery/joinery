<?php
/** @joinery-test
 * name: forum_access
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Who may read and post in the forum, through every door.
 *
 * A category's read rule decides what a signed-out visitor, a member, a group
 * member and a moderator may see, and every surface must ask the same rule:
 * the category list (SQL), a topic, a post, an attachment, search, the sitemap
 * inventory and the mention picker. A held post is seen only by its author and
 * the moderators, wherever it is looked for.
 *
 * Run: php tests/run.php test-db --filter=forum_access
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/forum_test_support.php');
forum_relax_limits();

$suffix = strtoupper(LibraryFunctions::random_string(5));
$w = forum_world($suffix);
$uid = function (string $who) use ($w) { return (int)$w[$who]->key; };
$author = $uid('author');

$topics = array();
foreach (array('public', 'members', 'group') as $rule) {
	$topics[$rule] = forum_topic($w[$rule], $author, ucfirst($rule) . ' topic ' . $suffix, 'Findable zebracorn ' . $rule . ' ' . $suffix);
}
$topics['staff'] = forum_topic($w['staff'], $uid('moderator'), 'Staff topic ' . $suffix, 'Findable zebracorn staff ' . $suffix);

// =====================================================================
section('SpaceAccess decides each read rule');

$expect = array(
	// rule      signed out  member  grouped  moderator
	'public'  => array(true,  true,  true,  true),
	'members' => array(false, true,  true,  true),
	'group'   => array(false, false, true,  true),
	'staff'   => array(false, false, false, true),
);
foreach ($expect as $rule => $row) {
	SpaceAccess::forget();
	$c = $w[$rule];
	check(SpaceAccess::mayRead($c, 0) === $row[0], "$rule: signed out " . ($row[0] ? 'reads' : 'does not read'));
	check(SpaceAccess::mayRead($c, $uid('member')) === $row[1], "$rule: a member " . ($row[1] ? 'reads' : 'does not read'));
	check(SpaceAccess::mayRead($c, $uid('grouped')) === $row[2], "$rule: a group member " . ($row[2] ? 'reads' : 'does not read'));
	check(SpaceAccess::mayRead($c, $uid('moderator')) === $row[3], "$rule: a moderator " . ($row[3] ? 'reads' : 'does not read'));
}
check(!SpaceAccess::mayPost($w['public'], 0), 'nobody signed out may post, even in a public category');
check(SpaceAccess::mayPost($w['public'], $uid('member')), 'a member may post in a public category');
check(!SpaceAccess::mayPost($w['staff'], $uid('member')), 'a member may not post in a staff category');
check(SpaceAccess::mayPost($w['staff'], $uid('moderator')), 'a moderator may');

$odd = forum_make_category('odd-' . strtolower($suffix), 'members');
$odd->set('fcg_read_rule', 'nonsense');   // in memory only: an unknown rule
check(!SpaceAccess::mayRead($odd, $uid('member')), 'an unknown rule is closed, never open');
check(SpaceAccess::moderatorLevel('ForumCategory') === ForumPost::$write_permission, 'the moderator level is the post model\'s declared $write_permission');

// =====================================================================
section('accounts that are gone read nothing, not even public');

$gone = make_user('FrmGone' . $suffix);
$gone_id = (int)$gone->key;
User::updateColumns($gone_id, array('usr_is_disabled' => true));
SpaceAccess::forget();
check(!SpaceAccess::mayRead($w['public'], $gone_id), 'a disabled account cannot read a public category');
check(SpaceAccess::readableUserIds($w['public'], array($gone_id, $uid('member'))) === array($uid('member')), 'and is left out of every alert');
check(!SpaceAccess::mayRead($w['public'], 2000000000), 'an id with no account reads nothing');
check(MentionParser::mentionableName($gone_id, $w['public']) === null, 'nor can be mentioned');

$tierless = forum_make_category('tls-' . strtolower($suffix), 'members');
ForumCategory::updateColumns((int)$tierless->key, array('fcg_read_rule' => 'tier', 'fcg_tier_min_level' => null));
$tierless = new ForumCategory((int)$tierless->key, TRUE);
SpaceAccess::forget();
check(!SpaceAccess::mayRead($tierless, $uid('member')), 'a tier category with no tier set is closed to members');
check(!in_array((int)$tierless->key, array_map(function ($c) { return (int)$c->key; },
	iterator_to_array(new MultiForumCategory(array('readable_by' => $uid('member'), 'deleted' => false)), false)), true),
	'in SQL as well');

// =====================================================================
section('the batch reader check agrees with the single one');

$people = array($uid('author'), $uid('member'), $uid('other'), $uid('grouped'), $uid('moderator'), $gone_id);
foreach (array('public', 'members', 'group', 'staff') as $rule) {
	SpaceAccess::forget();
	$single = array();
	foreach ($people as $id) {
		if ($id !== 0 && SpaceAccess::mayRead($w[$rule], $id)) $single[] = $id;
	}
	$batch = SpaceAccess::readableUserIds($w[$rule], $people);
	sort($single);
	sort($batch);
	check($batch === $single, "$rule: readableUserIds and mayRead agree", implode(',', $batch) . ' vs ' . implode(',', $single));
}

// =====================================================================
section('the SQL form agrees with the PHP one');

foreach (array(0 => 'signed out', $uid('member') => 'member', $uid('grouped') => 'grouped', $uid('moderator') => 'moderator') as $viewer => $who) {
	SpaceAccess::forget();
	$listed = array();
	foreach (new MultiForumCategory(array('readable_by' => $viewer, 'deleted' => false)) as $c) {
		$listed[(int)$c->key] = true;
	}
	foreach (array('public', 'members', 'group', 'staff') as $rule) {
		$id = (int)$w[$rule]->key;
		check(isset($listed[$id]) === SpaceAccess::mayRead($w[$rule], $viewer), "$who: the category list and mayRead agree on $rule");
	}
}

// =====================================================================
section('topics and posts follow their category');

foreach (array('public', 'members', 'group', 'staff') as $rule) {
	$t = $topics[$rule]['topic'];
	$p = $topics[$rule]['post'];
	SpaceAccess::forget();
	check(Forum::mayReadTopic($t, $uid('member')) === SpaceAccess::mayRead($w[$rule], $uid('member')), "$rule: a member reads the topic exactly when they read the category");
	check(Forum::mayReadPost($p, 0) === ($rule === 'public'), "$rule: a signed-out visitor reads the post only if public");
	$r = forum_call('forum_post_source', $uid('member'), array('post_id' => (int)$p->key));
	check(($r->error === null) === SpaceAccess::mayRead($w[$rule], $uid('member')), "$rule: forum_post_source answers a member exactly when they may read");
}
forum_refused(forum_call('forum_post_create', $uid('member'), array('topic_id' => (int)$topics['group']['topic']->key, 'body' => 'x')),
	'a member outside the group cannot reply there, and is told it is not available', 'not available');
forum_refused(forum_call('forum_topic_create', $uid('member'), array('category_id' => (int)$w['staff']->key, 'title' => 'x', 'body' => 'y')),
	'a member cannot start a topic in a category they cannot read', 'not available');

// The REST and AI surfaces go through the models.
$caught = false;
try {
	$topics['group']['post']->authenticate_read(array('current_user_id' => $uid('member'), 'current_user_permission' => 0));
} catch (Throwable $e) {
	$caught = true;
}
check($caught, 'the post model refuses a reader the category refuses');

// =====================================================================
section('held posts are the author\'s and the moderators\' alone');

harness_set_setting_mem('forum_hold_first_posts', '5');
$newbie = make_user('FrmNew' . $suffix);
$held = forum_topic($w['public'], (int)$newbie->key, 'Held topic ' . $suffix, 'Held zebracorn body ' . $suffix);
harness_set_setting_mem('forum_hold_first_posts', '0');
check($held['held'] === true, 'a brand-new member\'s first topic is held');
SpaceAccess::forget();
check(!Forum::mayReadTopic($held['topic'], 0), 'a signed-out visitor cannot see it');
check(!Forum::mayReadTopic($held['topic'], $uid('member')), 'another member cannot see it');
check(Forum::mayReadTopic($held['topic'], (int)$newbie->key), 'its author can');
check(Forum::mayReadTopic($held['topic'], $uid('moderator')), 'a moderator can');

// =====================================================================
section('search finds only what the searcher may read');

$found_ids = function (int $viewer) use ($suffix) {
	SpaceAccess::forget();
	$ids = array();
	foreach (ForumSearch::search('zebracorn ' . $suffix, $viewer)['results'] as $r) {
		$ids[$r['post_id']] = true;
	}
	return $ids;
};
$anon = $found_ids(0);
check(isset($anon[(int)$topics['public']['post']->key]), 'signed out finds the public post');
check(!isset($anon[(int)$topics['members']['post']->key]) && !isset($anon[(int)$topics['staff']['post']->key]), 'and nothing members-only or staff');
$member_found = $found_ids($uid('member'));
check(isset($member_found[(int)$topics['members']['post']->key]), 'a member finds the members post');
check(!isset($member_found[(int)$topics['group']['post']->key]), 'but not the group post');
check(!isset($member_found[(int)$held['post']->key]), 'and never a held post');
$mod_found = $found_ids($uid('moderator'));
check(isset($mod_found[(int)$topics['staff']['post']->key]) && isset($mod_found[(int)$topics['group']['post']->key]), 'a moderator finds everything');

// =====================================================================
section('the sitemap inventory is public, live and approved topics only');

$inventory = array();
foreach (ForumTopic::seo_public_records() as $rec) {
	$inventory[$rec['id']] = $rec['link'];
}
check(isset($inventory[(int)$topics['public']['topic']->key]), 'a public topic is listed');
check(($inventory[(int)$topics['public']['topic']->key] ?? '') === (int)$topics['public']['topic']->key . '/' . $topics['public']['topic']->get('ftp_slug'),
	'with its id/slug link (the topic URL under forum/t)');
foreach (array('members', 'group', 'staff') as $rule) {
	check(!isset($inventory[(int)$topics[$rule]['topic']->key]), "a $rule topic is not");
}
check(!isset($inventory[(int)$held['topic']->key]), 'a held topic is not');

// =====================================================================
section('the mention picker offers only people who may read the space');

$r = forum_ok(forum_call('core:mention_search', $author, array('space' => 'forum_category:' . (int)$w['group']->key, 'q' => 'UserFrmGrp' . $suffix)), 'mention_search answers');
check(array_column($r['people'] ?? array(), 'id') === array($uid('grouped')), 'a group member is offered in the group category, by their last name');
$r = forum_ok(forum_call('core:mention_search', $author, array('space' => 'forum_category:' . (int)$w['group']->key, 'q' => 'UserFrmMem' . $suffix)), 'searching for someone outside the group');
check(($r['people'] ?? array('x')) === array(), 'a member outside the group is never offered');
$r = forum_ok(forum_call('core:mention_search', $uid('member'), array('space' => 'forum_category:' . (int)$w['staff']->key, 'q' => 'HarnessTest')), 'mention_search in a space you cannot post in');
check(($r['people'] ?? array('x')) === array(), 'answers nobody');
$r = forum_ok(forum_call('core:mention_search', $author, array('space' => 'forum_category:' . (int)$w['members']->key, 'q' => 'h')), 'one letter');
check(($r['people'] ?? array('x')) === array(), 'one letter answers nobody');
$picked = array();
foreach (forum_ok(forum_call('core:mention_search', $author, array('space' => 'forum_category:' . (int)$w['members']->key, 'q' => 'HarnessTest')), 'search by name')['people'] ?? array() as $p) {
	$picked[] = $p;
}
check(count($picked) <= 8, 'at most eight people');
check(!in_array($author, array_column($picked, 'id'), true), 'never the person typing');

// =====================================================================
section('an attachment is exactly as visible as its post');

$file = File::createFromBytes('bytes ' . $suffix, 'note.txt', 'text/plain', $author, array(
	'fil_private' => true, 'fil_source' => ForumUploads::FILE_SOURCE));
harness_register_model('File', (int)$file->key);
ForumUploads::claimAll(array((int)$file->key), $author, $topics['members']['post']);
$gate = new ForumAttachmentGate();
SpaceAccess::forget();
check($gate->userMayAccess($uid('member'), (int)$topics['members']['post']->key), 'a member opens an attachment on a members post');
check(!$gate->userMayAccess(0, (int)$topics['members']['post']->key), 'a signed-out visitor does not');
check($gate->userMayAccess(0, (int)$topics['public']['post']->key), 'a signed-out visitor does open one on a public post');
check(!$gate->userMayAccess($uid('member'), (int)$held['post']->key), 'nobody else opens one on a held post');
AccessGateRegistry::register($gate);   // serve.php registers it on every web request
check(AccessGateRegistry::userMayAccess(ForumAttachmentGate::KEY, (int)$topics['public']['post']->key, null),
	'core\'s file gate asks the forum about a signed-out visitor (as user 0) rather than refusing outright');
$reloaded = new File((int)$file->key, TRUE);
check($reloaded->get('fil_access_provider') === ForumAttachmentGate::KEY && !$reloaded->get('fil_private'),
	'a claimed upload is gated on its post and no longer private to its uploader');
$listed = EntityAttachment::forEntity(ForumPost::ENTITY_TYPE, (int)$topics['members']['post']->key);
check(count($listed) === 1 && (int)$listed[0]->get('eat_byte_size') === strlen('bytes ' . $suffix) && $listed[0]->get('eat_byte_size') > 0,
	'the attachment row records the file\'s real size', (string)($listed[0] ?? null ? $listed[0]->get('eat_byte_size') : ''));
$caught = '';
try {
	ForumUploads::claimAll(array((int)$file->key), $author, $topics['public']['post']);
} catch (ForumRefusal $e) {
	$caught = $e->getMessage();
}
check($caught !== '', 'a file already on a post cannot be claimed onto another');

harness_finish();

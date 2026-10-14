<?php
/** @joinery-test
 * name: forum_notify
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Who hears about what in the forum.
 *
 *  - a new topic reaches the category's watchers;
 *  - a reply reaches the topic's watchers and the author of the post it answers;
 *  - a mention reaches the mentioned person, once, instead of a reply alert;
 *  - nobody hears about their own post; muted people hear nothing;
 *  - people who can no longer read the category hear nothing;
 *  - a held post says nothing until it is approved, then says it once.
 *
 * Run: php tests/run.php test-db --filter=forum_notify
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
$member = $uid('member');
$other = $uid('other');
$moderator = $uid('moderator');
$cat = $w['members'];
$count = function (int $user_id) { return count(forum_notes($user_id)); };
$last = function (int $user_id) {
	$notes = forum_notes($user_id);
	return $notes ? $notes[count($notes) - 1] : array('ntf_title' => '', 'ntf_body' => '', 'ntf_link' => '');
};

// =====================================================================
section('a new topic reaches the category\'s watchers');

ForumWatch::setLevel($member, ForumWatch::TARGET_CATEGORY, (int)$cat->key, ForumWatch::LEVEL_WATCHING);
ForumWatch::setLevel($author, ForumWatch::TARGET_CATEGORY, (int)$cat->key, ForumWatch::LEVEL_WATCHING);
$before_member = $count($member);
$before_author = $count($author);
$t = forum_topic($cat, $author, 'Watched topic ' . $suffix, 'The opening');
check($count($member) === $before_member + 1, 'a category watcher hears about it');
$note = $last($member);
check($note['ntf_title'] === '[' . $cat->get('fcg_name') . '] Watched topic ' . $suffix, 'the title is "[category] topic"', $note['ntf_title']);
check(stripos($note['ntf_body'], 'started a topic') !== false, 'and says what happened', $note['ntf_body']);
check(strpos($note['ntf_link'], '/forum/t/' . (int)$t['topic']->key . '/') === 0, 'and links to the topic', $note['ntf_link']);
check($count($author) === $before_author, 'the author does not hear about their own topic');
check($count($other) === 0, 'someone not watching hears nothing');

// =====================================================================
section('a reply reaches the topic\'s watchers');

$topic = $t['topic'];
$before = array($author => $count($author), $member => $count($member), $other => $count($other));
$r1 = ForumPosting::reply($topic, $cat, $other, 'First reply');
check($count($author) === $before[$author] + 1, 'the topic\'s author (watching it) hears about the reply');
check($count($member) === $before[$member], 'a category watcher does not hear about replies');
check($count($other) === $before[$other], 'the replier does not hear about their own reply');
check(stripos($last($author)['ntf_body'], 'replied') !== false, 'the alert says someone replied');
check(strpos($last($author)['ntf_link'], '?post=' . (int)$r1['post']->key) !== false, 'and links to the reply itself');

$before = array($author => $count($author), $other => $count($other));
ForumPosting::reply($topic, $cat, $member, 'Answering you', (int)$r1['post']->key);
check($count($other) === $before[$other] + 1, 'the author of the post answered hears about it');
check($count($author) === $before[$author] + 1, 'the topic watchers still hear once each');

// =====================================================================
section('a mention reaches the mentioned person once');

$before = array($author => $count($author), $member => $count($member), $other => $count($other));
ForumPosting::reply($topic, $cat, $other, 'Ping ' . MentionParser::token($author, 'x') . ' and ' . MentionParser::token($moderator, 'y'));
check($count($author) === $before[$author] + 1, 'the topic author, mentioned, gets one alert, not two');
check(stripos($last($author)['ntf_body'], 'mentioned you') !== false, 'and it is the mention');
check($count($moderator) === 1, 'a mentioned moderator who does not watch gets the mention');
check($count($member) === $before[$member] + 1, 'a watcher not mentioned gets the reply alert');

// =====================================================================
section('muted, and people who can no longer read');

ForumWatch::setLevel($member, ForumWatch::TARGET_TOPIC, (int)$topic->key, ForumWatch::LEVEL_MUTED);
$before = $count($member);
ForumPosting::reply($topic, $cat, $other, 'Muted? ' . MentionParser::token($member, 'z'));
check($count($member) === $before, 'a muted member hears nothing from the topic, not even a mention');

$gcat = $w['group'];
$g = forum_topic($gcat, $uid('grouped'), 'Group topic ' . $suffix);
ForumWatch::setLevel($author, ForumWatch::TARGET_TOPIC, (int)$g['topic']->key, ForumWatch::LEVEL_WATCHING);
$w['group_row']->remove_member($author);
SpaceAccess::forget();
$before = $count($author);
ForumPosting::reply($g['topic'], $gcat, $uid('grouped'), 'After you left');
check($count($author) === $before, 'someone who left the group keeps their watch but is told nothing');

// =====================================================================
section('a held post says nothing until approved');

harness_set_setting_mem('forum_hold_first_posts', '1');
$newbie = (int)make_user('FrmNtf' . $suffix)->key;
$before = $count($other);
$mod_before = $count($moderator);
$held = ForumPosting::reply($topic, $cat, $newbie, 'Held ' . MentionParser::token($other, 'o'));
harness_set_setting_mem('forum_hold_first_posts', '0');
check($held['held'] === true, 'the new member\'s reply is held');
check($count($other) === $before, 'its mention sends nothing yet');
$mod_notes = forum_notes($moderator);
$mod_last = $mod_notes ? $mod_notes[count($mod_notes) - 1] : array('ntf_title' => '', 'ntf_link' => '');
check($count($moderator) === $mod_before + 1 && stripos($mod_last['ntf_title'], 'Waiting for approval') === 0
	&& $mod_last['ntf_link'] === '/plugins/forum/admin/admin_forum_held',
	'the moderators are told, once, that a post is waiting, with a link to the queue', $mod_last['ntf_title']);
ForumPosting::approvePost($held['post'], $moderator);
check($count($other) === $before + 1, 'approving it sends the mention, once');

// =====================================================================
section('an edit that mentions someone new tells them, once');

$edited = ForumPosting::reply($topic, $cat, $author, 'No mention yet');
$before = $count($other);
forum_ok(forum_call('forum_post_edit', $author, array('post_id' => (int)$edited['post']->key, 'body' => 'Now ' . MentionParser::token($other, 'o'))), 'the author edits in a mention');
check($count($other) === $before + 1, 'the newly mentioned person is told');
forum_ok(forum_call('forum_post_edit', $author, array('post_id' => (int)$edited['post']->key, 'body' => 'Still ' . MentionParser::token($other, 'o') . ' here')), 'and edits again');
check($count($other) === $before + 1, 'and not again when a later edit keeps the mention');

harness_finish();

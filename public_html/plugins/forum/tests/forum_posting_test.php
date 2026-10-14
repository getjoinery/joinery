<?php
/** @joinery-test
 * name: forum_posting
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Writing in the forum: topics, replies, edits, removal, locks, moves, holding,
 * the post limit, mentions and the counters a category list is ordered by.
 *
 * The counters (reply count, last post) must change in the same save as the
 * post — on reply, removal, hold and approval — and the nightly check must
 * find nothing to fix after any of it.
 *
 * Run: php tests/run.php test-db --filter=forum_posting
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/forum_test_support.php');
require_once(PathHelper::getIncludePath('plugins/forum/tasks/ForumMaintenance.php'));
forum_relax_limits();

$suffix = strtoupper(LibraryFunctions::random_string(5));
$w = forum_world($suffix);
$uid = function (string $who) use ($w) { return (int)$w[$who]->key; };
$author = $uid('author');
$member = $uid('member');
$moderator = $uid('moderator');
$cat = $w['members'];

$reload = function (ForumTopic $t): ForumTopic { return new ForumTopic((int)$t->key, TRUE); };

// =====================================================================
section('a topic and its replies keep their counters');

$data = forum_ok(forum_call('forum_topic_create', $author, array('category_id' => (int)$cat->key,
	'title' => '  Hello,   world! ' . $suffix, 'body' => "First **post**\nline two")), 'forum_topic_create starts a topic');
$topic = new ForumTopic((int)$data['topic_id'], TRUE);
harness_register_model('ForumTopic', (int)$topic->key);
check($topic->get('ftp_title') === 'Hello, world! ' . $suffix, 'the title is trimmed to one line');
check($topic->get('ftp_slug') === 'hello-world-' . strtolower($suffix), 'the slug is made from the title', (string)$topic->get('ftp_slug'));
check($data['url'] === '/forum/t/' . $topic->key . '/' . $topic->get('ftp_slug'), 'it answers the topic\'s address');
check((int)$topic->get('ftp_reply_count') === 0 && (int)$topic->get('ftp_last_post_id') > 0, 'no replies yet; the opening post is the last post');
$first_id = (int)$topic->get('ftp_last_post_id');
$first = new ForumPost($first_id, TRUE);
check($first->get('fpo_is_first') && strpos((string)$first->get('fpo_body_html'), '<strong>post</strong>') !== false, 'the opening post is rendered at write time');
check(strpos((string)$first->get('fpo_body_html'), '<br>') !== false, 'with soft line breaks');
check(ForumWatch::levelFor($author, ForumWatch::TARGET_TOPIC, (int)$topic->key) === 'watching', 'the author watches their topic');

$r1 = forum_ok(forum_call('forum_post_create', $member, array('topic_id' => (int)$topic->key, 'body' => 'A reply')), 'a member replies');
$r2 = forum_ok(forum_call('forum_post_create', $author, array('topic_id' => (int)$topic->key, 'body' => 'An answer', 'reply_to_id' => $r1['post_id'])), 'the author answers it');
$topic = $reload($topic);
check((int)$topic->get('ftp_reply_count') === 2, 'two replies counted');
check((int)$topic->get('ftp_last_post_id') === (int)$r2['post_id'], 'the last post is the newest reply');
check((int)(new ForumPost((int)$r2['post_id'], TRUE))->get('fpo_reply_to_id') === (int)$r1['post_id'], 'the answer records what it answers');
check(ForumWatch::levelFor($member, ForumWatch::TARGET_TOPIC, (int)$topic->key) === 'watching', 'a replier watches the topic');

$other_topic = forum_topic($cat, $author, 'Another ' . $suffix);
$x = forum_ok(forum_call('forum_post_create', $member, array('topic_id' => (int)$topic->key, 'body' => 'Cross', 'reply_to_id' => (int)$other_topic['post']->key)), 'a reply naming a post in another topic');
check((new ForumPost((int)$x['post_id'], TRUE))->get('fpo_reply_to_id') === null, 'is saved without the stray reply-to');

// =====================================================================
section('editing');

forum_refused(forum_call('forum_post_edit', $member, array('post_id' => $first_id, 'body' => 'Hijack')), 'a member cannot edit someone else\'s post');
$e = forum_ok(forum_call('forum_post_edit', $author, array('post_id' => $first_id, 'body' => 'Edited _text_', 'title' => 'Renamed ' . $suffix)), 'the author edits their opening post and title');
$first = new ForumPost($first_id, TRUE);
$topic = $reload($topic);
check($first->get('fpo_edited_time') !== null && strpos((string)$first->get('fpo_body_html'), '<em>text</em>') !== false, 'the post shows edited and is rendered again');
check($topic->get('ftp_title') === 'Renamed ' . $suffix && $topic->get('ftp_slug') === 'renamed-' . strtolower($suffix), 'the title and slug follow');
check((int)forum_scalar("SELECT COUNT(*) FROM cht_change_tracking WHERE cht_entity_type = 'forum_post' AND cht_entity_id = ? AND cht_old_value LIKE 'First%'", array($first_id)) === 1,
	'the old text is kept in the change history');
forum_ok(forum_call('forum_post_edit', $moderator, array('post_id' => (int)$r1['post_id'], 'body' => 'Moderated')), 'a moderator edits anyone\'s post');

// =====================================================================
section('locking and moving');

forum_refused(forum_call('forum_topic_set', $author, array('topic_id' => (int)$topic->key, 'op' => 'lock')), 'only a moderator locks', 'moderator');
forum_ok(forum_call('forum_topic_set', $moderator, array('topic_id' => (int)$topic->key, 'op' => 'lock')), 'a moderator locks the topic');
forum_refused(forum_call('forum_post_create', $member, array('topic_id' => (int)$topic->key, 'body' => 'Too late')), 'a locked topic refuses a member\'s reply', 'locked');
forum_ok(forum_call('forum_post_create', $moderator, array('topic_id' => (int)$topic->key, 'body' => 'Mod note')), 'a moderator still replies');
forum_refused(forum_call('forum_post_edit', $member, array('post_id' => (int)$r1['post_id'], 'body' => 'Late edit')), 'an author cannot edit in a locked topic', 'locked');
forum_refused(forum_call('forum_post_delete', $member, array('post_id' => (int)$r1['post_id'])), 'an author cannot remove a post in a locked topic either', 'locked');
forum_ok(forum_call('forum_topic_set', $moderator, array('topic_id' => (int)$topic->key, 'op' => 'unlock')), 'and unlocks it');
forum_ok(forum_call('forum_topic_set', $moderator, array('topic_id' => (int)$topic->key, 'op' => 'pin')), 'pins it');
check((bool)$reload($topic)->get('ftp_is_pinned'), 'pinned');

$before_url = $reload($topic)->url();
forum_ok(forum_call('forum_topic_set', $moderator, array('topic_id' => (int)$topic->key, 'op' => 'move', 'category_id' => (int)$w['public']->key)), 'moves it to another category');
$topic = $reload($topic);
check($topic->categoryId() === (int)$w['public']->key, 'it is in the new category');
check($topic->url() === $before_url, 'and its address did not change');
forum_ok(forum_call('forum_topic_set', $moderator, array('topic_id' => (int)$topic->key, 'op' => 'move', 'category_id' => (int)$cat->key)), 'and back');

// =====================================================================
section('removing');

$reply_count = (int)$reload($topic)->get('ftp_reply_count');
forum_refused(forum_call('forum_post_delete', $member, array('post_id' => (int)$r2['post_id'])), 'a member cannot remove someone else\'s post');
$d = forum_ok(forum_call('forum_post_delete', $author, array('post_id' => (int)$r2['post_id'])), 'the author removes their reply');
check($d['removed'] === 'post', 'one post went');
$topic = $reload($topic);
check((int)$topic->get('ftp_reply_count') === $reply_count - 1, 'the reply count drops in the same step');
check((int)$topic->get('ftp_last_post_id') !== (int)$r2['post_id'], 'and the last post moves back');
forum_refused(forum_call('forum_post_delete', $author, array('post_id' => $first_id)), 'an author cannot remove a topic others answered', 'moderator');
$quiet = forum_topic($cat, $author, 'Quiet ' . $suffix);
harness_set_setting_mem('forum_hold_first_posts', '5');
$heldby = (int)make_user('FrmHb' . $suffix)->key;
$hr = ForumPosting::reply($quiet['topic'], $cat, $heldby, 'A held answer');
harness_set_setting_mem('forum_hold_first_posts', '0');
check($hr['held'] === true, 'a newcomer\'s reply to a quiet topic is held');
forum_refused(forum_call('forum_post_delete', $author, array('post_id' => (int)$quiet['post']->key)),
	'an author cannot remove a topic someone else answered even while that answer is held', 'moderator');

$lonely = forum_topic($cat, $author, 'Lonely ' . $suffix);
$d = forum_ok(forum_call('forum_post_delete', $author, array('post_id' => (int)$lonely['post']->key)), 'an author removes their unanswered topic by removing its opening post');
check($d['removed'] === 'topic' && (new ForumTopic((int)$lonely['topic']->key, TRUE))->get('ftp_delete_time') !== null, 'the topic went with it');
forum_ok(forum_call('forum_topic_set', $moderator, array('topic_id' => (int)$lonely['topic']->key, 'op' => 'restore')), 'a moderator restores it');
check((new ForumTopic((int)$lonely['topic']->key, TRUE))->get('ftp_delete_time') === null, 'it is back');

// =====================================================================
section('holding a new member\'s first posts');

harness_set_setting_mem('forum_hold_first_posts', '2');
$newbie = (int)make_user('FrmNb' . $suffix)->key;
$h1 = forum_ok(forum_call('forum_post_create', $newbie, array('topic_id' => (int)$topic->key, 'body' => 'My first post')), 'a new member replies');
check($h1['held'] === true, 'it is held');
$count_before = (int)$reload($topic)->get('ftp_reply_count');
check((int)$reload($topic)->get('ftp_last_post_id') !== (int)$h1['post_id'], 'a held post is not the topic\'s last post');
forum_refused(forum_call('forum_like', $member, array('post_id' => (int)$h1['post_id'])), 'nobody else can even reach it', 'not available');
forum_refused(forum_call('forum_post_moderate', $member, array('post_id' => (int)$h1['post_id'], 'op' => 'approve')), 'a member cannot approve');
forum_ok(forum_call('forum_post_moderate', $moderator, array('post_id' => (int)$h1['post_id'], 'op' => 'approve')), 'a moderator approves it');
check((int)$reload($topic)->get('ftp_reply_count') === $count_before + 1, 'then it counts');
check((int)$reload($topic)->get('ftp_last_post_id') === (int)$h1['post_id'], 'and is the last post');
$h2 = forum_ok(forum_call('forum_post_create', $newbie, array('topic_id' => (int)$topic->key, 'body' => 'Second')), 'the second post');
check($h2['held'] === true, 'is held too');
forum_ok(forum_call('forum_post_moderate', $moderator, array('post_id' => (int)$h2['post_id'], 'op' => 'reject')), 'turned down');
check((new ForumPost((int)$h2['post_id'], TRUE))->get('fpo_delete_time') !== null, 'and removed');
$h3 = forum_ok(forum_call('forum_post_create', $newbie, array('topic_id' => (int)$topic->key, 'body' => 'Third')), 'the third post');
check($h3['held'] === true, 'is still held: only approved posts count toward the two');
forum_ok(forum_call('forum_post_moderate', $moderator, array('post_id' => (int)$h3['post_id'], 'op' => 'approve')), 'approved');
forum_refused(forum_call('forum_post_moderate', $moderator, array('post_id' => (int)$h3['post_id'], 'op' => 'reject')),
	'a published post cannot be "turned down"', 'Remove');
$newbie2 = (int)make_user('FrmNb2' . $suffix)->key;
$turned = forum_ok(forum_call('forum_topic_create', $newbie2, array('category_id' => (int)$cat->key, 'title' => 'Spam topic ' . $suffix, 'body' => 'Spam')), 'a new member\'s topic');
$turned_post = (int)forum_scalar('SELECT fpo_forum_post_id FROM fpo_forum_posts WHERE fpo_ftp_forum_topic_id = ? AND fpo_is_first', array($turned['topic_id']));
forum_ok(forum_call('forum_post_moderate', $moderator, array('post_id' => $turned_post, 'op' => 'reject')), 'turned down: post and topic go');
forum_refused(forum_call('forum_topic_set', $moderator, array('topic_id' => $turned['topic_id'], 'op' => 'restore')),
	'a topic whose opening post was turned down cannot be restored', 'opening post');
$h4 = forum_ok(forum_call('forum_post_create', $newbie, array('topic_id' => (int)$topic->key, 'body' => 'Fourth')), 'after two approved posts');
check($h4['held'] === false, 'the member posts freely');
$mod_post = forum_ok(forum_call('forum_post_create', (int)make_user('FrmMd2' . $suffix, 5)->key, array('topic_id' => (int)$topic->key, 'body' => 'Mod')), 'a brand-new moderator');
check($mod_post['held'] === false, 'is never held');
harness_set_setting_mem('forum_hold_first_posts', '0');

// =====================================================================
section('the post limit');

harness_set_setting_mem('forum_post_rate_limit', '2');
harness_set_setting_mem('forum_post_rate_window', '60');
$quick = (int)make_user('FrmQk' . $suffix)->key;
forum_ok(forum_call('forum_post_create', $quick, array('topic_id' => (int)$topic->key, 'body' => 'one')), 'first post inside the window');
forum_ok(forum_call('forum_post_create', $quick, array('topic_id' => (int)$topic->key, 'body' => 'two')), 'second');
forum_refused(forum_call('forum_post_create', $quick, array('topic_id' => (int)$topic->key, 'body' => 'three')), 'the third is refused with a wait', 'Try again in');
forum_ok(forum_call('forum_post_create', (int)make_user('FrmQk2' . $suffix)->key, array('topic_id' => (int)$topic->key, 'body' => 'someone else')), 'another member is not affected');
harness_set_setting_mem('forum_post_rate_limit', '1000');

// =====================================================================
section('mentions are kept only for people who may read');

$mentioned = $w['member'];
$outsider = $w['other'];
$group_cat = $w['group'];
$body = 'Hi ' . MentionParser::token((int)$w['grouped']->key, 'Fake Name') . ' and ' . MentionParser::token((int)$outsider->key, 'Out Sider');
$g = forum_topic($group_cat, $author, 'Group talk ' . $suffix, $body);
$stored = (string)$g['post']->get('fpo_body');
check(strpos($stored, '(user:' . (int)$w['grouped']->key . ')') !== false, 'a mention of a group member stays a mention');
check(strpos($stored, 'Fake Name') === false && strpos($stored, MentionParser::nameFor($w['grouped'])) !== false, 'with their real name, not the one typed');
check(strpos($stored, '(user:' . (int)$outsider->key . ')') === false && strpos($stored, '@Out Sider') !== false, 'a mention of an outsider becomes plain text');
check(MentionParser::userIds($stored) === array((int)$w['grouped']->key), 'so only the group member is mentioned');
$many = '';
for ($i = 0; $i < MentionParser::MAX_PER_POST + 5; $i++) {
	$many .= MentionParser::token($member, 'x') . ' ' . MentionParser::token((int)make_user('FrmMn' . $i . $suffix)->key, 'y') . ' ';
}
$capped = forum_topic($cat, $author, 'Many mentions ' . $suffix, $many);
check(count(MentionParser::userIds((string)$capped['post']->get('fpo_body'))) === MentionParser::MAX_PER_POST,
	'a post keeps at most ' . MentionParser::MAX_PER_POST . ' mentions; the rest become plain text');
$dead = make_user('FrmDd' . $suffix);
User::updateColumns((int)$dead->key, array('usr_delete_time' => gmdate('Y-m-d H:i:s')));
$d = forum_topic($w['public'], $author, 'Dead mention ' . $suffix, 'Hi ' . MentionParser::token((int)$dead->key, 'x') . ' and ' . MentionParser::token(2000000000, 'y'));
check(MentionParser::userIds((string)$d['post']->get('fpo_body')) === array(), 'a deleted account or a made-up id is never kept, even in a public category');
check(strpos((string)$d['post']->get('fpo_body'), 'HarnessTest') === false, 'so typing ids reveals no names');

// =====================================================================
section('likes');

$liked = forum_ok(forum_call('forum_like', $member, array('post_id' => $first_id)), 'a member likes a post');
check($liked['liked'] === true && $liked['count'] === 1, 'liked, one like');
$unliked = forum_ok(forum_call('forum_like', $member, array('post_id' => $first_id)), 'and again');
check($unliked['liked'] === false && $unliked['count'] === 0, 'unliked');
forum_refused(forum_call('forum_like', $author, array('post_id' => $first_id)), 'nobody likes their own post', 'own post');

// =====================================================================
section('reading position and the nightly check');

$t = $reload($topic);
check(ForumPages::landing($t, $cat, $member)['page'] === 1, 'a first visit opens at the top');
forum_ok(forum_call('forum_mark_read', $member, array('topic_id' => (int)$t->key, 'post_id' => $first_id)), 'the page reports how far the member read');
forum_ok(forum_call('forum_mark_read', $member, array('topic_id' => (int)$t->key, 'post_id' => 1)), 'an older position');
check((int)forum_scalar('SELECT ftr_last_read_post_id FROM ftr_topic_reads WHERE ftr_usr_user_id = ? AND ftr_ftp_forum_topic_id = ?', array($member, (int)$t->key)) === $first_id,
	'never moves the marker back');
$landing = ForumPages::landing($t, $cat, $member);
check($landing['post_id'] !== null && $landing['post_id'] > $first_id, 'the next visit lands on the first unread post');
check(ForumPages::unreadFlags(array($t), $member)[(int)$t->key] === true, 'and the topic shows as unread');
forum_ok(forum_call('forum_mark_read', $member, array('topic_id' => (int)$t->key, 'post_id' => (int)$t->get('ftp_last_post_id'))), 'reading to the end');
check(ForumPages::unreadFlags(array($t), $member)[(int)$t->key] === false, 'clears it');
check(ForumMaintenance::reconcile() === 0, 'after all of it, the nightly check finds no counter to fix');
ForumTopic::updateColumns((int)$t->key, array('ftp_reply_count' => 99));
check(ForumMaintenance::reconcile() >= 1 && (int)$reload($t)->get('ftp_reply_count') !== 99, 'a counter knocked out of line is put back');

// =====================================================================
section('reports reach the moderators and their actions work');

ContentReportRegistry::register(new ForumReportHandler());
$bad = forum_ok(forum_call('forum_post_create', $member, array('topic_id' => (int)$t->key, 'body' => 'Buy cheap things')), 'a spammy reply');
$handler = ContentReportRegistry::get('forum_post');
check($handler instanceof ForumReportHandler, 'the forum registers its report handler');
check(!$handler->mayReport((int)$bad['post_id'], $member), 'nobody reports their own post');
check($handler->mayReport((int)$bad['post_id'], $author), 'a reader may report it');
check(!$handler->mayReport((int)$bad['post_id'], 0), 'a signed-out visitor may not');
$preview = $handler->preview((int)$bad['post_id']);
check(($preview['excerpt'] ?? '') === 'Buy cheap things' && strpos($preview['url'] ?? '', '?post=' . $bad['post_id']) !== false, 'the queue gets a plain excerpt and a link into the topic');
check(isset($handler->actions((int)$bad['post_id'])['delete']), 'and may remove it');
list($report) = ContentReport::file('forum_post', (int)$bad['post_id'], $author, 'spam', null);
harness_register_model('ContentReport', (int)$report->key);
$message = $report->act('delete', $moderator);
check(stripos($message, 'Removed') !== false, 'acting on the report removes the post', $message);
check((new ForumPost((int)$bad['post_id'], TRUE))->get('fpo_delete_time') !== null, 'and the post is gone');

// =====================================================================
section('a link to a post on page 1 still names the page');

check(strpos($t->url(1, 7), '?page=1#post-7') !== false, 'url(1, post) keeps ?page=1, so a returning member is not sent to their unread post', $t->url(1, 7));
check(strpos($t->url(1), '?') === false, 'a plain page-1 address stays bare');

// =====================================================================
section('the held count matches the held list');

$gone_topic = forum_topic($cat, $author, 'Will go ' . $suffix);
harness_set_setting_mem('forum_hold_first_posts', '5');
ForumPosting::reply($gone_topic['topic'], $cat, (int)make_user('FrmHq' . $suffix)->key, 'Held in a topic that goes');
harness_set_setting_mem('forum_hold_first_posts', '0');
$listed = (new MultiForumPost(array('fpo_is_approved' => false, 'deleted' => false, 'in_live_topic' => true)))->count_all();
ForumPosting::deleteTopic($gone_topic['topic'], $cat, $moderator);
$after = (new MultiForumPost(array('fpo_is_approved' => false, 'deleted' => false, 'in_live_topic' => true)))->count_all();
check($after === $listed - 1, 'a held reply in a removed topic leaves the queue and its count together');

// =====================================================================
section('posts are rendered again when the renderer changes');

ForumPost::updateColumns($first_id, array('fpo_render_version' => 'old', 'fpo_body_html' => 'stale'));
check(ForumMaintenance::rerender() >= 1, 'the upkeep task renders stale posts again');
$again = new ForumPost($first_id, TRUE);
check($again->get('fpo_render_version') === MarkdownRenderer::VERSION && $again->get('fpo_body_html') !== 'stale', 'with the current renderer');

harness_finish();

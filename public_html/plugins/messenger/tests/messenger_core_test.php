<?php
/** @joinery-test
 * name: messenger_core
 * tier: db
 * env: any
 * needs: []
 */
/**
 * The messenger's local data layer (specs/implemented/joinery_messenger.md phase 1).
 *
 * What is pinned here is the behaviour a UI cannot be trusted to prove:
 *
 *  - group membership authorization — who may add, remove, rename, and what a
 *    non-admin is refused;
 *  - the poll cursor — no message missed and none delivered twice across
 *    concurrent sends, which is the one bug a polling transport can have that
 *    looks like nothing at all;
 *  - reactions, read positions and delete-for-everyone tombstones;
 *  - the legacy conversation_* actions, unchanged, because the iOS member app
 *    calls them and this build is not allowed to move them.
 *
 * Run: php tests/run.php db --filter=messenger_core
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/conversations_class.php'));
require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));
require_once(PathHelper::getIncludePath('data/messages_class.php'));
require_once(PathHelper::getIncludePath('data/message_reactions_class.php'));
require_once(PathHelper::getIncludePath('data/message_attachments_class.php'));
require_once(PathHelper::getIncludePath('plugins/messenger/includes/Messenger.php'));

$suffix = strtoupper(LibraryFunctions::random_string(6));
$alice = make_user('MsgA' . $suffix);
$bob   = make_user('MsgB' . $suffix);
$carol = make_user('MsgC' . $suffix);
$dave  = make_user('MsgD' . $suffix);

/** Register a conversation (and everything hanging off it) for teardown. */
function msgr_track(Conversation $c) {
	harness_register_row('cnv_conversations', 'cnv_conversation_id', (int)$c->key);
	return $c;
}

// =====================================================================
section('a group is created with its creator as the only admin');

$group = msgr_track(Conversation::create_conversation(
	array($alice->key, $bob->key, $carol->key), 'Trip ' . $suffix,
	array('admin_user_id' => $alice->key)));

check($group->is_group(), 'three people make a group');
check($group->is_admin($alice->key), 'the creator administers it');
check(!$group->is_admin($bob->key), 'nobody else does');
check($group->title_for($bob->key) === 'Trip ' . $suffix, 'and everyone sees its name');
check(count($group->participant_user_ids()) === 3, 'with three members');

// =====================================================================
section('only an admin manages membership');

$refused = false;
try { $group->add_participant($dave->key, $bob->key); }
catch (ConversationException $e) { $refused = true; }
check($refused, 'a plain member cannot add anyone');

$refused = false;
try { $group->rename('Hijacked', $bob->key); }
catch (ConversationException $e) { $refused = true; }
check($refused, 'nor rename the group');

$refused = false;
try { $group->remove_participant($carol->key, $bob->key); }
catch (ConversationException $e) { $refused = true; }
check($refused, 'nor remove another member');

check($group->add_participant($dave->key, $alice->key), 'an admin can add');
check(count($group->participant_user_ids()) === 4, 'and the group grows');
check($group->add_participant($dave->key, $alice->key) === false,
	'adding the same person twice changes nothing');

// =====================================================================
section('leaving needs no permission, and is announced');

check($group->leave($bob->key), 'anyone may show themselves out');
check(!in_array((int)$bob->key, $group->participant_user_ids(), true),
	'and they are gone from the membership');

$systems = new MultiMessage(array('conversation_id' => $group->key, 'message_type' => 'system'));
$system_bodies = array();
foreach ($systems as $m) { $system_bodies[] = $m->get('msg_body'); }
check(count($system_bodies) >= 2, 'membership changes leave a record in the thread');
check(strpos(implode(' | ', $system_bodies), 'left') !== false, 'including the one who left');

// =====================================================================
section('a group that loses its last admin promotes someone');

$orphan = msgr_track(Conversation::create_conversation(
	array($alice->key, $bob->key, $carol->key), 'Orphan ' . $suffix,
	array('admin_user_id' => $alice->key)));
$orphan->leave($alice->key);
$still_admin = false;
foreach ($orphan->participants() as $p) {
	if ($p->get('cnp_is_admin')) { $still_admin = true; }
}
check($still_admin, 'membership never becomes unmanageable');

// =====================================================================
section('the poll cursor misses nothing and repeats nothing');

$thread = msgr_track(Conversation::get_or_create_conversation($alice->key, $bob->key));
$sent = array();
for ($i = 0; $i < 12; $i++) {
	$sender = ($i % 2 === 0) ? $alice->key : $bob->key;
	$sent[] = (int)$thread->add_message($sender, 'line ' . $i)->key;
}

$cursor = 0;
$seen = array();
$rounds = 0;
do {
	$page = Messenger::threadPage($thread, array('after_message_id' => $cursor, 'limit' => 5));
	foreach ($page['messages'] as $m) {
		$seen[] = (int)$m->key;
		$cursor = max($cursor, (int)$m->key);
	}
	$rounds++;
} while ($page['messages'] && $rounds < 20);

check($seen === $sent, 'polling from a cursor returns every message exactly once, in order', 'sent=' . implode(',', $sent) . ' seen=' . implode(',', $seen));

// A message stored while a page was in flight is still above the cursor.
$late = (int)$thread->add_message($alice->key, 'arrived late')->key;
$page = Messenger::threadPage($thread, array('after_message_id' => $cursor));
$late_ids = array_map(fn($m) => (int)$m->key, $page['messages']);
check($late_ids === array($late), 'and a message sent afterwards lands on the next poll');

// =====================================================================
section('paging back reaches the start and stops');

$page = Messenger::threadPage($thread, array('limit' => 5));
check(count($page['messages']) === 5, 'the newest page is a page');
check($page['has_more'] === true, 'and says there is more behind it');

$oldest = (int)$page['messages'][0]->key;
$older = Messenger::threadPage($thread, array('before_message_id' => $oldest, 'limit' => 50));
check($older['has_more'] === false, 'and the last page back says so');

// =====================================================================
section('reactions toggle and count per emoji');

$message_id = $sent[0];
check(MessageReaction::toggle($message_id, $alice->key, '👍') === true, 'a reaction goes on');
check(MessageReaction::toggle($message_id, $bob->key, '👍') === true, 'and another on the same emoji');
$reactions = Messenger::reactionsFor(array($message_id), (int)$alice->key);
check(count($reactions[$message_id]) === 1, 'one emoji, one chip');
check($reactions[$message_id][0]['count'] === 2, 'counting both reactors');
check($reactions[$message_id][0]['mine'] === true, 'and marking the caller\'s own');

check(MessageReaction::toggle($message_id, $alice->key, '👍') === false, 'tapping again takes it off');
$reactions = Messenger::reactionsFor(array($message_id), (int)$alice->key);
check($reactions[$message_id][0]['count'] === 1, 'leaving the other reactor alone');
check($reactions[$message_id][0]['mine'] === false, 'and no longer marked as the caller\'s');

$refused = false;
try { MessageReaction::toggle($message_id, $alice->key, 'lgtm'); }
catch (MessageReactionException $e) { $refused = true; }
check($refused, 'a word is not an emoji and is refused rather than stored');

// =====================================================================
section('a deleted message leaves a tombstone, not a hole');

$victim = new Message($sent[1], TRUE);
$victim->set('msg_delete_time', gmdate('Y-m-d H:i:s'));
$victim->save();

$page = Messenger::threadPage($thread, array('limit' => 100));
$ids = array_map(fn($m) => (int)$m->key, $page['messages']);
check(in_array($sent[1], $ids, true), 'the deleted message is still in the thread');

$payload = Messenger::messagesPayload($page['messages'], (int)$alice->key);
$row = null;
foreach ($payload as $r) { if ($r['id'] === $sent[1]) { $row = $r; } }
check($row !== null && $row['is_deleted'] === true, 'and reads as deleted');
check($row !== null && $row['body'] === '', 'with its words withheld');

// =====================================================================
section('unread counts skip your own messages and the system ones');

$fresh = msgr_track(Conversation::get_or_create_conversation($carol->key, $dave->key));
$fresh->add_message($carol->key, 'first');
check(Conversation::get_unread_count($carol->key) === 0,
	'your own message does not make your own conversation unread');
check(Conversation::get_unread_count($dave->key) === 1, 'but it is unread for the other person');

Messenger::markRead($fresh, (int)$dave->key);
check(Conversation::get_unread_count($dave->key) === 0, 'reading it clears the count');

$fresh->add_system_message('Something happened');
check(Conversation::get_unread_count($dave->key) === 0,
	'and a system note is not something to catch up on');

// =====================================================================
section('a reply may only quote its own conversation');

$other = msgr_track(Conversation::get_or_create_conversation($alice->key, $carol->key));
$foreign = $other->add_message($alice->key, 'somewhere else');
$reply = $thread->add_message($alice->key, 'quoting',
	array('reply_to_message_id' => $foreign->key));
check($reply->get('msg_reply_to_message_id') === null,
	'a quote pointing out of the conversation is dropped, not stored');

$local_parent = $thread->add_message($bob->key, 'quotable');
$reply = $thread->add_message($alice->key, 'quoted',
	array('reply_to_message_id' => $local_parent->key));
check((int)$reply->get('msg_reply_to_message_id') === (int)$local_parent->key,
	'a quote inside the conversation is kept');

// =====================================================================
section('every message carries an identity of its own');

check(preg_match('/^[0-9a-f-]{36}$/', (string)$reply->get('msg_guid')) === 1,
	'messages are minted with a guid');
check(preg_match('/^[0-9a-f-]{36}$/', (string)$group->get('cnv_guid')) === 1,
	'and so are conversations');

// =====================================================================
section('the legacy conversation_* actions still behave (iOS regression gate)');

foreach (array('conversation_list', 'conversation_thread', 'conversation_send', 'conversation_action') as $action) {
	$path = PathHelper::getThemeFilePath($action . '_logic.php', 'logic');
	check(is_string($path) && file_exists($path), $action . ' still exists');
	require_once($path);
	check(function_exists($action . '_logic'), $action . ' still has its logic function');
	check(function_exists($action . '_logic_descriptor'), $action . ' is still exposed to the API');
}

$legacy = msgr_track(Conversation::get_or_create_conversation($alice->key, $dave->key));
$legacy_message = $legacy->add_message($alice->key, 'two-argument call still works');
check($legacy_message->key > 0, 'add_message() still takes its original two arguments');
check($legacy_message->get('msg_body') === 'two-argument call still works',
	'and stores what it was given');

harness_finish();

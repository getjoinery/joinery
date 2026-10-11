<?php
/** @joinery-test
 * name: boards_notify
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Who hears about what on a board.
 *
 * Boards is deliberately quieter than the tools it is compared with: notifications
 * go only to people on a card, nobody hears about their own action, and nobody is
 * told twice about the same thing. What is pinned here:
 *
 *  - the recipients of each signal (assigned, mentioned, card_activity,
 *    card_created, due_soon);
 *  - a person with no saved preference still gets the email (default_email);
 *  - an @mention suppresses the card-activity message for the same person;
 *  - Unwatch is honoured, and Watch brings the person back;
 *  - board watchers who reach the board through a group are told, and stop being
 *    told when they leave the group;
 *  - a due reminder goes out once, and again only after the due date moves.
 *
 * Run: php tests/run.php test-db --filter=boards_notify
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/boards_test_support.php');

// Notify queues its emails against these; the test database copy may carry none.
harness_set_setting_mem('defaultemail', 'boards-test@dev.getjoinery.com');
harness_set_setting_mem('defaultemailname', 'Boards test');

$suffix = strtoupper(LibraryFunctions::random_string(5));
$w = boards_world($suffix);
$board = $w['board'];
$owner = (int)$w['owner']->key;
$editor = (int)$w['editor']->key;
$viewer = (int)$w['viewer']->key;
$card = $w['card'];
$title = '[' . $board->get('bbd_name') . '] ' . $card->get('bcd_title');

/** The in-app notifications a person has, oldest first, as [title, body, link]. */
function boards_notes(int $user_id): array {
	$q = DbConnector::GetPreparedStatement(
		'SELECT ntf_title, ntf_body, ntf_link FROM ntf_notifications WHERE ntf_usr_user_id = ? ORDER BY ntf_notification_id');
	$q->execute(array($user_id));
	return $q->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The emails queued to a person. In test mode a queued email is redirected to a
 * trap address (or suppressed) with the original recipient in the subject:
 * "[for person@example] subject" — so a row belongs to a person when it is
 * addressed to them or names them there.
 */
function boards_mails(User $user): array {
	$email = (string)$user->get('usr_email');
	$q = DbConnector::GetPreparedStatement(
		'SELECT equ_subject, equ_body FROM equ_queued_emails WHERE equ_to = ? OR equ_subject LIKE ? ORDER BY equ_queued_email_id');
	$q->execute(array($email, '[for ' . $email . '%'));
	return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** A person's newest notification. */
function boards_last_note(int $user_id): array {
	$notes = boards_notes($user_id);
	return $notes ? $notes[count($notes) - 1] : array('ntf_title' => '', 'ntf_body' => '', 'ntf_link' => '');
}

function boards_note_count(int $user_id): int { return count(boards_notes($user_id)); }

// =====================================================================
section('added to a card: boards.assigned');

BoardsCards::addMember($card, $board, $owner, $editor);
$notes = boards_notes($editor);
check(count($notes) === 1, 'the person added is told once', 'notes=' . count($notes));
check(($notes[0]['ntf_title'] ?? '') === $title, 'the title is "[board] card"', $notes[0]['ntf_title'] ?? '');
check(stripos($notes[0]['ntf_body'] ?? '', 'added you to this card') !== false, 'and says what happened', $notes[0]['ntf_body'] ?? '');
check(($notes[0]['ntf_link'] ?? '') === '/profile/boards/board?id=' . $board->key . '&card=' . $card->key, 'and links to the card');
check(boards_note_count($owner) === 0, 'the person who did it is not told');
check(count(boards_mails($w['editor'])) === 1, 'and with no saved preference the email is queued too (default_email)');
check(boards_note_count((int)$w['stranger']->key) === 0, 'someone with no part in it hears nothing');

BoardsCards::addMember($card, $board, $owner, $editor);
check(boards_note_count($editor) === 1, 'adding the same person again says nothing more');

$refused = false;
try { BoardsCards::addMember($card, $board, $owner, (int)$w['stranger']->key); } catch (BoardsRefusal $e) { $refused = true; }
check($refused, 'a person who cannot open the board cannot be put on its card');

// =====================================================================
section('activity on a card: boards.card_activity');

$before_editor = boards_note_count($editor);
BoardsCards::addComment($card, $board, $owner, 'First comment', array());
check(boards_note_count($editor) === $before_editor + 1, 'a card member hears about a comment');
check(boards_note_count($owner) === 0, 'the commenter does not hear about their own comment');
check(boards_note_count($viewer) === 0, 'a person who is not on the card, and has not watched it, does not');

BoardsCards::addComment($card, $board, $editor, 'Reply from the editor', array());
check(boards_note_count($owner) === 1, 'the earlier commenter now hears about replies (commenting makes you a watcher)');

section('an @mention is sent once, not twice');

$before_editor = boards_note_count($editor);
$editor_name = Boards::person($editor)['name'];
BoardsCards::addComment($card, $board, $owner, 'Please look @' . $editor_name . ' ok?', array($editor));
$after = array_slice(boards_notes($editor), $before_editor);
check(count($after) === 1, 'a person mentioned in a comment on a card they watch gets one message, not two', 'got ' . count($after));
check(stripos($after[0]['ntf_body'] ?? '', 'mentioned you') !== false, 'and it is the mention', $after[0]['ntf_body'] ?? '');

$before_owner = boards_note_count($owner);
BoardsCards::addComment($card, $board, $editor, 'Thanks @' . Boards::person($owner)['name'], array());
check(boards_note_count($owner) === $before_owner + 1, 'the other way round is also one message');

$before_editor = boards_note_count($editor);
BoardsCards::addComment($card, $board, $owner, 'Mentioning a name nobody has: @Nobody Here', array($viewer));
check(boards_note_count($viewer) === 0, 'naming someone in the list but not in the text mentions nobody');
check(boards_note_count($editor) === $before_editor + 1, 'and the card watchers still hear about the comment');

section('moves, due dates and done');

$before = boards_note_count($editor);
BoardsCards::move($card, $board, $w['doing'], $owner, null, null);
check(boards_note_count($editor) === $before + 1, 'moving a card to another list is told');
$last = boards_last_note($editor);
check(stripos($last['ntf_body'], 'from To do to Doing') !== false, 'with where it went', $last['ntf_body']);

$before = boards_note_count($editor);
BoardsCards::move(new BoardCard((int)$card->key, TRUE), $board, $w['doing'], $owner, null, null);
check(boards_note_count($editor) === $before, 'reordering inside a list is not');

$card = new BoardCard((int)$card->key, TRUE);
BoardsCards::update($card, $board, $owner, array('due' => '2030-01-02 15:00:00'));
check(boards_note_count($editor) === $before + 1, 'a new due date is told');
BoardsCards::update($card, $board, $owner, array('is_done' => true));
check(boards_note_count($editor) === $before + 2, 'and so is done');
BoardsCards::update($card, $board, $owner, array('title' => 'Renamed card'));
BoardsCards::update($card, $board, $owner, array('description' => 'Words'));
check(boards_note_count($editor) === $before + 2, 'a rename or a new description is not');
BoardsCards::update($card, $board, $owner, array('is_done' => false, 'due' => null, 'title' => 'First card', 'description' => ''));

// =====================================================================
section('Unwatch is honoured; Watch brings a person back');

$before = boards_note_count($editor);
BoardsCards::setWatching($card, $editor, false);
BoardsCards::addComment($card, $board, $owner, 'Unwatched now', array());
check(boards_note_count($editor) === $before, 'a member who unwatched hears nothing about the card');
BoardsCards::setWatching($card, $editor, true);
BoardsCards::addComment($card, $board, $owner, 'Watched again', array());
check(boards_note_count($editor) === $before + 1, 'watching again brings them back');

$before = boards_note_count($viewer);
BoardsCards::setWatching($card, $viewer, true);
BoardsCards::addComment($card, $board, $owner, 'For the viewer', array());
check(boards_note_count($viewer) === $before + 1, 'a viewer who pressed Watch hears about the card');

// =====================================================================
section('a person\'s own preferences apply');

$pref = new NotificationPreference(NULL);
$pref->set('ntp_usr_user_id', $editor);
$pref->set('ntp_signal_name', 'boards.card_activity');
$pref->set('ntp_subscribed', false);
$pref->save();
$before = boards_note_count($editor);
BoardsCards::addComment($card, $board, $owner, 'Muted for the editor', array());
check(boards_note_count($editor) === $before, 'a muted signal is not delivered');

$pref->set('ntp_subscribed', true);
$pref->set('ntp_email_enabled', false);
$pref->save();
$mails_before = count(boards_mails($w['editor']));
BoardsCards::addComment($card, $board, $owner, 'Email off for the editor', array());
check(boards_note_count($editor) === $before + 1, 'with email off the in-app notification still arrives');
check(count(boards_mails($w['editor'])) === $mails_before, 'and no email is queued');

// =====================================================================
section('every new card: boards.card_created, through a group');

$board_watcher = make_user('BrdWat' . $suffix);
$group = boards_make_group('Board watchers ' . $suffix, array((int)$board_watcher->key));
BoardsManager::addGroup($board, (int)$group->key, 'viewer');
BoardsManager::setWatching($board, (int)$board_watcher->key, true);
$watcher_id = (int)$board_watcher->key;

BoardsCards::create($board, $w['todo'], $owner, 'A brand new card');
$notes = boards_notes($watcher_id);
check(count($notes) === 1 && stripos($notes[0]['ntf_body'], 'added this card to To do') !== false,
	'a board watcher who is on the board only through a group hears about a new card', json_encode($notes));
check(boards_note_count($watcher_id) === 1, 'the creator\'s own cards do not double up on the watcher');

$group->remove_member($watcher_id);
Boards::forgetRoles();
BoardsCards::create($board, $w['todo'], $owner, 'Another new card');
check(boards_note_count($watcher_id) === 1, 'and stops hearing when they leave the group');

$none = boards_note_count($viewer);
BoardsCards::create($board, $w['todo'], $owner, 'Nobody watching this');
check(boards_note_count($viewer) === $none, 'a member who did not choose "every new card" is not told');

// =====================================================================
section('due reminders go out once');

/** Reminders (not other activity) a person has been sent. */
function boards_reminders(int $user_id): array {
	$q = DbConnector::GetPreparedStatement("SELECT ntf_body FROM ntf_notifications WHERE ntf_usr_user_id = ? AND ntf_body LIKE '% is due %' ORDER BY ntf_notification_id");
	$q->execute(array($user_id));
	return $q->fetchAll(PDO::FETCH_COLUMN);
}

$reminded = make_user('BrdRem' . $suffix);
$rid = (int)$reminded->key;
BoardsManager::addUser($board, $rid, 'editor');
$due_card = BoardsCards::create($board, $w['todo'], $owner, 'Due soon card', array('notify' => false));
BoardsCards::addMember($due_card, $board, $owner, $rid, false);
$soon = gmdate('Y-m-d H:i:s', time() + 2 * 3600);
$due_card = new BoardCard((int)$due_card->key, TRUE);
BoardsCards::update($due_card, $board, $owner, array('due' => $soon));

// Task classes live under tasks/ and are loaded by the runner, not by name.
require_once(PathHelper::getIncludePath('plugins/boards/tasks/BoardsDueReminders.php'));
$task = new BoardsDueReminders();

check(count(boards_reminders($rid)) === 0, 'nothing is sent before the task runs');
$task->run(array());
$sent = boards_reminders($rid);
check(count($sent) === 1 && stripos($sent[0], 'this card is due in about') !== false, 'a card due inside the window is reminded about', json_encode($sent));
$task->run(array());
check(count(boards_reminders($rid)) === 1, 'running the task again sends nothing more');

BoardsCards::update(new BoardCard((int)$due_card->key, TRUE), $board, $owner, array('due' => gmdate('Y-m-d H:i:s', time() + 3 * 3600)));
$task->run(array());
check(count(boards_reminders($rid)) === 2, 'moving the due date earns one more reminder');
$task->run(array());
check(count(boards_reminders($rid)) === 2, 'and only one');

$far = BoardsCards::create($board, $w['todo'], $owner, 'Due much later', array('notify' => false));
BoardsCards::addMember($far, $board, $owner, $rid, false);
BoardsCards::update(new BoardCard((int)$far->key, TRUE), $board, $owner, array('due' => gmdate('Y-m-d H:i:s', time() + 20 * 86400)));
$task->run(array());
check(count(boards_reminders($rid)) === 2, 'a card due far off is not reminded about yet');

$finished = BoardsCards::create($board, $w['todo'], $owner, 'Done already', array('notify' => false));
BoardsCards::addMember($finished, $board, $owner, $rid, false);
BoardsCards::update(new BoardCard((int)$finished->key, TRUE), $board, $owner, array('due' => $soon, 'is_done' => true));
$task->run(array());
check(count(boards_reminders($rid)) === 2, 'a done card is not reminded about');

$archived = BoardsCards::create($board, $w['todo'], $owner, 'Archived card', array('notify' => false));
BoardsCards::addMember($archived, $board, $owner, $rid, false);
BoardsCards::update(new BoardCard((int)$archived->key, TRUE), $board, $owner, array('due' => $soon));
BoardsCards::archive(new BoardCard((int)$archived->key, TRUE), $board, $owner);
$before = count(boards_reminders($rid));
$task->run(array());
check(count(boards_reminders($rid)) === $before, 'nor an archived one');

$checklist = BoardsCards::addChecklist($due_card, $board, $owner, 'Steps');
BoardsCards::addItem($checklist, $due_card, $board, $owner, 'Assigned step', array(
	'user_id' => $rid, 'due' => gmdate('Y-m-d H:i:s', time() + 3600), 'notify' => false));
$task->run(array());
$sent = boards_reminders($rid);
check(count($sent) === $before + 1 && stripos(end($sent), 'Assigned step') !== false, 'an assigned checklist item with a due date is reminded about too', json_encode($sent));
$task->run(array());
check(count(boards_reminders($rid)) === $before + 1, 'once');

section('a person put on a new card is told once');

$dual = make_user('BrdDual' . $suffix);
BoardsManager::addUser($board, (int)$dual->key, 'editor');
BoardsManager::setWatching($board, (int)$dual->key, true);
$before = boards_note_count((int)$dual->key);
BoardsCards::create($board, $w['todo'], $owner, 'Card for the dual', array('member_ids' => array((int)$dual->key)));
check(boards_note_count((int)$dual->key) === $before + 1, 'a member who also watches the board gets "added to the card" and not "new card" as well');

section('a deleted comment leaves no words behind');

$secret = BoardsCards::addComment($card, $board, $owner, 'a very private remark', array());
$q = DbConnector::GetPreparedStatement("SELECT COUNT(*) FROM bac_activity WHERE bac_bcd_card_id = ? AND bac_data::text LIKE '%private remark%'");
$q->execute(array((int)$card->key));
check((int)$q->fetchColumn() === 1, 'the history quotes a new comment');
BoardsCards::deleteComment(new BoardComment((int)$secret->key, TRUE), $card, $board, $owner, 'admin');
$q->execute(array((int)$card->key));
check((int)$q->fetchColumn() === 0, 'and no longer does once it is deleted');

section('the title always fits');

$long = BoardsCards::create($board, $w['todo'], $owner, str_repeat('Long title ', 25), array('notify' => false));
BoardsCards::addMember($long, $board, $owner, $viewer, true);
check(strlen(boards_last_note($viewer)['ntf_title']) <= 255, 'a long board and card name still make a notification title that fits its column');

harness_finish();

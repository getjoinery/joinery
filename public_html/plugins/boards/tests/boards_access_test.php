<?php
/** @joinery-test
 * name: boards_access
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Access to Boards through every door: the API actions, the models, the
 * attachment gate, the board list.
 *
 * The rule is one function (Boards::roleFor) and every action calls it. What is
 * pinned here is that no door forgets to:
 *
 *  - a stranger gets "not found" from every action — the same answer as a board
 *    that does not exist, so a board's name never leaks;
 *  - a viewer reads and cannot change anything; an editor changes cards and lists
 *    but not the board's people, labels or settings;
 *  - the models refuse the same people (the REST and AI surfaces call them);
 *  - an attachment opens for exactly the people with a role on its board;
 *  - text other people wrote cannot carry script or a javascript: link.
 *
 * Run: php tests/run.php test-db --filter=boards_access
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/boards_test_support.php');

$suffix = strtoupper(LibraryFunctions::random_string(5));
$w = boards_world($suffix);
$board = $w['board'];
$owner = (int)$w['owner']->key;
$bid = (int)$board->key;
$card = $w['card'];
$cid = (int)$card->key;
$todo = (int)$w['todo']->key;

$label = BoardsManager::saveLabel($board, null, 'Tag', 'green');
$checklist = BoardsCards::addChecklist($card, $board, $owner, 'Steps');
$item = BoardsCards::addItem($checklist, $card, $board, $owner, 'An item');
$comment = BoardsCards::addComment($card, $board, $owner, 'Hello', array());
$link = BoardsCards::attachLink($card, $board, $owner, 'https://example.test/x', 'A link');
$member_row = BoardsManager::memberOn($board, boards_member_id($board, (int)$w['viewer']->key));

$uid = function (string $who) use ($w) { return (int)$w[$who]->key; };

// =====================================================================
section('a stranger gets "not found" from every action');

$reads = array(
	array('boards_board', array('board_id' => $bid)),
	array('boards_card', array('card_id' => $cid)),
	array('boards_poll', array('board_id' => $bid, 'after_activity_id' => 0)),
);
foreach ($reads as $read) {
	boards_refused(boards_call($read[0], $uid('stranger'), $read[1]), $read[0] . ' refuses a stranger', 'not found');
	boards_refused(boards_call($read[0], $uid('site_admin'), $read[1]), $read[0] . ' gives a site admin (below 10) no special access', 'not found');
}

// action, input, the least role that may do it
$writes = array(
	array('boards_board_save', array('op' => 'update', 'board_id' => $bid, 'name' => 'Renamed'), 'admin'),
	array('boards_board_save', array('op' => 'archive', 'board_id' => $bid), 'admin'),
	array('boards_board_save', array('op' => 'delete', 'board_id' => $bid), 'admin'),
	array('boards_board_save', array('op' => 'set_template', 'board_id' => $bid, 'is_template' => true), 'admin'),
	array('boards_board_save', array('op' => 'watch', 'board_id' => $bid), 'viewer'),
	array('boards_list_save', array('op' => 'create', 'board_id' => $bid, 'name' => 'New list'), 'editor'),
	array('boards_list_save', array('op' => 'rename', 'list_id' => $todo, 'name' => 'Renamed list'), 'editor'),
	array('boards_list_save', array('op' => 'archive', 'list_id' => $todo), 'editor'),
	array('boards_list_move', array('list_id' => $todo, 'next_id' => (int)$w['doing']->key), 'editor'),
	array('boards_card_save', array('op' => 'create', 'list_id' => $todo, 'title' => 'New card'), 'editor'),
	array('boards_card_save', array('op' => 'update', 'card_id' => $cid, 'title' => 'Renamed'), 'editor'),
	array('boards_card_save', array('op' => 'archive', 'card_id' => $cid), 'editor'),
	array('boards_card_save', array('op' => 'delete', 'card_id' => $cid), 'admin'),
	array('boards_card_save', array('op' => 'add_member', 'card_id' => $cid, 'user_id' => $owner), 'editor'),
	array('boards_card_save', array('op' => 'add_label', 'card_id' => $cid, 'label_id' => (int)$label->key), 'editor'),
	array('boards_card_save', array('op' => 'add_link', 'card_id' => $cid, 'url' => 'https://example.test/y'), 'editor'),
	array('boards_card_save', array('op' => 'watch', 'card_id' => $cid), 'viewer'),
	array('boards_card_move', array('card_id' => $cid, 'list_id' => (int)$w['doing']->key), 'editor'),
	array('boards_comment_save', array('op' => 'create', 'card_id' => $cid, 'body' => 'Nope'), 'editor'),
	array('boards_comment_save', array('op' => 'delete', 'card_id' => $cid, 'comment_id' => (int)$comment->key), 'editor'),
	array('boards_checklist_save', array('op' => 'create', 'card_id' => $cid, 'title' => 'More'), 'editor'),
	array('boards_checklist_save', array('op' => 'item_add', 'card_id' => $cid, 'checklist_id' => (int)$checklist->key, 'text' => 'More'), 'editor'),
	array('boards_checklist_save', array('op' => 'item_update', 'card_id' => $cid, 'item_id' => (int)$item->key, 'is_done' => true), 'editor'),
	array('boards_members_save', array('op' => 'add_user', 'board_id' => $bid, 'user_id' => $uid('stranger'), 'role' => 'editor'), 'admin'),
	array('boards_members_save', array('op' => 'remove', 'board_id' => $bid, 'member_id' => (int)$member_row->key), 'admin'),
	array('boards_members_save', array('op' => 'members', 'board_id' => $bid), 'admin'),
	array('boards_label_save', array('op' => 'create', 'board_id' => $bid, 'name' => 'X', 'color' => 'red'), 'admin'),
	array('boards_label_save', array('op' => 'delete', 'label_id' => (int)$label->key), 'admin'),
	array('boards_upload', array('card_id' => $cid), 'editor'),
);
$rank = array('viewer' => 1, 'editor' => 2, 'admin' => 3);

foreach ($writes as $write) {
	list($action, $input, $least) = $write;
	$name = $action . ' ' . ($input['op'] ?? '');

	boards_refused(boards_call($action, $uid('stranger'), $input), "$name refuses a stranger as not found", 'not found');

	if ($rank[$least] > $rank['viewer']) {
		$r = boards_call($action, $uid('viewer'), $input);
		boards_refused($r, "$name refuses a viewer", $least === 'admin' ? 'admin' : 'view this board but not change');
	}
	if ($rank[$least] > $rank['editor']) {
		boards_refused(boards_call($action, $uid('editor'), $input), "$name refuses an editor", 'admin');
	}
}

// Nothing above changed the board.
$fresh = new Board($bid, TRUE);
check($fresh->get('bbd_name') === 'Test board ' . $suffix && !$fresh->get('bbd_archived_time') && !$fresh->get('bbd_delete_time'),
	'none of the refused writes changed the board');
check((new BoardCard($cid, TRUE))->get('bcd_title') === 'First card' && !(new BoardCard($cid, TRUE))->get('bcd_archived_time'),
	'or the card');

section('what each role may do');

$r = boards_ok(boards_call('boards_board', $uid('viewer'), array('board_id' => $bid)), 'a viewer can read the board');
check(($r['role'] ?? '') === 'viewer' && count($r['cards'] ?? array()) === 1, 'and is told they are a viewer, with the cards');
$r = boards_ok(boards_call('boards_card', $uid('viewer'), array('card_id' => $cid)), 'a viewer can open a card');
check(!empty($r['comments']) && !$r['comments'][0]['can_edit'], 'but cannot edit a comment');

boards_ok(boards_call('boards_card_save', $uid('editor'), array('op' => 'update', 'card_id' => $cid, 'title' => 'Edited by editor')), 'an editor can edit a card');
boards_ok(boards_call('boards_card_move', $uid('editor'), array('card_id' => $cid, 'list_id' => (int)$w['doing']->key)), 'and move it');
boards_ok(boards_call('boards_comment_save', $uid('editor'), array('op' => 'create', 'card_id' => $cid, 'body' => 'From the editor')), 'and comment');
boards_refused(boards_call('boards_comment_save', $uid('editor'), array('op' => 'delete', 'card_id' => $cid, 'comment_id' => (int)$comment->key)),
	'an editor cannot remove someone else\'s comment', 'own comments');
boards_ok(boards_call('boards_comment_save', $uid('owner'), array('op' => 'delete', 'card_id' => $cid, 'comment_id' => (int)$comment->key)),
	'but a board admin can');
boards_ok(boards_call('boards_card_save', $uid('viewer'), array('op' => 'watch', 'card_id' => $cid)), 'a viewer can watch a card');
boards_ok(boards_call('boards_board_save', $uid('viewer'), array('op' => 'watch', 'board_id' => $bid)), 'and a board');
boards_ok(boards_call('boards_board', $uid('superadmin'), array('board_id' => $bid)), 'a superadmin can open any board');
boards_ok(boards_call('boards_board_save', $uid('owner'), array('op' => 'update', 'board_id' => $bid, 'name' => 'Admin renamed')), 'a board admin can change it');

// =====================================================================
section('the board list shows a person only what they can open');

$idx = boards_ok(boards_call('boards_board', $uid('stranger'), array()), 'the board list loads');
$all = array();
foreach ($idx['index'] as $group) { foreach ($group as $b) { $all[] = $b['id']; } }
check(!in_array($bid, $all, true), 'an invite-only board is not in a stranger\'s list');

$open = BoardsManager::createBoard($owner, 'Open board ' . $suffix, array('visibility' => Board::VISIBILITY_ALL_MEMBERS));
harness_register_model('Board', (int)$open->key);
$idx = boards_ok(boards_call('boards_board', $uid('stranger'), array()), 'the list loads again');
check(in_array((int)$open->key, array_column($idx['index']['open'], 'id'), true), 'but an Open to all members board is, under "open"');
$r = boards_call('boards_board', $uid('stranger'), array('board_id' => (int)$open->key));
check($r->error === null && $r->data['role'] === 'viewer', 'a stranger can read an open board, as a viewer');
boards_refused(boards_call('boards_list_save', $uid('stranger'), array('op' => 'create', 'board_id' => (int)$open->key, 'name' => 'Nope')),
	'but cannot change it', 'view this board but not change');
boards_ok(boards_call('boards_members_save', $uid('stranger'), array('op' => 'join', 'board_id' => (int)$open->key)), 'until they join');
boards_ok(boards_call('boards_list_save', $uid('stranger'), array('op' => 'create', 'board_id' => (int)$open->key, 'name' => 'Mine')), 'then they can');
boards_refused(boards_call('boards_members_save', $uid('stranger'), array('op' => 'join', 'board_id' => $bid)), 'joining an invite-only board is refused', 'not found');

// =====================================================================
section('the models refuse the same people');

$identity = function (string $who) use ($uid, $w) {
	return array('current_user_id' => $uid($who), 'current_user_permission' => (int)$w[$who]->get('usr_permission'));
};
$throws = function (callable $f): bool {
	try { $f(); return false; } catch (SystemAuthenticationError $e) { return true; }
};
$card_model = new BoardCard($cid, TRUE);
check($throws(function () use ($card_model, $identity) { $card_model->authenticate_read($identity('stranger')); }), 'BoardCard read: a stranger is refused');
check(!$throws(function () use ($card_model, $identity) { $card_model->authenticate_read($identity('viewer')); }), 'BoardCard read: a viewer is allowed');
check($throws(function () use ($card_model, $identity) { $card_model->authenticate_write($identity('viewer')); }), 'BoardCard write: a viewer is refused');
check(!$throws(function () use ($card_model, $identity) { $card_model->authenticate_write($identity('editor')); }), 'BoardCard write: an editor is allowed');
$label_model = new BoardLabel((int)$label->key, TRUE);
check($throws(function () use ($label_model, $identity) { $label_model->authenticate_write($identity('editor')); }), 'BoardLabel write: an editor is refused (admins manage labels)');
$comment_model = new BoardComment((int)BoardsCards::addComment($card, $board, $owner, 'Another', array())->key, TRUE);
check($throws(function () use ($comment_model, $identity) { $comment_model->authenticate_read($identity('stranger')); }), 'BoardComment read: a stranger is refused');
$member_model = new BoardMember((int)$member_row->key, TRUE);
check($throws(function () use ($member_model, $identity) { $member_model->authenticate_write($identity('editor')); }), 'BoardMember write: an editor is refused');
$activity = new BoardActivity((int)boards_scalar('SELECT MIN(bac_activity_id) FROM bac_activity WHERE bac_bbd_board_id = ?', array($bid)), TRUE);
check($throws(function () use ($activity, $identity) { $activity->authenticate_write($identity('owner')); }), 'BoardActivity write: nobody, not even an admin (the system writes it)');

// =====================================================================
section('an attachment opens for exactly the people with a role on its board');

$gate = new BoardsAttachmentGate();
Boards::forgetRoles();
check($gate->userMayAccess($uid('owner'), $bid), 'an admin may open it');
check($gate->userMayAccess($uid('editor'), $bid), 'an editor may');
check($gate->userMayAccess($uid('viewer'), $bid), 'a viewer may');
check($gate->userMayAccess($uid('superadmin'), $bid), 'a superadmin may');
check(!$gate->userMayAccess($uid('stranger'), $bid), 'a stranger may not');
check(!$gate->userMayAccess($uid('site_admin'), $bid), 'a site admin below 10 may not');
check(!$gate->userMayAccess(0, $bid) && !$gate->userMayAccess($uid('owner'), 0), 'nobody, and no board, are refused');
check($gate->userMayAccess($uid('stranger'), (int)$open->key), 'an Open to all members board\'s attachments open for any member');
BoardsManager::removeMember($board, BoardsManager::memberOn($board, boards_member_id($board, $uid('viewer'))));
Boards::forgetRoles();
check(!$gate->userMayAccess($uid('viewer'), $bid), 'removing a member closes the attachment to them at once');

// =====================================================================
section('removing someone takes them off the cards');

BoardsCards::addMember($card, $board, $owner, $uid('editor'), false);
BoardsCards::setWatching($card, $uid('editor'), true);
BoardsManager::setWatching($board, $uid('editor'), true);
BoardsManager::removeMember($board, BoardsManager::memberOn($board, boards_member_id($board, $uid('editor'))));
$q = DbConnector::GetPreparedStatement('SELECT COUNT(*) FROM bcm_card_members WHERE bcm_bcd_card_id = ? AND bcm_usr_user_id = ?');
$q->execute(array($cid, $uid('editor')));
check((int)$q->fetchColumn() === 0, 'their place on the card is gone');
$q = DbConnector::GetPreparedStatement('SELECT COUNT(*) FROM bbw_board_watchers WHERE bbw_bbd_board_id = ? AND bbw_usr_user_id = ?');
$q->execute(array($bid, $uid('editor')));
check((int)$q->fetchColumn() === 0, 'and their watch on the board');

// A card membership that outlived the access (the person left a group) is not shown to them.
$stale = new BoardCardMember(NULL);
$stale->set('bcm_bcd_card_id', $cid);
$stale->set('bcm_usr_user_id', $uid('stranger'));
$stale->save();
$mine = BoardsPayload::myCards($uid('stranger'));
check(!in_array($cid, array_column($mine['cards'], 'id'), true), 'a card they can no longer open is not in "My cards"');
$items = (new BoardsCalendarItemSource())->getItems(CalendarSubject::user($uid('stranger')), '2000-01-01 00:00:00', '2100-01-01 00:00:00', CalendarItem::VIS_DETAILS);
check(count($items) === 0, 'nor on their calendar');

// =====================================================================
section('text other people wrote cannot carry script');

boards_ok(boards_call('boards_comment_save', $uid('owner'), array('op' => 'create', 'card_id' => $cid,
	'body' => "<script>alert(1)</script> [click](javascript:alert(2)) ![t](https://tracker.example/p.png) <img src=x onerror=alert(3)>")), 'a hostile comment is stored');
$detail = boards_ok(boards_call('boards_card', $uid('owner'), array('card_id' => $cid)), 'and read back');
$html = '';
foreach ($detail['comments'] as $c) { $html .= $c['body_html']; }
check(stripos($html, '<script') === false && stripos($html, '<img') === false && stripos($html, 'href="javascript') === false,
	'its rendered form has no script tag, no image and no javascript: link', $html);

section('groups: a member offers only their own, a site admin any');

$g_member = make_user('BrdGM' . $suffix);
$g_in = boards_make_group('Mine ' . $suffix, array((int)$g_member->key));
$g_out = boards_make_group('Not mine ' . $suffix, array($owner));
$g_board = BoardsManager::createBoard((int)$g_member->key, 'Group board ' . $suffix);
harness_register_model('Board', (int)$g_board->key);

$found = boards_ok(boards_call('boards_members_save', (int)$g_member->key, array('op' => 'search_groups', 'board_id' => (int)$g_board->key, 'q' => $suffix)),
	'a board admin can search groups');
$names = array_column($found['groups'], 'name');
check(in_array('Mine ' . $suffix, $names, true) && !in_array('Not mine ' . $suffix, $names, true),
	'but sees only the groups they are in', json_encode($names));
boards_ok(boards_call('boards_members_save', (int)$g_member->key, array('op' => 'add_group', 'board_id' => (int)$g_board->key, 'group_id' => (int)$g_in->key, 'role' => 'viewer')),
	'and can put one of them on the board');
boards_refused(boards_call('boards_members_save', (int)$g_member->key, array('op' => 'add_group', 'board_id' => (int)$g_board->key, 'group_id' => (int)$g_out->key, 'role' => 'viewer')),
	'but not a group they are not in', 'Only groups of members');
BoardsManager::addUser($g_board, $uid('site_admin'), 'admin');
$found = boards_ok(boards_call('boards_members_save', $uid('site_admin'), array('op' => 'search_groups', 'board_id' => (int)$g_board->key, 'q' => $suffix)),
	'a site admin who admins a board can search groups');
check(count($found['groups']) === 2, 'and sees every group', json_encode(array_column($found['groups'], 'name')));

section('a search term cannot change the query');

foreach (array("x' OR '1'='1", "'; DROP TABLE usr_users; --", "%' ) OR 1=1 --") as $attack) {
	$r = boards_call('boards_members_save', $uid('owner'), array('op' => 'search_people', 'board_id' => $bid, 'q' => $attack));
	check($r->error === null && $r->data['people'] === array(), 'a one-word search of ' . json_encode($attack) . ' finds nobody and breaks nothing');
}
check(User::check_if_exists($owner), 'and the users table is intact');

section('a card on an archived board stays put');

$arch = BoardsManager::createBoard($owner, 'Archived board ' . $suffix);
harness_register_model('Board', (int)$arch->key);
$arch_list = BoardsManager::createList($arch, $owner, 'L');
$arch_card = BoardsCards::create($arch, $arch_list, $owner, 'Archived card', array('notify' => false));
BoardsCards::archive(new BoardCard((int)$arch_card->key, TRUE), $arch, $owner);
BoardsManager::archiveBoard($arch);
boards_refused(boards_call('boards_card_save', $owner, array('op' => 'restore', 'card_id' => (int)$arch_card->key)),
	'restoring a card on an archived board is refused', 'archived');
BoardsManager::restoreBoard($arch);
boards_ok(boards_call('boards_card_save', $owner, array('op' => 'restore', 'card_id' => (int)$arch_card->key)), 'but works once the board is back');

section('two people changing one card do not undo each other');

$race = BoardsCards::create($board, $w['doing'], $owner, 'Race card', array('notify' => false));
$stale_a = new BoardCard((int)$race->key, TRUE);    // person A loaded the card
$stale_b = new BoardCard((int)$race->key, TRUE);    // person B loaded it too
BoardsCards::move($stale_a, $board, $w['todo'], $owner, null, null);                  // A drags it to another list
BoardsCards::update($stale_b, $board, $owner, array('is_done' => true));             // B ticks done, from a stale copy
$now = new BoardCard((int)$race->key, TRUE);
check((int)$now->get('bcd_bls_list_id') === $todo && Boards::flag($now->get('bcd_is_done')),
	'A\'s move survives B\'s later save, and B\'s change is kept');

section('finding people returns names, never addresses');

$found = boards_ok(boards_call('boards_members_save', $uid('owner'), array('op' => 'search_people', 'board_id' => $bid, 'q' => 'HarnessTest')), 'an admin can search for people');
check(!empty($found['people']) && !array_key_exists('email', $found['people'][0]) && strpos(json_encode($found['people']), '@') === false,
	'results carry a name and a picture and no address');
$short = boards_ok(boards_call('boards_members_save', $uid('owner'), array('op' => 'search_people', 'board_id' => $bid, 'q' => 'x')), 'a one-letter search is answered');
check($short['people'] === array(), 'with nothing: a search needs at least two characters');

harness_finish();

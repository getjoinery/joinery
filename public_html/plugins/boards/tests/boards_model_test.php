<?php
/** @joinery-test
 * name: boards_model
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * The Boards data layer: ordering, who can do what on a board, archive and
 * restore, soft delete and permanent delete.
 *
 * What is pinned here is what a screen cannot be trusted to prove:
 *
 *  - a drop between two neighbours takes the midpoint, and when the gap closes
 *    the list is renumbered, in the same call, without losing the order;
 *  - the role rule — superadmin, direct row, group rows, open boards, nobody —
 *    including that a site admin (5–9) has NO special access;
 *  - deleting a board takes its cards with one timestamp, and undeleting it
 *    brings back exactly those cards;
 *  - removing a board for good leaves no row of any kind behind and removes the
 *    attachment Files.
 *
 * Run: php tests/run.php test-db --filter=boards_model
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/boards_test_support.php');

$suffix = strtoupper(LibraryFunctions::random_string(5));

/** The card ids on a list, in order (live, not archived). */
function boards_order(int $list_id): array {
	$q = DbConnector::GetPreparedStatement(
		'SELECT bcd_card_id FROM bcd_cards WHERE bcd_bls_list_id = ? AND bcd_delete_time IS NULL AND bcd_archived_time IS NULL ORDER BY bcd_position, bcd_card_id');
	$q->execute(array($list_id));
	return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

function boards_position(int $card_id): float {
	$q = DbConnector::GetPreparedStatement('SELECT bcd_position FROM bcd_cards WHERE bcd_card_id = ?');
	$q->execute(array($card_id));
	return (float)$q->fetchColumn();
}

function boards_count(string $table, string $where, array $params = array()): int {
	$q = DbConnector::GetPreparedStatement("SELECT COUNT(*) FROM $table WHERE $where");
	$q->execute($params);
	return (int)$q->fetchColumn();
}

/** The membership row a person has directly on a board. */
function boards_member_row(Board $board, int $user_id): int {
	$q = DbConnector::GetPreparedStatement('SELECT bbm_board_member_id FROM bbm_board_members WHERE bbm_bbd_board_id = ? AND bbm_usr_user_id = ?');
	$q->execute(array((int)$board->key, $user_id));
	return (int)$q->fetchColumn();
}

$w = boards_world($suffix);
$board = $w['board'];
$owner = (int)$w['owner']->key;
$todo = $w['todo'];
$doing = $w['doing'];
$db = DbConnector::get_instance()->get_db_link();

// =====================================================================
section('a drop between two cards takes the midpoint');

$a = $w['card'];
$b = BoardsCards::create($board, $todo, $owner, 'Second card', array('notify' => false));
$c = BoardsCards::create($board, $todo, $owner, 'Third card', array('notify' => false));
check(boards_order((int)$todo->key) === array((int)$a->key, (int)$b->key, (int)$c->key), 'cards are created in order');

$mid = Boards::computePosition('card', (int)$todo->key, (int)$a->key, (int)$b->key);
check($mid > boards_position((int)$a->key) && $mid < boards_position((int)$b->key),
	'between two cards lands strictly between them', "mid=$mid");
check(Boards::computePosition('card', (int)$todo->key, null, (int)$a->key) < boards_position((int)$a->key),
	'above the first card is before it');
check(Boards::computePosition('card', (int)$todo->key, (int)$c->key, null) > boards_position((int)$c->key),
	'below the last card is after it');
check(Boards::computePosition('card', (int)$todo->key) > boards_position((int)$c->key),
	'no neighbours means the end of the list');

$only_prev = Boards::computePosition('card', (int)$todo->key, (int)$a->key, null);
check($only_prev > boards_position((int)$a->key) && $only_prev < boards_position((int)$b->key),
	'naming only the card above puts it between that card and the one really below it');

$d = BoardsCards::create($board, $doing, $owner, 'On doing', array('notify' => false));
$stale = Boards::computePosition('card', (int)$todo->key, (int)$d->key, null);
check($stale > boards_position((int)$c->key),
	'a neighbour from another list (a stale drag) is ignored and the card goes to the end');

section('moving a card changes its list and its place');

BoardsCards::move($c, $board, $todo, $owner, (int)$a->key, (int)$b->key);
check(boards_order((int)$todo->key) === array((int)$a->key, (int)$c->key, (int)$b->key), 'a move within a list reorders it');

BoardsCards::move($c, $board, $doing, $owner, null, (int)$d->key);
check(boards_order((int)$doing->key) === array((int)$c->key, (int)$d->key), 'a move to another list lands above the named card');
check((int)(new BoardCard((int)$c->key, TRUE))->get('bcd_bls_list_id') === (int)$doing->key, 'and the card is on the new list');

$loud = boards_count('bac_activity', "bac_bcd_card_id = ? AND bac_verb = 'card.moved' AND (bac_data->>'quiet') IS NULL", array((int)$c->key));
check($loud === 1, 'a move to another list is recorded for the history', "rows=$loud");

$quiet_before = boards_count('bac_activity', "bac_bcd_card_id = ? AND bac_verb = 'card.moved' AND (bac_data->>'quiet') = 'true'", array((int)$c->key));
BoardsCards::move($c, $board, $doing, $owner, (int)$d->key, null);
$quiet_after = boards_count('bac_activity', "bac_bcd_card_id = ? AND bac_verb = 'card.moved' AND (bac_data->>'quiet') = 'true'", array((int)$c->key));
check($quiet_after === $quiet_before + 1, 'a reorder inside a list is recorded quietly, for the poll only', "before=$quiet_before after=$quiet_after");

section('when the gap closes the list is renumbered');

// Park the cards so $a and $b are 0.00001 apart, with a third card after them.
$e = BoardsCards::create($board, $todo, $owner, 'Fourth card', array('notify' => false));
$db->prepare('UPDATE bcd_cards SET bcd_position = 1.000000 WHERE bcd_card_id = ?')->execute(array((int)$a->key));
$db->prepare('UPDATE bcd_cards SET bcd_position = 1.000010 WHERE bcd_card_id = ?')->execute(array((int)$b->key));
$db->prepare('UPDATE bcd_cards SET bcd_position = 3.000000 WHERE bcd_card_id = ?')->execute(array((int)$e->key));

$order_before = boards_order((int)$todo->key);
$position = Boards::computePosition('card', (int)$todo->key, (int)$a->key, (int)$b->key);
$pa = boards_position((int)$a->key);
$pb = boards_position((int)$b->key);
check($pb - $pa >= 1000, 'a crowded list is respaced', "a=$pa b=$pb");
check($position > $pa && $position < $pb, 'and the new place is strictly between the neighbours', "pos=$position a=$pa b=$pb");
check(boards_order((int)$todo->key) === $order_before, 'renumbering never changes the order');

// =====================================================================
section('roles: who can do what on a board');

$role = function (User $u) use ($board) { Boards::forgetRoles(); return Boards::roleFor($board, (int)$u->key); };

check($role($w['owner']) === 'admin', 'the owner (direct admin row) is an admin');
check($role($w['editor']) === 'editor', 'a direct editor row is an editor');
check($role($w['viewer']) === 'viewer', 'a direct viewer row is a viewer');
check($role($w['stranger']) === null, 'a member with no row has no role on an invite-only board');
check($role($w['superadmin']) === 'admin', 'a superadmin (permission 10) is an admin on every board');
check($role($w['site_admin']) === null, 'a site admin below 10 gets NO special access');

$member_a = make_user('BrdGrA' . $suffix);
$member_b = make_user('BrdGrB' . $suffix);
$group_viewers = boards_make_group('Boards viewers ' . $suffix, array((int)$member_a->key, (int)$member_b->key));
$group_editors = boards_make_group('Boards editors ' . $suffix, array((int)$member_b->key));
BoardsManager::addGroup($board, (int)$group_viewers->key, 'viewer');
BoardsManager::addGroup($board, (int)$group_editors->key, 'editor');
check($role($member_a) === 'viewer', 'a member of a group on the board gets the group\'s role');
check($role($member_b) === 'editor', 'a member of several groups gets the highest role among them');

BoardsManager::addUser($board, (int)$member_b->key, 'viewer');
check($role($member_b) === 'viewer', 'a direct row wins over the groups, even when it is lower');
BoardsManager::removeMember($board, BoardsManager::memberOn($board, boards_member_row($board, (int)$member_b->key)));
check($role($member_b) === 'editor', 'and removing it hands back to the groups');

$group_viewers->remove_member((int)$member_a->key);
check($role($member_a) === null, 'group membership is live: someone who leaves the group loses the board at once');

$event_group = new Group(NULL);
$event_group->set('grp_name', 'Boards event group ' . $suffix);
$event_group->set('grp_category', 'event');
$event_group->save();
harness_register_model('Group', (int)$event_group->key);
$refused = false;
try { BoardsManager::addGroup($board, (int)$event_group->key, 'editor'); } catch (BoardsRefusal $ex) { $refused = true; }
check($refused, 'only user-category groups can be added to a board');

// Open to all members
$open = BoardsManager::createBoard($owner, 'Open board ' . $suffix, array('visibility' => Board::VISIBILITY_ALL_MEMBERS));
harness_register_model('Board', (int)$open->key);
Boards::forgetRoles();
check(Boards::roleFor($open, (int)$w['stranger']->key) === 'viewer', 'on an Open to all members board any signed-in member is a viewer');
BoardsManager::join($open, (int)$w['stranger']->key);
Boards::forgetRoles();
check(Boards::roleFor($open, (int)$w['stranger']->key) === 'editor', 'and joining makes them an editor');
$refused = false;
try { BoardsManager::join($board, (int)$w['viewer']->key); } catch (BoardsRefusal $ex) { $refused = true; }
check($refused, 'joining an invite-only board is refused');

check(Boards::roleFor(new Board(NULL), (int)$w['owner']->key) === null, 'a board that does not exist has no role for anyone');

section('the last admin cannot be removed');

$refused = false;
try { BoardsManager::removeMember($board, BoardsManager::memberOn($board, boards_member_row($board, $owner))); }
catch (BoardsRefusal $ex) { $refused = true; }
check($refused, 'removing the only admin is refused');
$refused = false;
try { BoardsManager::setRole($board, BoardsManager::memberOn($board, boards_member_row($board, $owner)), 'viewer'); }
catch (BoardsRefusal $ex) { $refused = true; }
check($refused, 'so is lowering the only admin');

// =====================================================================
section('archive and restore');

BoardsCards::archive($e, $board, $owner);
check(!in_array((int)$e->key, boards_order((int)$todo->key), true), 'an archived card leaves its list');
$page = BoardsPayload::boardPage($board, $owner, 'admin');
$ids = array_map(function ($card) { return $card['id']; }, $page['cards']);
check(!in_array((int)$e->key, $ids, true), 'and the board page');
$page = BoardsPayload::boardPage($board, $owner, 'admin', true);
$ids = array_map(function ($card) { return $card['id']; }, $page['cards']);
check(in_array((int)$e->key, $ids, true), 'but the archive panel (include_archived) still has it');

$e = new BoardCard((int)$e->key, TRUE);
BoardsCards::restore($e, $board, $owner);
$todo_order = boards_order((int)$todo->key);
check(end($todo_order) === (int)$e->key, 'a restored card comes back at the bottom of its list');

BoardsManager::archiveList($board, $doing, $owner);
$page = BoardsPayload::boardPage($board, $owner, 'admin');
check(count($page['lists']) === 1, 'an archived list leaves the board');
$doing = new BoardList((int)$doing->key, TRUE);
BoardsManager::restoreList($board, $doing, $owner);
$page = BoardsPayload::boardPage($board, $owner, 'admin');
check(count($page['lists']) === 2 && $page['lists'][1]['id'] === (int)$doing->key, 'and comes back at the end');

BoardsManager::archiveBoard($board);
$refused = false;
try { Boards::requireRole($board, (int)$w['editor']->key, 'editor'); } catch (BoardsRefusal $ex) { $refused = true; }
check($refused, 'an archived board is read-only: an editor is refused');
$readable = true;
try { Boards::requireRole($board, (int)$w['viewer']->key, 'viewer'); } catch (BoardsRefusal $ex) { $readable = false; }
check($readable, 'but anyone with a role can still read it');
$allowed = true;
try { Boards::requireRole($board, $owner, 'admin', true); } catch (BoardsRefusal $ex) { $allowed = false; }
check($allowed, 'and an admin can still restore it');
BoardsManager::restoreBoard($board);

// =====================================================================
section('delete takes the cards with it, undelete brings back exactly those');

$gone_earlier = BoardsCards::create($board, $todo, $owner, 'Deleted on its own', array('notify' => false));
$gone_earlier->soft_delete();
$live_before = boards_count('bcd_cards', 'bcd_bbd_board_id = ? AND bcd_delete_time IS NULL', array((int)$board->key));

$board->soft_delete();
check(boards_count('bcd_cards', 'bcd_bbd_board_id = ? AND bcd_delete_time IS NULL', array((int)$board->key)) === 0,
	'deleting a board deletes its cards');
check(Boards::roleFor(new Board((int)$board->key, TRUE), $owner) === null, 'and a deleted board has no role for anyone');

$board = new Board((int)$board->key, TRUE);
$board->undelete();
check(boards_count('bcd_cards', 'bcd_bbd_board_id = ? AND bcd_delete_time IS NULL', array((int)$board->key)) === $live_before,
	'undelete brings back the cards the delete took');
$still = new BoardCard((int)$gone_earlier->key, TRUE);
check((bool)$still->get('bcd_delete_time'), 'and leaves alone a card that was deleted earlier on its own');
Boards::forgetRoles();

// =====================================================================
section('a card moves between boards, keeping what it should');

$other = BoardsManager::createBoard($owner, 'Other board ' . $suffix);
harness_register_model('Board', (int)$other->key);
$other_list = BoardsManager::createList($other, $owner, 'Inbox');

$urgent = BoardsManager::saveLabel($board, null, 'Urgent', 'red');
$mover = BoardsCards::create($board, $todo, $owner, 'Moving card', array('notify' => false));
BoardsCards::addLabel($mover, $board, $owner, (int)$urgent->key);
BoardsCards::addMember($mover, $board, $owner, (int)$w['editor']->key, false);   // not on the other board
BoardsCards::addMember($mover, $board, $owner, $owner, false);                    // on both
$checklist = BoardsCards::addChecklist($mover, $board, $owner, 'Steps');
BoardsCards::addItem($checklist, $mover, $board, $owner, 'Step one');
BoardsCards::addComment($mover, $board, $owner, 'A comment that travels', array());
$file = File::createFromBytes('hello', 'note.txt', 'text/plain', $owner, array(
	'fil_private' => false, 'fil_access_provider' => BoardsAttachmentGate::KEY,
	'fil_access_ref' => (int)$board->key, 'fil_source' => File::SOURCE_BOARDS_ATTACHMENT));
$attachment = BoardsCards::attachFile($mover, $board, $owner, $file);

$mover = new BoardCard((int)$mover->key, TRUE);
BoardsCards::moveToBoard($mover, $board, $other, $other_list, $owner);
$mover = new BoardCard((int)$mover->key, TRUE);

check((int)$mover->get('bcd_bbd_board_id') === (int)$other->key && (int)$mover->get('bcd_bls_list_id') === (int)$other_list->key,
	'the card is on the other board and its list');
$label_there = boards_count('blb_labels', "blb_bbd_board_id = ? AND blb_name = 'Urgent' AND blb_color = 'red'", array((int)$other->key));
check($label_there === 1, 'its label was made on the other board by name and colour', "labels=$label_there");
check(boards_count('bcl_card_labels', 'bcl_bcd_card_id = ?', array((int)$mover->key)) === 1, 'and still carries it');
check(boards_count('bcm_card_members', 'bcm_bcd_card_id = ? AND bcm_usr_user_id = ?', array((int)$mover->key, (int)$w['editor']->key)) === 0,
	'a member who cannot open the other board is dropped from the card');
check(boards_count('bcm_card_members', 'bcm_bcd_card_id = ? AND bcm_usr_user_id = ?', array((int)$mover->key, $owner)) === 1,
	'a member who can stays');
$arrived = (string)boards_scalar("SELECT bac_data::text FROM bac_activity WHERE bac_bcd_card_id = ? AND bac_bbd_board_id = ? AND bac_verb = 'card.moved'", array((int)$mover->key, (int)$other->key));
$left = (string)boards_scalar("SELECT bac_data::text FROM bac_activity WHERE bac_bcd_card_id = ? AND bac_bbd_board_id = ? AND bac_verb = 'card.moved' ORDER BY bac_activity_id DESC LIMIT 1", array((int)$mover->key, (int)$board->key));
check(stripos($arrived, (string)$board->get('bbd_name')) === false && stripos($left, (string)$other->get('bbd_name')) === false,
	'each board\'s history says the card arrived or left, without naming the other board', $arrived . ' / ' . $left);
check(boards_count('bck_checklists', 'bck_bcd_card_id = ?', array((int)$mover->key)) === 1
	&& boards_count('bco_comments', 'bco_bcd_card_id = ?', array((int)$mover->key)) === 1, 'checklists and comments travel');
$file = new File((int)$file->key, TRUE);
check((int)$file->get('fil_access_ref') === (int)$other->key, 'the attachment is now gated on the other board');
check(!(new BoardsAttachmentGate())->userMayAccess((int)$w['editor']->key, (int)$other->key), 'so the editor of the first board cannot open it');

section('a copy keeps the shape and none of the people');

BoardsCards::addMember($mover, $other, $owner, $owner, false);
$copy = BoardsCards::copy($mover, $other, $other, $other_list, $owner, array('title' => 'Copy of it'));
check($copy->key && $copy->key != $mover->key && $copy->get('bcd_title') === 'Copy of it', 'a copy is a new card');
check(boards_count('bci_checklist_items', 'bci_bck_checklist_id IN (SELECT bck_checklist_id FROM bck_checklists WHERE bck_bcd_card_id = ?)', array((int)$copy->key)) === 1,
	'with the checklist and its items');
check(boards_count('bcm_card_members', 'bcm_bcd_card_id = ?', array((int)$copy->key)) === 0, 'but not the members');
check(boards_count('bco_comments', 'bco_bcd_card_id = ?', array((int)$copy->key)) === 0
	&& boards_count('bat_attachments', 'bat_bcd_card_id = ?', array((int)$copy->key)) === 0, 'or the comments and attachments');

// =====================================================================
section('the card ceiling');

harness_set_setting_mem('boards_max_cards_per_board', '3');
$small = BoardsManager::createBoard($owner, 'Small board ' . $suffix);
harness_register_model('Board', (int)$small->key);
$small_list = BoardsManager::createList($small, $owner, 'Only list');
for ($i = 1; $i <= 3; $i++) {
	BoardsCards::create($small, $small_list, $owner, 'Card ' . $i, array('notify' => false));
}
$refused = '';
try { BoardsCards::create($small, $small_list, $owner, 'One too many', array('notify' => false)); } catch (BoardsRefusal $ex) { $refused = $ex->getMessage(); }
check(stripos($refused, 'limit') !== false, 'a board at its ceiling refuses another card', $refused);

// =====================================================================
section('mentions need the name in the text');

$name = Boards::person((int)$w['editor']->key)['name'];
$both = array((int)$w['editor']->key, (int)$w['viewer']->key);
$found = BoardsCards::newMentions(null, 'Hello @' . $name . ' please look', $both);
check($found === array((int)$w['editor']->key), 'a person is mentioned when @name is in the text and not otherwise');
check(BoardsCards::newMentions('Hello @' . $name, 'Hello @' . $name . ' again', $both) === array(),
	'saving a description again does not mention the same person twice');
check(BoardsCards::newMentions('Hello', 'Hello @' . strtoupper($name), $both) === array((int)$w['editor']->key),
	'the match ignores case');

boards_removal_checks($w, $suffix);

harness_finish();

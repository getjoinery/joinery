<?php
/** @joinery-test
 * name: boards_integration
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Boards inside the rest of the site: the personal calendar, the member
 * dashboard, "My cards", the board list, and the board JSON the browser draws.
 *
 * Run: php tests/run.php test-db --filter=boards_integration
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
$editor = (int)$w['editor']->key;

$tz = 'America/New_York';
$w['editor']->set('usr_timezone', $tz);
$w['editor']->save();

/** A local wall-clock time in the editor's zone, as the UTC string the column holds. */
function boards_local_to_utc(string $local, string $tz): string {
	return LibraryFunctions::convert_time($local, $tz, 'UTC', 'Y-m-d H:i:s');
}

$window_start = gmdate('Y-m-d 00:00:00', time() - 2 * 86400);
$window_end = gmdate('Y-m-d 00:00:00', time() + 10 * 86400);
$subject = CalendarSubject::user($editor);
$source = new BoardsCalendarItemSource();

// =====================================================================
section('due cards on the personal calendar');

$timed = BoardsCards::create($board, $w['todo'], $owner, 'Timed card', array('notify' => false));
BoardsCards::addMember($timed, $board, $owner, $editor, false);
$timed_utc = boards_local_to_utc(date('Y-m-d', time() + 3 * 86400) . ' 15:30:00', $tz);
BoardsCards::update(new BoardCard((int)$timed->key, TRUE), $board, $owner, array('due' => $timed_utc));

$all_day = BoardsCards::create($board, $w['todo'], $owner, 'All-day card', array('notify' => false));
BoardsCards::addMember($all_day, $board, $owner, $editor, false);
$midnight_utc = boards_local_to_utc(date('Y-m-d', time() + 4 * 86400) . ' 00:00:00', $tz);
BoardsCards::update(new BoardCard((int)$all_day->key, TRUE), $board, $owner, array('due' => $midnight_utc));

$finished = BoardsCards::create($board, $w['todo'], $owner, 'Done card', array('notify' => false));
BoardsCards::addMember($finished, $board, $owner, $editor, false);
BoardsCards::update(new BoardCard((int)$finished->key, TRUE), $board, $owner, array('due' => $timed_utc, 'is_done' => true));

$not_mine = BoardsCards::create($board, $w['todo'], $owner, 'Not on me', array('notify' => false));
BoardsCards::update(new BoardCard((int)$not_mine->key, TRUE), $board, $owner, array('due' => $timed_utc));

$checklist = BoardsCards::addChecklist($w['card'], $board, $owner, 'Steps');
BoardsCards::addItem($checklist, $w['card'], $board, $owner, 'My step', array(
	'user_id' => $editor, 'due' => boards_local_to_utc(date('Y-m-d', time() + 9 * 86400) . ' 09:00:00', $tz), 'notify' => false));

$items = $source->getItems($subject, $window_start, $window_end, CalendarItem::VIS_DETAILS);
$by_title = array();
foreach ($items as $item) { $by_title[$item->title] = $item; }

check(isset($by_title['Timed card']) && !$by_title['Timed card']->all_day, 'a card with a due time is a timed item');
check(isset($by_title['Timed card']) && $by_title['Timed card']->start_utc === $timed_utc, 'at its due time', (string)($by_title['Timed card']->start_utc ?? ''));
check(isset($by_title['All-day card']) && $by_title['All-day card']->all_day, 'a due time at local midnight is an all-day item');
check(!isset($by_title['Done card']), 'a done card is not on the calendar');
check(!isset($by_title['Not on me']), 'nor a card the person is not on');
check(isset($by_title['My step (First card)']), 'a checklist item assigned to the person is, named with its card');
check(isset($by_title['Timed card']) && $by_title['Timed card']->url === '/profile/boards/board?id=' . $board->key . '&card=' . $timed->key,
	'an item links to its card');
check(isset($by_title['Timed card']) && $by_title['Timed card']->blocks_availability === false, 'and a due date never blocks the person\'s time');
check($source->getItems($subject, $window_start, $window_end, CalendarItem::VIS_BUSY) === array(),
	'someone viewing only busy time sees nothing of a person\'s to-do list');
check($source->getItems(CalendarSubject::user((int)$w['viewer']->key), $window_start, $window_end, CalendarItem::VIS_DETAILS) === array(),
	'and another person\'s calendar has none of it');

$registry = CalendarItemSourceRegistry::getSources();
check(isset($registry['boards']), 'the calendar discovers the source on its own');

BoardsCards::archive(new BoardCard((int)$timed->key, TRUE), $board, $owner);
$titles = array_map(function ($i) { return $i->title; }, $source->getItems($subject, $window_start, $window_end, CalendarItem::VIS_DETAILS));
check(!in_array('Timed card', $titles, true), 'an archived card leaves the calendar');

BoardsManager::setTemplate($board, true);
check($source->getItems($subject, $window_start, $window_end, CalendarItem::VIS_DETAILS) === array(), 'cards on a template board are never on a calendar');
BoardsManager::setTemplate($board, false);

harness_set_setting_mem('boards_active', '0');
check($source->getItems($subject, $window_start, $window_end, CalendarItem::VIS_DETAILS) === array(), 'with Boards switched off the calendar shows nothing of it');
harness_set_setting_mem('boards_active', '1');

// =====================================================================
section('"My cards"');

$mine = BoardsPayload::myCards($editor);
$titles = array_column($mine['cards'], 'title');
check(in_array('All-day card', $titles, true) && in_array('My step', $titles, true), 'lists the cards and the checklist items assigned to the person');
check(!in_array('Done card', $titles, true) && !in_array('Not on me', $titles, true) && !in_array('Timed card', $titles, true),
	'and not done, archived or someone else\'s');
$dues = array_column($mine['cards'], 'due');
$sorted = $dues;
usort($sorted, function ($a, $b) { return $a === null ? 1 : ($b === null ? -1 : strcmp($a, $b)); });
check($dues === $sorted, 'soonest due first, undated last');
$item_row = null;
foreach ($mine['cards'] as $row) { if ($row['kind'] === 'item') { $item_row = $row; } }
check($item_row && $item_row['card_title'] === 'First card' && $item_row['board_name'] === $board->get('bbd_name'), 'an item row names its card and board');

$week = BoardsPayload::myCards($editor, array('window' => 'week'));
check(in_array('All-day card', array_column($week['cards'], 'title'), true) && !in_array('My step', array_column($week['cards'], 'title'), true),
	'the week window keeps what is due within seven days and drops what is later');

// =====================================================================
section('the member dashboard');

// serve.php loads the provider on a web request.
require_once(PathHelper::getIncludePath('plugins/boards/includes/profile_dashboard_provider.php'));

$section = boards_dashboard_cards_due($w['editor']);
check($section instanceof ProfileDashboardSection && $section->id === 'boards_cards_due' && $section->title === 'My cards due this week',
	'contributes "My cards due this week"');
check($section->stat->count >= 1 && count($section->items) >= 1, 'with a count and its items');
check($section->items[0]->url === '/profile/boards/board?id=' . $board->key . '&card=' . $all_day->key
	|| strpos($section->items[0]->url, '/profile/boards/board?id=') === 0, 'each linking to its card');
check(boards_dashboard_cards_due($w['viewer']) === null, 'a person with nothing due gets no section');
harness_set_setting_mem('boards_active', '0');
check(boards_dashboard_cards_due($w['editor']) === null, 'and with Boards off, none');
harness_set_setting_mem('boards_active', '1');

// =====================================================================
section('the board list');

BoardsManager::setTemplate($board, true);
$index = BoardsPayload::boardIndex($owner);
$ids = function (array $group) { return array_column($group, 'id'); };
check(in_array((int)$board->key, $ids($index['templates']), true) && !in_array((int)$board->key, $ids($index['mine']), true), 'a template board is listed under templates');
BoardsManager::setTemplate($board, false);
BoardsManager::archiveBoard($board);
$index = BoardsPayload::boardIndex($owner);
check(in_array((int)$board->key, $ids($index['archived']), true), 'an archived board is under archived');
BoardsManager::restoreBoard($board);
$index = BoardsPayload::boardIndex($owner);
check(in_array((int)$board->key, $ids($index['mine']), true), 'a live board is under mine');
$entry = null;
foreach ($index['mine'] as $b) { if ($b['id'] === (int)$board->key) { $entry = $b; } }
check($entry && $entry['role'] === 'admin' && $entry['cards'] >= 1, 'with the person\'s role and a card count');

section('starter and template boards');

$starters = BoardsTemplates::starters();
check(array_keys($starters) === array('event_planning', 'volunteer_tasks', 'content_calendar', 'simple_pipeline'), 'the four starter boards ship');
foreach (array_keys($starters) as $key) {
	$made = BoardsManager::createBoard($owner, 'From ' . $key . ' ' . $suffix, array('starter' => $key));
	harness_register_model('Board', (int)$made->key);
	$page = BoardsPayload::boardPage($made, $owner, 'admin');
	check(count($page['lists']) >= 4 && count($page['labels']) >= 3 && count($page['cards']) >= 1, "$key builds lists, labels and example cards");
}
$event = BoardsManager::createBoard($owner, 'Event ' . $suffix, array('starter' => 'event_planning'));
harness_register_model('Board', (int)$event->key);
$page = BoardsPayload::boardPage($event, $owner, 'admin');
$with_list = 0;
foreach ($page['cards'] as $c) { if ($c['checklist']['total'] > 0) { $with_list++; } }
check($with_list >= 2, 'example cards carry their checklists');

BoardsManager::setTemplate($event, true);
$copy = BoardsManager::createBoard($owner, 'Copy of event ' . $suffix, array('template_id' => (int)$event->key));
harness_register_model('Board', (int)$copy->key);
$copy_page = BoardsPayload::boardPage($copy, $owner, 'admin');
check(count($copy_page['lists']) === count($page['lists']) && count($copy_page['cards']) === count($page['cards'])
	&& count($copy_page['labels']) === count($page['labels']), 'a board made from a template has its lists, labels and cards');
$copy_labels = 0;
foreach ($copy_page['cards'] as $c) { $copy_labels += count($c['labels']); }
$src_labels = 0;
foreach ($page['cards'] as $c) { $src_labels += count($c['labels']); }
check($copy_labels === $src_labels && $copy_labels > 0, 'and its cards keep their labels');
check($copy_page['people'] === array(array_merge($copy_page['people'][0], array('id' => $owner, 'role' => 'admin'))), 'but none of the people: only its maker, as admin');
$refused = false;
try { BoardsManager::createBoard($owner, 'Not a template', array('template_id' => (int)$board->key)); } catch (BoardsRefusal $e) { $refused = true; }
check($refused, 'a board that is not a template cannot be copied this way');

section('a renumbered list is resent to the other browsers');

$cur = Boards::latestActivityId((int)$board->key);
$spread = BoardsCards::create($board, $w['doing'], $owner, 'Spread 1', array('notify' => false));
$spread2 = BoardsCards::create($board, $w['doing'], $owner, 'Spread 2', array('notify' => false));
$db = DbConnector::get_instance()->get_db_link();
$db->prepare('UPDATE bcd_cards SET bcd_position = 5.000000 WHERE bcd_card_id = ?')->execute(array((int)$spread->key));
$db->prepare('UPDATE bcd_cards SET bcd_position = 5.000010 WHERE bcd_card_id = ?')->execute(array((int)$spread2->key));
$cur = Boards::latestActivityId((int)$board->key);
Boards::computePosition('card', (int)$w['doing']->key, (int)$spread->key, (int)$spread2->key);
$poll = BoardsPayload::poll($board, $owner, 'admin', $cur, '');
$sent = array_column($poll['cards'], 'id');
check(in_array((int)$spread->key, $sent, true) && in_array((int)$spread2->key, $sent, true),
	'after a renumber the poll resends every card on the list, not only the one that moved');

section('cards on an archived list are not live');

$arch_list2 = BoardsManager::createList($board, $owner, 'To be archived');
$hidden = BoardsCards::create($board, $arch_list2, $owner, 'Hidden card', array('notify' => false));
BoardsCards::addMember($hidden, $board, $owner, $editor, false);
BoardsCards::update(new BoardCard((int)$hidden->key, TRUE), $board, $owner, array('due' => gmdate('Y-m-d H:i:s', time() + 3600)));
BoardsManager::archiveList($board, $arch_list2, $owner);
check(!in_array('Hidden card', array_column(BoardsPayload::myCards($editor)['cards'], 'title'), true), 'they are not in "My cards"');
$t = array_map(function ($i) { return $i->title; }, $source->getItems($subject, $window_start, $window_end, CalendarItem::VIS_DETAILS));
check(!in_array('Hidden card', $t, true), 'nor on the calendar');
$before = boards_scalar('SELECT COUNT(*) FROM ntf_notifications WHERE ntf_body LIKE ?', array('%is due%'));
require_once(PathHelper::getIncludePath('plugins/boards/tasks/BoardsDueReminders.php'));
(new BoardsDueReminders())->run(array());
check(boards_scalar('SELECT COUNT(*) FROM ntf_notifications WHERE ntf_body LIKE ?', array('%is due%')) == $before, 'nor reminded about');

section('the board JSON the browser draws');

$page = BoardsPayload::boardPage($board, $owner, 'admin');
check(isset($page['cursor']) && $page['cursor'] === Boards::latestActivityId((int)$board->key), 'the page carries the poll cursor');
check(!empty($page['people_stamp']) && count($page['people']) >= 3, 'the people and their stamp');
$poll = BoardsPayload::poll($board, $owner, 'admin', $page['cursor'], $page['people_stamp']);
check($poll['cards'] === array() && $poll['gone'] === array() && !isset($poll['people']), 'a poll at the cursor reports nothing and omits an unchanged people list');
BoardsCards::update($w['card'], $board, $owner, array('title' => 'Renamed for the poll'));
$poll = BoardsPayload::poll($board, $owner, 'admin', $page['cursor'], $page['people_stamp']);
check(count($poll['cards']) === 1 && $poll['cards'][0]['title'] === 'Renamed for the poll' && $poll['cursor'] > $page['cursor'], 'a change shows up in the next poll, with the card as it now stands');
BoardsCards::delete(new BoardCard((int)$w['card']->key, TRUE), $board, $owner);
$poll2 = BoardsPayload::poll($board, $owner, 'admin', $poll['cursor'], $page['people_stamp']);
check(in_array((int)$w['card']->key, $poll2['gone'], true), 'a deleted card is reported as gone');
BoardsManager::addUser($board, (int)$w['stranger']->key, 'viewer');
$poll3 = BoardsPayload::poll($board, $owner, 'admin', $poll2['cursor'], $page['people_stamp']);
check(isset($poll3['people']) && $poll3['people_stamp'] !== $page['people_stamp'], 'and a changed people list is sent again with a new stamp');

harness_finish();

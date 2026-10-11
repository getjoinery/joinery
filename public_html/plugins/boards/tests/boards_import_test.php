<?php
/** @joinery-test
 * name: boards_import
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Importing a board from another kanban tool's JSON export.
 *
 * The fixture is shaped like such an export: a board with labels, lists (one
 * closed), cards (one closed, one done), several checklists on one card,
 * attachments (a link and an upload), members (one with an email that matches a
 * site member, one without) and an action log carrying comments.
 *
 * What is pinned here: the shape arrives intact, the people are matched or
 * credited rather than invented, uploaded files come across as links and nothing
 * is fetched, and a hostile export cannot smuggle in script links or break the
 * card ceiling.
 *
 * Run: php tests/run.php test-db --filter=boards_import
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/boards_test_support.php');

$suffix = strtoupper(LibraryFunctions::random_string(5));
$importer = make_user('BrdImp' . $suffix);
$matched = make_user('BrdMat' . $suffix);
$picked = make_user('BrdPick' . $suffix);
$iid = (int)$importer->key;

/** A fixture export. */
function boards_fixture_export(string $matched_email): array {
	return array(
		'name' => "Imported\nboard",
		'desc' => 'Where the work lives.',
		'prefs' => array('background' => 'purple_dark'),
		'members' => array(
			array('id' => 'm-matched', 'fullName' => 'Matched Person', 'username' => 'matched', 'email' => $matched_email),
			array('id' => 'm-picked', 'fullName' => 'Picked Person', 'username' => 'picked'),
			array('id' => 'm-unknown', 'fullName' => 'Unknown Person', 'username' => 'unknown'),
		),
		'labels' => array(
			array('id' => 'lb-1', 'name' => 'Urgent', 'color' => 'red', 'pos' => 1),
			array('id' => 'lb-2', 'name' => 'Calm', 'color' => 'green_dark', 'pos' => 2),
			array('id' => 'lb-3', 'name' => '', 'color' => 'black', 'pos' => 3),
			array('id' => 'lb-4', 'name' => 'Odd', 'color' => null, 'pos' => 4),
		),
		'lists' => array(
			array('id' => 'ls-2', 'name' => 'Doing', 'closed' => false, 'pos' => 2000),
			array('id' => 'ls-1', 'name' => 'To do', 'closed' => false, 'pos' => 1000),
			array('id' => 'ls-3', 'name' => 'Old list', 'closed' => true, 'pos' => 3000),
		),
		'cards' => array(
			array('id' => 'cd-2', 'idList' => 'ls-1', 'name' => 'Second', 'desc' => 'Two', 'pos' => 2000, 'idLabels' => array('lb-2'), 'idMembers' => array()),
			array('id' => 'cd-1', 'idList' => 'ls-1', 'name' => "First\ncard", 'desc' => "# Heading\nBody", 'pos' => 1000,
				'due' => '2026-12-24T09:30:00.000Z', 'dueComplete' => false,
				'idLabels' => array('lb-1', 'lb-3', 'lb-4', 'lb-missing'),
				'idMembers' => array('m-matched', 'm-picked', 'm-unknown'),
				'attachments' => array(
					array('name' => 'Plan', 'url' => 'https://example.test/plan', 'isUpload' => false),
					array('name' => 'scan.pdf', 'url' => 'https://example.test/files/scan.pdf', 'isUpload' => true),
					array('name' => 'Bad', 'url' => 'javascript:alert(1)', 'isUpload' => false),
				)),
			array('id' => 'cd-3', 'idList' => 'ls-2', 'name' => 'Finished', 'desc' => '', 'pos' => 1000, 'due' => '2026-11-01T00:00:00.000Z', 'dueComplete' => true,
				'idLabels' => array(), 'idMembers' => array()),
			array('id' => 'cd-4', 'idList' => 'ls-2', 'name' => 'Put away', 'desc' => '', 'pos' => 2000, 'closed' => true, 'idLabels' => array(), 'idMembers' => array()),
			array('id' => 'cd-5', 'idList' => 'ls-nowhere', 'name' => 'On a list the export does not describe', 'pos' => 1),
		),
		'checklists' => array(
			array('id' => 'ck-2', 'idCard' => 'cd-1', 'name' => 'Second checklist', 'pos' => 2000, 'checkItems' => array(
				array('id' => 'it-3', 'name' => 'Assigned and dated', 'state' => 'incomplete', 'pos' => 1, 'idMember' => 'm-matched', 'due' => '2026-12-20T12:00:00.000Z'),
			)),
			array('id' => 'ck-1', 'idCard' => 'cd-1', 'name' => 'First checklist', 'pos' => 1000, 'checkItems' => array(
				array('id' => 'it-2', 'name' => 'Not done', 'state' => 'incomplete', 'pos' => 2),
				array('id' => 'it-1', 'name' => 'Done', 'state' => 'complete', 'pos' => 1),
			)),
			array('id' => 'ck-3', 'idCard' => 'cd-1', 'name' => 'Third checklist', 'pos' => 3000, 'checkItems' => array(
				array('id' => 'it-4', 'name' => 'Only item', 'state' => 'complete', 'pos' => 1),
			)),
		),
		'actions' => array(
			array('type' => 'commentCard', 'date' => '2026-03-02T10:00:00.000Z', 'idMemberCreator' => 'm-matched',
				'memberCreator' => array('id' => 'm-matched', 'fullName' => 'Matched Person'),
				'data' => array('text' => 'Comment by someone we know', 'card' => array('id' => 'cd-1'))),
			array('type' => 'commentCard', 'date' => '2026-03-01T10:00:00.000Z', 'idMemberCreator' => 'm-unknown',
				'memberCreator' => array('id' => 'm-unknown', 'fullName' => 'Unknown Person'),
				'data' => array('text' => 'Comment by someone we do not', 'card' => array('id' => 'cd-1'))),
			array('type' => 'updateCard', 'date' => '2026-03-03T10:00:00.000Z', 'data' => array('card' => array('id' => 'cd-1'))),
		),
	);
}

$export = boards_fixture_export((string)$matched->get('usr_email'));
$json = json_encode($export);

// =====================================================================
section('the preview shows what would come across and who matches');

$preview = BoardsImporter::preview($json);
check($preview['name'] === "Imported\nboard", 'it names the board');
check($preview['counts']['lists'] === 3 && $preview['counts']['cards'] === 5 && $preview['counts']['open_cards'] === 4
	&& $preview['counts']['labels'] === 4 && $preview['counts']['checklist_items'] === 4 && $preview['counts']['comments'] === 2,
	'and counts lists, cards, labels, checklist items and comments', json_encode($preview['counts']));
$by_id = array();
foreach ($preview['members'] as $m) { $by_id[$m['id']] = $m; }
check(!empty($by_id['m-matched']['match']) && !isset($by_id['m-matched']['match']['id']), 'a person whose email matches a site member is marked matched, without saying who');
check($by_id['m-picked']['match'] === null && $by_id['m-unknown']['match'] === null, 'people without a matching email are listed unmatched');
check(DbConnector::GetPreparedStatement('SELECT 1')->execute() && boards_scalar("SELECT COUNT(*) FROM bbd_boards WHERE bbd_name LIKE 'Imported%'") == 0,
	'and the preview writes nothing');

// =====================================================================
section('the import brings the shape across');

$board = BoardsImporter::import($json, $iid, array('m-picked' => (int)$picked->key));
harness_register_model('Board', (int)$board->key);
$bid = (int)$board->key;

check($board->get('bbd_name') === 'Imported board', 'the board has its name, on one line', (string)$board->get('bbd_name'));
check($board->get('bbd_description') === 'Where the work lives.', 'and description');
check($board->get('bbd_background') === 'purple', 'its background colour (dark variants fold to the base colour)');
check($board->get('bbd_visibility') === Board::VISIBILITY_INVITE_ONLY, 'it starts Invite only');
Boards::forgetRoles();
check(Boards::roleFor($board, $iid) === 'admin', 'the importer owns it and is its admin');
check(Boards::roleFor($board, (int)$matched->key) === 'editor' && Boards::roleFor($board, (int)$picked->key) === 'editor',
	'matched and picked people are on it as editors');

$lists = DbConnector::GetPreparedStatement('SELECT bls_name, bls_archived_time FROM bls_lists WHERE bls_bbd_board_id = ? ORDER BY bls_position');
$lists->execute(array($bid));
$rows = $lists->fetchAll(PDO::FETCH_ASSOC);
check(array_column($rows, 'bls_name') === array('To do', 'Doing', 'Old list'), 'lists come in the export\'s order', json_encode(array_column($rows, 'bls_name')));
check(empty($rows[0]['bls_archived_time']) && !empty($rows[2]['bls_archived_time']), 'and the closed one stays archived');

$labels = DbConnector::GetPreparedStatement('SELECT blb_name, blb_color FROM blb_labels WHERE blb_bbd_board_id = ? ORDER BY blb_position');
$labels->execute(array($bid));
$rows = $labels->fetchAll(PDO::FETCH_ASSOC);
check(array_column($rows, 'blb_color') === array('red', 'green', 'gray', 'gray'), 'label colours map to the nearest of ours', json_encode(array_column($rows, 'blb_color')));
check($rows[0]['blb_name'] === 'Urgent' && $rows[2]['blb_name'] === null, 'with their names (a nameless label stays nameless)');

$cards = DbConnector::GetPreparedStatement(
	'SELECT bcd_card_id, bcd_title, bcd_description, bcd_due_time, bcd_is_done, bcd_archived_time, bcd_source, bls_name
	 FROM bcd_cards JOIN bls_lists ON bls_list_id = bcd_bls_list_id WHERE bcd_bbd_board_id = ? ORDER BY bls_position, bcd_position');
$cards->execute(array($bid));
$rows = $cards->fetchAll(PDO::FETCH_ASSOC);
check(count($rows) === 4, 'four cards come across (the one on an unknown list is left out)', 'got ' . count($rows));
check(array_column($rows, 'bcd_title') === array('First card', 'Second', 'Finished', 'Put away'), 'in their lists, in the export\'s order, titles on one line', json_encode(array_column($rows, 'bcd_title')));
check($rows[0]['bcd_due_time'] === '2026-12-24 09:30:00', 'a due date lands in UTC', (string)$rows[0]['bcd_due_time']);
check(Boards::flag($rows[2]['bcd_is_done']) && !Boards::flag($rows[0]['bcd_is_done']), 'done state comes across');
check(!empty($rows[3]['bcd_archived_time']) && empty($rows[0]['bcd_archived_time']), 'a closed card stays archived');
check($rows[0]['bcd_source'] === 'import', 'and every card says it was imported');
check(strpos((string)$rows[0]['bcd_description'], '# Heading') === 0, 'a description keeps its Markdown');
$first = (int)$rows[0]['bcd_card_id'];

section('checklists, members, labels and links');

$cl = DbConnector::GetPreparedStatement('SELECT bck_checklist_id, bck_title FROM bck_checklists WHERE bck_bcd_card_id = ? ORDER BY bck_position');
$cl->execute(array($first));
$checklists = $cl->fetchAll(PDO::FETCH_ASSOC);
check(array_column($checklists, 'bck_title') === array('First checklist', 'Second checklist', 'Third checklist'), 'all three checklists come across, in order', json_encode(array_column($checklists, 'bck_title')));
$items = array();
foreach ($checklists as $c) {
	$q = DbConnector::GetPreparedStatement('SELECT bci_text, bci_is_done, bci_usr_user_id, bci_due_time FROM bci_checklist_items WHERE bci_bck_checklist_id = ? ORDER BY bci_position');
	$q->execute(array((int)$c['bck_checklist_id']));
	$items[$c['bck_title']] = $q->fetchAll(PDO::FETCH_ASSOC);
}
check(array_column($items['First checklist'], 'bci_text') === array('Done', 'Not done'), 'items are in the export\'s order');
check(Boards::flag($items['First checklist'][0]['bci_is_done']) && !Boards::flag($items['First checklist'][1]['bci_is_done']), 'with their done state');
check((int)$items['Second checklist'][0]['bci_usr_user_id'] === (int)$matched->key && $items['Second checklist'][0]['bci_due_time'] === '2026-12-20 12:00:00',
	'an item keeps its assignee and its due date');
check(Boards::flag($items['Third checklist'][0]['bci_is_done']), 'the last checklist is not lost');

$members = DbConnector::GetPreparedStatement('SELECT bcm_usr_user_id FROM bcm_card_members WHERE bcm_bcd_card_id = ?');
$members->execute(array($first));
$ids = array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN));
sort($ids);
$expect = array((int)$matched->key, (int)$picked->key);
sort($expect);
check($ids === $expect, 'card members are the matched and the picked, and nobody invented for the unknown one', json_encode($ids));

$lab = DbConnector::GetPreparedStatement('SELECT COUNT(*) FROM bcl_card_labels WHERE bcl_bcd_card_id = ?');
$lab->execute(array($first));
check((int)$lab->fetchColumn() === 3, 'labels are on the card (a label the export does not describe is skipped)');

$att = DbConnector::GetPreparedStatement('SELECT bat_name, bat_url, bat_fil_file_id FROM bat_attachments WHERE bat_bcd_card_id = ? ORDER BY bat_attachment_id');
$att->execute(array($first));
$rows = $att->fetchAll(PDO::FETCH_ASSOC);
check(count($rows) === 2 && $rows[0]['bat_url'] === 'https://example.test/plan' && $rows[1]['bat_url'] === 'https://example.test/files/scan.pdf'
	&& $rows[0]['bat_fil_file_id'] === null && $rows[1]['bat_fil_file_id'] === null,
	'links and uploaded files both come across as links, and no file is fetched', json_encode($rows));
check(strpos((string)(new BoardCard($first, TRUE))->get('bcd_description'), 'stays at the original board') !== false,
	'a note on the card says an uploaded file stays where it was');
check(stripos(json_encode($rows), 'javascript') === false, 'a javascript: link in the export is dropped');

section('comments keep their authors and their dates');

$co = DbConnector::GetPreparedStatement('SELECT bco_body, bco_usr_user_id, bco_create_time FROM bco_comments WHERE bco_bcd_card_id = ? ORDER BY bco_comment_id');
$co->execute(array($first));
$rows = $co->fetchAll(PDO::FETCH_ASSOC);
check(count($rows) === 2, 'both comments come across, oldest first', 'got ' . count($rows));
check(strpos($rows[0]['bco_body'], 'Originally by Unknown Person:') === 0 && (int)$rows[0]['bco_usr_user_id'] === $iid,
	'a comment is kept under the importer with "Originally by …"', $rows[0]['bco_body']);
check((int)$rows[1]['bco_usr_user_id'] === $iid && strpos($rows[1]['bco_body'], 'Originally by Matched Person:') === 0,
	'and so is one by a matched person: the export proves nothing about who wrote it, so no comment is ever put under a site member\'s name');
check(substr($rows[0]['bco_create_time'], 0, 10) === '2026-03-01' && substr($rows[1]['bco_create_time'], 0, 10) === '2026-03-02', 'with the dates they were written');

section('hostile dates are refused, not stored');

$bad = boards_fixture_export((string)$matched->get('usr_email'));
$bad['cards'][0]['due'] = '0000-00-00T00:00:00.000Z';
$refused = '';
try { BoardsImporter::import(json_encode($bad), $iid); } catch (BoardsRefusal $e) { $refused = $e->getMessage(); }
check(stripos($refused, 'not a valid date') !== false, 'a zero date in an export is refused cleanly', $refused);
check(boards_scalar("SELECT COUNT(*) FROM bbd_boards WHERE bbd_usr_user_id_owner = ?", array($iid)) == 1, 'and leaves no half-made board');

section('an import is quiet');

check(boards_scalar('SELECT COUNT(*) FROM ntf_notifications WHERE ntf_usr_user_id IN (?, ?)', array((int)$matched->key, (int)$picked->key)) == 0,
	'nobody is notified about hundreds of cards they did not make');
check(boards_scalar("SELECT COUNT(*) FROM bac_activity WHERE bac_bbd_board_id = ? AND bac_verb = 'card.created'", array($bid)) == 0,
	'and imported cards carry no made-up history');

// =====================================================================
section('a bad export is refused');

foreach (array(
	'not json at all' => 'this is not json',
	'a JSON list' => '[1,2,3]',
	'no lists' => json_encode(array('name' => 'x', 'cards' => array())),
	'no cards' => json_encode(array('name' => 'x', 'lists' => array())),
) as $label => $bad) {
	$refused = false;
	try { BoardsImporter::parse($bad); } catch (BoardsRefusal $e) { $refused = true; }
	check($refused, "$label is refused");
}

harness_set_setting_mem('boards_max_cards_per_board', '3');
$refused = '';
try { BoardsImporter::import($json, $iid); } catch (BoardsRefusal $e) { $refused = $e->getMessage(); }
check(stripos($refused, 'more cards than the limit') !== false, 'an export over the card ceiling is refused before anything is made', $refused);
check(boards_scalar("SELECT COUNT(*) FROM bbd_boards WHERE bbd_usr_user_id_owner = ?", array($iid)) == 1, 'and leaves no half-made board');

section('through the API action');

harness_set_setting_mem('boards_max_cards_per_board', '5000');
$r = boards_call('boards_import', (int)$importer->key, array('op' => 'preview', 'json' => $json));
check($r->error === null && $r->data['counts']['cards'] === 5, 'preview answers through the action');
$r = boards_call('boards_import', (int)$importer->key, array('op' => 'import', 'json' => $json, 'member_map' => array('m-picked' => (int)$picked->key)));
check($r->error === null && strpos($r->data['url'], '/profile/boards/board?id=') === 0, 'import answers with the new board\'s page');
if (!empty($r->data['board']['id'])) { harness_register_model('Board', (int)$r->data['board']['id']); }
$r = boards_call('boards_import', (int)$importer->key, array('op' => 'import', 'json' => ''));
boards_refused($r, 'an empty import is refused', 'Choose the exported JSON file');

harness_finish();

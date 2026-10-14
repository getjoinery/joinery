<?php
/**
 * Shared fixtures for the Boards suites. Not a test (no header, not *_test.php):
 * each suite requires it after harness_boot() and harness_test_mode().
 *
 * boards_world() builds one board with the five kinds of person the access rules
 * distinguish, and a card with something of everything on it. boards_call() runs
 * an API action's logic the way the API does — under a simulated session for the
 * given person.
 */

/** The first column of the first row of a query (or null). */
function boards_scalar(string $sql, array $params = array()) {
	$q = DbConnector::GetPreparedStatement($sql);
	$q->execute($params);
	$value = $q->fetchColumn();
	return $value === false ? null : $value;
}

/** The membership row (id) a person has directly on a board, or 0. */
function boards_member_id(Board $board, int $user_id): int {
	return (int)boards_scalar('SELECT bbm_board_member_id FROM bbm_board_members WHERE bbm_bbd_board_id = ? AND bbm_usr_user_id = ?',
		array((int)$board->key, $user_id));
}

/** A user-category group with the given members; removed at teardown. */
function boards_make_group(string $name, array $user_ids): Group {
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

/**
 * Run a Boards API action as a person and return its LogicResult. The logic
 * files are loaded the way the API loads them: by action name.
 */
function boards_call(string $action, int $user_id, array $input): LogicResult {
	$file = PathHelper::getIncludePath('plugins/boards/logic/' . $action . '_logic.php');
	require_once($file);
	$session = SessionControl::get_instance();
	$session->set_api_user($user_id, null);
	try {
		return call_user_func($action . '_logic', $input);
	} finally {
		$session->clear_api_user();
	}
}

/** The data of a successful result, failing the check if the action was refused. */
function boards_ok(LogicResult $result, string $label): array {
	check($result->error === null, $label, (string)$result->error);
	return is_array($result->data) ? $result->data : array();
}

/** Did the action refuse, with a message containing $needle (any message if empty)? */
function boards_refused(LogicResult $result, string $label, string $needle = ''): void {
	$refused = $result->error !== null && ($needle === '' || stripos((string)$result->error, $needle) !== false);
	check($refused, $label, 'error=' . var_export($result->error, true));
}

/**
 * A board with its people.
 *
 * owner   admin (direct row)   editor  viewer (direct rows)   stranger (no row)
 * superadmin (permission 10, no row)   site_admin (permission 5, no row)
 */
function boards_world(string $suffix, array $opts = array()): array {
	$w = array();
	$w['owner']      = make_user('BrdOwn' . $suffix);
	$w['editor']     = make_user('BrdEd' . $suffix);
	$w['viewer']     = make_user('BrdVw' . $suffix);
	$w['stranger']   = make_user('BrdStr' . $suffix);
	$w['superadmin'] = make_user('BrdSup' . $suffix, 10);
	$w['site_admin'] = make_user('BrdAdm' . $suffix, 5);

	$board = BoardsManager::createBoard((int)$w['owner']->key, 'Test board ' . $suffix, array(
		'visibility' => $opts['visibility'] ?? Board::VISIBILITY_INVITE_ONLY,
	));
	harness_register_model('Board', (int)$board->key);
	BoardsManager::addUser($board, (int)$w['editor']->key, 'editor');
	BoardsManager::addUser($board, (int)$w['viewer']->key, 'viewer');
	$w['board'] = $board;

	$w['todo'] = BoardsManager::createList($board, (int)$w['owner']->key, 'To do');
	$w['doing'] = BoardsManager::createList($board, (int)$w['owner']->key, 'Doing');
	$w['card'] = BoardsCards::create($board, $w['todo'], (int)$w['owner']->key, 'First card', array('notify' => false));
	return $w;
}

/**
 * The "removing a board for good leaves nothing behind" checks. Shared by the
 * test-db model suite (where the database's own foreign keys do the cascading —
 * the copy carries no deletion rules) and the db-tier removal suite (where the
 * deletion rules and the models' permanent_delete() overrides run for real).
 */
function boards_removal_checks(array $w, string $suffix): void {
	$owner = (int)$w['owner']->key;
	$count = function (string $table, string $where, array $params = array()): int {
		$q = DbConnector::GetPreparedStatement("SELECT COUNT(*) FROM $table WHERE $where");
		$q->execute($params);
		return (int)$q->fetchColumn();
	};
section('removing a board for good leaves nothing behind');

$full = BoardsManager::createBoard($owner, 'Doomed board ' . $suffix);
$full_id = (int)$full->key;
BoardsManager::addUser($full, (int)$w['editor']->key, 'editor');
BoardsManager::setWatching($full, (int)$w['editor']->key, true);
$full_list = BoardsManager::createList($full, $owner, 'Everything');
$label = BoardsManager::saveLabel($full, null, 'Tag', 'blue');
$doomed = BoardsCards::create($full, $full_list, $owner, 'Doomed card', array('notify' => false));
BoardsCards::addLabel($doomed, $full, $owner, (int)$label->key);
BoardsCards::addMember($doomed, $full, $owner, (int)$w['editor']->key, false);
BoardsCards::setWatching($doomed, (int)$w['editor']->key, true);
$list_check = BoardsCards::addChecklist($doomed, $full, $owner, 'To check');
BoardsCards::addItem($list_check, $doomed, $full, $owner, 'An item', array('user_id' => (int)$w['editor']->key, 'notify' => false));
BoardsCards::addComment($doomed, $full, $owner, 'A comment', array());
BoardsCards::attachLink($doomed, $full, $owner, 'https://example.test/a', 'A link');
$doomed_file = File::createFromBytes('bytes', 'doomed.txt', 'text/plain', $owner, array(
	'fil_private' => false, 'fil_access_provider' => BoardsAttachmentGate::KEY,
	'fil_access_ref' => $full_id, 'fil_source' => BoardsUploads::FILE_SOURCE));
$doomed_file_id = (int)$doomed_file->key;
BoardsCards::attachFile($doomed, $full, $owner, $doomed_file);
$doomed_id = (int)$doomed->key;

$tables = array(
	'bbd_boards' => 'bbd_board_id = ?', 'bbm_board_members' => 'bbm_bbd_board_id = ?', 'bbw_board_watchers' => 'bbw_bbd_board_id = ?',
	'bls_lists' => 'bls_bbd_board_id = ?', 'blb_labels' => 'blb_bbd_board_id = ?', 'bcd_cards' => 'bcd_bbd_board_id = ?',
	'bac_activity' => 'bac_bbd_board_id = ?',
);
foreach ($tables as $table => $where) {
	check($count($table, $where, array($full_id)) > 0, "before: $table has rows for the board");
}

$full->permanent_delete();

foreach ($tables as $table => $where) {
	check($count($table, $where, array($full_id)) === 0, "after: $table has none");
}
foreach (array(
	'bcm_card_members' => 'bcm_bcd_card_id', 'bcw_card_watchers' => 'bcw_bcd_card_id', 'bcl_card_labels' => 'bcl_bcd_card_id',
	'bck_checklists' => 'bck_bcd_card_id', 'bco_comments' => 'bco_bcd_card_id', 'bat_attachments' => 'bat_bcd_card_id',
) as $table => $column) {
	check($count($table, "$column = ?", array($doomed_id)) === 0, "after: $table has none for the card");
}
check($count('bci_checklist_items', 'bci_bck_checklist_id = ?', array((int)$list_check->key)) === 0, 'after: no checklist items');
check(!File::check_if_exists($doomed_file_id), 'and the attached File is gone, bytes and all');
}

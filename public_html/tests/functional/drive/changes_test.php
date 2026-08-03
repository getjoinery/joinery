<?php
/** @joinery-test
 * name: drive_changes
 * tier: db
 * env: dev-only
 * needs: []
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/folders_class.php'));
require_once(PathHelper::getIncludePath('data/file_changes_class.php'));
require_once(PathHelper::getIncludePath('data/file_access_grants_class.php'));
require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
require_once(PathHelper::getIncludePath('logic/drive_folder_create_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_rename_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_move_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_trash_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_restore_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_delete_forever_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_share_sync_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_changes_logic.php'));

$made_files = array(); $made_folders = array();
harness_defer(function () use (&$made_files, &$made_folders) {
	$dblink = DbConnector::get_instance()->get_db_link();
	foreach ($made_files as $fid) { $f = new File((int)$fid, true); if ($f->key) { $f->permanent_delete(); } }
	foreach (array_reverse($made_folders) as $fid) {
		$dblink->prepare("DELETE FROM fga_file_access_grants WHERE fga_entity_type='folder' AND fga_entity_id=?")->execute(array((int)$fid));
		$dblink->prepare("DELETE FROM fol_folders WHERE fol_folder_id=?")->execute(array((int)$fid));
	}
});

harness_set_setting_mem('drive_active', '1');

$owner   = make_user('changesowner');
$grantee = make_user('changesgrantee');

$session = SessionControl::get_instance();
$session->set_api_user($owner->key);
harness_defer(function () use ($session) { $session->clear_api_user(); });

$dblink = DbConnector::get_instance()->get_db_link();
$baseline = (int)$dblink->query("SELECT COALESCE(MAX(fch_file_change_id),0) FROM fch_file_changes")->fetchColumn();

// ---------------------------------------------------------------------------
section('every mutation kind is recorded and surfaced by the feed');

// created (folder)
$rF = drive_folder_create_logic(array('name' => 'ChFeed_' . bin2hex(random_bytes(3))));
$F = (int)$rF->data['folder']['id']; $made_folders[] = $F;
$rF2 = drive_folder_create_logic(array('name' => 'ChDest_' . bin2hex(random_bytes(3))));
$Fdest = (int)$rF2->data['folder']['id']; $made_folders[] = $Fdest;

// A file X in F to drive file-scoped kinds.
$X = File::createFromBytes('x-' . bin2hex(random_bytes(6)), 'x.txt', 'text/plain', $owner->key, array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$X->set('fil_fol_folder_id', $F); $X->save();
$made_files[] = $X->key;
FileChange::record(FileChange::KIND_CREATED, 'file', $X->key, $owner->key, $owner->key);   // created (file, as upload would)
FileChange::record(FileChange::KIND_CONTENT, 'file', $X->key, $owner->key, $owner->key);   // content (as a new version would)

drive_rename_logic(array('entity_type' => 'file', 'entity_id' => $X->key, 'name' => 'x-renamed.txt'));   // renamed
drive_move_logic(array('entity_type' => 'file', 'entity_id' => $X->key, 'parent_id' => $Fdest));         // moved
drive_share_sync_logic(array('entity_type' => 'file', 'entity_id' => $X->key, 'grants' => array((string)$grantee->key => 'viewer'))); // grant_changed
drive_trash_logic(array('entity_type' => 'file', 'entity_id' => $X->key));                               // trashed
drive_restore_logic(array('entity_type' => 'file', 'entity_id' => $X->key));                             // restored
drive_delete_forever_logic(array('entity_type' => 'file', 'entity_id' => $X->key, 'confirm' => true));   // deleted

$feed = drive_changes_logic(array('cursor' => $baseline));
$kinds = array();
$ids = array();
foreach ($feed->data['changes'] as $c) { $kinds[$c['kind']] = true; $ids[] = $c['id']; }

foreach (array('created', 'content', 'renamed', 'moved', 'grant_changed', 'trashed', 'restored', 'deleted') as $k) {
	check(isset($kinds[$k]), "feed surfaces a '$k' change");
}

// cursor ordering: strictly ascending; next_cursor is the last id
$sorted = $ids; sort($sorted, SORT_NUMERIC);
check($ids === $sorted, 'changes are returned in ascending cursor order');
check((int)$feed->data['next_cursor'] === (int)end($ids), 'next_cursor is the last returned id');

// incremental: from next_cursor there are no more of these changes
$feed2 = drive_changes_logic(array('cursor' => (int)$feed->data['next_cursor']));
check(count($feed2->data['changes']) === 0, 'polling from next_cursor returns nothing new');

// ---------------------------------------------------------------------------
section('grant visibility');

// A file Y owned by owner, shared to grantee, then renamed.
$Y = File::createFromBytes('y-' . bin2hex(random_bytes(6)), 'y.txt', 'text/plain', $owner->key, array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$Y->set('fil_fol_folder_id', $F); $Y->save();
$made_files[] = $Y->key;
$before_share = (int)$dblink->query("SELECT COALESCE(MAX(fch_file_change_id),0) FROM fch_file_changes")->fetchColumn();
drive_share_sync_logic(array('entity_type' => 'file', 'entity_id' => $Y->key, 'grants' => array((string)$grantee->key => 'viewer')));
drive_rename_logic(array('entity_type' => 'file', 'entity_id' => $Y->key, 'name' => 'y-renamed.txt'));

$session->clear_api_user();
$session->set_api_user($grantee->key);

$gfeed = drive_changes_logic(array('cursor' => $before_share));
$sees_y = false;
foreach ($gfeed->data['changes'] as $c) { if ($c['entity_type'] === 'file' && $c['entity_id'] === (int)$Y->key) { $sees_y = true; } }
check($sees_y, 'grantee sees changes on an entity shared to them');

// grantee does NOT see the owner's unrelated folder-create change
$sees_owner_folder = false;
foreach ($gfeed->data['changes'] as $c) { if ($c['entity_type'] === 'folder' && $c['entity_id'] === $F) { $sees_owner_folder = true; } }
check(!$sees_owner_folder, 'grantee does not see the owner\'s unshared changes');

$session->clear_api_user();
$session->set_api_user($owner->key);

// ---------------------------------------------------------------------------
section('reset on a cursor before the retained window');

// The reset branch fires when cursor + 1 < MIN(retained id). On a table with
// any history, a cursor below the window can simply be chosen — never delete
// rows this test does not own from a shared table.
$min_id = (int)$dblink->query("SELECT MIN(fch_file_change_id) FROM fch_file_changes")->fetchColumn();
if ($min_id >= 3) {
	$stale_cursor = $min_id - 2;
} else {
	// Fresh install: the table's only rows are this run's own, so a real purge
	// of the earliest few is safe here and raises MIN above the cursor.
	$stale_cursor = max(1, $min_id);
	$dblink->prepare("DELETE FROM fch_file_changes WHERE fch_file_change_id <= ?")
	       ->execute(array($stale_cursor + 1));
}
$new_min = $dblink->query("SELECT MIN(fch_file_change_id) FROM fch_file_changes")->fetchColumn();

$reset_feed = drive_changes_logic(array('cursor' => $stale_cursor));
check(!empty($reset_feed->data['reset']), 'a cursor before the retained window triggers reset', 'new_min=' . var_export($new_min, true) . ' stale=' . $stale_cursor);
check(empty($reset_feed->data['changes']), 'reset response carries no incremental changes');

harness_finish();

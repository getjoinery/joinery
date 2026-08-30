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
require_once(PathHelper::getIncludePath('logic/drive_stat_logic.php'));

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


// ---------------------------------------------------------------------------
section('a folder shared to me reaches the files inside it');

// drive_index expands a granted folder to its whole subtree, so a cold start
// hands the grantee every file inside a shared folder. The feed has to reach
// just as far: a change row for a file INSIDE that folder carries the file's
// own id and the OWNER's user id, so reading the grant rows alone matches
// neither clause and the grantee is never told. That is a file which syncs
// once and is stale for ever -- the hole this endpoint exists to prevent.
$session->set_api_user($owner->key);
$rSh = drive_folder_create_logic(array('name' => 'ChShared_' . bin2hex(random_bytes(3))));
$Fsh = (int)$rSh->data['folder']['id']; $made_folders[] = $Fsh;

$Z = File::createFromBytes('z-' . bin2hex(random_bytes(6)), 'inside.txt', 'text/plain', $owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$Z->set('fil_fol_folder_id', $Fsh); $Z->save();
$made_files[] = $Z->key;
FileChange::record(FileChange::KIND_CREATED, 'file', $Z->key, $owner->key, $owner->key);

FileAccessGrant::sync_for_entity('folder', $Fsh, array((int)$grantee->key => 'viewer'), $owner->key);

$cur_sh = (int)$dblink->query("SELECT COALESCE(MAX(fch_file_change_id),0) FROM fch_file_changes")->fetchColumn();
drive_rename_logic(array('entity_type' => 'file', 'entity_id' => $Z->key, 'name' => 'inside-renamed.txt'));

$session->set_api_user($grantee->key);
$feed_sh = drive_changes_logic(array('cursor' => $cur_sh));
$sees_inside = false;
foreach (($feed_sh->data['changes'] ?? array()) as $c) {
	if (($c['entity_type'] ?? '') === 'file' && (int)($c['entity_id'] ?? 0) === (int)$Z->key) { $sees_inside = true; }
}
check($sees_inside, 'grantee is told when a file inside the folder shared to them changes');

// The widened reach must not become "sees everything": a file the owner keeps
// outside any shared folder stays invisible.
$session->set_api_user($owner->key);
$Q = File::createFromBytes('q-' . bin2hex(random_bytes(6)), 'private.txt', 'text/plain', $owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$Q->set('fil_fol_folder_id', $F); $Q->save();
$made_files[] = $Q->key;
$cur_q = (int)$dblink->query("SELECT COALESCE(MAX(fch_file_change_id),0) FROM fch_file_changes")->fetchColumn();
drive_rename_logic(array('entity_type' => 'file', 'entity_id' => $Q->key, 'name' => 'private-renamed.txt'));

$session->set_api_user($grantee->key);
$feed_q = drive_changes_logic(array('cursor' => $cur_q));
$sees_private = false;
foreach (($feed_q->data['changes'] ?? array()) as $c) {
	if (($c['entity_type'] ?? '') === 'file' && (int)($c['entity_id'] ?? 0) === (int)$Q->key) { $sees_private = true; }
}
check(!$sees_private, 'grantee is still not told about files outside anything shared to them');

// ---------------------------------------------------------------------------
section('unsharing reaches the person who lost access');

// Best effort take-back. When a share is revoked the grant row is hard-deleted,
// so by the time the change row is written the losing user matches nothing the
// feed selects on -- they do not own the entity, and they no longer hold a
// grant. Without a row addressed to them they are never told, and the copy sits
// on their disk for ever. This is the whole mechanism by which an unshare ever
// reaches a synced device.
$session->set_api_user($owner->key);
$R = File::createFromBytes('r-' . bin2hex(random_bytes(6)), 'revoked.txt', 'text/plain', $owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$R->set('fil_fol_folder_id', $F); $R->save();
$made_files[] = $R->key;
drive_share_sync_logic(array('entity_type' => 'file', 'entity_id' => $R->key,
	'grants' => array((string)$grantee->key => 'viewer')));

$cur_rev = (int)$dblink->query("SELECT COALESCE(MAX(fch_file_change_id),0) FROM fch_file_changes")->fetchColumn();
// Revoke: an empty grant map is the whole point of a reconcile endpoint.
drive_share_sync_logic(array('entity_type' => 'file', 'entity_id' => $R->key, 'grants' => array()));

check(FileAccessGrant::role_for('file', $R->key, $grantee->key) === null, 'the grant is actually gone');

$session->set_api_user($grantee->key);
$feed_rev = drive_changes_logic(array('cursor' => $cur_rev));
$told = false;
foreach (($feed_rev->data['changes'] ?? array()) as $c) {
	if (($c['entity_type'] ?? '') === 'file' && (int)($c['entity_id'] ?? 0) === (int)$R->key
		&& ($c['kind'] ?? '') === 'grant_changed') { $told = true; }
}
check($told, 'the user who lost access is told the share changed');

// And the row must not lie about who owns the file.
foreach (($feed_rev->data['changes'] ?? array()) as $c) {
	if ((int)($c['entity_id'] ?? 0) === (int)$R->key) {
		check((int)$c['owner_id'] === (int)$owner->key, 'the revocation row still names the real owner');
		break;
	}
}

// Following that row, stat has to say no-longer-yours rather than merely gone:
// a deletion invites the client to rescue local edits into a new file, which
// for an unshare would leave a copy of somebody else's file behind.
$stat = drive_stat_logic(array('entities' => array(array('entity_type' => 'file', 'entity_id' => (int)$R->key))));
$in_missing = false; $in_not_yours = false;
foreach (($stat->data['missing'] ?? array()) as $m) {
	if ((int)($m['entity_id'] ?? 0) === (int)$R->key) { $in_missing = true; }
}
foreach (($stat->data['not_yours'] ?? array()) as $m) {
	if ((int)($m['entity_id'] ?? 0) === (int)$R->key) { $in_not_yours = true; }
}
check($in_missing, 'a revoked entity is still reported missing, so an older client is unaffected');
check($in_not_yours, 'a revoked entity is reported as no longer the caller\'s, not merely gone');

// A file that never existed is gone, NOT not-yours -- or the client would keep
// a record waiting for access that is never coming back.
$stat_gone = drive_stat_logic(array('entities' => array(array('entity_type' => 'file', 'entity_id' => 2147483600))));
check(count($stat_gone->data['missing'] ?? array()) === 1, 'a file that does not exist is missing');
check(empty($stat_gone->data['not_yours']), 'a file that does not exist is not reported as somebody else\'s');

$session->set_api_user($owner->key);

harness_finish();

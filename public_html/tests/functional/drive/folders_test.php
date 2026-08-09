<?php
/** @joinery-test
 * name: drive_folders
 * tier: db
 * env: dev-only
 * needs: []
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('data/folders_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
require_once(PathHelper::getIncludePath('logic/drive_folder_create_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_rename_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_move_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_trash_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_restore_logic.php'));

// ---- helpers -------------------------------------------------------------
function res_ok($r) { return $r instanceof LogicResult && $r->error === null && !empty($r->data['ok']); }
function res_err($r) { return $r instanceof LogicResult && $r->error !== null; }

$made_folders = array();
$made_files = array();
harness_defer(function () use (&$made_files, &$made_folders) {
	foreach ($made_files as $fid) {
		$f = new File((int)$fid, true);
		if ($f->key) { $f->permanent_delete(); }
	}
	// Folders deepest-first: just delete rows directly (bytes already gone).
	$dblink = DbConnector::get_instance()->get_db_link();
	foreach (array_reverse($made_folders) as $fid) {
		$q = $dblink->prepare("DELETE FROM fol_folders WHERE fol_folder_id = ?");
		$q->execute(array((int)$fid));
	}
});

function mk_folder($name, $parent_id = 0) {
	global $made_folders;
	$r = drive_folder_create_logic(array('name' => $name, 'parent_id' => $parent_id));
	if (res_ok($r)) { $made_folders[] = $r->data['folder']['id']; }
	return $r;
}

$owner = make_user('drivefolders');
$session = SessionControl::get_instance();
$session->set_api_user($owner->key);
harness_defer(function () use ($session) { $session->clear_api_user(); });

harness_set_setting_mem('drive_active', '1');
harness_set_setting_mem('drive_max_folder_depth', '32');

// -------------------------------------------------------------------------
section('Create + sibling-name uniqueness');

$rA = mk_folder('Alpha');
check(res_ok($rA), 'create root folder Alpha');
$alpha_id = res_ok($rA) ? (int)$rA->data['folder']['id'] : 0;

$rDup = mk_folder('Alpha');
check(res_err($rDup), 'duplicate sibling name at root is rejected');

$rB = mk_folder('Beta');
check(res_ok($rB), 'create sibling folder Beta with a different name');
$beta_id = res_ok($rB) ? (int)$rB->data['folder']['id'] : 0;

$rChild = mk_folder('Child', $alpha_id);
check(res_ok($rChild), 'create Alpha/Child subfolder');
$child_id = res_ok($rChild) ? (int)$rChild->data['folder']['id'] : 0;

$rSameNameDiffParent = mk_folder('Child', $beta_id);
check(res_ok($rSameNameDiffParent), 'same name allowed under a different parent');

// -------------------------------------------------------------------------
section('Rename');

$rRen = drive_rename_logic(array('entity_type' => 'folder', 'entity_id' => $child_id, 'name' => 'ChildRenamed'));
check(res_ok($rRen), 'rename Alpha/Child to ChildRenamed');

$rRenDup = drive_rename_logic(array('entity_type' => 'folder', 'entity_id' => $beta_id, 'name' => 'Alpha'));
check(res_err($rRenDup), 'rename Beta to an existing sibling name (Alpha) is rejected');

// -------------------------------------------------------------------------
section('Move + cycle rejection');

// Build Alpha/ChildRenamed/Grand
$rGrand = mk_folder('Grand', $child_id);
check(res_ok($rGrand), 'create Alpha/ChildRenamed/Grand');
$grand_id = res_ok($rGrand) ? (int)$rGrand->data['folder']['id'] : 0;

// Move Alpha under Grand -> cycle (Grand is a descendant of Alpha)
$rCycle = drive_move_logic(array('entity_type' => 'folder', 'entity_id' => $alpha_id, 'parent_id' => $grand_id));
check(res_err($rCycle), 'moving a folder into its own descendant is rejected (cycle)');

// Move Alpha into itself -> cycle
$rSelf = drive_move_logic(array('entity_type' => 'folder', 'entity_id' => $alpha_id, 'parent_id' => $alpha_id));
check(res_err($rSelf), 'moving a folder into itself is rejected');

// Legit move: Grand -> Beta
$rMove = drive_move_logic(array('entity_type' => 'folder', 'entity_id' => $grand_id, 'parent_id' => $beta_id));
check(res_ok($rMove), 'move Grand under Beta');
$grand_reload = new Folder($grand_id, true);
check((int)$grand_reload->get('fol_parent_folder_id') === $beta_id, 'Grand now has Beta as parent');

// Move Grand to root
$rMoveRoot = drive_move_logic(array('entity_type' => 'folder', 'entity_id' => $grand_id, 'parent_id' => 0));
check(res_ok($rMoveRoot), 'move Grand to root');
$grand_reload = new Folder($grand_id, true);
check($grand_reload->get('fol_parent_folder_id') === null, 'Grand parent is null (root) after move');

// -------------------------------------------------------------------------
section('Depth cap');

harness_set_setting_mem('drive_max_folder_depth', '2');
$rDepthTop = mk_folder('DepthTop');          // depth 1
check(res_ok($rDepthTop), 'root folder allowed at depth cap 2');
$dt_id = res_ok($rDepthTop) ? (int)$rDepthTop->data['folder']['id'] : 0;
$rDepth2 = mk_folder('DepthTwo', $dt_id);    // depth 2
check(res_ok($rDepth2), 'depth-2 folder allowed');
$d2_id = res_ok($rDepth2) ? (int)$rDepth2->data['folder']['id'] : 0;
$rDepth3 = mk_folder('DepthThree', $d2_id);  // depth 3 > cap
check(res_err($rDepth3), 'depth-3 folder rejected at cap 2');
harness_set_setting_mem('drive_max_folder_depth', '32');

// -------------------------------------------------------------------------
section('Trash cascade + selective restore');

// Tree: Trunk/{Branch(file Leaf), EarlyGone}
$rTrunk = mk_folder('Trunk');
$trunk_id = (int)$rTrunk->data['folder']['id'];
$rBranch = mk_folder('Branch', $trunk_id);
$branch_id = (int)$rBranch->data['folder']['id'];
$rEarly = mk_folder('EarlyGone', $trunk_id);
$early_id = (int)$rEarly->data['folder']['id'];

// A file inside Branch.
$leaf = File::createFromBytes('drive-leaf-' . bin2hex(random_bytes(6)), 'leaf.txt', 'text/plain', $owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$leaf->set('fil_fol_folder_id', $branch_id);
$leaf->save();
$made_files[] = $leaf->key;
$leaf_id = $leaf->key;

// Trash EarlyGone BEFORE the parent (its delete_time is earlier).
$rTrashEarly = drive_trash_logic(array('entity_type' => 'folder', 'entity_id' => $early_id));
check(res_ok($rTrashEarly), 'trash EarlyGone on its own first');
usleep(20000); // ensure a strictly later delete_time for the parent cascade

// Trash the Trunk -> cascade to Branch + Leaf (EarlyGone already gone, untouched).
$rTrashTrunk = drive_trash_logic(array('entity_type' => 'folder', 'entity_id' => $trunk_id));
check(res_ok($rTrashTrunk), 'trash Trunk (cascade)');

$branch_after = new Folder($branch_id, true);
$leaf_after = new File($leaf_id, true);
check($branch_after->get('fol_delete_time') !== null, 'Branch soft-deleted by cascade');
check($leaf_after->get('fil_delete_time') !== null, 'Leaf file soft-deleted by cascade');

// Restore Trunk: Branch + Leaf come back (deleted with it); EarlyGone stays gone.
$rRestore = drive_restore_logic(array('entity_type' => 'folder', 'entity_id' => $trunk_id));
check(res_ok($rRestore), 'restore Trunk');

$trunk_after = new Folder($trunk_id, true);
$branch_after = new Folder($branch_id, true);
$leaf_after = new File($leaf_id, true);
$early_after = new Folder($early_id, true);
check($trunk_after->get('fol_delete_time') === null, 'Trunk restored');
check($branch_after->get('fol_delete_time') === null, 'Branch restored with parent');
check($leaf_after->get('fil_delete_time') === null, 'Leaf file restored with parent');
check($early_after->get('fol_delete_time') !== null, 'EarlyGone (trashed earlier) stays in trash');

section('Losing a name race answers like a name clash, not a database error');
// folder_name_taken() runs before the insert, so two concurrent creates can
// both pass it and the partial unique index refuses the loser. Saving a folder
// whose name is already taken puts the row in exactly that state, without
// needing two real requests in flight.
$raceDir = mk_folder('RaceTarget');
check(res_ok($raceDir), 'create RaceTarget');

$loser = new Folder(NULL);
$loser->set('fol_usr_user_id', (int)$owner->key);
$loser->set('fol_name', 'RaceTarget');
$loser->set('fol_protection_level', 'standard');
$saved = null;
$threw = null;
try {
	$saved = DriveHelper::save_folder_unless_name_taken($loser);
} catch (Exception $e) {
	$threw = $e->getMessage();
}
check($threw === null, 'a lost race does not escape as an exception'
	. ($threw === null ? '' : " (got: $threw)"));
check($saved === false, 'the helper reports the name as taken rather than saving');
check(!$loser->key, 'the losing folder was not created');

// A failure the helper cannot explain must still reach the caller: nothing
// here should turn an unrelated database fault into a quiet false.
$broken = new Folder(NULL);
$broken->set('fol_usr_user_id', (int)$owner->key);
$broken->set('fol_name', str_repeat('z', 300)); // longer than the column allows
$broken->set('fol_protection_level', 'standard');
$rethrown = false;
try {
	DriveHelper::save_folder_unless_name_taken($broken);
	if ($broken->key) { $made_folders[] = $broken->key; }
} catch (Exception $e) {
	$rethrown = true;
}
check($rethrown, 'a failure that is not a name clash is rethrown');

// -------------------------------------------------------------------------
section('Two live files may not share a name in one folder');
// The soak rig found the cost of not enforcing this. Two devices that conflict
// on one file both pick the same conflicted-copy name, each having checked only
// its own disk; without a rule the server takes both, and every device can then
// materialize only one of them. One campaign ended with 55 duplicate names and
// 91 files no device could ever place.

// The folder is part of the creation, exactly as the upload path does it: a
// file made at the root and moved afterwards would occupy the root's namespace
// on the way past, and collide with a root file it has nothing to do with.
function mk_drive_file($title, $folder_id, $owner_id) {
	global $made_files;
	$f = File::createFromBytes('dup-' . bin2hex(random_bytes(6)), $title, 'text/plain', $owner_id,
		array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE,
		      'fil_fol_folder_id' => $folder_id ?: null));
	$made_files[] = $f->key;
	return $f;
}

$dupDir = mk_folder('DupHome');
$dup_dir_id = (int)$dupDir->data['folder']['id'];
$first = mk_drive_file('report.txt', $dup_dir_id, $owner->key);
check((bool)$first->key, 'the first file is created');

check(DriveHelper::file_name_taken((int)$owner->key, $dup_dir_id, 'report.txt'),
	'the name reads as taken');
check(!DriveHelper::file_name_taken((int)$owner->key, $dup_dir_id, 'report.txt', (int)$first->key),
	'and not taken when the holder itself is excluded');
check(!DriveHelper::file_name_taken((int)$owner->key, 0, 'report.txt'),
	'a name taken in a folder is free at the root');

// The database is the backstop, not the check above.
$loser_file = File::createFromBytes('dup-' . bin2hex(random_bytes(6)), 'report.txt', 'text/plain',
	$owner->key, array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$made_files[] = $loser_file->key;
$loser_file->set('fil_fol_folder_id', $dup_dir_id); // into the folder that already has one
$file_threw = null;
$file_saved = null;
try {
	$file_saved = DriveHelper::save_file_unless_name_taken($loser_file);
} catch (Exception $e) {
	$file_threw = $e->getMessage();
}
check($file_threw === null, 'a lost file-name race does not escape as an exception'
	. ($file_threw === null ? '' : " (got: $file_threw)"));
check($file_saved === false, 'the helper reports the file name as taken rather than saving');

// Renaming onto a taken name is refused, and says so in a way a client can act
// on without reading English.
$other = mk_drive_file('notes.txt', $dup_dir_id, $owner->key);
$rDupRename = drive_rename_logic(array(
	'entity_type' => 'file', 'entity_id' => (int)$other->key, 'name' => 'report.txt'));
check(res_err($rDupRename), 'renaming a file onto a sibling name is refused');
check(isset($rDupRename->data['reason']) && $rDupRename->data['reason'] === 'name_taken',
	'and the refusal carries the name_taken marker a sync client branches on');
$other_after = new File((int)$other->key, true);
check($other_after->get('fil_title') === 'notes.txt', 'the file keeps its own name');

// Moving onto a taken name is refused the same way, and the file does not move.
// The refused file above is still sitting at the root under its own name, which
// is exactly the case: a real file, a real name, a destination that has one.
$elsewhere = new File((int)$loser_file->key, true);
check($elsewhere->get('fil_title') === 'report.txt' && !$elsewhere->get('fil_fol_folder_id'),
	'the refused file stayed at the root with its name');
$rDupMove = drive_move_logic(array(
	'entity_type' => 'file', 'entity_id' => (int)$elsewhere->key, 'parent_id' => $dup_dir_id));
check(res_err($rDupMove), 'moving a file into a folder that has that name is refused');
check(isset($rDupMove->data['reason']) && $rDupMove->data['reason'] === 'name_taken',
	'the move refusal carries the marker too');
$elsewhere_after = new File((int)$elsewhere->key, true);
check($elsewhere_after->get('fil_fol_folder_id') === null
	|| (int)$elsewhere_after->get('fil_fol_folder_id') === 0,
	'and the file stayed where it was');

// Restoring onto a name taken while the file sat in the trash keeps the file
// and changes the name — the user asked for their file back, and a name is a
// smaller thing to change than the answer.
$rTrashDup = drive_trash_logic(array('entity_type' => 'file', 'entity_id' => (int)$first->key));
check(res_ok($rTrashDup), 'trash the original report.txt');
$replacement = mk_drive_file('report.txt', $dup_dir_id, $owner->key);
check((bool)$replacement->key, 'a new report.txt takes the freed name');
$rRestoreDup = drive_restore_logic(array('entity_type' => 'file', 'entity_id' => (int)$first->key));
check(res_ok($rRestoreDup), 'restoring the original still succeeds');
$restored = new File((int)$first->key, true);
check($restored->get('fil_delete_time') === null, 'the restored file is live again');
check($restored->get('fil_title') === 'report (restored).txt',
	'and came back suffixed before its extension, not on the end: '
	. $restored->get('fil_title'));

harness_finish();

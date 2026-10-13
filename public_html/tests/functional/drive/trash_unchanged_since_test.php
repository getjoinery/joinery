<?php
/** @joinery-test
 * name: drive_trash_unchanged_since
 * tier: db
 * env: dev-only
 * needs: []
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('logic/drive_folder_create_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_trash_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_move_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_restore_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_rename_logic.php'));

$made_files = array(); $made_folders = array();
harness_defer(function () use (&$made_files, &$made_folders) {
	$dblink = DbConnector::get_instance()->get_db_link();
	$exists = $dblink->prepare("SELECT 1 FROM fil_files WHERE fil_file_id = ?");
	foreach ($made_files as $fid) {
		$exists->execute(array((int)$fid));
		if ($exists->fetchColumn()) { $f = new File((int)$fid, true); if ($f->key) { $f->permanent_delete(); } }
	}
	foreach (array_reverse($made_folders) as $fid) {
		$dblink->prepare("DELETE FROM fga_file_access_grants WHERE fga_entity_type='folder' AND fga_entity_id=?")->execute(array((int)$fid));
		$dblink->prepare("DELETE FROM fol_folders WHERE fol_folder_id=?")->execute(array((int)$fid));
	}
});

harness_set_setting_mem('drive_active', '1');

$owner = make_user('trashsinceowner');
$session = SessionControl::get_instance();
$session->set_api_user($owner->key);
harness_defer(function () use ($session) { $session->clear_api_user(); });

$tag = bin2hex(random_bytes(3));
$dblink = DbConnector::get_instance()->get_db_link();
$cursor = function () use ($dblink) {
	return (int)$dblink->query("SELECT COALESCE(MAX(fch_file_change_id), 0) FROM fch_file_changes")->fetchColumn();
};
$folder = function ($name, $parent = 0) use (&$made_folders) {
	$in = array('name' => $name);
	if ($parent) { $in['parent_id'] = $parent; }
	$r = drive_folder_create_logic($in);
	$id = (int)$r->data['folder']['id'];
	$made_folders[] = $id;
	return $id;
};
$file = function ($name, $folder_id) use ($owner, &$made_files) {
	$f = File::createFromBytes('body-' . bin2hex(random_bytes(6)), $name, 'text/plain', $owner->key,
		array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	$f->set('fil_fol_folder_id', $folder_id);
	$f->save();
	$made_files[] = $f->key;
	FileChange::record(FileChange::KIND_CREATED, 'file', $f->key, $owner->key, $owner->key);
	return $f;
};
$trash = function ($type, $id, $since = null) {
	$in = array('entity_type' => $type, 'entity_id' => $id);
	if ($since !== null) { $in['unchanged_since'] = $since; }
	return drive_trash_logic($in);
};
$refused = function ($r) {
	return $r->error !== null && (($r->data['reason'] ?? null) === 'changed_since');
};
$folder_live = function ($id) {
	$f = DriveHelper::load_folder($id);
	return $f && ($f->get('fol_delete_time') === null || $f->get('fol_delete_time') === '');
};

// ---------------------------------------------------------------------------
section('A folder trash refuses an edit made after its cursor, and takes it before');

$A = $folder('A_' . $tag);
$x = $file('x.txt', $A);
$since = $cursor();
FileChange::record(FileChange::KIND_CONTENT, 'file', $x->key, $owner->key, $owner->key);
$r = $trash('folder', $A, $since);
check($refused($r), 'refused with changed_since', var_export(array($r->error, $r->data), true));
check(isset($r->data['newest']) && (int)$r->data['newest'] > $since, 'carries the newest change it found', var_export($r->data, true));
check($folder_live($A), 'the folder is still live');
$r = $trash('folder', $A, $cursor());
check($r->error === null, 'a cursor past the edit is accepted', var_export($r->error, true));
check(($r->data['checked_since'] ?? null) !== null, 'and answers checked_since', var_export($r->data, true));
check(!$folder_live($A), 'the folder went to the trash');

// ---------------------------------------------------------------------------
section('What counts: a new file, a move in, a restore, a subfolder moved in');

$B = $folder('B_' . $tag);
$since = $cursor();
$file('new.txt', $B);
check($refused($trash('folder', $B, $since)), 'a file created after the cursor refuses');

$C = $folder('C_' . $tag);
$Out = $folder('Out_' . $tag);
$y = $file('y.txt', $Out);
$since = $cursor();
drive_move_logic(array('entity_type' => 'file', 'entity_id' => $y->key, 'parent_id' => $C));
check($refused($trash('folder', $C, $since)), 'a file moved in after the cursor refuses');

$D = $folder('D_' . $tag);
$z = $file('z.txt', $D);
drive_trash_logic(array('entity_type' => 'file', 'entity_id' => $z->key));
$since = $cursor();
drive_restore_logic(array('entity_type' => 'file', 'entity_id' => $z->key));
check($refused($trash('folder', $D, $since)), 'a file restored after the cursor refuses');

$E = $folder('E_' . $tag);
$Sub = $folder('Sub_' . $tag);
$file('inside.txt', $Sub);
$since = $cursor();
drive_move_logic(array('entity_type' => 'folder', 'entity_id' => $Sub, 'parent_id' => $E));
check($refused($trash('folder', $E, $since)), 'a subfolder moved in after the cursor refuses, though its files are older');

// ---------------------------------------------------------------------------
section('What does not count: a rename inside, an empty subfolder made inside');

$G = $folder('G_' . $tag);
$w = $file('w.txt', $G);
$since = $cursor();
drive_rename_logic(array('entity_type' => 'file', 'entity_id' => $w->key, 'name' => 'w renamed.txt'));
$folder('Empty_' . $tag, $G);
$r = $trash('folder', $G, $since);
check($r->error === null, 'accepted', var_export(array($r->error, $r->data), true));

// ---------------------------------------------------------------------------
section('A file trash, an absent cursor, zero, and a cursor the feed would reset');

$H = $folder('H_' . $tag);
$f = $file('f.txt', $H);
$since = $cursor();
FileChange::record(FileChange::KIND_CONTENT, 'file', $f->key, $owner->key, $owner->key);
check($refused($trash('file', $f->key, $since)), 'a file trash refuses its own edit after the cursor');

$J = $folder('J_' . $tag);
$file('j.txt', $J);
$r = $trash('folder', $J);
check($r->error === null && !array_key_exists('checked_since', $r->data), 'no cursor: trashed as before, no checked_since',
	var_export(array($r->error, $r->data), true));

$K = $folder('K_' . $tag);
$file('k.txt', $K);
check($refused($trash('folder', $K, 0)), 'zero means nothing agreed: any retained change refuses');

$min = (int)$dblink->query("SELECT COALESCE(MIN(fch_file_change_id), 0) FROM fch_file_changes")->fetchColumn();
if ($min > 2) {
	$r = $trash('folder', $K, $min - 2);
	check($refused($r) && (int)$r->data['newest'] === $min - 2, 'a cursor older than the feed keeps refuses, carrying the cursor',
		var_export($r->data, true));
} else {
	check(true, 'a cursor older than the feed keeps: not reachable here (nothing pruned)');
}

// ---------------------------------------------------------------------------
section('The owner lock is never left held');

// Advisory locks are per session, so a lock a verb failed to release would
// still be listed against this backend.
$q = $dblink->prepare(
	"SELECT COUNT(*) FROM pg_locks
	  WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND classid = ? AND objid = ?");
$q->execute(array(DriveHelper::TRASH_LOCK_CLASS, (int)$owner->key));
check((int)$q->fetchColumn() === 0, 'after every verb above, this session holds no owner trash lock');

harness_finish();

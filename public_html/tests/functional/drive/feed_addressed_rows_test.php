<?php
/** @joinery-test
 * name: drive_feed_addressed_rows
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * The change-feed rows a Drive change owes the members it touches who would
 * not otherwise hear of it (docs/drive.md, Change feed):
 *   - drive_key_grants_sync: a grant_changed row per file per member whose key
 *     was added or removed, addressed to them; none for a re-wrap;
 *   - drive_move: a moved row addressed to each member whose share the item
 *     leaves -- out of every share, between two shares of one member, or out
 *     of a folder share onto a grant on the item -- and none while it stays in
 *     their share; the member who lost it learns nothing of where it went;
 *   - drive_restore bringing an item back at the top because its folder is
 *     still in the trash: the same rows;
 *   - each change and its rows are one write, and a name race lost inside
 *     that write is still a name clash.
 *
 * Run: php tests/run.php --only=tests/functional/drive/feed_addressed_rows_test.php
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();
harness_test_mode();

require_once(PathHelper::getIncludePath('logic/drive_move_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_trash_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_restore_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_key_grants_sync_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_changes_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_stat_logic.php'));

$db = DbConnector::get_instance()->get_db_link();
$test_db = (string)$db->query('SELECT current_database()')->fetchColumn();
$live_db = (string)Globalvars::get_instance()->get_setting('dbname');
if ($test_db === '' || $test_db === $live_db) {
	// The transaction checks below put a trigger on the change table. Never
	// anywhere but the copy.
	harness_skip('feed addressed rows', 'not connected to the test database (' . $test_db . ')');
	harness_finish();
}

harness_set_setting_mem('drive_active', '1');

$made_files = array();
$made_folders = array();
harness_defer(function () use (&$made_files, &$made_folders) {
	$db = DbConnector::get_instance()->get_db_link();
	if ($db->inTransaction()) { $db->rollBack(); }
	$files = DriveHelper::int_in_list($made_files);
	$folders = DriveHelper::int_in_list($made_folders);
	$db->exec("DELETE FROM fch_file_changes WHERE (fch_entity_type = 'file' AND fch_entity_id IN ($files))
	            OR (fch_entity_type = 'folder' AND fch_entity_id IN ($folders))");
	$db->exec("DELETE FROM fga_file_access_grants WHERE (fga_entity_type = 'file' AND fga_entity_id IN ($files))
	            OR (fga_entity_type = 'folder' AND fga_entity_id IN ($folders))");
	$db->exec("DELETE FROM fkg_file_key_grants WHERE fkg_fil_file_id IN ($files)");
	foreach ($made_files as $fid) {
		$f = new File((int)$fid, true);
		if ($f->key) { $f->permanent_delete(); }
	}
	// A delete inside a transaction leaves its bytes to a reclaim at shutdown,
	// by which time this process is back on the live database. Reclaimed here,
	// on the copy that holds the rows.
	FileBlob::flushDeferredReclaims();
	$db->exec("DELETE FROM fol_folders WHERE fol_folder_id IN ($folders)");
});
harness_defer(function () { SessionControl::get_instance()->clear_api_user(); });

function far_as($user) {
	$session = SessionControl::get_instance();
	$session->clear_api_user();
	$session->set_api_user((int)$user->key);
}
function far_folder($owner, $name, $parent = null, $level = null) {
	global $made_folders;
	$f = new Folder(NULL);
	$f->set('fol_usr_user_id', (int)$owner->key);
	$f->set('fol_name', $name . '_' . bin2hex(random_bytes(3)));
	if ($parent) { $f->set('fol_parent_folder_id', (int)$parent->key); }
	if ($level) { $f->set('fol_protection_level', $level); }
	$f->save(); $f->load();
	$made_folders[] = (int)$f->key;
	return $f;
}
function far_file($owner, $folder, array $extra = array()) {
	global $made_files;
	$f = File::createFromBytes('far-' . bin2hex(random_bytes(12)), 'f_' . bin2hex(random_bytes(3)) . '.txt', 'text/plain',
		(int)$owner->key, array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE,
		'fil_fol_folder_id' => (int)$folder->key) + $extra);
	$made_files[] = (int)$f->key;
	return $f;
}
function far_grant($owner, $type, $entity, array $roles) {
	$map = array();
	foreach ($roles as $uid => $role) { $map[(int)$uid] = $role; }
	FileAccessGrant::sync_for_entity($type, (int)$entity->key, $map, (int)$owner->key);
}
function far_cursor() {
	return (int)DbConnector::get_instance()->get_db_link()
		->query('SELECT COALESCE(MAX(fch_file_change_id), 0) FROM fch_file_changes')->fetchColumn();
}
/** Who rows of $kind on the entity after $since are addressed to (null for an unaddressed row). */
function far_audience($type, $id, $kind, $since) {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT fch_audience_usr_user_id FROM fch_file_changes
		  WHERE fch_file_change_id > ? AND fch_entity_type = ? AND fch_entity_id = ? AND fch_change_kind = ?
		  ORDER BY fch_file_change_id');
	$q->execute(array((int)$since, $type, (int)$id, $kind));
	return array_map(function ($v) { return $v === null ? null : (int)$v; }, $q->fetchAll(PDO::FETCH_COLUMN));
}
/** How many rows after $since are addressed to $user, on the entity, of $kind. */
function far_told($user, $type, $id, $kind, $since) {
	return count(array_filter(far_audience($type, $id, $kind, $since), function ($a) use ($user) { return $a === (int)$user->key; }));
}
/** The rows the default feed hands $user about the entity, after $since. */
function far_feed($user, $type, $id, $since) {
	far_as($user);
	$feed = drive_changes_logic(array('cursor' => (int)$since));
	$out = array();
	foreach (($feed->data['changes'] ?? array()) as $c) {
		if (($c['entity_type'] ?? '') === $type && (int)($c['entity_id'] ?? 0) === (int)$id) { $out[] = $c; }
	}
	return $out;
}
/** drive_stat as $user: [items for the entity, whether it is under not_yours]. */
function far_stat($user, $type, $id) {
	far_as($user);
	$r = drive_stat_logic(array('entities' => array(array('entity_type' => $type, 'entity_id' => (int)$id))));
	$not_yours = false;
	foreach (($r->data['not_yours'] ?? array()) as $m) {
		if ((int)($m['entity_id'] ?? 0) === (int)$id) { $not_yours = true; }
	}
	return array($r->data['items'] ?? array(), $not_yours);
}
function far_move($actor, $type, $entity, $to) {
	far_as($actor);
	return drive_move_logic(array('entity_type' => $type, 'entity_id' => (int)$entity->key, 'parent_id' => $to ? (int)$to->key : 0));
}
function far_parent_of_file($file) {
	return (int)DbConnector::get_instance()->get_db_link()
		->query('SELECT COALESCE(fil_fol_folder_id, 0) FROM fil_files WHERE fil_file_id = ' . (int)$file->key)->fetchColumn();
}
/**
 * Refuse every change row on one file, for the length of $fn: a trigger on the
 * change table, scoped to that file, in the test database only.
 */
function far_with_change_rows_refused($file_id, callable $fn) {
	$db = DbConnector::get_instance()->get_db_link();
	$name = 'far_refuse_' . bin2hex(random_bytes(4));
	$db->exec("CREATE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$f\$
	           BEGIN RAISE EXCEPTION 'change row refused by the test'; END \$f\$");
	$db->exec("CREATE TRIGGER $name BEFORE INSERT ON fch_file_changes FOR EACH ROW
	           WHEN (NEW.fch_entity_type = 'file' AND NEW.fch_entity_id = " . (int)$file_id . ")
	           EXECUTE FUNCTION $name()");
	try {
		return $fn();
	} finally {
		if ($db->inTransaction()) { $db->rollBack(); }
		$db->exec("DROP TRIGGER IF EXISTS $name ON fch_file_changes");
		$db->exec("DROP FUNCTION IF EXISTS $name()");
	}
}

$owner   = make_user('farowner');
$viewer  = make_user('farviewer');    // viewer on P only
$editor  = make_user('fareditor');    // editor on P and on R
$both    = make_user('farboth');      // viewer on P and on R
$direct  = make_user('fardirect');    // viewer on P, and on one file directly
$roomer  = make_user('farroomer');    // viewer on R only
$sub     = make_user('farsub');       // viewer on P/A only
$outside = make_user('faroutside');   // nothing

$P = far_folder($owner, 'SharedP');
$Q = far_folder($owner, 'PrivateQ');
$R = far_folder($owner, 'SharedR');
$A = far_folder($owner, 'SubA', $P);
$B = far_folder($owner, 'SubB', $P);
far_grant($owner, 'folder', $P, array($viewer->key => 'viewer', $editor->key => 'editor', $both->key => 'viewer', $direct->key => 'viewer'));
far_grant($owner, 'folder', $R, array($editor->key => 'editor', $both->key => 'viewer', $roomer->key => 'viewer'));
far_grant($owner, 'folder', $A, array($sub->key => 'viewer'));
// The folder's creation, as drive_folder_create records it. It also gives the
// feed a row, so no cursor below is 0, which asks for a fresh start.
FileChange::record(FileChange::KIND_CREATED, 'folder', $P->key, $owner->key, $owner->key);

// ---------------------------------------------------------------------------
section('A move out of a share reaches every member who lost it');

$X1 = far_file($owner, $P);
far_grant($owner, 'file', $X1, array($direct->key => 'viewer'));
$cur = far_cursor();
$r = far_move($owner, 'file', $X1, $Q);
check($r->error === null && far_parent_of_file($X1) === (int)$Q->key, 'the owner moves the file from a shared folder to a private one', (string)$r->error);

$aud = far_audience('file', $X1->key, FileChange::KIND_MOVED, $cur);
check(in_array(null, $aud, true), 'the move\'s own row is still recorded, against the owner');
foreach (array('viewer' => $viewer, 'editor' => $editor, 'both' => $both) as $label => $u) {
	check(far_told($u, 'file', $X1->key, FileChange::KIND_MOVED, $cur) === 1, "a moved row is addressed to the $label member, who reached it only through the folder");
}
check(far_told($direct, 'file', $X1->key, FileChange::KIND_MOVED, $cur) === 1,
	'a member who still holds a grant on the file itself is told too: it left the folder share they had it in');
check(count($aud) === 5, 'one row for the move and one per member it left, nothing more', json_encode($aud));
check(far_told($roomer, 'file', $X1->key, FileChange::KIND_MOVED, $cur) === 0 && far_told($outside, 'file', $X1->key, FileChange::KIND_MOVED, $cur) === 0,
	'nobody it never reached is told');

$rows = far_feed($viewer, 'file', $X1->key, $cur);
check(count($rows) === 1 && $rows[0]['kind'] === FileChange::KIND_MOVED, 'the viewer\'s feed names the file it lost, as moved', json_encode($rows));
if ($rows) {
	$keys = array_keys($rows[0]); sort($keys);
	check($keys === array('actor_id', 'entity_id', 'entity_type', 'id', 'kind', 'owner_id', 'time'),
		'the row carries ids and a kind only: no name, no folder', json_encode($keys));
	check((int)$rows[0]['owner_id'] === (int)$owner->key, 'and still names the real owner');
}
list($items, $not_yours) = far_stat($viewer, 'file', $X1->key);
check(empty($items) && $not_yours, 'asked about it, the viewer is told it is no longer theirs, with nothing about where it went', json_encode($items));
list($items, $not_yours) = far_stat($direct, 'file', $X1->key);
check(count($items) === 1 && !$not_yours, 'the member with a grant on the file itself still reads it');

// ---------------------------------------------------------------------------
section('A move between two shares of one member tells that member');

$X2 = far_file($owner, $P);
$cur = far_cursor();
$r = far_move($owner, 'file', $X2, $R);
check($r->error === null && far_parent_of_file($X2) === (int)$R->key, 'the owner moves a file from one shared folder to another', (string)$r->error);
check(far_told($both, 'file', $X2->key, FileChange::KIND_MOVED, $cur) === 1,
	'the member who holds both folders is told: the share it was in no longer holds it');
check(far_told($editor, 'file', $X2->key, FileChange::KIND_MOVED, $cur) === 1, 'so is the editor of both');
check(far_told($viewer, 'file', $X2->key, FileChange::KIND_MOVED, $cur) === 1, 'and the member of the first only');
check(far_told($roomer, 'file', $X2->key, FileChange::KIND_MOVED, $cur) === 0,
	'a member who gains it is not addressed: the move\'s own row reaches them where it lands');
check(count(far_feed($roomer, 'file', $X2->key, $cur)) >= 1, 'and their feed does name it');
list($items, $not_yours) = far_stat($both, 'file', $X2->key);
check(count($items) === 1 && (int)$items[0]['folder_id'] === (int)$R->key, 'the member of both reads it where it now is');

// ---------------------------------------------------------------------------
section('A move inside one share tells only who it left');

$X3 = far_file($owner, $A);
$cur = far_cursor();
$r = far_move($owner, 'file', $X3, $B);
check($r->error === null, 'the owner moves a file between two folders inside the shared one', (string)$r->error);
check(far_told($sub, 'file', $X3->key, FileChange::KIND_MOVED, $cur) === 1, 'the member of the subfolder it left is told');
check(far_audience('file', $X3->key, FileChange::KIND_MOVED, $cur) === array(null, (int)$sub->key),
	'nobody whose share still holds it is addressed', json_encode(far_audience('file', $X3->key, FileChange::KIND_MOVED, $cur)));

// ---------------------------------------------------------------------------
section('A folder\'s row speaks for its subtree');

$F = far_folder($owner, 'Carried', $P);
$Y = far_file($owner, $F);
$cur = far_cursor();
$r = far_move($owner, 'folder', $F, $Q);
check($r->error === null, 'the owner moves a folder out of the shared one', (string)$r->error);
check(far_told($viewer, 'folder', $F->key, FileChange::KIND_MOVED, $cur) === 1, 'the viewer is told about the folder');
check(far_audience('file', $Y->key, FileChange::KIND_MOVED, $cur) === array(), 'and nothing is written for each thing inside it');
list($items, $not_yours) = far_stat($viewer, 'folder', $F->key);
check(empty($items) && $not_yours, 'the folder is no longer the viewer\'s');
list($items, $not_yours) = far_stat($viewer, 'file', $Y->key);
check(empty($items) && $not_yours, 'nor the file inside it');

// ---------------------------------------------------------------------------
section('A move and its rows are one write');

$X5 = far_file($owner, $P);
$cur = far_cursor();
$threw = null;
far_with_change_rows_refused($X5->key, function () use ($owner, $X5, $Q, &$threw) {
	try {
		far_move($owner, 'file', $X5, $Q);
	} catch (Throwable $e) {
		$threw = $e->getMessage();
	}
});
check($threw !== null, 'a move whose rows cannot be written fails');
check(far_parent_of_file($X5) === (int)$P->key, 'and the file is still where it was, not moved with nobody told');
check(far_audience('file', $X5->key, FileChange::KIND_MOVED, $cur) === array(), 'with no row');
$r = far_move($owner, 'file', $X5, $Q);
check($r->error === null && far_told($viewer, 'file', $X5->key, FileChange::KIND_MOVED, $cur) === 1, 'tried again, it moves and tells');

// ---------------------------------------------------------------------------
section('A name race lost inside the write is still a name clash');

$taken = far_folder($owner, 'Taken');
$db->beginTransaction();
$loser = new Folder(NULL);
$loser->set('fol_usr_user_id', (int)$owner->key);
$loser->set('fol_name', $taken->get('fol_name'));
$loser->set('fol_protection_level', 'standard');
$saved = null;
$threw = null;
try {
	$saved = DriveHelper::save_folder_unless_name_taken($loser);
} catch (Throwable $e) {
	$threw = $e->getMessage();
}
$usable = false;
try {
	$usable = ((int)$db->query('SELECT 1')->fetchColumn() === 1);
} catch (Throwable $e) {
}
$db->rollBack();
check($threw === null && $saved === false, 'the helper answers name taken, not a database error', (string)$threw);
check($usable, 'and the transaction it ran in can still be used');

// ---------------------------------------------------------------------------
section('A restore that brings an item back at the top tells who it left');

$T = far_folder($owner, 'Trunk', $P);
$S = far_folder($owner, 'Branch', $T);
$Z = far_file($owner, $T);
far_as($owner);
check(drive_trash_logic(array('entity_type' => 'folder', 'entity_id' => (int)$S->key))->error === null, 'the owner trashes a subfolder');
check(drive_trash_logic(array('entity_type' => 'file', 'entity_id' => (int)$Z->key))->error === null, 'and a file beside it');
check(drive_trash_logic(array('entity_type' => 'folder', 'entity_id' => (int)$T->key))->error === null, 'then the folder holding both');

$cur = far_cursor();
far_as($owner);
check(drive_restore_logic(array('entity_type' => 'folder', 'entity_id' => (int)$S->key))->error === null, 'the subfolder is restored');
$parent = $db->query('SELECT fol_parent_folder_id FROM fol_folders WHERE fol_folder_id = ' . (int)$S->key)->fetchColumn();
check($parent === null, 'at the top, since its folder is still in the trash');
check(far_audience('folder', $S->key, FileChange::KIND_RESTORED, $cur) === array(null), 'the restore\'s own row is recorded against the owner');
check(far_told($viewer, 'folder', $S->key, FileChange::KIND_MOVED, $cur) === 1, 'the member of the share it left is told, as for a move');
list($items, $not_yours) = far_stat($viewer, 'folder', $S->key);
check(empty($items) && $not_yours, 'and it is no longer theirs');

far_as($owner);
check(drive_restore_logic(array('entity_type' => 'file', 'entity_id' => (int)$Z->key))->error === null && far_parent_of_file($Z) === 0,
	'the file is restored at the top too');
check(far_told($viewer, 'file', $Z->key, FileChange::KIND_MOVED, $cur) === 1, 'and the member it left is told');

// ---------------------------------------------------------------------------
section('A file key given or taken is told to the member it is for');

$vault = far_folder($owner, 'Vault', null, ProtectionLevel::FORTRESS);
$keyed = make_user('farkeyed');
$other = make_user('farother');
far_grant($owner, 'folder', $vault, array($keyed->key => 'viewer', $other->key => 'viewer'));
$enc = array('fil_protection_level' => ProtectionLevel::FORTRESS, 'fil_encrypted_metadata' => base64_encode(random_bytes(40)));
$E1 = far_file($owner, $vault, $enc);
$E2 = far_file($owner, $vault, $enc);
FileKeyGrant::put($E1->key, $owner->key, base64_encode(random_bytes(80)));
$blob = function () { return base64_encode(random_bytes(80)); };

$cur = far_cursor();
far_as($owner);
$k1 = $blob();
$r = drive_key_grants_sync_logic(array('file_keys' => array((string)$E1->key => array((string)$keyed->key => $k1, (string)$other->key => $blob()))));
check($r->error === null && (int)$r->data['synced'] === 1, 'the owner gives two members the file\'s key', (string)$r->error);
check(far_told($keyed, 'file', $E1->key, FileChange::KIND_GRANT_CHANGED, $cur) === 1, 'a grant_changed row is addressed to the first');
check(far_told($other, 'file', $E1->key, FileChange::KIND_GRANT_CHANGED, $cur) === 1, 'and to the second');
check(far_audience('file', $E1->key, FileChange::KIND_GRANT_CHANGED, $cur) === array((int)$keyed->key, (int)$other->key)
	|| far_audience('file', $E1->key, FileChange::KIND_GRANT_CHANGED, $cur) === array((int)$other->key, (int)$keyed->key),
	'one each and nothing for the owner, whose key did not change', json_encode(far_audience('file', $E1->key, FileChange::KIND_GRANT_CHANGED, $cur)));
// A member who can see the file sees the other member's row as well: the feed
// matches it through the shared folder, and a row says nothing of whom it is for.
$rows = far_feed($keyed, 'file', $E1->key, $cur);
check(count($rows) >= 1 && $rows[0]['kind'] === FileChange::KIND_GRANT_CHANGED, 'the member\'s feed names the file', json_encode($rows));
check(!array_key_exists('audience_id', $rows[0] ?? array()) && count($rows[0] ?? array()) === 7, 'and does not say whom the row was addressed to');
list($items, $not_yours) = far_stat($keyed, 'file', $E1->key);
check(count($items) === 1 && ($items[0]['wrapped_file_key'] ?? null) === $k1, 'and asked about it, hands over their key');

$cur = far_cursor();
far_as($owner);
drive_key_grants_sync_logic(array('file_keys' => array((string)$E1->key => array((string)$keyed->key => $blob(), (string)$other->key => $blob()))));
check(far_audience('file', $E1->key, FileChange::KIND_GRANT_CHANGED, $cur) === array(),
	'the same members re-wrapped is not news: the browser re-wraps every key on every change to a share');

$cur = far_cursor();
far_as($owner);
drive_key_grants_sync_logic(array('file_keys' => array((string)$E1->key => array((string)$keyed->key => $blob()))));
check(FileKeyGrant::wrapped_key_for($E1->key, $other->key) === null, 'the owner takes the second member\'s key away');
check(far_audience('file', $E1->key, FileChange::KIND_GRANT_CHANGED, $cur) === array((int)$other->key), 'that member alone is told');
check(count(far_feed($other, 'file', $E1->key, $cur)) === 1, 'in their feed');

$cur = far_cursor();
$threw = null;
far_with_change_rows_refused($E2->key, function () use ($owner, $E2, $keyed, $blob, &$threw) {
	far_as($owner);
	try {
		drive_key_grants_sync_logic(array('file_keys' => array((string)$E2->key => array((string)$keyed->key => $blob()))));
	} catch (Throwable $e) {
		$threw = $e->getMessage();
	}
});
check($threw !== null, 'a key whose row cannot be written fails');
check(FileKeyGrant::wrapped_key_for($E2->key, $keyed->key) === null, 'and is not kept: no key nobody was told of');

harness_finish();

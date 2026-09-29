<?php
/** @joinery-test
 * name: drive_level_change
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Changing a Drive folder tree's protection level, through the two actions the
 * page calls (drive_level_change, then drive_level_batch until nothing remains):
 *   - only the owner, only Standard <-> Private, only a top-level folder;
 *   - Private needs a vault; going Private reports the sharing it will end and
 *     does nothing until confirmed, then revokes it;
 *   - the whole subtree flips at once and the files converge afterwards, in
 *     bounded batches, with no window for a raise;
 *   - lowering needs the owner's window, before anything flips;
 *   - a change asks for a recent second factor, for an owner who has one.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));
// The session starts before harness_boot(), which may print (a stale-mail sweep)
// and so make a later session_start() impossible on the CLI.
$has_session = vault_ensure_session();
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/logic.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
VaultUnlock::loadConsumerBootstraps();   // DriveSealed loads only through the loader

$db = DbConnector::get_instance()->get_db_link();
$made_files = array();
$made_folders = array();
harness_defer(function () use (&$made_files, &$made_folders) {
	$db = DbConnector::get_instance()->get_db_link();
	foreach ($made_files as $fid) {
		if (!$db->query('SELECT 1 FROM fil_files WHERE fil_file_id = ' . (int)$fid)->fetchColumn()) continue;
		(new File((int)$fid, true))->permanent_delete();
	}
	foreach (array_reverse($made_folders) as $fid) {
		$db->prepare("DELETE FROM fsl_file_share_links WHERE fsl_entity_type = 'folder' AND fsl_entity_id = ?")->execute(array((int)$fid));
		$db->prepare("DELETE FROM fol_folders WHERE fol_folder_id = ?")->execute(array((int)$fid));
	}
});
$saved_session = array('usr_user_id' => $_SESSION['usr_user_id'] ?? null, 'loggedin' => $_SESSION['loggedin'] ?? null,
	'permission' => $_SESSION['permission'] ?? null);
harness_defer(function () use ($saved_session) {
	foreach ($saved_session as $k => $v) { if ($v === null) { unset($_SESSION[$k]); } else { $_SESSION[$k] = $v; } }
});

function dlv_as($user) {
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = (int)$user->get('usr_permission');
}
function dlv_folder($owner_id, $level, &$made_folders, $parent = null) {
	$f = new Folder(NULL);
	$f->set('fol_usr_user_id', (int)$owner_id);
	$f->set('fol_name', 'LC_' . bin2hex(random_bytes(4)));
	$f->set('fol_protection_level', $level);
	if ($parent) { $f->set('fol_parent_folder_id', (int)$parent); }
	$f->save(); $f->load();
	$made_folders[] = $f->key;
	return $f;
}
function dlv_file($owner_id, $folder_id, $body, &$made_files) {
	$f = File::createFromBytes($body, 'f_' . bin2hex(random_bytes(3)) . '.txt', 'text/plain', (int)$owner_id,
		array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	$f->set('fil_fol_folder_id', (int)$folder_id); $f->save(); $f->load();
	$made_files[] = $f->key;
	return $f;
}
function dlv_change($folder_id, $level, array $extra = array()) {
	return harness_call_logic('logic/drive_level_change_logic.php', 'drive_level_change_logic',
		array('folder_id' => (int)$folder_id, 'protection_level' => $level) + $extra);
}
function dlv_batch($folder_id) {
	return harness_call_logic('logic/drive_level_batch_logic.php', 'drive_level_batch_logic', array('folder_id' => (int)$folder_id));
}
function dlv_level($folder_id) {
	return (string)DbConnector::get_instance()->get_db_link()
		->query('SELECT fol_protection_level FROM fol_folders WHERE fol_folder_id = ' . (int)$folder_id)->fetchColumn();
}
/** Drive the batch action until nothing remains (or it stops making progress). */
function dlv_converge($folder_id) {
	$last = null;
	for ($i = 0; $i < 20; $i++) {
		$r = dlv_batch($folder_id);
		if ($r->error !== null) return $r;
		$last = $r;
		if ((int)$r->data['remaining'] === 0 || (int)$r->data['converted'] === 0) break;
	}
	return $last;
}

$owner = make_user('drvlc_owner');
$other = make_user('drvlc_other');
$kp = vault_fixture_server_vault((int)$owner->key);
$window_ok = $has_session && vault_apcu_usable();
// Made before anything decrypts: a process that has opened sealed content may
// not write long values (the TOTP secret) into an unsealed table.
$twofa = make_user('drvlc_2fa');
$twofa->enable_totp('JBSWY3DPEHPK3PXP');
$twofa->save();

// ---------------------------------------------------------------------------
section('Who, which levels, and which folders');

$top = dlv_folder($owner->key, ProtectionLevel::STANDARD, $made_folders);
$child = dlv_folder($owner->key, ProtectionLevel::STANDARD, $made_folders, $top->key);
dlv_as($other);
$r = dlv_change($top->key, ProtectionLevel::PRIVATE_);
check($r->error !== null && dlv_level($top->key) === ProtectionLevel::STANDARD, 'someone other than the owner is refused', (string)$r->error);
dlv_as($owner);
$r = dlv_change($top->key, ProtectionLevel::FORTRESS);
check($r->error !== null && dlv_level($top->key) === ProtectionLevel::STANDARD, 'Fortress is not a server-side change', (string)$r->error);
$r = dlv_change($top->key, 'privat');
check($r->error !== null, 'a mistyped level is refused', (string)$r->error);
$r = dlv_change($child->key, ProtectionLevel::PRIVATE_);
check($r->error !== null && stripos((string)$r->error, 'top-level') !== false && dlv_level($child->key) === ProtectionLevel::STANDARD,
	'only a top-level folder can be made Private', (string)$r->error);
$fortress = dlv_folder($owner->key, ProtectionLevel::FORTRESS, $made_folders);
$r = dlv_change($fortress->key, ProtectionLevel::STANDARD);
check($r->error !== null && dlv_level($fortress->key) === ProtectionLevel::FORTRESS,
	'a Fortress folder\'s level is its browser\'s to change', (string)$r->error);
$r = dlv_change($top->key, ProtectionLevel::STANDARD);
check($r->error === null && !empty($r->data['unchanged']), 'the level it already has is reported unchanged');

dlv_as($other);
$other_top = dlv_folder($other->key, ProtectionLevel::STANDARD, $made_folders);
$r = dlv_change($other_top->key, ProtectionLevel::PRIVATE_);
check($r->error !== null && stripos((string)$r->error, 'vault') !== false && dlv_level($other_top->key) === ProtectionLevel::STANDARD,
	'Private needs a vault', (string)$r->error);
dlv_as($owner);

// ---------------------------------------------------------------------------
section('Going Private reports the sharing it will end, then ends it once confirmed');

$shared = dlv_folder($owner->key, ProtectionLevel::STANDARD, $made_folders);
$db->prepare("INSERT INTO fsl_file_share_links (fsl_entity_type, fsl_entity_id, fsl_token_sha256, fsl_usr_user_id)
	VALUES ('folder', ?, ?, ?)")->execute(array((int)$shared->key, hash('sha256', random_bytes(16)), (int)$owner->key));
$r = dlv_change($shared->key, ProtectionLevel::PRIVATE_);
check($r->error === null && !empty($r->data['needs_confirmation']) && !empty($r->data['blockers'])
	&& dlv_level($shared->key) === ProtectionLevel::STANDARD,
	'the first Apply reports the link and changes nothing');
$r = dlv_change($shared->key, ProtectionLevel::PRIVATE_, array('confirm_revoke_sharing' => true));
$live = (int)$db->query("SELECT COUNT(*) FROM fsl_file_share_links WHERE fsl_entity_type = 'folder' AND fsl_entity_id = "
	. (int)$shared->key . " AND fsl_revoked_time IS NULL")->fetchColumn();
check($r->error === null && dlv_level($shared->key) === ProtectionLevel::PRIVATE_ && $live === 0,
	'confirmed: the link is revoked and the folder is Private', (string)$r->error);

// ---------------------------------------------------------------------------
section('The subtree flips at once; the files converge afterwards, with no window for a raise');

$tree = dlv_folder($owner->key, ProtectionLevel::STANDARD, $made_folders);
$sub = dlv_folder($owner->key, ProtectionLevel::STANDARD, $made_folders, $tree->key);
$bodies = array();
foreach (array($tree->key, $tree->key, $sub->key) as $i => $fid) {
	$bodies[$i] = 'level change body ' . $i . ' ' . str_repeat('x', 200);
	dlv_file($owner->key, $fid, $bodies[$i], $made_files);
}
if ($window_ok) VaultUnlock::close((int)$owner->key, 'user');
$r = dlv_change($tree->key, ProtectionLevel::PRIVATE_);
check($r->error === null && ($r->data['ok'] ?? false) === true, 'the raise is accepted with the window closed', (string)$r->error);
check(dlv_level($tree->key) === ProtectionLevel::PRIVATE_ && dlv_level($sub->key) === ProtectionLevel::PRIVATE_,
	'the folder and its subfolder promise Private at once');
check((int)($r->data['remaining'] ?? -1) === 3, 'and the three files already inside are reported as the backlog');
$sealed_now = (int)$db->query("SELECT COUNT(*) FROM fil_files WHERE fil_fol_folder_id IN (" . (int)$tree->key . ',' . (int)$sub->key
	. ") AND fil_protection_level = 'private'")->fetchColumn();
check($sealed_now === 0, 'the change itself converts nothing');
$pass = dlv_converge($tree->key);
check($pass !== null && $pass->error === null && (int)$pass->data['remaining'] === 0,
	'drive_level_batch converges every file, window closed', (string)($pass ? $pass->error : 'no pass'));
$raw = (string)$db->query("SELECT fil_protection_level FROM fil_files WHERE fil_fol_folder_id = " . (int)$sub->key . " LIMIT 1")->fetchColumn();
check($raw === ProtectionLevel::PRIVATE_, 'the file in the subfolder is Private too');

// ---------------------------------------------------------------------------
section('Lowering needs the owner\'s window, before anything flips');

if ($window_ok) VaultUnlock::close((int)$owner->key, 'user');
$r = dlv_change($tree->key, ProtectionLevel::STANDARD);
check($r->error !== null && stripos((string)$r->error, 'Unlock your vault') !== false
	&& dlv_level($tree->key) === ProtectionLevel::PRIVATE_,
	'with the window closed the lowering is refused and nothing flips', (string)$r->error);
if (!$window_ok) {
	harness_skip('window-open lowering', 'APCu/session unavailable (run with -d apc.enable_cli=1)');
} else {
	vault_fixture_open_window((int)$owner->key, $kp['secret'], 'user', array('idle' => null, 'absolute' => null));
	$r = dlv_change($tree->key, ProtectionLevel::STANDARD);
	check($r->error === null && dlv_level($tree->key) === ProtectionLevel::STANDARD && dlv_level($sub->key) === ProtectionLevel::STANDARD,
		'with the window open the tree is Standard again at once', (string)$r->error);
	$pass = dlv_converge($tree->key);
	check($pass !== null && $pass->error === null && (int)$pass->data['remaining'] === 0, 'and every file converges back');
	$f = new File((int)$db->query("SELECT fil_file_id FROM fil_files WHERE fil_fol_folder_id = " . (int)$sub->key . " LIMIT 1")->fetchColumn(), true);
	check($f->read_bytes('original') === $bodies[2], 'the bytes come back exactly');
	VaultUnlock::close((int)$owner->key, 'user');
}

// ---------------------------------------------------------------------------
section('Bounded passes, and a change that stopped part-way always finishes');

$slow = dlv_folder($owner->key, ProtectionLevel::STANDARD, $made_folders);
foreach (range(1, 3) as $i) { dlv_file($owner->key, $slow->key, 'slow body ' . $i . str_repeat('y', 100), $made_files); }
if ($window_ok) VaultUnlock::close((int)$owner->key, 'user');
$r = dlv_change($slow->key, ProtectionLevel::PRIVATE_);
check($r->error === null && (int)$r->data['remaining'] === 3, 'raised; three files to convert', (string)$r->error);
$scope = new DriveFolderLevel(DriveHelper::load_folder((int)$slow->key), (int)$owner->key);
$pass = ProtectionLevelChange::convergeBatch($scope, array('rows' => 2));
check($pass['converted'] === 2 && $pass['remaining'] === 1, 'a pass takes no more than its row bound');
$again = dlv_change($slow->key, ProtectionLevel::PRIVATE_);
check($again->error === null && !empty($again->data['unchanged']) && (int)$again->data['remaining'] === 1,
	'applying the level it already has reports what is left, so the dialog resumes it');
check(DriveFolderLevel::hasWork((int)$owner->key), 'the vault\'s deferred work sees the unfinished folder');
DriveFolderLevel::drain((int)$owner->key, microtime(true) + 60);
check(DriveSealed::transitionBacklog((int)$slow->key, ProtectionLevel::PRIVATE_)['files'] === 0
	&& !DriveFolderLevel::hasWork((int)$owner->key), 'and its drain finishes it');

// ---------------------------------------------------------------------------
section('A file that cannot convert is stamped, and stops holding everything up');

$stuck = dlv_folder($owner->key, ProtectionLevel::STANDARD, $made_folders);
$bad = dlv_file($owner->key, $stuck->key, 'unreadable body ' . str_repeat('z', 50), $made_files);
$good = dlv_file($owner->key, $stuck->key, 'fine body ' . str_repeat('w', 50), $made_files);
$bad_path = $bad->get_filesystem_path('original');
rename($bad_path, $bad_path . '.away');
$r = dlv_change($stuck->key, ProtectionLevel::PRIVATE_);
$pass = dlv_batch($stuck->key);
$stamp = (string)$db->query('SELECT fil_level_attempt_time FROM fil_files WHERE fil_file_id = ' . (int)$bad->key)->fetchColumn();
$good_level = (string)$db->query('SELECT fil_protection_level FROM fil_files WHERE fil_file_id = ' . (int)$good->key)->fetchColumn();
check($pass->error === null && $good_level === ProtectionLevel::PRIVATE_ && $stamp !== '' && (int)$pass->data['remaining'] === 1,
	'the readable file converts; the unreadable one is stamped and still counted', json_encode($pass->data));
check(!DriveFolderLevel::hasWork((int)$owner->key), 'a stamped file does not wake the deferred work until its retry is due');
rename($bad_path . '.away', $bad_path);
$db->prepare("UPDATE fil_files SET fil_level_attempt_time = NOW() AT TIME ZONE 'UTC' - INTERVAL '2 hours' WHERE fil_file_id = ?")
	->execute(array((int)$bad->key));
DriveFolderLevel::drain((int)$owner->key, microtime(true) + 60);
$after = $db->query('SELECT fil_protection_level, fil_level_attempt_time FROM fil_files WHERE fil_file_id = ' . (int)$bad->key)->fetch(PDO::FETCH_ASSOC);
check($after['fil_protection_level'] === ProtectionLevel::PRIVATE_ && $after['fil_level_attempt_time'] === null,
	'once due, the drain converts it and clears the stamp');

// ---------------------------------------------------------------------------
section('Going Private always ends the sharing, and tells a member who lost it');

$shared2 = dlv_folder($owner->key, ProtectionLevel::STANDARD, $made_folders);
$db->prepare("INSERT INTO fsl_file_share_links (fsl_entity_type, fsl_entity_id, fsl_token_sha256, fsl_usr_user_id)
	VALUES ('folder', ?, ?, ?)")->execute(array((int)$shared2->key, hash('sha256', random_bytes(16)), (int)$owner->key));
$db->prepare("INSERT INTO fga_file_access_grants (fga_entity_type, fga_entity_id, fga_usr_user_id, fga_role, fga_granted_by_user_id)
	VALUES ('folder', ?, ?, 'viewer', ?)")->execute(array((int)$shared2->key, (int)$other->key, (int)$owner->key));
harness_defer(function () use ($db, $shared2) {
	$db->prepare("DELETE FROM fga_file_access_grants WHERE fga_entity_type = 'folder' AND fga_entity_id = ?")->execute(array((int)$shared2->key));
	$db->prepare("DELETE FROM fch_file_changes WHERE fch_entity_type = 'folder' AND fch_entity_id = ?")->execute(array((int)$shared2->key));
});
// The flip itself, with no confirmation recorded on it: whatever is there by then goes.
$r = ProtectionLevelChange::change(new DriveFolderLevel(DriveHelper::load_folder((int)$shared2->key), (int)$owner->key),
	ProtectionLevel::PRIVATE_, (int)$owner->key);
$live = (int)$db->query("SELECT COUNT(*) FROM fsl_file_share_links WHERE fsl_entity_type = 'folder' AND fsl_entity_id = "
	. (int)$shared2->key . " AND fsl_revoked_time IS NULL")->fetchColumn();
$grants = (int)$db->query("SELECT COUNT(*) FROM fga_file_access_grants WHERE fga_entity_type = 'folder' AND fga_entity_id = "
	. (int)$shared2->key)->fetchColumn();
check($r['status'] === ProtectionLevelChange::OK && $live === 0 && $grants === 0, 'the link and the grant are gone with the flip');
$told = (int)$db->query("SELECT COUNT(*) FROM fch_file_changes WHERE fch_entity_type = 'folder' AND fch_entity_id = "
	. (int)$shared2->key . " AND fch_change_kind = '" . FileChange::KIND_GRANT_CHANGED . "' AND fch_audience_usr_user_id = "
	. (int)$other->key)->fetchColumn();
check($told === 1, 'the member who lost the grant gets a grant_changed row addressed to them');

// ---------------------------------------------------------------------------
section('A change asks for a recent second factor, for an owner who has one');

if (!$has_session || session_id() === '') {
	harness_skip('step-up', 'no session could be started on the CLI');
} else {
	$sid = session_id();
	$clear_markers = function () use ($sid) {
		DbConnector::get_instance()->get_db_link()
			->prepare("DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?")->execute(array($sid));
	};
	harness_defer($clear_markers);
	$clear_markers();
	vault_fixture_server_vault((int)$twofa->key);
	dlv_as($twofa);
	$mine = dlv_folder($twofa->key, ProtectionLevel::STANDARD, $made_folders);
	$r = dlv_change($mine->key, ProtectionLevel::PRIVATE_);
	check($r->error !== null && !empty($r->data['requires_stepup']) && dlv_level($mine->key) === ProtectionLevel::STANDARD,
		'without a recent confirmation: refused with requires_stepup, nothing flips', (string)$r->error);
	SessionControl::get_instance()->stamp_second_factor();
	$r = dlv_change($mine->key, ProtectionLevel::PRIVATE_);
	check($r->error === null && dlv_level($mine->key) === ProtectionLevel::PRIVATE_, 'confirmed: the change goes through', (string)$r->error);
	$clear_markers();
}

harness_finish();
?>

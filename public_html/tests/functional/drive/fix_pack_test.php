<?php
/** @joinery-test
 * name: drive_fix_pack
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
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('data/folders_class.php'));
require_once(PathHelper::getIncludePath('data/file_uploads_class.php'));
require_once(PathHelper::getIncludePath('data/file_versions_class.php'));
require_once(PathHelper::getIncludePath('data/file_changes_class.php'));
require_once(PathHelper::getIncludePath('data/file_access_grants_class.php'));
require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
require_once(PathHelper::getIncludePath('logic/drive_folder_create_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_list_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_move_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_trash_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_restore_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_share_sync_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_changes_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_upload_init_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_upload_complete_logic.php'));

$dblink = DbConnector::get_instance()->get_db_link();

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

// --- A dedicated small-quota tier so quota boundaries are testable ----------
$tier = new SubscriptionTier(NULL);
$tier->set('sbt_tier_level', 990);
$tier->set('sbt_name', 'drive_fixpack_' . bin2hex(random_bytes(3)));
$tier->set('sbt_display_name', 'Drive FixPack Test Tier');
$tier->set('sbt_features', json_encode(array(
	'drive_storage_bytes'    => 30000,
	'drive_max_file_bytes'   => 20000,
	'drive_versioning_depth' => 3,
)));
$tier->save();
$tier->load();
$tier_group = (int)$tier->get('sbt_grp_group_id');
harness_register_row('sbt_subscription_tiers', 'sbt_subscription_tier_id', $tier->key);
harness_register_row('grp_groups', 'grp_group_id', $tier_group);

function fixpack_enroll($user_id, $group_id) {
	$dblink = DbConnector::get_instance()->get_db_link();
	$ins = $dblink->prepare("INSERT INTO grm_group_members (grm_grp_group_id, grm_foreign_key_id) VALUES (?, ?) RETURNING grm_group_member_id");
	$ins->execute(array((int)$group_id, (int)$user_id));
	harness_register_row('grm_group_members', 'grm_group_member_id', $ins->fetchColumn());
}

/** Create a private Drive-source file owned by $user_id (optionally in a folder). */
function fixpack_file($content, $name, $user_id, $folder_id = 0) {
	global $made_files;
	$f = File::createFromBytes($content, $name, 'application/octet-stream', $user_id, array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	if ($folder_id) { $f->set('fil_fol_folder_id', $folder_id); $f->save(); }
	$made_files[] = $f->key;
	return $f;
}

/** Simulate a fully-received chunk upload: write the part file and mark it complete. */
function fixpack_fill_upload($raw_token, $content) {
	$up = FileUpload::load_by_token($raw_token);
	$part = $up->part_path();
	$dir = dirname($part);
	if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
	file_put_contents($part, $content);
	$up->set('fup_received_bytes', strlen($content));
	$up->set('fup_update_time', gmdate('Y-m-d H:i:s'));
	$up->save();
}

$ownerA  = make_user('fixowner');
$editorB = make_user('fixeditor');
$viewerC = make_user('fixviewer');
fixpack_enroll($ownerA->key, $tier_group);
fixpack_enroll($editorB->key, $tier_group);

$session = SessionControl::get_instance();
$session->set_api_user($ownerA->key);
harness_defer(function () use ($session) { $session->clear_api_user(); });

// ---------------------------------------------------------------------------
section('dedup requires possession: a foreign hash+size never matches');

$secret = 'fixpack-secret-' . bin2hex(random_bytes(16));
$secret_sha = hash('sha256', $secret);
$fileA = fixpack_file($secret, 'secret.bin', $ownerA->key);

// The owner re-initing their own bytes dedups (possession proven by ownership).
$rOwn = drive_upload_init_logic(array('name' => 'secret-copy.bin', 'size_bytes' => strlen($secret), 'sha256' => $secret_sha));
check(($rOwn->data['deduped'] ?? false) === true, 'owner init with own content hash dedups');
if (!empty($rOwn->data['file']['id'])) { $made_files[] = (int)$rOwn->data['file']['id']; }

// A different user presenting the same (stolen) hash+size gets NO dedup — and
// therefore no access to the bytes and no existence signal.
$session->clear_api_user();
$session->set_api_user($editorB->key);
$rForeign = drive_upload_init_logic(array('name' => 'steal.bin', 'size_bytes' => strlen($secret), 'sha256' => $secret_sha));
check(($rForeign->data['deduped'] ?? true) === false, 'foreign hash+size does not dedup (no oracle, no disclosure)');
check(!empty($rForeign->data['upload_token']), 'foreign-hash init falls through to a normal upload');
$upB = FileUpload::load_by_token($rForeign->data['upload_token']);
if ($upB) { $upB->discard(); }

// ---------------------------------------------------------------------------
section('quota is enforced at upload_complete (pre-opened uploads cannot bypass it)');

// Two 18k uploads both pass init under the 30k quota (usage 0 at both inits) —
// completing the second must fail at the storage boundary.
$c1 = str_repeat('a', 18000);
$c2 = str_repeat('b', 18000);
$i1 = drive_upload_init_logic(array('name' => 'q1.bin', 'size_bytes' => 18000));
$i2 = drive_upload_init_logic(array('name' => 'q2.bin', 'size_bytes' => 18000));
check(!empty($i1->data['upload_token']) && !empty($i2->data['upload_token']), 'both uploads open while under quota');

fixpack_fill_upload($i1->data['upload_token'], $c1);
fixpack_fill_upload($i2->data['upload_token'], $c2);

$done1 = drive_upload_complete_logic(array('upload_token' => $i1->data['upload_token']));
check(!empty($done1->data['ok']), 'first complete lands (18k of 30k)');
if (!empty($done1->data['file']['id'])) { $made_files[] = (int)$done1->data['file']['id']; }

$done2 = drive_upload_complete_logic(array('upload_token' => $i2->data['upload_token']));
check($done2->error !== null, 'second complete is rejected at the quota boundary', 'got ' . var_export($done2->error, true));
$up2 = FileUpload::load_by_token($i2->data['upload_token']);
check($up2 !== null, 'rejected upload keeps its pending row (retry after freeing space)');
if ($up2) { $up2->discard(); }

// Free B's quota space again for later sections.
$fB1 = new File((int)$done1->data['file']['id'], true);
if ($fB1->key) { $fB1->permanent_delete(); array_pop($made_files); }
DriveUsage::recompute($editorB->key);

// ---------------------------------------------------------------------------
section('editor upload into a shared folder: owned by and billed to the folder owner');

$session->clear_api_user();
$session->set_api_user($ownerA->key);
$rF = drive_folder_create_logic(array('name' => 'FixShared_' . bin2hex(random_bytes(3))));
$sharedF = (int)$rF->data['folder']['id']; $made_folders[] = $sharedF;
drive_share_sync_logic(array('entity_type' => 'folder', 'entity_id' => $sharedF, 'grants' => array((string)$editorB->key => 'editor')));

$usageA_before = DriveUsage::recompute($ownerA->key);

$session->clear_api_user();
$session->set_api_user($editorB->key);
$body = str_repeat('c', 4000);
$iE = drive_upload_init_logic(array('name' => 'from-editor.bin', 'size_bytes' => 4000, 'folder_id' => $sharedF));
check(!empty($iE->data['upload_token']), 'editor can open an upload into the shared folder');
fixpack_fill_upload($iE->data['upload_token'], $body);
$doneE = drive_upload_complete_logic(array('upload_token' => $iE->data['upload_token']));
check(!empty($doneE->data['ok']), 'editor upload completes');
$editor_file_id = (int)$doneE->data['file']['id'];
$made_files[] = $editor_file_id;
check((int)$doneE->data['file']['owner_id'] === (int)$ownerA->key, 'uploaded file is owned by the FOLDER owner, not the editor');

check(DriveUsage::recompute($ownerA->key) === $usageA_before + 4000, 'bytes bill the folder owner');
check(DriveUsage::recompute($editorB->key) === 0, 'the editor is not billed');

// The owner sees the editor's upload in their own folder browse.
$session->clear_api_user();
$session->set_api_user($ownerA->key);
$ownList = drive_list_logic(array('folder_id' => $sharedF));
$own_sees = false;
foreach ($ownList->data['items'] as $it) { if ($it['entity_type'] === 'file' && (int)$it['id'] === $editor_file_id) { $own_sees = true; } }
check($own_sees, 'owner sees the editor-uploaded file in the folder');

// ---------------------------------------------------------------------------
section('grantee browse of a shared folder lists the owner\'s children');

$session->clear_api_user();
$session->set_api_user($editorB->key);
$gList = drive_list_logic(array('folder_id' => $sharedF));
$g_sees = false;
foreach ($gList->data['items'] as $it) { if ($it['entity_type'] === 'file' && (int)$it['id'] === $editor_file_id) { $g_sees = true; } }
check($g_sees, 'grantee browsing the shared folder sees its contents (not an empty listing)');

// ---------------------------------------------------------------------------
section('cross-owner move is rejected (single-owner trees)');

$fileB = fixpack_file('b-own-' . bin2hex(random_bytes(6)), 'b-own.bin', $editorB->key);
$rMove = drive_move_logic(array('entity_type' => 'file', 'entity_id' => (int)$fileB->key, 'parent_id' => $sharedF));
check($rMove->error !== null, 'moving own file into another user\'s folder is rejected');
$fileB->load();
check($fileB->get('fil_fol_folder_id') === null, 'the file stays at its owner\'s root');

// ---------------------------------------------------------------------------
section('breadcrumb for a grantee starts at the granted root');

$session->clear_api_user();
$session->set_api_user($ownerA->key);
$rP = drive_folder_create_logic(array('name' => 'FixPrivateParent_' . bin2hex(random_bytes(3))));
$P = (int)$rP->data['folder']['id']; $made_folders[] = $P;
$rC = drive_folder_create_logic(array('name' => 'FixGrantedChild_' . bin2hex(random_bytes(3)), 'parent_id' => $P));
$C = (int)$rC->data['folder']['id']; $made_folders[] = $C;
$fInC = fixpack_file('in-c-' . bin2hex(random_bytes(6)), 'FindMeShared.bin', $ownerA->key, $C);
$fInP = fixpack_file('in-p-' . bin2hex(random_bytes(6)), 'FindMePrivate.bin', $ownerA->key, $P);
drive_share_sync_logic(array('entity_type' => 'folder', 'entity_id' => $C, 'grants' => array((string)$viewerC->key => 'viewer')));

$session->clear_api_user();
$session->set_api_user($viewerC->key);
$cList = drive_list_logic(array('folder_id' => $C));
$crumb_ids = array_map(function ($b) { return (int)$b['id']; }, $cList->data['breadcrumb']);
check($crumb_ids === array($C), 'grantee breadcrumb is cut at the granted folder (private ancestors hidden)', json_encode($crumb_ids));

$pList = drive_list_logic(array('folder_id' => $P));
check($pList->error !== null, 'grantee still cannot browse the private parent');

// ---------------------------------------------------------------------------
section('search spans shared files');

$sFound = drive_list_logic(array('search' => 'FindMeShared'));
$found_shared = false;
foreach ($sFound->data['items'] as $it) { if ((int)$it['id'] === (int)$fInC->key) { $found_shared = true; } }
check($found_shared, 'search finds a file inside a granted folder subtree');

$sPriv = drive_list_logic(array('search' => 'FindMePrivate'));
check(count($sPriv->data['items']) === 0, 'search does not surface the owner\'s ungranted files');

// ---------------------------------------------------------------------------
section('restoring a folder whose parent is still trashed re-roots it');

$session->clear_api_user();
$session->set_api_user($ownerA->key);
$rP2 = drive_folder_create_logic(array('name' => 'FixTrashParent_' . bin2hex(random_bytes(3))));
$P2 = (int)$rP2->data['folder']['id']; $made_folders[] = $P2;
$rC2 = drive_folder_create_logic(array('name' => 'FixTrashChild_' . bin2hex(random_bytes(3)), 'parent_id' => $P2));
$C2 = (int)$rC2->data['folder']['id']; $made_folders[] = $C2;
$fInC2 = fixpack_file('in-c2-' . bin2hex(random_bytes(6)), 'c2file.bin', $ownerA->key, $C2);

drive_trash_logic(array('entity_type' => 'folder', 'entity_id' => $C2)); // child first (independent trash)
drive_trash_logic(array('entity_type' => 'folder', 'entity_id' => $P2)); // then the parent
$rRestore = drive_restore_logic(array('entity_type' => 'folder', 'entity_id' => $C2));
check(!empty($rRestore->data['ok']), 'restore succeeds');
$c2row = new Folder($C2, true);
check($c2row->get('fol_delete_time') === null, 'restored folder is live');
check($c2row->get('fol_parent_folder_id') === null, 'restored folder re-rooted away from its still-trashed parent');
$rootList = drive_list_logic(array());
$c2_visible = false;
foreach ($rootList->data['items'] as $it) { if ($it['entity_type'] === 'folder' && (int)$it['id'] === $C2) { $c2_visible = true; } }
check($c2_visible, 'restored folder is visible at the root (not lost in limbo)');
$fInC2->load();
check($fInC2->get('fil_delete_time') === null, 'its file came back with it');

// ---------------------------------------------------------------------------
section('change feed resets when the retained log is empty');

$max_cursor = (int)$dblink->query("SELECT COALESCE(MAX(fch_file_change_id),0) FROM fch_file_changes")->fetchColumn();
$dblink->beginTransaction();
try {
	$dblink->exec("DELETE FROM fch_file_changes");
	$rEmpty = drive_changes_logic(array('cursor' => max(1, $max_cursor)));
	check(!empty($rEmpty->data['reset']), 'a nonzero cursor against an emptied change log returns reset:true');
} finally {
	$dblink->rollBack();
}

// ---------------------------------------------------------------------------
section('version bytes bill the file owner, not the saver');

$usageA_v = DriveUsage::recompute($ownerA->key);
$usageB_v = DriveUsage::recompute($editorB->key);
$vtmp = tempnam(sys_get_temp_dir(), 'fixpack_v');
file_put_contents($vtmp, str_repeat('v', 2500));
$vblob = FileBlob::createFromPath($vtmp, 'application/octet-stream', true);
FileVersion::save_new_content($fInC, $vblob, $editorB->key); // saved BY the editor
// fInC's previous head becomes a version (still billed to A); the 2500-byte blob is the new head.
check(DriveUsage::recompute($ownerA->key) === $usageA_v + 2500, 'owner usage grows by the new head; demoted version still bills the owner');
check(DriveUsage::recompute($editorB->key) === $usageB_v, 'the saver\'s usage is unchanged');

// ---------------------------------------------------------------------------
section('trash purge destroys only Drive files');

$purge_owner = make_user('fixpurge');
$nonDrive = File::createFromBytes('nd-' . bin2hex(random_bytes(6)), 'avatar.bin', 'application/octet-stream', $purge_owner->key, array('fil_private' => true, 'fil_source' => File::SOURCE_ENTITY_PHOTO));
$made_files[] = $nonDrive->key;
$oldDrive = fixpack_file('od-' . bin2hex(random_bytes(6)), 'old-drive.bin', $purge_owner->key);
$newDrive = fixpack_file('nw-' . bin2hex(random_bytes(6)), 'new-drive.bin', $purge_owner->key);

$stale = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
$dblink->prepare("UPDATE fil_files SET fil_delete_time = ? WHERE fil_file_id IN (?, ?)")->execute(array($stale, (int)$nonDrive->key, (int)$oldDrive->key));
$dblink->prepare("UPDATE fil_files SET fil_delete_time = now() WHERE fil_file_id = ?")->execute(array((int)$newDrive->key));

File::purgeExpiredTrash(30);

$exists = function ($id) use ($dblink) {
	$q = $dblink->prepare("SELECT 1 FROM fil_files WHERE fil_file_id = ?");
	$q->execute(array((int)$id));
	return $q->fetchColumn() !== false;
};
check($exists($nonDrive->key), 'a soft-deleted NON-Drive file survives the Drive trash purge');
check(!$exists($oldDrive->key), 'an over-retention Drive trash file is purged');
check($exists($newDrive->key), 'a freshly trashed Drive file is kept');

// ---------------------------------------------------------------------------
section('share_sync reports unresolvable grantees');

$session->clear_api_user();
$session->set_api_user($ownerA->key);
$rSync = drive_share_sync_logic(array(
	'entity_type' => 'file',
	'entity_id'   => (int)$fInC->key,
	'grants'      => array(
		(string)$viewerC->key            => 'viewer',
		'nobody-here@invalid.example'    => 'editor',
	),
));
check(($rSync->data['granted_count'] ?? -1) === 1, 'granted_count counts only applied grants');
check(($rSync->data['skipped'] ?? array()) === array('nobody-here@invalid.example'), 'unresolvable email is reported as skipped');

harness_finish();

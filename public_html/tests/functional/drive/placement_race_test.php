<?php
/** @joinery-test
 * name: drive_placement_race
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */

/**
 * Placing something into a folder, against that folder going to the trash.
 *
 * Every Drive verb that puts an item into a folder reads the destination,
 * decides it is not trashed, and then does work before the row lands — quota,
 * bytes, sealing, thumbnails. The trash cascade meanwhile marks the folder
 * deleted and sweeps the children it can see. A cascade that runs entirely
 * inside that window trashes the folder, finds nothing, and leaves the new row
 * live underneath it: listed by nothing, reachable by no tree walk, and — for a
 * sync client that hears about it through the change feed — a download whose
 * parent never arrives, so the device never converges again.
 *
 * The soak rig produced exactly this on 2026-08-19: folder 21878 trashed at
 * 11:40:02.070, file 76302 created inside it at 11:40:02.119, still live. Both
 * devices sat on one pending_download for the rest of the campaign.
 *
 * The race is made deterministic here rather than raced for. A worker process
 * completes a real upload into a live folder; it passes the destination check
 * and then blocks on the quota lock, which this process is holding. Seeing the
 * worker waiting is proof it is inside the window. The folder is trashed at
 * that moment, the lock released, and the worker runs on to its write.
 *
 * A build without the placement lock leaves a live file under a trashed folder
 * and fails the invariant below.
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/folders_class.php'));
require_once(PathHelper::getIncludePath('data/file_uploads_class.php'));
require_once(PathHelper::getIncludePath('data/file_changes_class.php'));
require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('logic/drive_folder_create_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_upload_init_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_trash_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_move_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_upload_complete_logic.php'));

if (!function_exists('proc_open')) {
	harness_skip('proc_open disabled — cannot spawn a concurrent worker');
	harness_finish();
}

$dblink = DbConnector::get_instance()->get_db_link();

$made_files = array();
$made_folders = array();
harness_defer(function () use (&$made_files, &$made_folders) {
	$dblink = DbConnector::get_instance()->get_db_link();
	foreach ($made_files as $fid) { $f = new File((int)$fid, true); if ($f->key) { $f->permanent_delete(); } }
	foreach (array_reverse($made_folders) as $fid) {
		$dblink->prepare("DELETE FROM fol_folders WHERE fol_folder_id=?")->execute(array((int)$fid));
	}
});

harness_set_setting_mem('drive_active', '1');

// --- A tier with room for the test uploads ---------------------------------
$tier = new SubscriptionTier(NULL);
$tier->set('sbt_tier_level', 991);
$tier->set('sbt_name', 'drive_race_' . bin2hex(random_bytes(3)));
$tier->set('sbt_display_name', 'Drive Placement Race Tier');
$tier->set('sbt_features', json_encode(array(
	'drive_storage_bytes'  => 5000000,
	'drive_max_file_bytes' => 200000,
)));
$tier->save();
$tier->load();
$tier_group = (int)$tier->get('sbt_grp_group_id');
harness_register_row('sbt_subscription_tiers', 'sbt_subscription_tier_id', $tier->key);
harness_register_row('grp_groups', 'grp_group_id', $tier_group);

$owner = make_user('placerace');
$ins = $dblink->prepare("INSERT INTO grm_group_members (grm_grp_group_id, grm_foreign_key_id) VALUES (?, ?) RETURNING grm_group_member_id");
$ins->execute(array($tier_group, (int)$owner->key));
harness_register_row('grm_group_members', 'grm_group_member_id', $ins->fetchColumn());

$owner_id = (int)$owner->key;
$session = SessionControl::get_instance();
$session->set_api_user($owner_id);
harness_defer(function () use ($session) { $session->clear_api_user(); });

/** Stage a fully-received upload into $folder_id and return its raw token. */
function race_staged_upload($name, $folder_id, $content) {
	$r = drive_upload_init_logic(array(
		'name'        => $name,
		'size_bytes'  => strlen($content),
		'folder_id'   => (int)$folder_id,
		'mime_type'   => 'application/octet-stream',
	));
	if ($r->error !== null) { return null; }
	$token = $r->data['upload_token'] ?? null;
	if (!$token) { return null; }
	$up = FileUpload::load_by_token($token);
	$part = $up->part_path();
	if (!is_dir(dirname($part))) { @mkdir(dirname($part), 0777, true); }
	file_put_contents($part, $content);
	$up->set('fup_received_bytes', strlen($content));
	$up->set('fup_update_time', gmdate('Y-m-d H:i:s'));
	$up->save();
	return $token;
}

/** Live files sitting under a trashed folder — the invariant, asked of the database. */
function race_unreachable_count($owner_id) {
	$dblink = DbConnector::get_instance()->get_db_link();
	$q = $dblink->prepare(
		"SELECT count(*) FROM fil_files f
		   JOIN fol_folders o ON o.fol_folder_id = f.fil_fol_folder_id
		  WHERE f.fil_usr_user_id = ?
		    AND f.fil_delete_time IS NULL
		    AND o.fol_delete_time IS NOT NULL");
	$q->execute(array((int)$owner_id));
	return (int)$q->fetchColumn();
}

// The worker: finish a real upload, print the verdict. Nothing test-only runs
// inside it — this is the production path a second request would take.
$worker_src = <<<'WORKER'
<?php
$root = '__ROOT__';
require_once($root . '/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/folders_class.php'));
require_once(PathHelper::getIncludePath('data/file_uploads_class.php'));
require_once(PathHelper::getIncludePath('data/file_changes_class.php'));
require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('logic/drive_upload_complete_logic.php'));

$user_id = (int)($argv[1] ?? 0);
$token   = (string)($argv[2] ?? '');

SessionControl::get_instance()->set_api_user($user_id);
try {
	$r = drive_upload_complete_logic(array('upload_token' => $token));
} catch (Throwable $e) {
	echo "RESULT:THREW:" . get_class($e) . ':' . $e->getMessage() . "\n";
	exit(0);
}
if ($r->error !== null) {
	echo "RESULT:REFUSED:" . ($r->data['reason'] ?? '-') . ':' . (int)($r->data['folder_id'] ?? 0) . ':' . $r->error . "\n";
} else {
	echo "RESULT:CREATED:" . (int)($r->data['file']['id'] ?? 0) . "\n";
}
WORKER;

$worker_src = str_replace('__ROOT__', PathHelper::getRootDir(), $worker_src);
$worker_path = tempnam(sys_get_temp_dir(), 'dpr_') . '.php';
file_put_contents($worker_path, $worker_src);
@chmod($worker_path, 0666);
harness_defer(function () use ($worker_path) { @unlink($worker_path); });

/**
 * Run the worker with the quota lock held, trash $folder the moment the worker
 * is provably waiting on it, then let it go. Returns the worker's RESULT line.
 *
 * The lock is a Postgres advisory lock, held for the session rather than a
 * transaction, so this connection keeps working normally while the worker is
 * stopped inside upload_complete on its own.
 */
function race_complete_while_trashing($worker_path, $owner_id, $token, $folder_id, &$note) {
	$dblink = DbConnector::get_instance()->get_db_link();
	$dblink->prepare("SELECT pg_advisory_lock(?, ?)")->execute(array(DriveHelper::QUOTA_LOCK_CLASS, (int)$owner_id));

	$descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$env = $_ENV;
	$env['PATH'] = getenv('PATH');
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker_path)
		. ' ' . (int)$owner_id . ' ' . escapeshellarg($token);
	$proc = proc_open($cmd, $descriptors, $pipes, null, $env);
	if (!is_resource($proc)) {
		$dblink->prepare("SELECT pg_advisory_unlock(?, ?)")->execute(array(DriveHelper::QUOTA_LOCK_CLASS, (int)$owner_id));
		$note = 'worker failed to start';
		return '';
	}

	// Wait for the worker to reach the quota lock. Until it does it has not yet
	// passed the destination check, and trashing now would prove nothing.
	$waiting = $dblink->prepare(
		"SELECT count(*) FROM pg_locks
		  WHERE locktype = 'advisory' AND classid = ? AND objid = ? AND NOT granted");
	$deadline = microtime(true) + 30.0;
	$arrived = false;
	while (microtime(true) < $deadline) {
		$waiting->execute(array(DriveHelper::QUOTA_LOCK_CLASS, (int)$owner_id));
		if ((int)$waiting->fetchColumn() > 0) { $arrived = true; break; }
		usleep(20000);
	}
	$note = $arrived ? 'worker reached the quota lock' : 'worker never reached the quota lock';

	// Inside the window: the destination is trashed while the upload is mid-flight.
	drive_trash_logic(array('entity_type' => 'folder', 'entity_id' => (int)$folder_id));

	$dblink->prepare("SELECT pg_advisory_unlock(?, ?)")->execute(array(DriveHelper::QUOTA_LOCK_CLASS, (int)$owner_id));

	$out = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	proc_close($proc);
	if (trim($err) !== '' && strpos($out, 'RESULT:') === false) {
		$note .= '; worker stderr: ' . trim(substr($err, 0, 300));
	}
	return $out;
}

// ---------------------------------------------------------------------------
section('a folder trashed mid-upload cannot be left holding a live file');

$rF = drive_folder_create_logic(array('name' => 'Race Target ' . bin2hex(random_bytes(3))));
check($rF->error === null, 'destination folder created', (string)$rF->error);
$folder_id = (int)($rF->data['folder']['id'] ?? 0);
if ($folder_id) { $made_folders[] = $folder_id; }

$token = race_staged_upload('caught-in-flight.bin', $folder_id, random_bytes(12000));
check(!empty($token), 'an upload is staged into the live folder');

check(race_unreachable_count($owner_id) === 0, 'no live file sits under a trashed folder before the race');

$note = '';
$out = $token ? race_complete_while_trashing($worker_path, $owner_id, $token, $folder_id, $note) : '';
check(strpos($note, 'reached the quota lock') !== false,
	'the worker was inside the window when the folder was trashed', $note);
check(strpos($out, 'RESULT:') !== false, 'the worker returned a verdict', trim($note . ' | ' . $out));

// The invariant. This is the one that fails without the placement lock.
$stranded = race_unreachable_count($owner_id);
check($stranded === 0,
	'the upload did not land a live file under the folder that was trashed',
	'live files under a trashed folder: ' . $stranded . ' | worker said: ' . trim($out));

check(strpos($out, 'RESULT:REFUSED:parent_trashed:' . $folder_id . ':') !== false,
	'and the upload is refused as parent_trashed naming the folder that was trashed',
	trim($out));

// Whatever the worker made must not survive teardown.
if (preg_match('/RESULT:CREATED:(\d+)/', $out, $m)) { $made_files[] = (int)$m[1]; }

// ---------------------------------------------------------------------------
section('the same upload into a folder that stays live still lands');

$rF2 = drive_folder_create_logic(array('name' => 'Race Control ' . bin2hex(random_bytes(3))));
check($rF2->error === null, 'control folder created', (string)$rF2->error);
$folder2 = (int)($rF2->data['folder']['id'] ?? 0);
if ($folder2) { $made_folders[] = $folder2; }

$token2 = race_staged_upload('lands-normally.bin', $folder2, random_bytes(9000));
check(!empty($token2), 'a second upload is staged');

$descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$env = $_ENV; $env['PATH'] = getenv('PATH');
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker_path)
	. ' ' . $owner_id . ' ' . escapeshellarg((string)$token2);
$proc = proc_open($cmd, $descriptors, $pipes, null, $env);
$out2 = '';
if (is_resource($proc)) {
	$out2 = stream_get_contents($pipes[1]);
	fclose($pipes[1]); fclose($pipes[2]);
	proc_close($proc);
}
check(preg_match('/RESULT:CREATED:(\d+)/', $out2, $m2) === 1,
	'an upload into a folder nobody trashed still creates the file', trim($out2));
if (!empty($m2[1])) { $made_files[] = (int)$m2[1]; }

// ---------------------------------------------------------------------------
section('a folder created into a parent trashed mid-flight is refused');

// The same window, one verb along: place_into is what both share, so this asks
// whether the refusal is the helper's and not one call site's local habit.
$rP = drive_folder_create_logic(array('name' => 'Race Parent ' . bin2hex(random_bytes(3))));
$parent_id = (int)($rP->data['folder']['id'] ?? 0);
if ($parent_id) { $made_folders[] = $parent_id; }
drive_trash_logic(array('entity_type' => 'folder', 'entity_id' => $parent_id));

$rChild = drive_folder_create_logic(array('name' => 'Race Child', 'parent_id' => $parent_id));
check($rChild->error !== null, 'a folder cannot be created under a trashed parent');
check(($rChild->data['reason'] ?? '') === 'parent_trashed', 'and the refusal names the reason');

// A sync client cannot work out which folder was refused. The destination it
// sent may not be the one its operation was planned with — a folder create
// re-resolves its parent at the moment it runs — so a client reading its own
// plan condemned a live folder that merely shared a name with the trashed one.
// The refusal says which folder, at every site that can raise it.
check((int)($rChild->data['folder_id'] ?? 0) === $parent_id,
	'and it names the folder, so a client condemns that one and no other',
	'got ' . var_export($rChild->data['folder_id'] ?? null, true) . ', expected ' . $parent_id);

$rMoveIn = drive_move_logic(array('entity_type' => 'folder', 'entity_id' => $folder2, 'parent_id' => $parent_id));
check(($rMoveIn->data['reason'] ?? '') === 'parent_trashed', 'a move into it is refused too');
check((int)($rMoveIn->data['folder_id'] ?? 0) === $parent_id, 'and names the same folder');

$rInit = drive_upload_init_logic(array('name' => 'late.bin', 'size_bytes' => 10, 'folder_id' => $parent_id));
check(($rInit->data['reason'] ?? '') === 'parent_trashed', 'an upload into it is refused too');
check((int)($rInit->data['folder_id'] ?? 0) === $parent_id, 'and names the same folder');

$q = $dblink->prepare(
	"SELECT count(*) FROM fol_folders c JOIN fol_folders p ON p.fol_folder_id = c.fol_parent_folder_id
	  WHERE c.fol_usr_user_id = ? AND c.fol_delete_time IS NULL AND p.fol_delete_time IS NOT NULL");
$q->execute(array($owner_id));
check((int)$q->fetchColumn() === 0, 'no live folder sits under a trashed one');

// ---------------------------------------------------------------------------
section('a new version of a file in the trash is refused, not swallowed');

// A trashed file is hidden from every listing, so a version admitted into one
// is a save that reports success and shows nothing — bytes charged to quota
// that the owner cannot see, find, or delete. The sync client reaches this
// whenever an upload it planned is still retrying when somebody else's delete
// lands; its answer is to rescue the bytes as a NEW file, which it can only do
// if the server says no to this one.
$rF3 = drive_folder_create_logic(array('name' => 'Version Target ' . bin2hex(random_bytes(3))));
$folder3 = (int)($rF3->data['folder']['id'] ?? 0);
if ($folder3) { $made_folders[] = $folder3; }

$tokenV = race_staged_upload('to-be-trashed.bin', $folder3, random_bytes(4000));
$outV = '';
if ($tokenV) {
	$descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$env = $_ENV; $env['PATH'] = getenv('PATH');
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker_path)
		. ' ' . $owner_id . ' ' . escapeshellarg((string)$tokenV);
	$proc = proc_open($cmd, $descriptors, $pipes, null, $env);
	if (is_resource($proc)) {
		$outV = stream_get_contents($pipes[1]);
		fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
	}
}
check(preg_match('/RESULT:CREATED:(\d+)/', $outV, $mV) === 1, 'a file to hold versions is created', trim($outV));
$file_v = (int)($mV[1] ?? 0);
if ($file_v) { $made_files[] = $file_v; }

// A version while it is live is ordinary and must still work.
$rLive = drive_upload_init_logic(array('file_id' => $file_v, 'size_bytes' => 500));
check($rLive->error === null, 'a version of a live file is accepted', (string)$rLive->error);

// Staged while live, trashed before the bytes land: refused at completion.
$tokenLate = $rLive->data['upload_token'] ?? null;
if ($tokenLate) {
	$up = FileUpload::load_by_token($tokenLate);
	$part = $up->part_path();
	if (!is_dir(dirname($part))) { @mkdir(dirname($part), 0777, true); }
	file_put_contents($part, random_bytes(500));
	$up->set('fup_received_bytes', 500);
	$up->set('fup_update_time', gmdate('Y-m-d H:i:s'));
	$up->save();
}
drive_trash_logic(array('entity_type' => 'file', 'entity_id' => $file_v));

$rComplete = drive_upload_complete_logic(array('upload_token' => $tokenLate));
check($rComplete->error !== null, 'a version staged before the trash is refused at completion');
check(($rComplete->data['reason'] ?? '') === 'file_trashed', 'and the refusal names the reason',
	var_export($rComplete->data['reason'] ?? null, true));
check((int)($rComplete->data['file_id'] ?? 0) === $file_v, 'and names the file');

// And a fresh attempt does not even start.
$rDead = drive_upload_init_logic(array('file_id' => $file_v, 'size_bytes' => 500));
check(($rDead->data['reason'] ?? '') === 'file_trashed', 'a new version of a trashed file is refused at init');

$fv = new File($file_v, true);
check($fv->key && $fv->get('fil_delete_time') !== null && $fv->get('fil_delete_time') !== '',
	'and the file is still in the trash, holding no new bytes');

harness_finish();

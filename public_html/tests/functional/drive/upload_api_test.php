<?php
/** @joinery-test
 * name: drive_upload_api
 * tier: db
 * env: dev-only
 * needs: []
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../api/api_test_harness.php');
api_test_boot($argv);

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

$made_files = array();
harness_defer(function () use (&$made_files) {
	foreach ($made_files as $fid) {
		$f = new File((int)$fid, true);
		if ($f->key) { $f->permanent_delete(); }
	}
});

// --- Find a tier with a nonzero drive quota and enroll a test user -----------
$dblink = DbConnector::get_instance()->get_db_link();
$grp_id = $dblink->query("SELECT sbt_grp_group_id FROM sbt_subscription_tiers WHERE (sbt_features->>'drive_storage_bytes')::bigint > 0 AND sbt_delete_time IS NULL LIMIT 1")->fetchColumn();

if (!$grp_id) {
	section('Setup');
	harness_skip('a tier with drive_storage_bytes > 0 exists', 'configure a test tier to run the upload API suite');
	harness_finish();
}

$user = make_user('driveupload');
$ins = $dblink->prepare("INSERT INTO grm_group_members (grm_grp_group_id, grm_foreign_key_id) VALUES (?, ?) RETURNING grm_group_member_id");
$ins->execute(array((int)$grp_id, (int)$user->key));
$grm_id = $ins->fetchColumn();
harness_register_row('grm_group_members', 'grm_group_member_id', $grm_id);

$key = make_machine_key($user->key, 'driveupload-' . bin2hex(random_bytes(3)));
$H = key_headers($key['api_key']->get('apk_public_key'), $key['secret_key']);

$quota = (int)SubscriptionTier::getUserFeature($user->key, 'drive_storage_bytes', 0);
check($quota > 0, 'test user has a nonzero drive quota (' . $quota . ' bytes)');

// ---------------------------------------------------------------------------
section('init → sequential chunks (wrong-offset 409 + resume) → complete');

$content = random_bytes(20000);
$sha = hash('sha256', $content);
$total = strlen($content);

$init = api_request('POST', '/api/v1/action/drive_upload_init', $H, array(
	'name' => 'chunked.bin', 'size_bytes' => $total, 'sha256' => $sha, 'mime_type' => 'application/octet-stream',
));
check($init['status'] === 200, 'init returns 200', 'got ' . $init['status']);
$token = $init['json']['data']['upload_token'] ?? null;
check(!empty($token), 'init returns an upload_token (no dedup for fresh content)');
check(($init['json']['data']['deduped'] ?? true) === false, 'fresh content is not deduped');

// Chunk 1: bytes 0..7999
$c1 = substr($content, 0, 8000);
$r1 = harness_put_chunk('/api/v1/drive_upload/' . $token, $H, 'bytes 0-7999/' . $total, $c1);
check($r1['status'] === 200 && ($r1['json']['data']['received_bytes'] ?? 0) === 8000, 'chunk 1 accepted, received=8000', 'got ' . $r1['status'] . ' / ' . json_encode($r1['json']['data'] ?? null));

// Wrong offset: replay chunk 1 → 409 with the current received_bytes
$rWrong = harness_put_chunk('/api/v1/drive_upload/' . $token, $H, 'bytes 0-7999/' . $total, $c1);
check($rWrong['status'] === 409, 'wrong-offset chunk rejected with 409', 'got ' . $rWrong['status']);
check(($rWrong['json']['data']['received_bytes'] ?? -1) === 8000, '409 reports received_bytes=8000 for resume');

// Resume: chunk 2 (8000..15999), chunk 3 (16000..19999)
$c2 = substr($content, 8000, 8000);
$r2 = harness_put_chunk('/api/v1/drive_upload/' . $token, $H, 'bytes 8000-15999/' . $total, $c2);
check($r2['status'] === 200 && ($r2['json']['data']['received_bytes'] ?? 0) === 16000, 'chunk 2 accepted, received=16000');
$c3 = substr($content, 16000);
$r3 = harness_put_chunk('/api/v1/drive_upload/' . $token, $H, 'bytes 16000-19999/' . $total, $c3);
check($r3['status'] === 200 && ($r3['json']['data']['received_bytes'] ?? 0) === $total, 'chunk 3 accepted, received=total');

// GET status
$stat = api_request('GET', '/api/v1/drive_upload/' . $token, $H);
check(($stat['json']['data']['received_bytes'] ?? -1) === $total, 'GET status reports full receipt');

$complete = api_request('POST', '/api/v1/action/drive_upload_complete', $H, array('upload_token' => $token));
check($complete['status'] === 200 && !empty($complete['json']['data']['file']), 'complete returns the file');
$file1 = $complete['json']['data']['file'] ?? array();
$file1_id = $file1['id'] ?? 0;
if ($file1_id) { $made_files[] = $file1_id; }
check(($file1['size'] ?? -1) === $total, 'stored file size matches uploaded bytes');

// verify stored bytes hash matches
$f1model = new File((int)$file1_id, true);
check($f1model->key && hash('sha256', (string)$f1model->read_bytes('original')) === $sha, 'stored bytes match the uploaded sha256');

// ---------------------------------------------------------------------------
section('dedup short-circuit');

$initDup = api_request('POST', '/api/v1/action/drive_upload_init', $H, array(
	'name' => 'chunked-copy.bin', 'size_bytes' => $total, 'sha256' => $sha, 'mime_type' => 'application/octet-stream',
));
check($initDup['status'] === 200, 'dedup init returns 200');
check(($initDup['json']['data']['deduped'] ?? false) === true, 'identical content dedup-completes immediately');
$dupFile = $initDup['json']['data']['file'] ?? array();
$dupId = $dupFile['id'] ?? 0;
if ($dupId) { $made_files[] = $dupId; }
check($dupId && $dupId !== $file1_id, 'dedup created a distinct logical file sharing the blob');
$fdup = new File((int)$dupId, true);
check($fdup->key && (int)$fdup->get('fil_fbb_file_blob_id') === (int)$f1model->get('fil_fbb_file_blob_id'), 'deduped file points at the same blob');

// ---------------------------------------------------------------------------
section('quota rejection at the boundary');

// Push recorded usage to exactly the quota, then a 1-byte upload must be refused.
$usage = DriveUsage::for_user($user->key);
$usage->set('dru_bytes_used', $quota);
$usage->save();
$initFull = api_request('POST', '/api/v1/action/drive_upload_init', $H, array(
	'name' => 'over.bin', 'size_bytes' => 1, 'mime_type' => 'application/octet-stream',
));
check($initFull['status'] >= 400 || !empty($initFull['json']['errortype']), 'upload at quota boundary is rejected', 'status ' . $initFull['status'] . ' ' . ($initFull['json']['error'] ?? ''));
DriveUsage::recompute($user->key); // restore accurate usage

// ---------------------------------------------------------------------------
section('idempotent complete');

$content4 = random_bytes(4096);
$init4 = api_request('POST', '/api/v1/action/drive_upload_init', $H, array(
	'name' => 'idem.bin', 'size_bytes' => strlen($content4), 'mime_type' => 'application/octet-stream',
));
$token4 = $init4['json']['data']['upload_token'] ?? null;
check(!empty($token4), 'init (idempotency case) returns a token');
$r4 = harness_put_chunk('/api/v1/drive_upload/' . $token4, $H, 'bytes 0-4095/4096', $content4);
check(($r4['json']['data']['received_bytes'] ?? 0) === 4096, 'single chunk uploaded');

$idemKey = 'drive-idem-' . bin2hex(random_bytes(8));
$hdrIdem = array_merge($H, array('Idempotency-Key: ' . $idemKey));
$comp1 = api_request('POST', '/api/v1/action/drive_upload_complete', $hdrIdem, array('upload_token' => $token4));
$comp2 = api_request('POST', '/api/v1/action/drive_upload_complete', $hdrIdem, array('upload_token' => $token4));
check($comp1['status'] === 200 && !empty($comp1['json']['data']['file']), 'first complete succeeds');
$idFile1 = $comp1['json']['data']['file']['id'] ?? 0;
if ($idFile1) { $made_files[] = $idFile1; }
$idFile2 = $comp2['json']['data']['file']['id'] ?? 0;
check($comp2['status'] === 200 && $idFile2 === $idFile1, 'retry with same Idempotency-Key replays the same result (no double-create)');

harness_finish();

<?php
/** @joinery-test
 * name: cloud_storage_characterization
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Characterization test — cloud-offload per-row behavior (PIN)
 *
 * The regression net for the destructive step: the forward path deletes local
 * bytes after flipping a row to 'cloud', so a slip there loses real files
 * silently. It drives the engine's private _sync_row / _pull_row through a mock
 * driver via reflection, against a SINGLE real fbb_file_blobs fixture (never the
 * batch entry, which would touch other rows), using the real BlobStorageProfile
 * adapter — the offload descriptor now lives on the blob, shared by every file
 * that references it.
 *
 * Run: php tests/integration/cloud_storage_characterization_test.php
 *
 * @version 2.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/BlobStorageProfile.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageLifecycle.php'));
require_once(__DIR__ . '/../lib/cloud_fixtures.php'); // RecordingMockDriver


$settings  = Globalvars::get_instance();
$dblink    = DbConnector::get_instance()->get_db_link();
$upload_dir = $settings->get_setting('upload_dir');
$fast_dir   = dirname($upload_dir) . '/static_files/uploads';

$created_blob_ids = [];
$created_task_ids = [];
$temp_paths = [];

/** A real fbb_file_blobs fixture. Public + local by default. */
function make_blob_row(array $overrides = []) {
	global $created_blob_ids;
	$name = '_chartest_' . bin2hex(random_bytes(6)) . '.bin';
	$b = new FileBlob(NULL);
	$b->set('fbb_stored_name', $name);
	$b->set('fbb_size_bytes', 16);
	$b->set('fbb_mime_type', $overrides['fbb_mime_type'] ?? 'application/octet-stream');
	$b->set('fbb_is_private', $overrides['fbb_is_private'] ?? false);
	$b->set('fbb_reference_count', 1);
	$b->set('fbb_storage_driver', $overrides['fbb_storage_driver'] ?? 'local');
	$b->set('fbb_sync_failed_count', $overrides['fbb_sync_failed_count'] ?? 0);
	$b->save();
	$created_blob_ids[] = $b->key;
	return $b;
}

// Reflection helpers to reach the engine's private per-row methods. Driving a
// single fixture id (not syncBatch) keeps the test from touching other rows.
$profile  = new BlobStorageProfile();
$sync_row = new ReflectionMethod('CloudOffloadEngine', '_sync_row');
$sync_row->setAccessible(true);
$pull_row = new ReflectionMethod('CloudOffloadEngine', '_pull_row');
$pull_row->setAccessible(true);

section('Cloud storage characterization (pins current behavior)');

try {
	// -------------------------------------------------------------------
	// 1. Forward happy path: push → flip to 'cloud' → local file deleted
	// -------------------------------------------------------------------
	$b = make_blob_row();
	$orig_path = $fast_dir . '/' . $b->get('fbb_stored_name'); // public → fast dir
	if (!is_dir($fast_dir)) { mkdir($fast_dir, 0777, true); }
	file_put_contents($orig_path, "original-bytes\n");
	$temp_paths[] = $orig_path;

	$driver = new RecordingMockDriver();
	$res = $sync_row->invoke(null, $profile, (int)$b->key, $driver);
	$reloaded = new FileBlob($b->key, true);
	ok('forward: returns pushed', $res === 'pushed');
	ok('forward: pushed original key', count($driver->ops('put')) === 1 && $driver->calls[0]['key'] === $b->get('fbb_stored_name'));
	ok('forward: row flipped to cloud', $reloaded->get('fbb_storage_driver') === 'cloud');
	ok('forward: failed_count reset to 0', (int)$reloaded->get('fbb_sync_failed_count') === 0);
	ok('forward: local original deleted after flip', !file_exists($orig_path));

	// -------------------------------------------------------------------
	// 2. Missing-on-disk: failure recorded, counter increments, stays local
	// -------------------------------------------------------------------
	$b2 = make_blob_row();   // no file placed on disk
	$driver2 = new RecordingMockDriver();
	$res2 = $sync_row->invoke(null, $profile, (int)$b2->key, $driver2);
	$reloaded2 = new FileBlob($b2->key, true);
	ok('missing: returns failed', $res2 === 'failed');
	ok('missing: nothing pushed', count($driver2->ops('put')) === 0);
	ok('missing: failed_count incremented to 1', (int)$reloaded2->get('fbb_sync_failed_count') === 1);
	ok('missing: row stays local', $reloaded2->get('fbb_storage_driver') === 'local');

	// (The failure-count cap is covered end-to-end by cloud_offload_engine_test
	// through the engine's REAL eligibility query — a self-referential check that
	// re-ran the SQL inline here was removed: it re-asserted its own copy and
	// could not catch a regression in the actual query.)

	// -------------------------------------------------------------------
	// 3. Mid-flight ineligibility: push undone, row stays local
	// -------------------------------------------------------------------
	$b3 = make_blob_row();
	$orig3 = $fast_dir . '/' . $b3->get('fbb_stored_name');
	file_put_contents($orig3, "original-bytes-3\n");
	$temp_paths[] = $orig3;
	$driver3 = new RecordingMockDriver();
	$fid3 = (int)$b3->key;
	// During the push, flip the blob private so the post-push reload re-check
	// fails for the public profile (isEligibleRow → false).
	$driver3->on_put = function($key) use ($dblink, $fid3) {
		$u = $dblink->prepare("UPDATE fbb_file_blobs SET fbb_is_private = TRUE WHERE fbb_file_blob_id = ?");
		$u->execute([$fid3]);
	};
	$res3 = $sync_row->invoke(null, $profile, $fid3, $driver3);
	$reloaded3 = new FileBlob($b3->key, true);
	ok('midflight: returns skipped', $res3 === 'skipped');
	ok('midflight: pushed keys were deleted (undo)', count($driver3->ops('delete')) === count($driver3->ops('put')) && count($driver3->ops('put')) > 0);
	ok('midflight: row stays local', $reloaded3->get('fbb_storage_driver') === 'local');
	ok('midflight: local original NOT deleted', file_exists($orig3));

	// -------------------------------------------------------------------
	// 4. Reverse pull-back: 'cloud' → 'local', inverse ordering (get before delete)
	// -------------------------------------------------------------------
	$b4 = make_blob_row(['fbb_storage_driver' => 'cloud']);
	$driver4 = new RecordingMockDriver();
	$res4 = $pull_row->invoke(null, $profile, (int)$b4->key, $driver4);
	$reloaded4 = new FileBlob($b4->key, true);
	$local4 = $fast_dir . '/' . $b4->get('fbb_stored_name');
	$temp_paths[] = $local4;
	ok('reverse: returns pulled', $res4 === 'pulled');
	ok('reverse: row flipped to local', $reloaded4->get('fbb_storage_driver') === 'local');
	ok('reverse: bytes placed in public fast dir', file_exists($local4));
	$ops4 = array_map(fn($c) => $c['op'], $driver4->calls);
	$first_get = array_search('get', $ops4, true);
	$first_del = array_search('delete', $ops4, true);
	ok('reverse: pull (get) precedes bucket delete', $first_get !== false && $first_del !== false && $first_get < $first_del);

	// -------------------------------------------------------------------
	// 5. Admin task transitions: activate / deactivate (throwaway class,
	//    via the lifecycle helpers — no live task is touched)
	// -------------------------------------------------------------------
	$activate   = new ReflectionMethod('CloudStorageLifecycle', '_activate_task');
	$activate->setAccessible(true);
	$deactivate = new ReflectionMethod('CloudStorageLifecycle', '_deactivate_task');
	$deactivate->setAccessible(true);

	$probe_class = 'CharProbeTask_' . bin2hex(random_bytes(3));
	$activate->invoke(null, $probe_class);
	$m = new MultiScheduledTask(['task_class' => $probe_class, 'deleted' => false]);
	$m->load();
	$activated = null;
	foreach ($m as $t) { $activated = $t; $created_task_ids[] = $t->key; }
	ok('admin: activate creates an active task', $activated && (bool)$activated->get('sct_is_active') === true);
	ok('admin: activate sets every_run frequency', $activated && $activated->get('sct_frequency') === 'every_run');

	$deactivate->invoke(null, $probe_class);
	$m2 = new MultiScheduledTask(['task_class' => $probe_class, 'deleted' => false]);
	$m2->load();
	$deactivated = null;
	foreach ($m2 as $t) { $deactivated = $t; }
	ok('admin: deactivate clears is_active', $deactivated && (bool)$deactivated->get('sct_is_active') === false);

} finally {
	// Teardown — remove every fixture this test created.
	foreach ($temp_paths as $p) { if (is_file($p)) @unlink($p); }
	foreach ($created_blob_ids as $id) {
		$d = $dblink->prepare("DELETE FROM fbb_file_blobs WHERE fbb_file_blob_id = ?");
		$d->execute([$id]);
	}
	foreach ($created_task_ids as $id) {
		$d = $dblink->prepare("DELETE FROM sct_scheduled_tasks WHERE sct_scheduled_task_id = ?");
		$d->execute([$id]);
	}
}

harness_finish();

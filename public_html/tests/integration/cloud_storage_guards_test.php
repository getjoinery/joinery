<?php
/** @joinery-test
 * name: cloud_storage_guards
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Guards / offload-mode test.
 *
 * Guard 1 (binding immutability): with ≥1 'cloud' row, a Save that changes
 * (endpoint, bucket) is rejected; same binding + rotated key is allowed; with
 * 0 cloud rows a change is allowed.
 *
 * Offload mode dispatch: one CloudOffloadRun tick drives every profile by the
 * store's MODE, derived from the enabled latch + draining flag. The store has
 * exactly one mode per tick (offload / drain / idle), so a row can never
 * ping-pong — forward/reverse mutual-exclusion is structural.
 *
 * Stored settings are overridden only in the Globalvars in-memory cache (this
 * process; never persisted), so no live settings or scheduled tasks are touched.
 *
 * Run: php tests/integration/cloud_storage_guards_test.php
 *
 * @version 3.0 - one store: one binding, one latch, one drain flag; the cloud row is a private blob
 * @version 2.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageLifecycle.php'));

$dblink = DbConnector::get_instance()->get_db_link();
$cloud_fixture_id = null;

try {
	section('Guard 1 — binding immutability');

	// 0 cloud rows → change allowed.
	harness_set_setting_mem('cloud_storage_endpoint', 'ep1.example.com');
	harness_set_setting_mem('cloud_storage_bucket', 'bucket-A');
	$baseline = CloudStorageLifecycle::cloudRowCount();
	if ($baseline === 0) {
		$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep1.example.com', 'bucket' => 'bucket-B']);
		ok('0 cloud rows ⇒ bucket change allowed', $r['ok'] === true);
	} else {
		harness_skip('0 cloud rows ⇒ bucket change allowed', 'this site has offloaded files of its own');
	}

	// Same binding ⇒ allowed (this is the access-key-rotation case).
	$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep1.example.com', 'bucket' => 'bucket-A']);
	ok('same (endpoint,bucket) ⇒ allowed (key rotation)', $r['ok'] === true);

	// Now a cloud row: a private blob, the only kind that reaches the bucket.
	$b = new FileBlob(NULL);
	$b->set('fbb_stored_name', '_guardtest_' . bin2hex(random_bytes(5)) . '.bin');
	$b->set('fbb_size_bytes', 16);
	$b->set('fbb_mime_type', 'application/octet-stream');
	$b->set('fbb_is_private', true);
	$b->set('fbb_reference_count', 1);
	$b->set('fbb_storage_driver', 'cloud');
	$b->save();
	$cloud_fixture_id = $b->key;

	ok('cloudRowCount sees the cloud row', CloudStorageLifecycle::cloudRowCount() === $baseline + 1);

	$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep1.example.com', 'bucket' => 'bucket-B']);
	ok('cloud rows + bucket change ⇒ REJECTED', $r['ok'] === false && !empty($r['message']));

	$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep2.example.com', 'bucket' => 'bucket-A']);
	ok('cloud rows + endpoint change ⇒ REJECTED', $r['ok'] === false);

	$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep1.example.com', 'bucket' => 'bucket-A']);
	ok('cloud rows + same binding ⇒ allowed (key rotation)', $r['ok'] === true);

	section('Offload mode dispatch (mode)');

	// Enabled latch on ⇒ offload (takes precedence over any draining flag).
	harness_set_setting_mem('cloud_storage_enabled', '1');
	harness_set_setting_mem('cloud_storage_draining', '1');
	ok('enabled ⇒ offload (precedence over draining)', CloudStorageLifecycle::mode() === 'offload');

	// Disabled + draining ⇒ drain.
	harness_set_setting_mem('cloud_storage_enabled', '0');
	harness_set_setting_mem('cloud_storage_draining', '1');
	ok('disabled + draining ⇒ drain', CloudStorageLifecycle::mode() === 'drain');

	// Disabled + not draining ⇒ idle (paused: keep serving, do nothing).
	harness_set_setting_mem('cloud_storage_enabled', '0');
	harness_set_setting_mem('cloud_storage_draining', '0');
	ok('disabled + not draining ⇒ idle', CloudStorageLifecycle::mode() === 'idle');

	// With the store idle but a file still offloaded (a paused store), the
	// tick moves nothing, gives the daily file-store check its slice, and stays
	// active: a paused store serves the same files as an active one. No store
	// is bound here, so the check counts the row as one it cannot check.
	harness_set_setting_mem('cloud_storage_enabled', '0');
	harness_set_setting_mem('cloud_storage_draining', '0');
	CloudStoreInventory::$test_hooks['driver'] = function () { return null; };
	CloudStoreInventory::$test_hooks['record'] = array();
	$tick = CloudStorageLifecycle::runOffloadTick();
	ok('runOffloadTick: the store idle, a file offloaded ⇒ no deactivate signal', empty($tick['deactivate']));
	ok('runOffloadTick: status success when idle', ($tick['status'] ?? '') === 'success');
	ok('runOffloadTick: the daily check took its slice', strpos((string)$tick['message'], 'not checked (no store configured') !== false
		|| strpos((string)$tick['message'], 'under the daily check') !== false);
	unset(CloudStoreInventory::$test_hooks['driver']);
	CloudStoreInventory::$test_hooks['record'] = array();

	// With the offloaded file gone as well, there is nothing to move and
	// nothing to check, and the tick asks to be switched off.
	$d = $dblink->prepare("DELETE FROM fbb_file_blobs WHERE fbb_file_blob_id = ?");
	$d->execute([$cloud_fixture_id]);
	$cloud_fixture_id = null;
	$tick = CloudStorageLifecycle::runOffloadTick();
	if (CloudStorageLifecycle::cloudRowCount() === 0) {
		ok('runOffloadTick: the store idle, nothing offloaded ⇒ deactivate signal', !empty($tick['deactivate']));
	} else {
		ok('runOffloadTick: this site has offloaded files of its own, so the tick stays active', empty($tick['deactivate']));
	}

} finally {
	if ($cloud_fixture_id) {
		$d = $dblink->prepare("DELETE FROM fbb_file_blobs WHERE fbb_file_blob_id = ?");
		$d->execute([$cloud_fixture_id]);
	}
}

harness_finish();

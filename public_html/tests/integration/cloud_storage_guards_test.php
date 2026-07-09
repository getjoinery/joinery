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
 * Offload mode dispatch: one CloudOffloadRun tick drives every store by its
 * MODE, derived from the store's enabled latch + draining flag. A store has
 * exactly one mode per tick (offload / drain / idle), so a row can never
 * ping-pong — the old forward/reverse mutual-exclusion is now structural.
 *
 * Stored settings are overridden only in the Globalvars in-memory cache (this
 * process; never persisted), so no live settings or scheduled tasks are touched.
 *
 * Run: php tests/integration/cloud_storage_guards_test.php
 *
 * @version 2.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageLifecycle.php'));

$dblink = DbConnector::get_instance()->get_db_link();
$cloud_fixture_id = null;

try {
	section('Guard 1 — binding immutability');

	// 0 cloud rows for the private store → change allowed.
	harness_set_setting_mem('cloud_storage_endpoint', 'ep1.example.com');
	harness_set_setting_mem('cloud_storage_private_bucket', 'priv-alpha');
	$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep1.example.com', 'bucket' => 'priv-beta'], 'private');
	ok('private: 0 cloud rows ⇒ bucket change allowed', $r['ok'] === true);

	// Same binding ⇒ allowed (this is the access-key-rotation case).
	$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep1.example.com', 'bucket' => 'priv-alpha'], 'private');
	ok('private: same (endpoint,bucket) ⇒ allowed (key rotation)', $r['ok'] === true);

	// Now a public store WITH a cloud row (no restrictions ⇒ public-owned).
	harness_set_setting_mem('cloud_storage_bucket', 'pub-A');
	$f = new File(NULL);
	$f->set('fil_name', '_guardtest_' . bin2hex(random_bytes(5)) . '.bin');
	$f->set('fil_type', 'application/octet-stream');
	$f->set('fil_storage_driver', 'cloud');
	$f->save();
	$cloud_fixture_id = $f->key;

	$count = CloudStorageLifecycle::cloudRowCount('public');
	ok('public: cloudRowCount sees the cloud row', $count >= 1);

	$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep1.example.com', 'bucket' => 'pub-B'], 'public');
	ok('public: cloud rows + bucket change ⇒ REJECTED', $r['ok'] === false && !empty($r['message']));

	$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep2.example.com', 'bucket' => 'pub-A'], 'public');
	ok('public: cloud rows + endpoint change ⇒ REJECTED', $r['ok'] === false);

	$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep1.example.com', 'bucket' => 'pub-A'], 'public');
	ok('public: cloud rows + same binding ⇒ allowed (key rotation)', $r['ok'] === true);

	section('Offload mode dispatch (modeForVisibility)');

	// Enabled latch on ⇒ offload (takes precedence over any draining flag).
	harness_set_setting_mem('cloud_storage_enabled', '1');
	harness_set_setting_mem('cloud_storage_draining', '1');
	ok('public: enabled ⇒ offload (precedence over draining)', CloudStorageLifecycle::modeForVisibility('public') === 'offload');

	// Disabled + draining ⇒ drain.
	harness_set_setting_mem('cloud_storage_enabled', '0');
	harness_set_setting_mem('cloud_storage_draining', '1');
	ok('public: disabled + draining ⇒ drain', CloudStorageLifecycle::modeForVisibility('public') === 'drain');

	// Disabled + not draining ⇒ idle (paused: keep serving, do nothing).
	harness_set_setting_mem('cloud_storage_enabled', '0');
	harness_set_setting_mem('cloud_storage_draining', '0');
	ok('public: disabled + not draining ⇒ idle', CloudStorageLifecycle::modeForVisibility('public') === 'idle');

	// The private store reads its own latch/flag, independently.
	harness_set_setting_mem('cloud_storage_private_enabled', '0');
	harness_set_setting_mem('cloud_storage_private_draining', '1');
	ok('private: own draining flag ⇒ drain (independent of public)', CloudStorageLifecycle::modeForVisibility('private') === 'drain');

	harness_set_setting_mem('cloud_storage_private_enabled', '1');
	ok('private: own enabled latch ⇒ offload', CloudStorageLifecycle::modeForVisibility('private') === 'offload');

	// With every store idle, the tick is a no-op that asks to self-deactivate.
	harness_set_setting_mem('cloud_storage_enabled', '0');
	harness_set_setting_mem('cloud_storage_draining', '0');
	harness_set_setting_mem('cloud_storage_private_enabled', '0');
	harness_set_setting_mem('cloud_storage_private_draining', '0');
	$tick = CloudStorageLifecycle::runOffloadTick();
	ok('runOffloadTick: all stores idle ⇒ deactivate signal', !empty($tick['deactivate']));
	ok('runOffloadTick: status success when idle', ($tick['status'] ?? '') === 'success');

} finally {
	if ($cloud_fixture_id) {
		$d = $dblink->prepare("DELETE FROM fil_files WHERE fil_file_id = ?");
		$d->execute([$cloud_fixture_id]);
	}
}

harness_finish();

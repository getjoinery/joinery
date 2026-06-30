<?php
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

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageLifecycle.php'));

$pass = 0; $fail = 0;
function ok($label, $cond) {
	global $pass, $fail;
	if ($cond) { echo "PASS: $label\n"; $pass++; }
	else       { echo "FAIL: $label\n"; $fail++; }
}

function set_setting_mem($key, $value) {
	$gv = Globalvars::get_instance();
	$ref = new ReflectionProperty('Globalvars', 'settings');
	$ref->setAccessible(true);
	$arr = $ref->getValue($gv);
	if (!is_array($arr)) $arr = [];
	$arr[$key] = $value;
	$ref->setValue($gv, $arr);
}

$dblink = DbConnector::get_instance()->get_db_link();
$cloud_fixture_id = null;

try {
	echo "=== Guard 1 — binding immutability ===\n";

	// 0 cloud rows for the private store → change allowed.
	set_setting_mem('cloud_storage_endpoint', 'ep1.example.com');
	set_setting_mem('cloud_storage_private_bucket', 'priv-alpha');
	$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep1.example.com', 'bucket' => 'priv-beta'], 'private');
	ok('private: 0 cloud rows ⇒ bucket change allowed', $r['ok'] === true);

	// Same binding ⇒ allowed (this is the access-key-rotation case).
	$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep1.example.com', 'bucket' => 'priv-alpha'], 'private');
	ok('private: same (endpoint,bucket) ⇒ allowed (key rotation)', $r['ok'] === true);

	// Now a public store WITH a cloud row (no restrictions ⇒ public-owned).
	set_setting_mem('cloud_storage_bucket', 'pub-A');
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

	echo "\n=== Offload mode dispatch (modeForVisibility) ===\n";

	// Enabled latch on ⇒ offload (takes precedence over any draining flag).
	set_setting_mem('cloud_storage_enabled', '1');
	set_setting_mem('cloud_storage_draining', '1');
	ok('public: enabled ⇒ offload (precedence over draining)', CloudStorageLifecycle::modeForVisibility('public') === 'offload');

	// Disabled + draining ⇒ drain.
	set_setting_mem('cloud_storage_enabled', '0');
	set_setting_mem('cloud_storage_draining', '1');
	ok('public: disabled + draining ⇒ drain', CloudStorageLifecycle::modeForVisibility('public') === 'drain');

	// Disabled + not draining ⇒ idle (paused: keep serving, do nothing).
	set_setting_mem('cloud_storage_enabled', '0');
	set_setting_mem('cloud_storage_draining', '0');
	ok('public: disabled + not draining ⇒ idle', CloudStorageLifecycle::modeForVisibility('public') === 'idle');

	// The private store reads its own latch/flag, independently.
	set_setting_mem('cloud_storage_private_enabled', '0');
	set_setting_mem('cloud_storage_private_draining', '1');
	ok('private: own draining flag ⇒ drain (independent of public)', CloudStorageLifecycle::modeForVisibility('private') === 'drain');

	set_setting_mem('cloud_storage_private_enabled', '1');
	ok('private: own enabled latch ⇒ offload', CloudStorageLifecycle::modeForVisibility('private') === 'offload');

	// With every store idle, the tick is a no-op that asks to self-deactivate.
	set_setting_mem('cloud_storage_enabled', '0');
	set_setting_mem('cloud_storage_draining', '0');
	set_setting_mem('cloud_storage_private_enabled', '0');
	set_setting_mem('cloud_storage_private_draining', '0');
	$tick = CloudStorageLifecycle::runOffloadTick();
	ok('runOffloadTick: all stores idle ⇒ deactivate signal', !empty($tick['deactivate']));
	ok('runOffloadTick: status success when idle', ($tick['status'] ?? '') === 'success');

} finally {
	if ($cloud_fixture_id) {
		$d = $dblink->prepare("DELETE FROM fil_files WHERE fil_file_id = ?");
		$d->execute([$cloud_fixture_id]);
	}
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);

<?php
/**
 * Guards test — the two latent bugs this refactor fixes.
 *
 * Guard 1 (binding immutability): with ≥1 'cloud' row, a Save that changes
 * (endpoint, bucket) is rejected; same binding + rotated key is allowed; with
 * 0 cloud rows a change is allowed.
 *
 * Guard 2 (forward/reverse mutual exclusion): activating the forward task
 * deactivates the reverse task, and vice versa.
 *
 * Stored bindings are overridden only in the Globalvars in-memory cache (this
 * process; never persisted). Guard 2 runs against throwaway task classes so no
 * live scheduled task is touched.
 *
 * Run: php tests/integration/cloud_storage_guards_test.php
 *
 * @version 1.0
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

/** Minimal profile whose only meaningful methods are the task class names. */
class GuardMockProfile implements StorageProfile {
	public $fwd; public $rev;
	public function __construct($fwd, $rev) { $this->fwd = $fwd; $this->rev = $rev; }
	public function forwardTaskClass(): string { return $this->fwd; }
	public function reverseTaskClass(): string { return $this->rev; }
	public function table(): string { return 'fil_files'; }
	public function pkeyColumn(): string { return 'fil_file_id'; }
	public function driverColumn(): string { return 'fil_storage_driver'; }
	public function failedCountColumn(): string { return 'fil_sync_failed_count'; }
	public function lastAttemptColumn(): string { return 'fil_sync_last_attempt'; }
	public function visibility(): string { return 'public'; }
	public function eligibilityWhere(): string { return ''; }
	public function rowExists(int $id): bool { return false; }
	public function isEligibleRow(int $id): bool { return false; }
	public function itemsForRow(int $id): ?array { return null; }
	public function reverseItemsForRow(int $id): array { return []; }
}

$dblink = DbConnector::get_instance()->get_db_link();
$cloud_fixture_id = null;
$created_task_classes = [];

function task_active($task_class) {
	$m = new MultiScheduledTask(['task_class' => $task_class, 'deleted' => false]);
	$m->load();
	foreach ($m as $t) { return (bool)$t->get('sct_is_active'); }
	return null; // not present
}

try {
	echo "=== Guard 1 — binding immutability ===\n";

	// 0 cloud rows (private store has no profile in spec A) → change allowed.
	set_setting_mem('cloud_storage_endpoint', 'ep1.example.com');
	set_setting_mem('cloud_storage_private_bucket', 'priv-alpha');
	$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep1.example.com', 'bucket' => 'priv-beta'], 'private');
	ok('private: 0 cloud rows ⇒ bucket change allowed', $r['ok'] === true);

	// Same binding ⇒ allowed (this is the access-key-rotation case).
	$r = CloudStorageLifecycle::assertBindingMutable(['endpoint' => 'ep1.example.com', 'bucket' => 'priv-alpha'], 'private');
	ok('private: same (endpoint,bucket) ⇒ allowed (key rotation)', $r['ok'] === true);

	// Now a public store WITH a cloud row.
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

	echo "\n=== Guard 2 — forward/reverse mutual exclusion ===\n";
	$sfx = bin2hex(random_bytes(3));
	$fwd = 'GuardMockFwd_' . $sfx;
	$rev = 'GuardMockRev_' . $sfx;
	$created_task_classes = [$fwd, $rev];
	$mock = new GuardMockProfile($fwd, $rev);

	CloudStorageLifecycle::activateReverse($mock);
	ok('activateReverse: reverse active', task_active($rev) === true);
	ok('activateReverse: forward NOT active', task_active($fwd) !== true);

	CloudStorageLifecycle::activateForward($mock);
	ok('activateForward: forward active', task_active($fwd) === true);
	ok('activateForward: reverse deactivated', task_active($rev) === false);

} finally {
	if ($cloud_fixture_id) {
		$d = $dblink->prepare("DELETE FROM fil_files WHERE fil_file_id = ?");
		$d->execute([$cloud_fixture_id]);
	}
	foreach ($created_task_classes as $tc) {
		$d = $dblink->prepare("DELETE FROM sct_scheduled_tasks WHERE sct_task_class = ?");
		$d->execute([$tc]);
	}
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);

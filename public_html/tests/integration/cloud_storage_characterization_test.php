<?php
/** @joinery-test
 * name: cloud_storage_characterization
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Characterization test — current cloud-offload behavior (PIN)
 *
 * Written FIRST, against the UNREFACTORED CloudStorageSync /
 * CloudStorageReverseSync per-row logic and the admin task-transition
 * helpers, before the unification refactor touches any of that code. It is
 * the regression net for the destructive step (the forward path deletes
 * local files after flipping a row to 'cloud'); a transcription slip there
 * loses real user files silently.
 *
 * The per-row logic is driven through a mock driver via reflection on the
 * engine's private _sync_row / _pull_row, against a SINGLE real fil_files
 * fixture (never the batch entry, which would touch other rows). It uses the
 * real FileStorageProfile adapter, so it proves the relocated public-files
 * path behaves identically to the pre-refactor task.
 *
 * Parity note: this test was first written and run green against the
 * unrefactored CloudStorageSync::_sync_row / CloudStorageReverseSync::_pull_row.
 * The only change the refactor required here was repointing the reflected
 * method from the task to CloudOffloadEngine — which is precisely the
 * indirection the refactor introduced. The scenarios and assertions are
 * unchanged.
 *
 * Run: php tests/integration/cloud_storage_characterization_test.php
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/FileStorageProfile.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageLifecycle.php'));

/**
 * In-memory mock driver. Records every call; behavior is overridable via
 * closures so a test can inject a mid-flight side effect or a failure.
 */
class CharMockDriver implements CloudStorageDriver {
	public $calls = [];          // ordered log of ['op'=>..., 'key'=>...]
	public $put_should_fail = false;
	public $on_put = null;       // closure(remote_key): void — side effect during push

	public function putMany(array $items): array {
		$out = [];
		foreach ($items as $item) {
			$this->calls[] = ['op' => 'put', 'key' => $item['remote_key']];
			if ($this->on_put) { ($this->on_put)($item['remote_key']); }
			$out[$item['remote_key']] = $this->put_should_fail
				? new RuntimeException('mock put failure')
				: true;
		}
		return $out;
	}
	public function put(string $local_path, string $remote_key, string $content_type): void {
		$this->calls[] = ['op' => 'put', 'key' => $remote_key];
		if ($this->on_put) { ($this->on_put)($remote_key); }
		if ($this->put_should_fail) { throw new RuntimeException('mock put failure'); }
	}
	public function get(string $remote_key, string $local_path): void {
		$this->calls[] = ['op' => 'get', 'key' => $remote_key];
		$dir = dirname($local_path);
		if (!is_dir($dir)) { mkdir($dir, 0777, true); }
		file_put_contents($local_path, "mock-bytes-for-$remote_key\n");
	}
	public function delete(string $remote_key): void {
		$this->calls[] = ['op' => 'delete', 'key' => $remote_key];
	}
	public function url(string $remote_key): string { return 'https://mock/' . $remote_key; }
	public function ping(): array { return ['ok' => true, 'message' => 'mock']; }

	public function ops($filter = null) {
		$out = [];
		foreach ($this->calls as $c) { if ($filter === null || $c['op'] === $filter) $out[] = $c; }
		return $out;
	}
}

$settings  = Globalvars::get_instance();
$dblink    = DbConnector::get_instance()->get_db_link();
$upload_dir = $settings->get_setting('upload_dir');
$fast_dir   = dirname($upload_dir) . '/static_files/uploads';

$created_file_ids = [];
$created_task_ids = [];
$temp_paths = [];

function make_file_row(array $overrides = []) {
	global $created_file_ids;
	$name = '_chartest_' . bin2hex(random_bytes(6)) . '.bin';
	$f = new File(NULL);
	$f->set('fil_name', $name);
	$f->set('fil_title', 'char test');
	$f->set('fil_type', $overrides['fil_type'] ?? 'application/octet-stream');
	$f->set('fil_storage_driver', $overrides['fil_storage_driver'] ?? 'local');
	$f->set('fil_sync_failed_count', $overrides['fil_sync_failed_count'] ?? 0);
	if (isset($overrides['fil_min_permission'])) $f->set('fil_min_permission', $overrides['fil_min_permission']);
	$f->save();
	$created_file_ids[] = $f->key;
	return $f;
}

// Reflection helpers to reach the engine's private per-row methods. Driving a
// single fixture id (not syncBatch) keeps the test from touching other rows.
$profile  = new FileStorageProfile();
$sync_row = new ReflectionMethod('CloudOffloadEngine', '_sync_row');
$sync_row->setAccessible(true);
$pull_row = new ReflectionMethod('CloudOffloadEngine', '_pull_row');
$pull_row->setAccessible(true);

section('Cloud storage characterization (pins current behavior)');

try {
	// -------------------------------------------------------------------
	// 1. Forward happy path: push → flip to 'cloud' → local file deleted
	// -------------------------------------------------------------------
	$f = make_file_row();
	$orig_path = $upload_dir . '/' . $f->get('fil_name');
	file_put_contents($orig_path, "original-bytes\n");
	$temp_paths[] = $orig_path;

	$driver = new CharMockDriver();
	$res = $sync_row->invoke(null, $profile, (int)$f->key, $driver);
	$reloaded = new File($f->key, true);
	ok('forward: returns pushed', $res === 'pushed');
	ok('forward: pushed original key', count($driver->ops('put')) === 1 && $driver->calls[0]['key'] === $f->get('fil_name'));
	ok('forward: row flipped to cloud', $reloaded->get('fil_storage_driver') === 'cloud');
	ok('forward: failed_count reset to 0', (int)$reloaded->get('fil_sync_failed_count') === 0);
	ok('forward: local original deleted after flip', !file_exists($orig_path));

	// -------------------------------------------------------------------
	// 2. Missing-on-disk: failure recorded, counter increments, stays local
	// -------------------------------------------------------------------
	$f2 = make_file_row();   // no file placed on disk
	$driver2 = new CharMockDriver();
	$res2 = $sync_row->invoke(null, $profile, (int)$f2->key, $driver2);
	$reloaded2 = new File($f2->key, true);
	ok('missing: returns failed', $res2 === 'failed');
	ok('missing: nothing pushed', count($driver2->ops('put')) === 0);
	ok('missing: failed_count incremented to 1', (int)$reloaded2->get('fil_sync_failed_count') === 1);
	ok('missing: row stays local', $reloaded2->get('fil_storage_driver') === 'local');

	// -------------------------------------------------------------------
	// 2b. Cap: a row at FAILED_COUNT_CAP is excluded by the eligibility query
	// -------------------------------------------------------------------
	$f_cap = make_file_row(['fil_sync_failed_count' => CloudOffloadEngine::FAILED_COUNT_CAP]);
	$f_elig = make_file_row(['fil_sync_failed_count' => 0]);
	$q = $dblink->prepare(
		"SELECT fil_file_id FROM fil_files
		 WHERE (fil_storage_driver IS NULL OR fil_storage_driver = 'local')
		   AND fil_delete_time IS NULL
		   AND (fil_min_permission IS NULL OR fil_min_permission = 0)
		   AND (fil_grp_group_id IS NULL OR fil_grp_group_id = 0)
		   AND (fil_evt_event_id IS NULL OR fil_evt_event_id = 0)
		   AND (fil_tier_min_level IS NULL OR fil_tier_min_level = 0)
		   AND COALESCE(fil_sync_failed_count, 0) < :cap
		   AND fil_file_id = ANY(:ids)");
	$q->bindValue(':cap', CloudOffloadEngine::FAILED_COUNT_CAP, PDO::PARAM_INT);
	$q->bindValue(':ids', '{' . $f_cap->key . ',' . $f_elig->key . '}');
	$q->execute();
	$selected = $q->fetchAll(PDO::FETCH_COLUMN, 0);
	ok('cap: capped row excluded', !in_array((string)$f_cap->key, array_map('strval', $selected), true));
	ok('cap: eligible row included', in_array((string)$f_elig->key, array_map('strval', $selected), true));

	// -------------------------------------------------------------------
	// 3. Mid-flight ineligibility: push undone, row stays local
	// -------------------------------------------------------------------
	$f3 = make_file_row();
	$orig3 = $upload_dir . '/' . $f3->get('fil_name');
	file_put_contents($orig3, "original-bytes-3\n");
	$temp_paths[] = $orig3;
	$driver3 = new CharMockDriver();
	$fid3 = (int)$f3->key;
	// During the push, flip the row private so the post-push reload re-check fails.
	$driver3->on_put = function($key) use ($dblink, $fid3) {
		$u = $dblink->prepare("UPDATE fil_files SET fil_min_permission = 5 WHERE fil_file_id = ?");
		$u->execute([$fid3]);
	};
	$res3 = $sync_row->invoke(null, $profile, $fid3, $driver3);
	$reloaded3 = new File($f3->key, true);
	ok('midflight: returns skipped', $res3 === 'skipped');
	ok('midflight: pushed keys were deleted (undo)', count($driver3->ops('delete')) === count($driver3->ops('put')) && count($driver3->ops('put')) > 0);
	ok('midflight: row stays local', $reloaded3->get('fil_storage_driver') === 'local');
	ok('midflight: local original NOT deleted', file_exists($orig3));

	// -------------------------------------------------------------------
	// 4. Reverse pull-back: 'cloud' → 'local', inverse ordering (get before delete)
	// -------------------------------------------------------------------
	$f4 = make_file_row(['fil_storage_driver' => 'cloud']);
	$driver4 = new CharMockDriver();
	$res4 = $pull_row->invoke(null, $profile, (int)$f4->key, $driver4);
	$reloaded4 = new File($f4->key, true);
	$local4 = $fast_dir . '/' . $f4->get('fil_name');
	$temp_paths[] = $local4;
	ok('reverse: returns pulled', $res4 === 'pulled');
	ok('reverse: row flipped to local', $reloaded4->get('fil_storage_driver') === 'local');
	ok('reverse: bytes placed in public fast dir', file_exists($local4));
	$ops4 = array_map(fn($c) => $c['op'], $driver4->calls);
	$first_get = array_search('get', $ops4, true);
	$first_del = array_search('delete', $ops4, true);
	ok('reverse: pull (get) precedes bucket delete', $first_get !== false && $first_del !== false && $first_get < $first_del);

	// -------------------------------------------------------------------
	// 5. Admin task transitions: activate / deactivate (throwaway class,
	//    via the relocated lifecycle helpers — no live task is touched)
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
	foreach ($created_file_ids as $id) {
		$d = $dblink->prepare("DELETE FROM fil_files WHERE fil_file_id = ?");
		$d->execute([$id]);
	}
	foreach ($created_task_ids as $id) {
		$d = $dblink->prepare("DELETE FROM sct_scheduled_tasks WHERE sct_scheduled_task_id = ?");
		$d->execute([$id]);
	}
}

harness_finish();

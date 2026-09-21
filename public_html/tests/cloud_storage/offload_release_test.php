<?php
/** @joinery-test
 * name: offload_release
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * The offload tick, after the flip to cloud: store to the site's backup storage,
 * then release the local bytes only when every enabled backup profile holds
 * the object (specs/backup_offloaded_files.md § The offload tick).
 *
 * Driven one row at a time through CloudOffloadEngine::_sync_row() with the
 * real BlobStorageProfile, real blob rows (registered, removed), a mock file
 * bucket, and a local-provider fixture standing in for the site's backup
 * shelf:
 *
 *   - no profile enabled: released at offload, as it always was
 *   - site profile enabled: the object lands in the site's backup storage under the
 *     current epoch, held.json names it, the bytes go
 *   - manager profile enabled too and its held set lacks the object: the
 *     bytes stay; once its held set names it, the run's release lets them go
 *   - a failed tick store leaves the row cloud with its bytes and no
 *     ciphertext anywhere
 *   - the store step waits on the tick's row lock
 *   - a blob permanently deleted while waiting leaves no local file
 *
 * head() on the fixture driver is part of the file-store inventory and is
 * proven with it.
 *
 * Run: php tests/cloud_storage/offload_release_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/s3_fixtures.php');
require_once(__DIR__ . '/../lib/cloud_fixtures.php');

require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/BlobStorageProfile.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('offload release', 'could not start a local PHP HTTP server on 127.0.0.1');
	harness_finish();
}
harness_defer(function() use ($fx) { s3fx_stop($fx); });

$work = sys_get_temp_dir() . '/jy_offload_release_' . getmypid();
$out  = $work . '/backups';
@mkdir($out, 0700, true);
harness_defer(function() use ($work) { exec('chmod -R u+rwX ' . escapeshellarg($work) . ' 2>/dev/null; rm -rf ' . escapeshellarg($work)); });

$settings = Globalvars::get_instance();
$fast_dir = dirname($settings->get_setting('upload_dir')) . '/static_files/uploads';
if (!is_dir($fast_dir)) { @mkdir($fast_dir, 0777, true); }
$temp_paths = array();
harness_defer(function () use (&$temp_paths) { foreach ($temp_paths as $p) { if (is_file($p)) { @unlink($p); } } });

$dblink = DbConnector::get_instance()->get_db_link();
$profile  = new BlobStorageProfile();
$sync_row = new ReflectionMethod('CloudOffloadEngine', '_sync_row');
$sync_row->setAccessible(true);

/** A real public blob row with local bytes in the fast-serve directory. */
$make_blob = function ($tag, $bytes) use (&$temp_paths, $fast_dir) {
	$name = 'jyor_' . getmypid() . '_' . $tag . '.bin';
	$b = new FileBlob(NULL);
	$b->set('fbb_stored_name', $name);
	$b->set('fbb_size_bytes', strlen($bytes));
	$b->set('fbb_mime_type', 'application/octet-stream');
	$b->set('fbb_is_private', false);
	$b->set('fbb_reference_count', 1);
	$b->set('fbb_storage_driver', 'local');
	$b->set('fbb_sync_failed_count', 0);
	$b->save();
	harness_register_row('fbb_file_blobs', 'fbb_file_blob_id', $b->key);
	$path = $fast_dir . '/' . $name;
	file_put_contents($path, $bytes);
	$temp_paths[] = $path;
	return array($b, $path, $name);
};

$slug = 'offload-release-' . getmypid();
$site_plan = function ($creds, $slug) use ($out) {
	$plan = BackupRunner::plan(array('profile' => 'manager', 'manager' => array(
		'bucket' => 'bkt', 'credentials' => $creds, 'slug' => $slug, 'type' => 'project', 'mode' => 'chain',
		'target_name' => 'local fixture')));
	// The site profile's shape: it builds in the base directory, lists its
	// shelf, and prunes it.
	$plan['profile']        = BackupProfile::SITE;
	$plan['base_dir']       = $out;
	$plan['output_dir']     = $out;
	$plan['objects']        = true;
	$plan['objects_source'] = 'listing';
	$plan['prunes_cloud']   = true;
	return $plan;
};
$plan = $site_plan(s3fx_creds($fx), $slug);
$base = 'joinery-backups/' . $slug . '/site/';
harness_defer(function () { BackupObjects::$test_hooks = array(); BackupProfile::$enabled_for_tests = null; });

$shelf_objects = function () use ($fx, $base) {
	$o = array();
	foreach (s3fx_keys($fx) as $k) {
		if (strpos($k, 'bkt/' . $base . 'objects/') === 0) { $o[] = substr($k, strlen('bkt/' . $base . 'objects/')); }
	}
	sort($o);
	return $o;
};

// ─────────────────────────────────────────────────────────────────────────────
section('No profile enabled: released at offload, as today');

BackupProfile::$enabled_for_tests = array();
BackupObjects::$test_hooks = array('site_plan' => $plan);
list($b0, $p0) = $make_blob('none', "bytes zero\n");
$driver = new RecordingMockDriver();
$r = $sync_row->invoke(null, $profile, (int)$b0->key, $driver);
check($r === 'pushed', 'the row is pushed', (string)$r);
check((new FileBlob($b0->key, true))->get('fbb_storage_driver') === 'cloud', 'and flipped to cloud');
check(!is_file($p0), 'the local bytes are gone');
check($shelf_objects() === array(), 'nothing went to backup storage');
check(!is_file($out . '/objects/held.json'), 'and no held set was written');

// ─────────────────────────────────────────────────────────────────────────────
section('Site profile enabled: stored to the site\'s backup storage, then released');

BackupProfile::$enabled_for_tests = array(BackupProfile::SITE);
$bytes1 = random_bytes(5000);
list($b1, $p1, $n1) = $make_blob('site', $bytes1);
$driver = new RecordingMockDriver();
$r = $sync_row->invoke(null, $profile, (int)$b1->key, $driver);
check($r === 'pushed', 'the row is pushed', (string)$r);
check((new FileBlob($b1->key, true))->get('fbb_storage_driver') === 'cloud', 'and flipped to cloud');
$objs = $shelf_objects();
$epoch = explode('/', $objs[0] ?? '/')[0];
check(count($objs) === 2 && preg_match('/^epoch-\d{8}_\d{6}$/', $epoch) === 1
	&& in_array($epoch . '/' . $n1 . '.enc', $objs, true) && in_array($epoch . '/envelope.json', $objs, true),
	'the object and the epoch envelope are on the site\'s backup storage', json_encode($objs));
$env = json_decode((string)s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/envelope.json'), true);
$epoch_key = BackupEnvelope::open_as_site($env);
file_put_contents($work . '/o.enc', s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/' . $n1 . '.enc'));
BackupObjects::decrypt_file($work . '/o.enc', $work . '/o.plain', $epoch_key);
check(file_get_contents($work . '/o.plain') === $bytes1, 'the object decrypts with the epoch key to the original');
$held = BackupObjects::read_held($plan);
check(is_array($held) && isset($held[$n1]) && $held[$n1]['epoch'] === $epoch
	&& $held[$n1]['object_sha256'] === hash_file('sha256', $work . '/o.enc'), 'held.json names it with epoch and hash', json_encode($held));
check(!is_file($p1), 'the local bytes are gone: the only enabled backup storage holds them');
check(!glob($out . '/objects/tmp/*'), 'no ciphertext is left in objects/tmp');
check(count($driver->ops('put')) === 1, 'the file-bucket push happened once, through its own driver');
check(json_decode(file_get_contents($out . '/objects/epoch.json'), true)['id'] === $epoch, 'epoch.json records the epoch the tick minted');

// A second row in the same epoch: no second envelope.
list($b1b, $p1b, $n1b) = $make_blob('site2', random_bytes(100));
$sync_row->invoke(null, $profile, (int)$b1b->key, new RecordingMockDriver());
$objs = $shelf_objects();
check(count($objs) === 3 && in_array($epoch . '/' . $n1b . '.enc', $objs, true), 'a second object joins the same epoch', json_encode($objs));
check(!is_file($p1b), 'and is released');

// ─────────────────────────────────────────────────────────────────────────────
section('Manager profile enabled too: bytes wait until its held set names the object');

BackupProfile::$enabled_for_tests = array(BackupProfile::SITE, BackupProfile::MANAGER);
$bytes2 = random_bytes(700);
list($b2, $p2, $n2) = $make_blob('wait', $bytes2);
$r = $sync_row->invoke(null, $profile, (int)$b2->key, new RecordingMockDriver());
check($r === 'pushed', 'the row is pushed', (string)$r);
check((new FileBlob($b2->key, true))->get('fbb_storage_driver') === 'cloud', 'and flipped to cloud');
check(in_array($epoch . '/' . $n2 . '.enc', $shelf_objects(), true), 'the site\'s backup storage holds it');
check(is_file($p2) && file_get_contents($p2) === $bytes2, 'the local bytes STAY: the manager profile has no held set');
$held = BackupObjects::read_held($plan);
check(isset($held[$n2]), 'the site held set names it');

// The management node's run happens: its held set names the object.
$mgr_held = BackupObjects::held_path_for(BackupProfile::MANAGER, $out);
@mkdir(dirname($mgr_held), 02775, true);
file_put_contents($mgr_held, json_encode(array('version' => 1, 'objects' => array($n2 => array('epoch' => $epoch, 'object_bytes' => 1, 'object_sha256' => 'x')))));
$obj = $profile->backupObject((int)$b2->key);
check($obj !== null && $obj['name'] === $n2 && $obj['original'] === $p2 && in_array($p2, $obj['paths'], true),
	'backupObject() describes the waiting row: name, live original, paths', json_encode($obj));
$released = BackupObjects::release_waiting(array($obj), BackupProfile::enabled(), $out);
check($released === 1 && !is_file($p2), 'once both held sets name it, the release lets the bytes go');

// A row whose manager held set lacks it stays through a release pass.
list($b3, $p3, $n3) = $make_blob('still', random_bytes(64));
$sync_row->invoke(null, $profile, (int)$b3->key, new RecordingMockDriver());
$released = BackupObjects::release_waiting(array($profile->backupObject((int)$b3->key)), BackupProfile::enabled(), $out);
check($released === 0 && is_file($p3), 'a row the manager set lacks keeps its bytes through a release pass');

// ─────────────────────────────────────────────────────────────────────────────
section('A failed tick store leaves the bytes, the row cloud, and no ciphertext');

$fx_fail = s3fx_start(array('FIXTURE_FAIL_PUT' => 1));
if ($fx_fail === null) {
	harness_skip('failed tick store', 'no second fixture');
} else {
	harness_defer(function() use ($fx_fail) { s3fx_stop($fx_fail); });
	$fail_plan = $site_plan(s3fx_creds($fx_fail), $slug . '-fail');
	$fail_plan['base_dir'] = $fail_plan['output_dir'] = $work . '/failshelf';
	@mkdir($work . '/failshelf', 0700, true);
	BackupProfile::$enabled_for_tests = array(BackupProfile::SITE);
	BackupObjects::$test_hooks = array('site_plan' => $fail_plan);
	$bytes4 = random_bytes(300);
	list($b4, $p4, $n4) = $make_blob('fail', $bytes4);
	$r = $sync_row->invoke(null, $profile, (int)$b4->key, new RecordingMockDriver());
	check($r === 'pushed', 'the offload itself succeeds', (string)$r);
	check((new FileBlob($b4->key, true))->get('fbb_storage_driver') === 'cloud', 'the row is cloud');
	check(is_file($p4) && file_get_contents($p4) === $bytes4, 'the local bytes stay');
	check(s3fx_keys($fx_fail) === array(), 'nothing landed in backup storage');
	check(!glob($work . '/failshelf/objects/tmp/*'), 'no ciphertext is left behind');
	check(!is_file($work . '/failshelf/objects/epoch.json'), 'no epoch is recorded for an envelope backup storage never took');
	check(!is_file($work . '/failshelf/objects/held.json'), 'and nothing is held');
	BackupObjects::$test_hooks = array('site_plan' => $plan);
}

// ─────────────────────────────────────────────────────────────────────────────
section('The store step waits on the tick\'s row lock');

BackupProfile::$enabled_for_tests = array(BackupProfile::SITE);
list($b5, $p5, $n5) = $make_blob('lock', random_bytes(64));
$other = new PDO('pgsql:host=localhost dbname=' . $settings->get_setting('dbname', true, true), 'postgres',
	(string)$settings->get_setting('dbpassword', true, true));
$other->query('SELECT pg_advisory_lock(' . CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE . ', ' . (int)$b5->key . ')');
$obj5 = $profile->backupObject((int)$b5->key);
$r = BackupObjects::store_object($plan, BackupObjects::epoch($plan), $obj5, $p5);
check($r === null, 'store_object() steps back while another session holds the row (the tick pushing it)');
check(!in_array($epoch . '/' . $n5 . '.enc', $shelf_objects(), true), 'and uploads nothing');
$other->query('SELECT pg_advisory_unlock(' . CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE . ', ' . (int)$b5->key . ')');
$other = null;
$r = BackupObjects::store_object($plan, BackupObjects::epoch($plan), $obj5, $p5);
check(is_array($r) && in_array($epoch . '/' . $n5 . '.enc', $shelf_objects(), true), 'and stores once the lock is free');

// ─────────────────────────────────────────────────────────────────────────────
section('A blob permanently deleted while waiting leaves no local file');

// A cloud row with local bytes: refcount to zero → reclaim. Cloud offload is
// off on dev, so the bucket delete has no driver (logged as an orphan) and
// the local paths are what is left to remove.
list($b6, $p6, $n6) = $make_blob('reclaim', random_bytes(64));
$thumb_dir = $fast_dir . '/avatar';
if (!is_dir($thumb_dir)) { @mkdir($thumb_dir, 0777, true); }
$dblink->prepare("UPDATE fbb_file_blobs SET fbb_storage_driver = 'cloud', fbb_mime_type = 'image/png' WHERE fbb_file_blob_id = ?")->execute(array($b6->key));
file_put_contents($thumb_dir . '/' . $n6, 'thumb');
$temp_paths[] = $thumb_dir . '/' . $n6;
FileBlob::release((int)$b6->key);
FileBlob::flushDeferredReclaims();
check(!(new FileBlob($b6->key, true))->key, 'the row is gone');
check(!is_file($p6), 'the waiting original is gone');
check(!is_file($thumb_dir . '/' . $n6), 'and its variant');

harness_finish();

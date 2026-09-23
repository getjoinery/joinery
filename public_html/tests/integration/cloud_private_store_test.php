<?php
/** @joinery-test
 * name: cloud_private_store
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * Private store test — the one file store's safety properties
 * (specs/implemented/cloud_storage_private_only.md).
 *
 *  - The privacy gate verdict: an anonymous 2xx ⇒ FAIL (bucket public); any
 *    denied/unreachable status ⇒ PASS.
 *  - driver() is null until the bucket is configured AND the latch is on;
 *    driverUnlatched() needs the whole binding; driverWithFallback() answers
 *    while the store is paused.
 *  - The registry refuses a profile that is not private and keeps one that is.
 *  - Save refuses a bucket an anonymous read can see, and passes a private one,
 *    over the loopback S3 fixture (FIXTURE_ANON_READ).
 *
 * Settings are toggled only in the Globalvars in-memory cache (this process).
 *
 * Run: php tests/integration/cloud_private_store_test.php
 *
 * @version 2.1 - the latch-off case uses 0, and the empty-bucket checks skip on a box with a bucket stored
 * @version 2.0 - one store: the factory's single binding, the registry's refusal, the Save gate over the fixture
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/s3_fixtures.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageLifecycle.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/StorageProfileRegistry.php'));
require_once(PathHelper::getIncludePath('includes/BucketCheck.php'));

section('Privacy gate verdict (the privacy-critical decision)');
ok('anonymous 200 ⇒ gate FAILS (bucket public)', CloudStorageLifecycle::privacyVerdict(200)['pass'] === false);
ok('anonymous 204 ⇒ gate FAILS', CloudStorageLifecycle::privacyVerdict(204)['pass'] === false);
ok('anonymous 403 ⇒ gate PASSES (denied)', CloudStorageLifecycle::privacyVerdict(403)['pass'] === true);
ok('anonymous 401 ⇒ gate PASSES', CloudStorageLifecycle::privacyVerdict(401)['pass'] === true);
ok('anonymous 404 ⇒ gate PASSES', CloudStorageLifecycle::privacyVerdict(404)['pass'] === true);
ok('connection refused (0) ⇒ gate PASSES', CloudStorageLifecycle::privacyVerdict(0)['pass'] === true);
ok('a failed verdict says to make the bucket private and save again', strpos(CloudStorageLifecycle::privacyVerdict(200)['message'], 'Make it private at the provider and save again') !== false);

section('driver() is null until configured AND enabled');

$bind = function ($endpoint, $bucket, $key, $secret) {
	harness_set_setting_mem('cloud_storage_endpoint', $endpoint);
	harness_set_setting_mem('cloud_storage_region', 'r');
	harness_set_setting_mem('cloud_storage_bucket', $bucket);
	harness_set_setting_mem('cloud_storage_access_key', $key);
	harness_set_setting_mem('cloud_storage_secret_key', $secret);
	CloudStorageDriverFactory::reset();
};

// Latch off ⇒ null regardless of bucket. ('0', not blank: a blank in memory
// reads the stored row, and this box may have the store enabled.)
harness_set_setting_mem('cloud_storage_enabled', '0');
$bind('s3.example.com', 'some-bucket', 'k', 's');
ok('latch off ⇒ driver null', CloudStorageDriverFactory::driver() === null);
ok('latch off ⇒ unlatched driver still built from the binding', CloudStorageDriverFactory::driverUnlatched() !== null);
ok('latch off ⇒ with-fallback answers (a paused store still serves)', CloudStorageDriverFactory::driverWithFallback() !== null);

// Latch on but no bucket ⇒ null. A blank bucket cannot be forced in memory
// on a box that has one stored, so the three checks skip there.
harness_set_setting_mem('cloud_storage_enabled', '1');
if (harness_stored_setting_is_blank('cloud_storage_bucket')) {
	$bind('s3.example.com', '', 'k', 's');
	ok('latch on + empty bucket ⇒ driver null', CloudStorageDriverFactory::driver() === null);
	ok('empty bucket ⇒ unlatched driver null', CloudStorageDriverFactory::driverUnlatched() === null);
	ok('empty bucket ⇒ with-fallback null (the store is unconfigured)', CloudStorageDriverFactory::driverWithFallback() === null);
} else {
	foreach (array('latch on + empty bucket ⇒ driver null', 'empty bucket ⇒ unlatched driver null', 'empty bucket ⇒ with-fallback null (the store is unconfigured)') as $label) {
		harness_skip($label, 'this box has a bucket configured and a blank cannot be forced in memory');
	}
}

// Latch on, whole binding ⇒ a driver.
$bind('s3.example.com', 'some-bucket', 'k', 's');
ok('latch on + whole binding ⇒ a driver', CloudStorageDriverFactory::driver() !== null);
$b = CloudStorageDriverFactory::binding();
ok('binding() is endpoint, region, bucket, access key, secret key and nothing else',
	array_keys($b) === array('endpoint', 'region', 'bucket', 'access_key', 'secret_key'));

section('mode() from the latch and the drain flag');
harness_set_setting_mem('cloud_storage_enabled', '1');
harness_set_setting_mem('cloud_storage_draining', '1');
ok('enabled ⇒ offload (precedence over draining)', CloudStorageLifecycle::mode() === 'offload');
harness_set_setting_mem('cloud_storage_enabled', '0');
ok('disabled + draining ⇒ drain', CloudStorageLifecycle::mode() === 'drain');
harness_set_setting_mem('cloud_storage_draining', '0');
ok('disabled + not draining ⇒ idle', CloudStorageLifecycle::mode() === 'idle');

section('The registry refuses a profile that is not private');

$src = function ($cls, $visibility) {
	return "<?php\nclass $cls implements StorageProfile {\n"
		. "  public function table(): string { return 'nowhere'; }\n"
		. "  public function pkeyColumn(): string { return 'id'; }\n"
		. "  public function driverColumn(): string { return 'drv'; }\n"
		. "  public function failedCountColumn(): string { return 'failed'; }\n"
		. "  public function lastAttemptColumn(): string { return 'last_attempt'; }\n"
		. "  public function lastErrorColumn(): string { return 'last_error'; }\n"
		. "  public function visibility(): string { return '$visibility'; }\n"
		. "  public function eligibilityWhere(): string { return ''; }\n"
		. "  public function rowExists(int \$id): bool { return false; }\n"
		. "  public function isEligibleRow(int \$id): bool { return false; }\n"
		. "  public function itemsForRow(int \$id): ?array { return null; }\n"
		. "  public function reverseItemsForRow(int \$id): array { return []; }\n}\n";
};
$tmp = sys_get_temp_dir() . '/cloud_private_store_' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
harness_defer(function () use ($tmp) { foreach (glob($tmp . '/*') as $f) { @unlink($f); } @rmdir($tmp); });
$pub_cls  = 'TmpPublicProfile_' . bin2hex(random_bytes(3));
$priv_cls = 'TmpPrivateProfile_' . bin2hex(random_bytes(3));
file_put_contents($tmp . '/' . $pub_cls . '.php', $src($pub_cls, 'public'));
file_put_contents($tmp . '/' . $priv_cls . '.php', $src($priv_cls, 'private'));

StorageProfileRegistry::reset();
$declared = array_map('get_class', StorageProfileRegistry::all());
ok('every declared profile answers private', !in_array(false, array_map(function ($p) { return $p->visibility() === 'private'; }, StorageProfileRegistry::all()), true));
ok('BlobStorageProfile is declared', in_array('BlobStorageProfile', $declared, true));

$register = new ReflectionMethod('StorageProfileRegistry', '_load_and_register');
$register->setAccessible(true);
$register->invoke(null, $pub_cls, $tmp . '/' . $pub_cls . '.php');
$register->invoke(null, $priv_cls, $tmp . '/' . $priv_cls . '.php');
$now = array_map('get_class', StorageProfileRegistry::all());
ok('a profile answering public is refused: not in all()', !in_array($pub_cls, $now, true));
ok('a profile answering private is kept', in_array($priv_cls, $now, true));
StorageProfileRegistry::reset();

section('Save refuses a bucket an anonymous read can see (the loopback fixture)');

$labels = function (array $steps) { $out = array(); foreach ($steps as $s) { $out[] = $s['label']; } return $out; };
$step = function (array $steps, $label) { foreach ($steps as $s) { if ($s['label'] === $label) { return $s; } } return null; };
$no_targets = function () { return array(); };

$fx_pub = s3fx_start(array('FIXTURE_ANON_READ' => 1));
if ($fx_pub === null) {
	harness_skip('a public bucket is refused', 'no loopback S3 fixture could start');
} else {
	harness_defer(function () use ($fx_pub) { s3fx_stop($fx_pub); });
	BucketCheck::$test_hooks = array('backup_target_buckets' => $no_targets);
	$r = CloudStorageLifecycle::testConnection(s3fx_creds($fx_pub) + array('bucket' => 'files'));
	ok('the Save check fails', $r['ok'] === false);
	ok('the steps are own bucket, reach, write, private, delete', $labels($r['steps']) === array('Its own bucket', 'Reach', 'Write', 'Private', 'Delete'), json_encode($labels($r['steps'])));
	$p = $step($r['steps'], 'Private');
	ok('the private step failed and says the bucket is publicly readable', $p && $p['status'] === 'fail' && strpos($p['message'], 'publicly readable') !== false, json_encode($p));
	ok('reach and write passed before it', $step($r['steps'], 'Reach')['status'] === 'pass' && $step($r['steps'], 'Write')['status'] === 'pass');
	ok('the probe was still deleted', $step($r['steps'], 'Delete')['status'] === 'pass' && s3fx_keys($fx_pub) === array(), json_encode(s3fx_keys($fx_pub)));
	BucketCheck::$test_hooks = array();
}

section('Save passes a private bucket');

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('a private bucket passes', 'no loopback S3 fixture could start');
} else {
	harness_defer(function () use ($fx) { s3fx_stop($fx); });
	BucketCheck::$test_hooks = array('backup_target_buckets' => $no_targets);
	$r = CloudStorageLifecycle::testConnection(s3fx_creds($fx) + array('bucket' => 'files'));
	ok('the Save check passes', $r['ok'] === true, json_encode($r['steps']));
	$p = $step($r['steps'], 'Private');
	ok('the private step passed: nobody can read the bucket without a key', $p && $p['status'] === 'pass' && strpos($p['message'], 'Nobody can read this bucket without a key') !== false, json_encode($p));
	ok('nothing is left in the bucket', s3fx_keys($fx) === array());
	ok('exactly one anonymous read was tried, and refused', s3fx_count($fx, 'anonymous') === 1);
	BucketCheck::$test_hooks = array();
}

harness_finish();

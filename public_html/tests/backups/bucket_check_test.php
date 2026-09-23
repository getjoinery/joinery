<?php
/** @joinery-test
 * name: bucket_check
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The bucket and key check both bucket forms run before they save
 * (specs/implemented/storage_bucket_and_key_check.md), over the loopback S3 fixture and
 * BucketCheck::$test_hooks:
 *
 *   - same_bucket(): a name match on the same host, or with a host unknown
 *   - a backup target is refused when its bucket is the file store's, before
 *     the network is touched; the file store is refused a backup target's
 *   - a target that can list, write, is private and can prune passes and
 *     leaves nothing behind
 *   - a bucket anyone can read is refused; a main key that cannot delete is
 *     refused; a node key that can delete is refused; one that cannot passes
 *   - a Backblaze key pinned to another bucket is refused, one that opens the
 *     whole account warns and names the other side's bucket, one missing a
 *     capability is refused naming it, minting per run asks for the key
 *     capabilities, a refused authorize is a fail
 *   - the cloud storage test starts with the same two questions
 *
 * Run: php tests/backups/bucket_check_test.php
 *
 * @version 1.2 - the no-bucket check skips on a box with a bucket stored (a blank cannot be forced in memory)
 * @version 1.1 - one file store bucket, labelled "the file store"; the cloud storage check's steps
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/s3_fixtures.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BucketCheck.php'));
require_once(PathHelper::getIncludePath('includes/TargetTester.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageLifecycle.php'));

$labels = function (array $steps, $status = null) {
	$out = array();
	foreach ($steps as $s) { if ($status === null || $s['status'] === $status) { $out[] = $s['label']; } }
	return $out;
};
$step = function (array $steps, $label) {
	foreach ($steps as $s) { if ($s['label'] === $label) { return $s; } }
	return null;
};
$make_target = function (array $creds, array $over = array()) {
	$t = new BackupTarget(NULL);
	$t->set('bkt_name', 'check');
	$t->set('bkt_provider', 's3');
	$t->set('bkt_bucket', 'bk');
	$t->set('bkt_path_prefix', 'joinery-backups');
	$t->set('bkt_credentials', $creds);
	$t->set('bkt_enabled', true);
	foreach ($over as $k => $v) { $t->set($k, $v); }
	return $t;
};
$no_store = function () { return array(); };

// ── same_bucket ─────────────────────────────────────────────────────
section('Two bindings name the same bucket by name and host');

check(BucketCheck::same_bucket('Photos', 'https://s3.us-east-005.backblazeb2.com', 'photos', 'https://s3.us-east-005.backblazeb2.com'), 'same name, same host, any case');
check(!BucketCheck::same_bucket('photos', 'https://s3.us-east-005.backblazeb2.com', 'photos', 'https://s3.eu-west-1.amazonaws.com'), 'same name on another provider is another bucket');
check(BucketCheck::same_bucket('photos', '', 'photos', 'https://s3.us-east-005.backblazeb2.com'), 'a host nobody knows is taken as the same');
check(!BucketCheck::same_bucket('photos', 'x', 'backups', 'x'), 'different names differ');
check(!BucketCheck::same_bucket('', 'x', '', 'x'), 'two empty names are not a match');
check(BucketCheck::host('s3.us-east-005.backblazeb2.com') === 's3.us-east-005.backblazeb2.com' && BucketCheck::host('') === '', 'host() takes a bare host and an empty one');
check(BucketCheck::is_b2('https://s3.us-east-005.backblazeb2.com') && !BucketCheck::is_b2('https://s3.eu-west-1.amazonaws.com'), 'is_b2() by host');

// ── collision, both directions ──────────────────────────────────────
section('Its own bucket: the two sides refuse each other');

$others = array(array('bucket' => 'shared', 'endpoint' => 'https://s3.example', 'label' => 'the file store'));
$s = BucketCheck::collision_step('shared', 'https://s3.example', $others, 'backups');
check($s['status'] === 'fail' && strpos($s['message'], 'already the file store') !== false && strpos($s['message'], 'private bucket for backups') !== false,
	'a backup target named after the file store bucket fails and says what to do', $s['message']);
$s = BucketCheck::collision_step('other', 'https://s3.example', $others, 'backups');
check($s['status'] === 'pass', 'another bucket passes');
$others = array(array('bucket' => 'bk', 'endpoint' => '', 'label' => 'the backup target "Nightly"'));
$s = BucketCheck::collision_step('bk', 'https://s3.example', $others, 'files');
check($s['status'] === 'fail' && strpos($s['message'], 'already the backup target "Nightly"') !== false && strpos($s['message'], 'private bucket for files') !== false,
	'a file store named after a backup bucket fails the other way round', $s['message']);

section('The file store bucket is the one bucket, from the settings');
harness_set_setting_mem('cloud_storage_endpoint', 'https://s3.example');
if (harness_stored_setting_is_blank('cloud_storage_bucket')) {
	harness_set_setting_mem('cloud_storage_bucket', '');
	check(BucketCheck::file_store_buckets() === array(), 'no bucket set: none');
} else {
	harness_skip('no bucket set: none', 'this box has a bucket configured and a blank cannot be forced in memory');
}
harness_set_setting_mem('cloud_storage_bucket', 'files');
check(BucketCheck::file_store_buckets() === array(array('bucket' => 'files', 'endpoint' => 'https://s3.example', 'label' => 'the file store')),
	'the one bucket, labelled the file store', json_encode(BucketCheck::file_store_buckets()));

// ── the fixture ─────────────────────────────────────────────────────
$fx = s3fx_start();
if ($fx === null) {
	harness_skip('bucket check over the fixture', 'no loopback S3 fixture could start');
} else {
	harness_defer(function () use ($fx) { s3fx_stop($fx); });
	$creds = s3fx_creds($fx);

	section('A target that can list, write, is private and can prune passes and leaves nothing behind');
	BucketCheck::$test_hooks = array('file_store_buckets' => $no_store);
	$r = TargetTester::test($make_target($creds));
	check($r['success'] === true, 'the target passes', $r['message']);
	check($labels($r['steps'], 'pass') === array('Its own bucket', 'Reach', 'Write', 'Private', 'Prune'), 'five steps, all passed', json_encode($labels($r['steps'])));
	check(strpos($r['message'], 'bucket "bk" is accessible') !== false, 'the pass line names the bucket', $r['message']);
	check(s3fx_keys($fx) === array(), 'the probe is gone', json_encode(s3fx_keys($fx)));
	check(s3fx_count($fx, 'anonymous') === 1, 'exactly one anonymous read was tried');

	section('The file store bucket is refused before the network is touched');
	$lists = s3fx_count($fx, 'list');
	BucketCheck::$test_hooks = array('file_store_buckets' => function () use ($creds) {
		return array(array('bucket' => 'bk', 'endpoint' => $creds['endpoint'], 'label' => 'the file store'));
	});
	$r = TargetTester::test($make_target($creds));
	check($r['success'] === false && strpos($r['message'], 'already the file store') !== false, 'refused, naming the file store', $r['message']);
	check($labels($r['steps']) === array('Its own bucket'), 'nothing after the first step ran', json_encode($labels($r['steps'])));
	check(s3fx_count($fx, 'list') === $lists, 'no list call was made');

	section('No bucket, no credentials');
	BucketCheck::$test_hooks = array('file_store_buckets' => $no_store);
	$r = TargetTester::test($make_target($creds, array('bkt_bucket' => '')));
	check($r['success'] === false && $r['message'] === 'No bucket configured.', 'an empty bucket is refused', $r['message']);
	$r = TargetTester::test($make_target(array('access_key' => '', 'secret_key' => '', 'region' => 'r', 'endpoint' => $creds['endpoint'])));
	check($r['success'] === false && $r['message'] === 'No credentials configured.', 'empty credentials are refused', $r['message']);

	section('The cloud storage test starts with the same questions');
	BucketCheck::$test_hooks = array('backup_target_buckets' => function () use ($creds) {
		return array(array('bucket' => 'files', 'endpoint' => $creds['endpoint'], 'label' => 'the backup target "Nightly"'));
	});
	$r = CloudStorageLifecycle::testConnection(array('endpoint' => $creds['endpoint'], 'region' => 'r', 'bucket' => 'files',
		'access_key' => 'k', 'secret_key' => 's'));
	check($r['ok'] === false && $r['steps'][0]['label'] === 'Its own bucket' && $r['steps'][0]['status'] === 'fail'
		&& strpos($r['steps'][0]['message'], 'the backup target "Nightly"') !== false, 'the file store is refused a backup bucket', json_encode($r['steps'][0]));
	check($labels($r['steps']) === array('Its own bucket', 'Reach', 'Write', 'Private', 'Delete') && $labels($r['steps'], 'skip') === array('Reach', 'Write', 'Private', 'Delete'),
		'the network steps are skipped', json_encode($labels($r['steps'])));
	BucketCheck::$test_hooks = array('backup_target_buckets' => $no_store, 'is_b2' => true,
		'b2_allowed' => function () { return array('capabilities' => array('listFiles', 'readFiles', 'writeFiles'), 'bucketName' => 'files'); });
	$r = CloudStorageLifecycle::testConnection(array('endpoint' => $creds['endpoint'], 'region' => 'r', 'bucket' => 'files',
		'access_key' => 'k', 'secret_key' => 's'));
	check($r['ok'] === false && $step($r['steps'], 'File store key capabilities')['status'] === 'fail'
		&& strpos($step($r['steps'], 'File store key capabilities')['message'], 'cannot deleteFiles') !== false,
		'a file store key that cannot delete is refused before the network', json_encode($r['steps']));
	check($step($r['steps'], 'File store key reach')['status'] === 'pass', 'a key pinned to this bucket passes reach');
	BucketCheck::$test_hooks = array();
}

// ── a public bucket ─────────────────────────────────────────────────
$fx_pub = s3fx_start(array('FIXTURE_ANON_READ' => 1));
if ($fx_pub === null) {
	harness_skip('public bucket', 'no loopback S3 fixture could start');
} else {
	harness_defer(function () use ($fx_pub) { s3fx_stop($fx_pub); });
	section('A bucket anyone can read is refused');
	BucketCheck::$test_hooks = array('file_store_buckets' => $no_store);
	$r = TargetTester::test($make_target(s3fx_creds($fx_pub)));
	check($r['success'] === false && strpos($r['message'], 'Anyone can read this bucket without a key') !== false, 'refused as public', $r['message']);
	check($step($r['steps'], 'Private')['status'] === 'fail' && $step($r['steps'], 'Prune')['status'] === 'pass', 'the private step failed; prune still ran and passed');
	check(s3fx_keys($fx_pub) === array(), 'the probe is still cleaned up');
	BucketCheck::$test_hooks = array();
}

// ── keys that cannot delete ─────────────────────────────────────────
$fx_wo = s3fx_start(array('FIXTURE_WRITE_ONLY_KEY' => 1));
if ($fx_wo === null) {
	harness_skip('write-only keys', 'no loopback S3 fixture could start');
} else {
	harness_defer(function () use ($fx_wo) { s3fx_stop($fx_wo); });
	$creds = s3fx_creds($fx_wo);
	$wo = array_merge($creds, array('access_key' => 'wo-node'));
	BucketCheck::$test_hooks = array('file_store_buckets' => $no_store);

	section('A main key that cannot delete is refused');
	$r = TargetTester::test($make_target(array_merge($creds, array('access_key' => 'wo-main'))));
	check($r['success'] === false && strpos($r['message'], 'The main key cannot delete') !== false && strpos($r['message'], 'bucket would only grow') !== false,
		'refused, saying retention could never prune', $r['message']);

	check(count(s3fx_keys($fx_wo)) === 1, 'a main key that cannot delete leaves its probe behind: the one thing the check cannot undo');

	section('A node key that can write and cannot delete passes; one that can delete is refused');
	$before = s3fx_keys($fx_wo);
	$r = TargetTester::test($make_target($creds, array('bkt_node_credentials' => $wo)));
	check($r['success'] === true && $step($r['steps'], 'Node key')['status'] === 'pass', 'the write-only node key passes', $r['message']);
	check(s3fx_keys($fx_wo) === $before, 'the node probe was removed by the main key', json_encode(s3fx_keys($fx_wo)));
	$r = TargetTester::test($make_target($creds, array('bkt_node_credentials' => array_merge($creds, array('access_key' => 'TESTKEY2')))));
	check($r['success'] === false && strpos($r['message'], 'The node key can delete objects') !== false, 'a node key that deletes is refused', $r['message']);
	check(s3fx_keys($fx_wo) === $before, 'its probe was deleted in the proving');
	BucketCheck::$test_hooks = array();
}

// ── what Backblaze says a key may do ────────────────────────────────
section('A Backblaze key: pinned elsewhere fails, account-wide warns, a missing capability fails');

$others = array(array('bucket' => 'photos', 'endpoint' => 'https://s3.us-east-005.backblazeb2.com', 'label' => 'the file store'));
$all = BucketCheck::B2_BACKUP_CAPABILITIES;
BucketCheck::$test_hooks = array('b2_allowed' => function () use ($all) { return array('capabilities' => $all, 'bucketName' => 'elsewhere'); });
$steps = BucketCheck::b2_key_steps(array('access_key' => 'k', 'secret_key' => 's'), 'bk', $all, 'main key', $others);
check($steps[0]['status'] === 'fail' && strpos($steps[0]['message'], 'made for the bucket "elsewhere", not "bk"') !== false, 'pinned to another bucket fails', $steps[0]['message']);

BucketCheck::$test_hooks = array('b2_allowed' => function () use ($all) { return array('capabilities' => $all, 'bucketName' => ''); });
$steps = BucketCheck::b2_key_steps(array('access_key' => 'k', 'secret_key' => 's'), 'bk', $all, 'main key', $others);
check($steps[0]['status'] === 'warn' && strpos($steps[0]['message'], 'opens every bucket on the account, including photos (the file store)') !== false,
	'an account-wide key warns and names the file store bucket it also opens', $steps[0]['message']);
check($steps[1]['status'] === 'pass', 'with every capability, capabilities pass');

BucketCheck::$test_hooks = array('b2_allowed' => function () { return array('capabilities' => array('listFiles', 'readFiles', 'writeFiles'), 'bucketName' => 'bk', 'namePrefix' => 'joinery-backups/'); });
$steps = BucketCheck::b2_key_steps(array('access_key' => 'k', 'secret_key' => 's'), 'bk', $all, 'main key', $others);
check($steps[0]['status'] === 'pass' && strpos($steps[0]['message'], 'names under "joinery-backups/"') !== false, 'pinned to this bucket passes and shows the prefix', $steps[0]['message']);
check($steps[1]['status'] === 'fail' && strpos($steps[1]['message'], 'cannot deleteFiles') !== false && strpos($steps[1]['message'], 'Retention could never prune') !== false,
	'a missing deleteFiles is named with what it would break', $steps[1]['message']);

$steps = BucketCheck::b2_key_steps(array('access_key' => 'k', 'secret_key' => 's'), 'bk', array_merge($all, BucketCheck::B2_MINT_CAPABILITIES), 'main key', $others);
check(strpos($steps[1]['message'], 'writeKeys, listKeys, deleteKeys') !== false && strpos($steps[1]['message'], 'no per-run key could be minted') !== false,
	'minting per run asks for the key capabilities and says every run would fail', $steps[1]['message']);

BucketCheck::$test_hooks = array('b2_allowed' => function () { throw new Exception('B2 authorize failed (401): bad key'); });
$steps = BucketCheck::b2_key_steps(array('access_key' => 'k', 'secret_key' => 's'), 'bk', $all, 'node key', $others);
check(count($steps) === 1 && $steps[0]['status'] === 'fail' && strpos($steps[0]['message'], 'Backblaze refused the node key') !== false, 'a refused authorize is a fail naming the key', $steps[0]['message']);

section('The tester runs the Backblaze steps for a b2 target, with the minting ones when minting is on');
if ($fx !== null) {
	$creds = s3fx_creds($fx);
	$seen = array();
	BucketCheck::$test_hooks = array('file_store_buckets' => $no_store, 'b2_allowed' => function ($id) use (&$seen, $all) {
		$seen[] = $id;
		return array('capabilities' => $all, 'bucketName' => 'bk');
	});
	$r = TargetTester::test($make_target($creds, array('bkt_provider' => 'b2', 'bkt_mint_run_keys' => true)));
	check($r['success'] === false && strpos($r['message'], 'cannot writeKeys, listKeys, deleteKeys') !== false, 'a b2 target with minting on needs the key capabilities', $r['message']);
	check($seen === array('TESTKEY'), 'the main key was asked once', json_encode($seen));
	$r = TargetTester::test($make_target($creds, array('bkt_provider' => 'b2')));
	check($r['success'] === true && $labels($r['steps']) === array('Its own bucket', 'Reach', 'Write', 'Private', 'Prune', 'Main key reach', 'Main key capabilities'),
		'without minting the four capabilities suffice', json_encode($labels($r['steps'])));
	BucketCheck::$test_hooks = array();
} else {
	harness_skip('b2 target steps', 'no loopback S3 fixture could start');
}

// ── what Backblaze actually answers ─────────────────────────────────
section('B2Client::authorize() keeps what the key is allowed to do, in the v3 and the v2 shape');

$b2_answer = function (array $body) {
	$mock = new GuzzleHttp\Handler\MockHandler(array(new GuzzleHttp\Psr7\Response(200, array('Content-Type' => 'application/json'), json_encode($body))));
	$http = new GuzzleHttp\Client(array('handler' => GuzzleHttp\HandlerStack::create($mock)));
	return (new B2Client('id', 'key', $http))->authorize();
};
$v3 = $b2_answer(array('accountId' => 'a', 'authorizationToken' => 't', 'apiInfo' => array('storageApi' => array(
	'apiUrl' => 'https://api005.backblazeb2.com', 's3ApiUrl' => 'https://s3.us-east-005.backblazeb2.com',
	'bucketId' => 'bid', 'bucketName' => 'joinery-backups-354', 'namePrefix' => null,
	'capabilities' => array('listFiles', 'readFiles', 'writeFiles', 'deleteFiles')))));
check($v3['allowed'] === array('capabilities' => array('listFiles', 'readFiles', 'writeFiles', 'deleteFiles'), 'bucketId' => 'bid', 'bucketName' => 'joinery-backups-354', 'namePrefix' => ''),
	'v3: capabilities and the pinned bucket read from apiInfo.storageApi', json_encode($v3['allowed']));
$v2 = $b2_answer(array('accountId' => 'a', 'authorizationToken' => 't', 'apiInfo' => array('storageApi' => array('apiUrl' => 'https://api005.backblazeb2.com')),
	'allowed' => array('capabilities' => array('writeFiles'), 'bucketId' => null, 'bucketName' => null, 'namePrefix' => null)));
check($v2['allowed'] === array('capabilities' => array('writeFiles'), 'bucketId' => '', 'bucketName' => '', 'namePrefix' => ''),
	'v2: read from allowed; an account-wide key has an empty bucket name', json_encode($v2['allowed']));
BucketCheck::$test_hooks = array();
harness_finish();

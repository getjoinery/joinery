<?php
/** @joinery-test
 * name: linode_target
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A Linode Object Storage backup target, checked the same way the other providers are:
 *
 *   - BackupTarget::credential_problem(): the Linode endpoint rules both save forms run
 *     before anything is asked of Linode. A region and a bare https cluster host pass;
 *     a missing region or endpoint, a bucket in the host, a path, http, a host outside
 *     linodeobjects.com are each refused with a sentence. Other providers pass untouched.
 *   - Both save paths call credential_problem() (a source check, so a later edit cannot
 *     quietly drop the guard from one of them).
 *   - A Linode-provider target runs the whole connection test (reach, write, private,
 *     prune) against the loopback S3 fixture, which signs path-style as Linode needs.
 *
 * Run: php tests/backups/linode_target_test.php
 *
 * @version 1.2 - no per-run key minting to check (specs/storage_targets.md WP5)
 * @version 1.1 - a bare cluster host signs as https in both signers
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/s3_fixtures.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BucketCheck.php'));
require_once(PathHelper::getIncludePath('includes/TargetTester.php'));

$step = function (array $steps, $label) {
	foreach ($steps as $s) { if ($s['label'] === $label) { return $s; } }
	return null;
};

// ── the endpoint rules ──────────────────────────────────────────────
section('A Linode endpoint is the cluster host alone, over https, with a region');

$ok = array(
	array('us-east-1', 'us-east-1.linodeobjects.com'),
	array('us-east-1', 'https://us-east-1.linodeobjects.com'),
	array('us-east-1', 'https://us-east-1.linodeobjects.com/'),
	array('us-east-1', 'US-EAST-1.LinodeObjects.com'),
	array('us-iad-1', 'us-iad-1.linodeobjects.com'),
);
foreach ($ok as $case) {
	$problem = BackupTarget::credential_problem('linode', array('region' => $case[0], 'endpoint' => $case[1]));
	check($problem === '', 'accepted: ' . $case[1], $problem);
}

$refused = array(
	'no region'              => array('', 'us-east-1.linodeobjects.com', 'region'),
	'no endpoint'            => array('us-east-1', '', 'endpoint'),
	'bucket in the host'     => array('us-east-1', 'mybucket.us-east-1.linodeobjects.com', 'bucket name'),
	'a path after the host'  => array('us-east-1', 'us-east-1.linodeobjects.com/mybucket', 'bucket name'),
	'plain http'             => array('us-east-1', 'http://us-east-1.linodeobjects.com', 'https'),
	'an Amazon host'         => array('us-east-1', 's3.us-east-1.amazonaws.com', 'linodeobjects'),
	'no cluster'             => array('us-east-1', 'linodeobjects.com', 'linodeobjects'),
	'a lookalike domain'     => array('us-east-1', 'us-east-1.linodeobjects.com.example.net', 'linodeobjects'),
);
foreach ($refused as $label => $case) {
	$problem = BackupTarget::credential_problem('linode', array('region' => $case[0], 'endpoint' => $case[1]));
	check($problem !== '' && stripos($problem, $case[2]) !== false, 'refused: ' . $label, $problem);
}

section('Every provider: a region is a short name, and one that asks for it needs it');
check(BackupTarget::credential_problem('s3', array('region' => 'us-east-1', 'endpoint' => '')) === '', 'an S3 target with a region passes; its endpoint comes from the catalogue');
check(BackupTarget::credential_problem('s3', array('region' => '', 'endpoint' => '')) !== '', 'an S3 target with no region is refused');
check(stripos(BackupTarget::credential_problem('wasabi', array('region' => 's3.us-east-1.wasabisys.com')), 'short name') !== false, 'an address typed as the region is refused');
check(BackupTarget::credential_problem('b2', array()) === '', 'a B2 target passes: Backblaze names its own region');
check(BackupTarget::credential_problem('generic', array('region' => '', 'endpoint' => 'minio.example.com')) === '', 'a generic target may leave the region for the test to judge');

// ── both save paths run the check ───────────────────────────────────
section('Every target form saves through the one path, which runs the check');

$form = (string)file_get_contents(__DIR__ . '/../../includes/BackupTargetForm.php');
check(strpos($form, 'BackupTarget::credential_problem(') !== false, 'the shared save runs the check');
foreach (array('adm/logic/admin_backups_logic.php' => 'the core Backups save', 'plugins/server_manager/views/admin/targets.php' => 'the server_manager Backup Targets save') as $rel => $label) {
	$src = (string)file_get_contents(__DIR__ . '/../../' . $rel);
	check(strpos($src, 'BackupTargetForm::save(') !== false, $label . ' is the shared save');
}

// ── the endpoint the form accepts is one the signers can use ────────
section('A bare cluster host signs as https');

$bare = array('access_key' => 'k', 'secret_key' => 's', 'region' => 'us-east-1', 'endpoint' => 'us-east-1.linodeobjects.com');
try {
	$url = S3Signer::presign_get($bare, 'lin', '/joinery-backups/x.enc');
	check(strpos($url, 'https://us-east-1.linodeobjects.com/lin/joinery-backups/x.enc?') === 0, 'S3Signer signs a bare host', $url);
} catch (Exception $e) {
	check(false, 'S3Signer signs a bare host', $e->getMessage());
}
try {
	$url = S3Signer::presign($bare, 'lin', 'joinery-backups/t1/x.enc', 'PUT');
	check(strpos($url, 'https://us-east-1.linodeobjects.com/lin/joinery-backups/t1/x.enc?') === 0, 'the backup storage presigner signs a bare host', $url);
} catch (Exception $e) {
	check(false, 'the backup storage presigner signs a bare host', $e->getMessage());
}
check(S3Signer::endpoint('http://127.0.0.1:9000') === array('http', '127.0.0.1:9000'), 'an explicit scheme and port are kept');
check(BucketCheck::object_url($bare, 'lin', 'p/x.txt') === 'https://us-east-1.linodeobjects.com/lin/p/x.txt',
	'the privacy probe reads a bare host over https', BucketCheck::object_url($bare, 'lin', 'p/x.txt'));
BucketCheck::$test_hooks = array('anonymous_status' => function () { return 0; });
check(BucketCheck::private_read_step('https://x')['status'] === 'warn', 'a probe with no answer is a warning, not a pass');
BucketCheck::$test_hooks = array();
try {
	S3Signer::endpoint('');
	check(false, 'an empty endpoint is refused');
} catch (S3SignerException $e) {
	check(true, 'an empty endpoint is refused');
}

// ── a Linode target saves ───────────────────────────────────────────
section('A Linode target passes the model\'s own checks');

$t = new BackupTarget(NULL);
$t->set('bkt_name', 'linode');
$t->set('bkt_provider', 'linode');
$t->set('bkt_bucket', 'lin');
$t->set('bkt_credentials', array('access_key' => 'k', 'secret_key' => 's', 'region' => 'us-east-1', 'endpoint' => 'us-east-1.linodeobjects.com'));
try {
	$t->prepare();
	check(true, 'a Linode provider passes prepare()');
} catch (Exception $e) {
	check(false, 'a Linode provider passes prepare()', $e->getMessage());
}

// ── the connection test, against the loopback fixture ───────────────
section('A Linode target passes the connection test');

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('connection test', 'no loopback S3 fixture could start');
} else {
	harness_defer(function () use ($fx) { s3fx_stop($fx); });
	BucketCheck::$test_hooks = array('file_store_buckets' => function () { return array(); });

	$creds = s3fx_creds($fx);
	$t = new BackupTarget(NULL);
	$t->set('bkt_name', 'Linode nightly');
	$t->set('bkt_provider', 'linode');
	$t->set('bkt_bucket', 'lin');
	$t->set('bkt_path_prefix', 'joinery-backups');
	$t->set('bkt_credentials', $creds);
	$t->set('bkt_enabled', true);

	$r = TargetTester::test($t);
	check($r['success'] === true, 'the connection test passes', $r['message']);
	check($step($r['steps'], 'Reach') !== null && $step($r['steps'], 'Reach')['status'] === 'pass', 'reach passes');
	check($step($r['steps'], 'Write') !== null && $step($r['steps'], 'Write')['status'] === 'pass', 'write passes');
	check($step($r['steps'], 'Private') !== null && $step($r['steps'], 'Private')['status'] === 'pass', 'the bucket is private');
	check($step($r['steps'], 'Prune') !== null && $step($r['steps'], 'Prune')['status'] === 'pass', 'the main key can prune');
	check(s3fx_keys($fx) === array(), 'the probe is cleaned up', json_encode(s3fx_keys($fx)));

	BucketCheck::$test_hooks = array();
}

harness_finish();

<?php
/** @joinery-test
 * name: shelf_broker
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * The shelf broker (specs/services_phase2_platform.md §3, §7 — build item 2a):
 * the plane signs what a tenant may do and keeps the ledger of what it signed.
 *
 *   - The presigner signs GET exactly as S3Signer::presign_get does, signs PUT
 *     and the three multipart calls, and refuses to sign a DELETE.
 *   - The broker refuses, each asserted: a tenant with no date, a key outside
 *     the run's base key, any delete, a run over the allowance (with the
 *     sentence the run records), a lapsed or released tenant, a spent or
 *     unknown run id, a run from another tenant. And signs: put, get, the
 *     three multipart calls, list inside the prefix.
 *   - The URLs work: performed with curl against the local S3 fixture, a put
 *     lands the bytes, a multipart upload assembles them, a get reads them
 *     back, and the plane's own abort cancels an unfinished upload.
 *   - The ledger is the figure: completed rows sum; a row signed but never
 *     completed is dropped by the abort, not counted.
 *   - The five actions run over the connected key.
 *
 * Rows are written to the test database and deleted; the fixture is a php -S
 * on a loopback port. Nothing leaves the machine.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/shelf_broker_test.php
 *
 * @version 1.2 - a taken-over row stays counted; a retried multipart_create aborts the held upload
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
// A live session before any \$_SESSION write: FormWriter and OAuth2State start
// one when none is active, which would replace what the test put there.
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
require_once(PathHelper::getIncludePath('tests/lib/logic.php'));
require_once(PathHelper::getIncludePath('tests/lib/s3_fixtures.php'));

$db = DbConnector::get_instance()->get_db_link();
foreach (array('svt_service_tenants', 'svr_shelf_runs', 'svo_shelf_objects') as $table) {
	if (!$db->query("SELECT to_regclass('public." . $table . "') IS NOT NULL")->fetchColumn()) {
		section('schema');
		harness_skip($table . ' is not in the test database yet — sync the server_manager plugin, then copy live to test');
		harness_finish();
		exit;
	}
}

$cleanup = array();   // [table, column, id]
harness_defer(function () use (&$cleanup, $db) {
	foreach (array_reverse($cleanup) as $row) {
		$db->exec("DELETE FROM {$row[0]} WHERE {$row[1]} = " . (int)$row[2]);
	}
});

// ── The presigner ───────────────────────────────────────────────────────────
section('presigner: GET parity with S3Signer, every verb but DELETE');
$creds = array('access_key' => 'AKIAHARNESS', 'secret_key' => 'harness-secret', 'region' => 'us-east-005',
	'endpoint' => 'https://s3.us-east-005.backblazeb2.com');
$a = $b = '';
for ($try = 0; $try < 3; $try++) {
	$a = ShelfPresigner::get($creds, 'bkt', 'joinery-backups/t1/site/chain-1/files 0.tar.gz.enc', 900);
	$b = S3Signer::presign_get($creds, 'bkt', '/joinery-backups/t1/site/chain-1/files 0.tar.gz.enc', 900);
	if ($a === $b) { break; }
}
check($a === $b, 'a GET presigns exactly as S3Signer::presign_get', $a . "\n" . $b);
$put = ShelfPresigner::put($creds, 'bkt', 'k/one', 900);
check(strpos($put, 'https://s3.us-east-005.backblazeb2.com/bkt/k/one?') === 0 && strpos($put, 'X-Amz-Signature=') !== false,
	'a PUT URL names the key and carries a signature');
check($put !== str_replace('X-Amz-Signature=', 'X-Amz-Signature=', $a), 'PUT and GET sign differently');
$create = ShelfPresigner::multipartCreate($creds, 'bkt', 'k/big', 900);
check(strpos($create, '?X-Amz-Algorithm') !== false && strpos($create, '&uploads=') !== false, 'multipart create carries ?uploads', $create);
$part = ShelfPresigner::multipartPart($creds, 'bkt', 'k/big', 'up-1', 3, 900);
check(strpos($part, 'partNumber=3') !== false && strpos($part, 'uploadId=up-1') !== false, 'a part URL carries partNumber and uploadId');
$complete = ShelfPresigner::multipartComplete($creds, 'bkt', 'k/big', 'up-1', 900);
check(strpos($complete, 'uploadId=up-1') !== false && strpos($complete, 'partNumber') === false, 'complete carries uploadId only');
try {
	ShelfPresigner::presign($creds, 'bkt', 'k/one', 'DELETE');
	check(false, 'DELETE is refused');
} catch (ShelfPresignerException $e) {
	check(strpos($e->getMessage(), 'DELETE') !== false, 'the presigner refuses to sign a DELETE');
}
check(strpos(ShelfPresigner::put($creds, 'bkt', 'k/one', 10), 'X-Amz-Expires=60') !== false, 'expiry floors at 60 s');

// ── The fixture and the plane's shelf target ────────────────────────────────
$fx = s3fx_start();
if ($fx === null) {
	section('fixture');
	harness_skip('no loopback S3 fixture could start');
	harness_finish();
	exit;
}
harness_defer(function () use ($fx) { s3fx_stop($fx); });
$fx_creds = s3fx_creds($fx);

$target = new BackupTarget(NULL);
$target->set('bkt_name', 'harnesstest shelf broker target');
$target->set('bkt_provider', 's3');
$target->set('bkt_bucket', 'shelf');
$target->set('bkt_path_prefix', 'harness-backups');
$target->set('bkt_credentials', json_encode($fx_creds));
$target->save();
$cleanup[] = array('bkt_backup_targets', 'bkt_backup_target_id', (int)$target->key);
harness_set_setting_mem('server_manager_services_shelf_target_id', (string)$target->key);
harness_set_setting_mem('server_manager_hosted_shelf_allowance_gb', '1');
harness_set_setting_mem('server_manager_services_grace_days', '14');

$owner = make_user('ShelfOwner');
$key = make_machine_key($owner->key, 'Joinery services', 3);
$key_id = (int)$key['api_key']->key;
$stranger = make_user('ShelfStranger');
$skey = make_machine_key($stranger->key, 'Joinery services', 3);
$skey_id = (int)$skey['api_key']->key;

/** Perform a presigned URL with curl. */
$perform = function (string $method, string $url, $body = null) {
	$ch = curl_init($url);
	$opts = array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
		CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => array('Expect:'));
	if ($body !== null) {
		$opts[CURLOPT_POSTFIELDS] = $body;
	}
	curl_setopt_array($ch, $opts);
	$raw = (string)curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	$headers = substr($raw, 0, $hsize);
	$etag = preg_match('/^ETag:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : '';
	return array('status' => $status, 'body' => substr($raw, $hsize), 'etag' => $etag);
};

// ── Standing ────────────────────────────────────────────────────────────────
section('a tenant with no date is refused before a byte moves');
JoineryServices::enrol($owner->key, $key_id, 'shelf', 'shelf-' . substr(md5(uniqid('', true)), 0, 6) . '.example.com');
$row = ServiceTenant::forKey($key_id, 'shelf');
$cleanup[] = array('svt_service_tenants', 'svt_service_tenant_id', (int)$row->key);
check(strpos(ShelfBroker::refusal($row), 'no paid-through date') !== false, 'the refusal names the missing date');
try {
	ShelfBroker::beginRun($row, 'site', 'chain-1', array(array('name' => 'chain-1/a', 'bytes' => 10)));
	check(false, 'begin_run refuses an unentitled tenant');
} catch (ShelfBrokerException $e) {
	check(strpos($e->getMessage(), 'no paid-through date') !== false, 'begin_run refuses with the sentence');
}
try {
	ShelfBroker::tenantFor($stranger->key, $key_id);
	check(false, 'another account cannot present this key');
} catch (ShelfBrokerException $e) {
	check(true, 'a key from another account finds no tenant');
}
try {
	ShelfBroker::tenantFor($stranger->key, $skey_id);
	check(false, 'a site never enrolled has no shelf');
} catch (ShelfBrokerException $e) {
	check(strpos($e->getMessage(), 'not enrolled') !== false, 'a site never enrolled is told so');
}

JoineryServices::grant($row, '2030-01-01');
JoineryServices::enrol($owner->key, $key_id, 'shelf', (string)$row->get('svt_host'));
$row = ServiceTenant::forKey($key_id, 'shelf');
check(ShelfBroker::refusal($row) === '' && $row->usable(), 'granted and enrolled: writable');

// ── begin_run ───────────────────────────────────────────────────────────────
section('begin_run: the base key, the allowance, the names');
$slug = (string)$row->get('svt_slug');
$begun = ShelfBroker::beginRun($row, 'site', 'chain-20260920_010000', array(
	array('name' => 'chain-20260920_010000/db.sql.gz.enc', 'bytes' => 400),
	array('name' => 'chain-20260920_010000/files-0000.tar.gz.enc', 'bytes' => 600),
));
$run_id = (int)$begun['run_id'];
$cleanup[] = array('svr_shelf_runs', 'svr_shelf_run_id', $run_id);
check($run_id > 0 && $begun['base_key'] === 'harness-backups/' . $slug . '/site/', 'the base key is {prefix}/{slug}/{profile}/', $begun['base_key']);
check($begun['bucket'] === 'shelf' && $begun['allowance'] === 1073741824 && $begun['used'] === 0, 'bucket, allowance and used come back');
$run = new ShelfRun($run_id, TRUE);
check((int)$run->get('svr_declared_bytes') === 1000 && $run->declaredNames() === array('chain-20260920_010000/db.sql.gz.enc', 'chain-20260920_010000/files-0000.tar.gz.enc'),
	'the run records what was declared');

try {
	ShelfBroker::beginRun($row, 'site', 'c', array(array('name' => 'c/huge', 'bytes' => 1073741825)));
	check(false, 'over the allowance refuses');
} catch (ShelfBrokerException $e) {
	check(strpos($e->getMessage(), 'over its allowance') !== false && strpos($e->getMessage(), 'Local backups continue') !== false,
		'a run that would cross the allowance is refused with the sentence', $e->getMessage());
	check((string)ServiceTenant::forKey($key_id, 'shelf')->get('svt_notice') === $e->getMessage(), 'and the sentence is the tenant\'s notice');
}
foreach (array('../escape', '/abs', 'a//b', 'a/./b', '', "bad\nname") as $bad) {
	try {
		ShelfBroker::beginRun($row, 'site', 'c', array(array('name' => $bad, 'bytes' => 1)));
		check(false, 'bad name refused: ' . json_encode($bad));
	} catch (ShelfBrokerException $e) {
		check(true, 'bad name refused: ' . json_encode($bad));
	}
}
try {
	ShelfBroker::beginRun($row, 'nonsense', 'c', array(array('name' => 'c/x', 'bytes' => 1)));
	check(false, 'an unknown profile refuses');
} catch (ShelfBrokerException $e) {
	check(strpos($e->getMessage(), 'Unknown backup profile') !== false, 'an unknown profile refuses');
}
$mgr = ShelfBroker::beginRun($row, 'manager', 'c', array(array('name' => 'c/x', 'bytes' => 1)));
$cleanup[] = array('svr_shelf_runs', 'svr_shelf_run_id', (int)$mgr['run_id']);
check($mgr['base_key'] === 'harness-backups/' . $slug . '/manager/', 'the manager profile files under its own segment');
check((string)ServiceTenant::forKey($key_id, 'shelf')->get('svt_notice') === '', 'an accepted run clears the refusal notice');

// ── sign ────────────────────────────────────────────────────────────────────
section('sign: inside the base key, the five operations, nothing else');
$base = $begun['base_key'];
$signed = ShelfBroker::sign($row, $run_id, 'chain-20260920_010000/db.sql.gz.enc', 'put', array('bytes' => 400));
check(strpos($signed['url'], $fx_creds['endpoint'] . '/shelf/' . $base . 'chain-20260920_010000/db.sql.gz.enc?') === 0,
	'a put URL names the key inside the base', $signed['url']);
check($signed['key'] === $base . 'chain-20260920_010000/db.sql.gz.enc' && $signed['expires_at'] > gmdate('Y-m-d H:i:s'), 'the key and expiry come back');
$ledger = ShelfObject::forKey((int)$row->key, $signed['key']);
check($ledger !== null && (int)$ledger->get('svo_bytes') === 400 && $ledger->get('svo_completed_time') === null
	&& (string)$ledger->get('svo_chain') === 'chain-20260920_010000', 'a signed put is a ledger row, uncompleted');
$again = ShelfBroker::sign($row, $run_id, 'chain-20260920_010000/db.sql.gz.enc', 'put', array('bytes' => 400));
check(count(iterator_to_array(new MultiShelfObject(array('tenant_id' => (int)$row->key, 'key' => $signed['key'], 'deleted' => false)))) === 1,
	'signing the same key again for the same run keeps one ledger row');

foreach (array('../' . $slug . '-other/x', '/etc/passwd', 'a/../../b') as $bad) {
	try {
		ShelfBroker::sign($row, $run_id, $bad, 'put');
		check(false, 'a key outside the base is refused: ' . $bad);
	} catch (ShelfBrokerException $e) {
		check(true, 'a key outside the base is refused: ' . $bad);
	}
}
foreach (array('delete', 'multipart_abort', 'DELETE', 'list') as $op) {
	try {
		ShelfBroker::sign($row, $run_id, 'chain-20260920_010000/x', $op);
		check(false, 'operation refused: ' . $op);
	} catch (ShelfBrokerException $e) {
		check(strpos($e->getMessage(), 'does not sign') !== false, 'operation refused: ' . $op);
	}
}
try {
	ShelfBroker::sign($row, 999999999, 'chain-20260920_010000/x', 'put');
	check(false, 'an unknown run is refused');
} catch (ShelfBrokerException $e) {
	check($e->getMessage() === 'Unknown run.', 'an unknown run is refused');
}
try {
	ShelfBroker::sign($row, $run_id, 'chain-20260920_010000/x', 'multipart_parts', array('upload_id' => 'u', 'first' => 1, 'count' => 11));
	check(false, 'eleven parts refused');
} catch (ShelfBrokerException $e) {
	check(strpos($e->getMessage(), 'batches of up to 10') !== false || strpos($e->getMessage(), 'No open upload') !== false,
		'a batch over ten parts, or parts for a key never created, is refused', $e->getMessage());
}
try {
	ShelfBroker::sign($row, $run_id, 'chain-20260920_010000/never-created', 'multipart_complete', array('upload_id' => 'u'));
	check(false, 'complete for a key never signed refused');
} catch (ShelfBrokerException $e) {
	check(strpos($e->getMessage(), 'No open upload') !== false, 'a complete for a key the ledger never saw is refused');
}

// Another tenant's run.
JoineryServices::enrol($stranger->key, $skey_id, 'shelf', 'other-' . substr(md5(uniqid('', true)), 0, 6) . '.example.com');
$srow = ServiceTenant::forKey($skey_id, 'shelf');
$cleanup[] = array('svt_service_tenants', 'svt_service_tenant_id', (int)$srow->key);
JoineryServices::grant($srow, '2030-01-01');
JoineryServices::enrol($stranger->key, $skey_id, 'shelf', (string)$srow->get('svt_host'));
$srow = ServiceTenant::forKey($skey_id, 'shelf');
try {
	ShelfBroker::sign($srow, $run_id, 'chain-20260920_010000/x', 'put');
	check(false, 'another tenant cannot sign against this run');
} catch (ShelfBrokerException $e) {
	check($e->getMessage() === 'Unknown run.', 'a run from another tenant is unknown to this one');
}
$sbegun = ShelfBroker::beginRun($srow, 'site', 'c', array(array('name' => 'c/x', 'bytes' => 1)));
$cleanup[] = array('svr_shelf_runs', 'svr_shelf_run_id', (int)$sbegun['run_id']);
check(strpos($sbegun['base_key'], 'harness-backups/' . (string)$srow->get('svt_slug') . '/') === 0
	&& $sbegun['base_key'] !== $base, 'each tenant is boxed inside its own slug');

// ── The URLs work, against the fixture ──────────────────────────────────────
section('the signed URLs work: put, multipart, get');
$r = $perform('PUT', $signed['url'], str_repeat('D', 400));
check($r['status'] === 200, 'the put URL lands the bytes', 'HTTP ' . $r['status']);
check(s3fx_object($fx, 'shelf', '/' . $signed['key']) === str_repeat('D', 400), 'the fixture holds them at the signed key');

$create = ShelfBroker::sign($row, $run_id, 'chain-20260920_010000/files-0000.tar.gz.enc', 'multipart_create', array('bytes' => 600));
$r = $perform('POST', $create['url']);
check($r['status'] === 200 && preg_match('#<UploadId>([^<]+)</UploadId>#', $r['body'], $m), 'multipart create answers an upload id', $r['body']);
$upload_id = $m[1] ?? '';
$parts = ShelfBroker::sign($row, $run_id, 'chain-20260920_010000/files-0000.tar.gz.enc', 'multipart_parts',
	array('upload_id' => $upload_id, 'first' => 1, 'count' => 2));
check(count($parts['urls']) === 2 && isset($parts['urls'][1], $parts['urls'][2]), 'a batch of two part URLs, numbered from 1');
$etags = array();
$r1 = $perform('PUT', $parts['urls'][1], str_repeat('A', 300)); $etags[1] = $r1['etag'];
$r2 = $perform('PUT', $parts['urls'][2], str_repeat('B', 300)); $etags[2] = $r2['etag'];
check($r1['status'] === 200 && $r2['status'] === 200 && $etags[1] !== '' && $etags[2] !== '', 'both parts land with ETags');
$ledger = ShelfObject::forKey((int)$row->key, $create['key']);
check((string)$ledger->get('svo_upload_id') === $upload_id, 'the ledger holds the open upload id');
$done = ShelfBroker::sign($row, $run_id, 'chain-20260920_010000/files-0000.tar.gz.enc', 'multipart_complete', array('upload_id' => $upload_id));
$r = $perform('POST', $done['url'], S3Signer::build_complete_xml($etags));
check($r['status'] === 200 && S3Signer::complete_body_ok($r['body']), 'complete assembles the upload', $r['body']);
check(s3fx_object($fx, 'shelf', '/' . $create['key']) === str_repeat('A', 300) . str_repeat('B', 300), 'the assembled object is the parts in order');

$get = ShelfBroker::sign($row, $run_id, 'chain-20260920_010000/db.sql.gz.enc', 'get');
$r = $perform('GET', $get['url']);
check($r['status'] === 200 && $r['body'] === str_repeat('D', 400), 'a get URL reads the bytes back');
check(ShelfObject::completedBytes((int)$row->key) === 0, 'nothing counts until the site says it completed');

// ── finish_run and the figure ───────────────────────────────────────────────
section('finish_run: the ledger marks complete, the figure is the sum');
$extra = ShelfBroker::sign($row, $run_id, 'chain-20260920_010000/never-finished', 'put', array('bytes' => 50));
$fin = ShelfBroker::finishRun($row, $run_id, array(
	array('name' => 'chain-20260920_010000/db.sql.gz.enc', 'bytes' => 400),
	array('name' => 'chain-20260920_010000/files-0000.tar.gz.enc', 'bytes' => 600),
	array('name' => 'chain-20260920_010000/not-signed', 'bytes' => 9999),
));
check($fin['completed'] === 2 && $fin['cancelled'] === 1 && $fin['figure'] === 1000, 'two signed objects complete; an unsigned name is ignored; the one not named is cancelled', json_encode($fin));
$row = ServiceTenant::forKey($key_id, 'shelf');
check((int)$row->get('svt_figure') === 1000 && $row->get('svt_figure_time') !== null, 'the tenant figure is the ledger sum');
check(ShelfObject::forKey((int)$row->key, $extra['key']) === null, 'the object signed but not named is dropped from the ledger');
check(ShelfObject::forKey((int)$row->key, $create['key'])->get('svo_upload_id') === null, 'a completed multipart drops its upload id');
check((string)(new ShelfRun($run_id, TRUE))->get('svr_state') === 'finished', 'the run is finished');
try {
	ShelfBroker::sign($row, $run_id, 'chain-20260920_010000/late', 'put');
	check(false, 'a spent run is refused');
} catch (ShelfBrokerException $e) {
	check(strpos($e->getMessage(), 'already finished') !== false, 'a spent run is refused');
}
try {
	ShelfBroker::finishRun($row, $run_id, array());
	check(false, 'finishing twice is refused');
} catch (ShelfBrokerException $e) {
	check(true, 'finishing a finished run is refused');
}
$s = JoineryServices::statusOf($row);
check($s['figure'] === 1000 && $s['used_label'] === '0 MB' && $s['allowance_label'] === '1 GB', 'status carries the figure');

// ── finish_run cancels an open multipart at the provider ────────────────────
section('finish_run: a multipart the site never completed is aborted at the provider');
$bm = ShelfBroker::beginRun($row, 'site', 'chain-m', array(array('name' => 'chain-m/big', 'bytes' => 100), array('name' => 'chain-m/small', 'bytes' => 3)));
$cleanup[] = array('svr_shelf_runs', 'svr_shelf_run_id', (int)$bm['run_id']);
$cm = ShelfBroker::sign($row, (int)$bm['run_id'], 'chain-m/big', 'multipart_create', array('bytes' => 100));
$r = $perform('POST', $cm['url']);
preg_match('#<UploadId>([^<]+)</UploadId>#', $r['body'], $m);
ShelfBroker::sign($row, (int)$bm['run_id'], 'chain-m/big', 'multipart_parts', array('upload_id' => $m[1], 'first' => 1, 'count' => 1));
$sm = ShelfBroker::sign($row, (int)$bm['run_id'], 'chain-m/small', 'put', array('bytes' => 3));
$perform('PUT', $sm['url'], 'abc');
$aborts_before = s3fx_count($fx, 'abort');
$fin = ShelfBroker::finishRun($row, (int)$bm['run_id'], array(array('name' => 'chain-m/small', 'bytes' => 3)));
check($fin['completed'] === 1 && $fin['cancelled'] === 1, 'the small object completes; the multipart is cancelled', json_encode($fin));
check(s3fx_count($fx, 'abort') === $aborts_before + 1, 'the open multipart is aborted at the provider before the run closes');
check(ShelfObject::forKey((int)$row->key, $cm['key']) === null, 'its ledger row is gone');
check(ShelfObject::completedBytes((int)$row->key) === 1003, 'the figure counts only what completed');

// ── one ledger row per tenant and key ───────────────────────────────────────
section('a key signed by two runs is one row and one figure');
$manifest = 'chain-m/manifest.json';
$b1 = ShelfBroker::beginRun($row, 'site', 'chain-m', array(array('name' => $manifest, 'bytes' => 20)));
$cleanup[] = array('svr_shelf_runs', 'svr_shelf_run_id', (int)$b1['run_id']);
$s1 = ShelfBroker::sign($row, (int)$b1['run_id'], $manifest, 'put', array('bytes' => 20));
$perform('PUT', $s1['url'], str_repeat('1', 20));
ShelfBroker::finishRun($row, (int)$b1['run_id'], array(array('name' => $manifest, 'bytes' => 20)));
check(ShelfObject::completedBytes((int)$row->key) === 1023, 'the first run counts the manifest');
$b2m = ShelfBroker::beginRun($row, 'site', 'chain-m', array(array('name' => $manifest, 'bytes' => 30)));
$cleanup[] = array('svr_shelf_runs', 'svr_shelf_run_id', (int)$b2m['run_id']);
$s2 = ShelfBroker::sign($row, (int)$b2m['run_id'], $manifest, 'put', array('bytes' => 30));
$moved = ShelfObject::forKey((int)$row->key, $s2['key']);
check((int)$moved->get('svo_svr_shelf_run_id') === (int)$b2m['run_id'] && $moved->get('svo_completed_time') !== null && (int)$moved->get('svo_bytes') === 20,
	'the row moves to the second run and stays completed at its first size until that run finishes it');
check(ShelfObject::completedBytes((int)$row->key) === 1023, 'the figure is unchanged by the takeover alone');
$perform('PUT', $s2['url'], str_repeat('2', 30));
ShelfBroker::finishRun($row, (int)$b2m['run_id'], array(array('name' => $manifest, 'bytes' => 30)));
$rows_for_key = new MultiShelfObject(array('tenant_id' => (int)$row->key, 'key' => $s2['key'], 'deleted' => false));
check(count($rows_for_key) === 1, 'two runs, one ledger row for the key');
check(ShelfObject::completedBytes((int)$row->key) === 1033, 'two runs, one figure: the manifest counts once, at its new size');
$row = ServiceTenant::forKey($key_id, 'shelf');

// ── list ────────────────────────────────────────────────────────────────────
section('list: inside the tenant\'s prefix only');
$listed = ShelfBroker::listPrefix($row, '');
$keys = array_map(function ($o) { return $o['key']; }, $listed['objects']);
sort($keys);
check($listed['prefix'] === 'harness-backups/' . $slug . '/' && $keys === array(
	'site/chain-20260920_010000/db.sql.gz.enc', 'site/chain-20260920_010000/files-0000.tar.gz.enc',
	'site/chain-m/manifest.json', 'site/chain-m/small'),
	'the listing is relative to the tenant prefix and shows what was written', json_encode($keys));
$listed = ShelfBroker::listPrefix($row, 'site/chain-20260920_010000/');
check(count($listed['objects']) === 2 && $listed['objects'][0]['size'] > 0, 'a sub-prefix narrows it');
$listed = ShelfBroker::listPrefix($srow, '');
check($listed['objects'] === array(), 'the other tenant sees nothing of it');
try {
	ShelfBroker::listPrefix($row, '../');
	check(false, 'a prefix that climbs is refused');
} catch (ShelfBrokerException $e) {
	check(true, 'a prefix that climbs is refused');
}

// ── abort: the plane's own act ──────────────────────────────────────────────
section('abort: signed-but-unfinished is dropped, an open multipart is cancelled');
$b2 = ShelfBroker::beginRun($row, 'site', 'chain-2', array(array('name' => 'chain-2/big', 'bytes' => 100), array('name' => 'chain-2/small', 'bytes' => 1)));
$cleanup[] = array('svr_shelf_runs', 'svr_shelf_run_id', (int)$b2['run_id']);
$c2 = ShelfBroker::sign($row, (int)$b2['run_id'], 'chain-2/big', 'multipart_create', array('bytes' => 100));
$r = $perform('POST', $c2['url']);
preg_match('#<UploadId>([^<]+)</UploadId>#', $r['body'], $m);
ShelfBroker::sign($row, (int)$b2['run_id'], 'chain-2/big', 'multipart_parts', array('upload_id' => $m[1], 'first' => 1, 'count' => 1));
ShelfBroker::sign($row, (int)$b2['run_id'], 'chain-2/small', 'put', array('bytes' => 1));
$aborts_before = s3fx_count($fx, 'abort');
$dropped = ShelfBroker::abortRun(new ShelfRun((int)$b2['run_id'], TRUE), 'harness: the site never finished');
check($dropped === 2, 'both unfinished ledger rows are dropped');
check(s3fx_count($fx, 'abort') === $aborts_before + 1, 'the open multipart upload is aborted at the provider by the plane');
check(ShelfObject::forKey((int)$row->key, $c2['key']) === null, 'nothing of the aborted run remains in the ledger');
$r2 = new ShelfRun((int)$b2['run_id'], TRUE);
check((string)$r2->get('svr_state') === 'aborted' && strpos((string)$r2->get('svr_cause'), 'never finished') !== false, 'the run records its cause');
check(ShelfObject::completedBytes((int)$row->key) === 1033, 'the figure is untouched by the abort');

// ── B10: a taken-over row survives the new run's abort ──────────────────────
section('a run that takes over a completed key and aborts leaves it counted at its earlier size');
$b3 = ShelfBroker::beginRun($row, 'site', 'chain-m', array(array('name' => $manifest, 'bytes' => 40)));
$cleanup[] = array('svr_shelf_runs', 'svr_shelf_run_id', (int)$b3['run_id']);
$c3 = ShelfBroker::sign($row, (int)$b3['run_id'], $manifest, 'multipart_create', array('bytes' => 40));
$r = $perform('POST', $c3['url']);
preg_match('#<UploadId>([^<]+)</UploadId>#', $r['body'], $m3);
ShelfBroker::sign($row, (int)$b3['run_id'], $manifest, 'multipart_parts', array('upload_id' => $m3[1], 'first' => 1, 'count' => 1));
$held = ShelfObject::forKey((int)$row->key, $c3['key']);
check((int)$held->get('svo_svr_shelf_run_id') === (int)$b3['run_id'] && $held->get('svo_completed_time') !== null
	&& (int)$held->get('svo_bytes') === 30 && (string)$held->get('svo_upload_id') === $m3[1],
	'the completed row is the new run\'s, still completed at 30 bytes, and holds the upload it opened');
$aborts_before = s3fx_count($fx, 'abort');
$dropped = ShelfBroker::abortRun(new ShelfRun((int)$b3['run_id'], TRUE), 'harness: the incremental died');
check($dropped === 0, 'nothing is dropped: the row was completed by an earlier run');
check(s3fx_count($fx, 'abort') === $aborts_before + 1, 'the upload the aborted run opened on the manifest is cancelled at the provider');
$kept = ShelfObject::forKey((int)$row->key, $c3['key']);
check($kept !== null && $kept->get('svo_completed_time') !== null && (int)$kept->get('svo_bytes') === 30 && $kept->get('svo_upload_id') === null,
	'the row stays, completed at its earlier size, with no upload id');
check(ShelfObject::completedBytes((int)$row->key) === 1033, 'the manifest stays counted at the size the earlier run gave it');

// ── B9: a retried multipart_create aborts the upload the row held ───────────
section('two multipart creates for one key: the first upload is aborted before its id is let go');
$b4 = ShelfBroker::beginRun($row, 'site', 'chain-4', array(array('name' => 'chain-4/big', 'bytes' => 100)));
$cleanup[] = array('svr_shelf_runs', 'svr_shelf_run_id', (int)$b4['run_id']);
$c4 = ShelfBroker::sign($row, (int)$b4['run_id'], 'chain-4/big', 'multipart_create', array('bytes' => 100));
$r = $perform('POST', $c4['url']);
preg_match('#<UploadId>([^<]+)</UploadId>#', $r['body'], $m4);
ShelfBroker::sign($row, (int)$b4['run_id'], 'chain-4/big', 'multipart_parts', array('upload_id' => $m4[1], 'first' => 1, 'count' => 1));
$aborts_before = s3fx_count($fx, 'abort');
ShelfBroker::sign($row, (int)$b4['run_id'], 'chain-4/big', 'multipart_create', array('bytes' => 100));
check(s3fx_count($fx, 'abort') === $aborts_before + 1, 'two creates for one key: one abort, the upload the row held');
$retried = ShelfObject::forKey((int)$row->key, $c4['key']);
check($retried !== null && $retried->get('svo_upload_id') === null && (int)$retried->get('svo_svr_shelf_run_id') === (int)$b4['run_id'],
	'the row is one, holds no upload id, and is still the run\'s');
$rows_for_key = new MultiShelfObject(array('tenant_id' => (int)$row->key, 'key' => $c4['key'], 'deleted' => false));
check(count($rows_for_key) === 1, 'the retry did not add a row');
$dropped = ShelfBroker::abortRun(new ShelfRun((int)$b4['run_id'], TRUE), 'harness: never finished');
check($dropped === 1 && s3fx_count($fx, 'abort') === $aborts_before + 1, 'aborting the run drops the row; with no upload held there is nothing more to cancel');
check(ShelfObject::completedBytes((int)$row->key) === 1033, 'the figure is untouched');

// ── The ladder's rungs refuse ───────────────────────────────────────────────
section('a suspended or released tenant is refused; a new date restores it');
JoineryServices::suspend($row);
$row = ServiceTenant::forKey($key_id, 'shelf');
try {
	ShelfBroker::beginRun($row, 'site', 'c', array(array('name' => 'c/x', 'bytes' => 1)));
	check(false, 'suspended refuses');
} catch (ShelfBrokerException $e) {
	check(strpos($e->getMessage(), 'paid-through date has passed') !== false, 'a suspended tenant is refused with the ladder\'s sentence');
}
$listed = ShelfBroker::listPrefix($row, 'site/chain-m/');
check(count($listed['objects']) === 2, 'a suspended tenant can still list its copies');
$get = ShelfBroker::sign($row, 0, 'site/chain-m/small', 'get');
$r = $perform('GET', $get['url']);
check($get['key'] === 'harness-backups/' . $slug . '/site/chain-m/small' && $r['status'] === 200 && $r['body'] === 'abc',
	'a suspended tenant gets a read URL with no run, named as the listing names it');
$get = ShelfBroker::sign($row, (int)$b2m['run_id'], 'manifest.json', 'get');
check($get['key'] === 'harness-backups/' . $slug . '/site/manifest.json' && $perform('GET', $get['url'])['status'] === 404,
	'a get under a finished run is keyed by that run\'s base key (there is no site/manifest.json)');
$get = ShelfBroker::sign($row, (int)$b2m['run_id'], 'chain-m/manifest.json', 'get');
check($perform('GET', $get['url'])['body'] === str_repeat('2', 30), 'and reads the run\'s object');
try {
	ShelfBroker::sign($row, (int)$b2m['run_id'], 'chain-m/late', 'put');
	check(false, 'a put is refused while suspended');
} catch (ShelfBrokerException $e) {
	check(strpos($e->getMessage(), 'has passed') !== false, 'a put is refused while suspended, by the standing not the run');
}
JoineryServices::releaseRow($row);
$row = ServiceTenant::forKey($key_id, 'shelf');
check(strpos(ShelfBroker::refusal($row), 'released') !== false, 'a released tenant is refused');
check(ShelfBroker::readRefusal($row) === '' && count(ShelfBroker::listPrefix($row, 'site/chain-m/')['objects']) === 2, 'a released tenant still reads, for the retention to mean anything');
$row->set('svt_pruned_time', gmdate('Y-m-d H:i:s'));
$row->save();
try {
	ShelfBroker::listPrefix($row, '');
	check(false, 'a pruned tenant cannot list');
} catch (ShelfBrokerException $e) {
	check(strpos($e->getMessage(), 'pruned') !== false, 'a pruned tenant cannot list');
}
try {
	ShelfBroker::sign($row, 0, 'site/chain-m/small', 'get');
	check(false, 'a pruned tenant cannot get');
} catch (ShelfBrokerException $e) {
	check(strpos($e->getMessage(), 'pruned') !== false, 'a pruned tenant cannot get');
}
$row->set('svt_pruned_time', null);
$row->save();
JoineryServices::grant($row, '2031-01-01');
$row = ServiceTenant::forKey($key_id, 'shelf');
check(ShelfBroker::refusal($row) === '', 'a new date makes it writable again in place');
$row->set('svt_paid_until', '2020-01-01 00:00:00');
$row->save();
check(strpos(ShelfBroker::refusal($row), 'has passed') !== false, 'a date in the past refuses even while the row is still active');
$row->set('svt_paid_until', '2031-01-01 00:00:00');
$row->save();

// ── The actions ─────────────────────────────────────────────────────────────
section('the five actions over the connected key');
$saved_session = $_SESSION ?? array();
$_SESSION = array('loggedin' => 1, 'usr_user_id' => (int)$owner->key, 'permission' => 0, 'api_key_id' => $key_id);
try {
	$st = harness_call_logic('plugins/server_manager/logic/shelf_status_logic.php', 'shelf_status_logic', array());
	check(!$st->error && $st->data['figure'] === 1033 && $st->data['writable'] === true && $st->data['refusal'] === '' && $st->data['readable'] === true,
		'shelf_status answers the figure, writability and readability');
	$bg = harness_call_logic('plugins/server_manager/logic/shelf_begin_run_logic.php', 'shelf_begin_run_logic',
		array('profile' => 'site', 'chain' => 'chain-3', 'artifacts' => array(array('name' => 'chain-3/a', 'bytes' => 5))));
	check(!$bg->error && (int)$bg->data['run_id'] > 0, 'shelf_begin_run opens a run', (string)$bg->error);
	$rid = (int)$bg->data['run_id'];
	$cleanup[] = array('svr_shelf_runs', 'svr_shelf_run_id', $rid);
	$sg = harness_call_logic('plugins/server_manager/logic/shelf_sign_logic.php', 'shelf_sign_logic',
		array('run_id' => $rid, 'name' => 'chain-3/a', 'operation' => 'put', 'bytes' => 5));
	check(!$sg->error && strpos($sg->data['url'], '/shelf/harness-backups/' . $slug . '/site/chain-3/a?') !== false, 'shelf_sign signs a put');
	$perform('PUT', $sg->data['url'], 'hello');
	$bad = harness_call_logic('plugins/server_manager/logic/shelf_sign_logic.php', 'shelf_sign_logic',
		array('run_id' => $rid, 'name' => '../x', 'operation' => 'put'));
	check($bad->error !== '' && $bad->error !== null, 'shelf_sign refuses a key outside the run');
	$fn = harness_call_logic('plugins/server_manager/logic/shelf_finish_run_logic.php', 'shelf_finish_run_logic',
		array('run_id' => $rid, 'completed' => array(array('name' => 'chain-3/a', 'bytes' => 5))));
	check(!$fn->error && $fn->data['completed'] === 1 && $fn->data['figure'] === 1038, 'shelf_finish_run completes and refreshes the figure');
	$ls = harness_call_logic('plugins/server_manager/logic/shelf_list_logic.php', 'shelf_list_logic', array('prefix' => 'site/chain-3/'));
	check(!$ls->error && count($ls->data['objects']) === 1 && $ls->data['objects'][0]['key'] === 'site/chain-3/a', 'shelf_list lists inside the prefix');
	$_SESSION['api_key_id'] = $skey_id;
	$st = harness_call_logic('plugins/server_manager/logic/shelf_status_logic.php', 'shelf_status_logic', array());
	check($st->error !== '' && $st->error !== null, 'a key of another account finds no tenant for this user');
} finally {
	$_SESSION = $saved_session;
}

// Ledger rows are removed with their tenant (cascade); the defer deletes tenants last.
$db->exec("DELETE FROM svo_shelf_objects WHERE svo_svt_service_tenant_id IN (" . (int)$row->key . ", " . (int)$srow->key . ")");
harness_finish();

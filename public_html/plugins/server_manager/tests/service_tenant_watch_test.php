<?php
/** @joinery-test
 * name: service_tenant_watch
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * The services reconcile (specs/services_phase2_platform.md §5, §7 — build
 * item 3): the ladder against the date, the meters, the ledger against a
 * listing, and retention.
 *
 *   - The ladder: a passed date lapses, grace holds, past grace suspends
 *     (mail's subaccount closed; the shelf refused) and starts the retention
 *     clock; a new date reactivates at every stage before the prune.
 *   - Mail's figure is the provider's own month-to-date count, read hourly;
 *     the allowance setting re-sets the subaccount limit when it changes.
 *   - The ledger reconciles against a listing: an object the shelf does not
 *     have is dropped, one the ledger does not have is adopted at its listed
 *     size, and an abandoned run is aborted (its multipart cancelled).
 *   - Retention: chains pruned whole to the keep count, per profile, newest
 *     kept, a chain with an open run untouched; a stopped tenant's whole
 *     prefix pruned once its day comes, and the row says so.
 *   - An act the provider refused is retried on the next pass.
 *
 * The mail provider is a Guzzle MockHandler; the shelf is the local S3
 * fixture. Rows go to the test database and are deleted.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/service_tenant_watch_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('tests/lib/s3_fixtures.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/Smtp2GoClient.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ServiceTenantWatch.php'));

$db = DbConnector::get_instance()->get_db_link();
foreach (array('svt_service_tenants', 'svr_shelf_runs', 'svo_shelf_objects') as $table) {
	if (!$db->query("SELECT to_regclass('public." . $table . "') IS NOT NULL")->fetchColumn()) {
		section('schema');
		harness_skip($table . ' is not in the test database yet — sync the server_manager plugin, then copy live to test');
		harness_finish();
		exit;
	}
}
if (!$db->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'svt_service_tenants' AND column_name = 'svt_reconciled_time'")->fetchColumn()) {
	section('schema');
	harness_skip('svt_service_tenants lacks svt_reconciled_time — sync the server_manager plugin, then copy live to test');
	harness_finish();
	exit;
}

$cleanup = array();
harness_defer(function () use (&$cleanup, $db) {
	foreach (array_reverse($cleanup) as $row) {
		$db->exec("DELETE FROM {$row[0]} WHERE {$row[1]} = " . (int)$row[2]);
	}
});

// ── Mail provider, mocked ───────────────────────────────────────────────────
$history = array();
$mock = new \GuzzleHttp\Handler\MockHandler(array());
$stack = \GuzzleHttp\HandlerStack::create($mock);
$stack->push(\GuzzleHttp\Middleware::history($history));
$client = new Smtp2GoClient('harness-master-key', new \GuzzleHttp\Client(array('handler' => $stack)));
$reply = function (array $bodies) use ($mock) {
	foreach ($bodies as $body) {
		$mock->append(new \GuzzleHttp\Psr7\Response(200, array(), json_encode(array('data' => $body))));
	}
};
$drain = function () use (&$history) {
	$calls = array();
	foreach ($history as $entry) {
		$calls[] = array('path' => ltrim($entry['request']->getUri()->getPath(), '/'),
			'body' => json_decode((string)$entry['request']->getBody(), true) ?: array());
	}
	$history = array();
	return $calls;
};
$paths = function (array $calls) { return array_map(function ($c) { return $c['path']; }, $calls); };

// ── Shelf fixture ───────────────────────────────────────────────────────────
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
$target->set('bkt_name', 'harnesstest tenant watch target');
$target->set('bkt_provider', 's3');
$target->set('bkt_bucket', 'shelf');
$target->set('bkt_path_prefix', 'hb');
$target->set('bkt_credentials', json_encode($fx_creds));
$target->save();
$cleanup[] = array('bkt_backup_targets', 'bkt_backup_target_id', (int)$target->key);
harness_set_setting_mem('server_manager_services_shelf_target_id', (string)$target->key);
harness_set_setting_mem('server_manager_hosted_shelf_allowance_gb', '1');
harness_set_setting_mem('server_manager_hosted_send_allowance', '1000');
harness_set_setting_mem('server_manager_services_grace_days', '14');
harness_set_setting_mem('server_manager_services_shelf_keep_chains', '2');

/** Put bytes straight into the fixture at a key. */
$put_raw = function (string $key, string $bytes) use ($fx_creds) {
	S3Signer::put_file($fx_creds, 'shelf', '/' . $key, tempnam_with($bytes));
};
function tempnam_with(string $bytes): string {
	$f = tempnam(sys_get_temp_dir(), 'svtw');
	file_put_contents($f, $bytes);
	register_shutdown_function(function () use ($f) { @unlink($f); });
	return $f;
}

$owner = make_user('WatchOwner');
$key = make_machine_key($owner->key, 'Joinery services', 3);
$key_id = (int)$key['api_key']->key;
$host = 'watch-' . substr(md5(uniqid('', true)), 0, 6) . '.example.com';

$watch = new ServiceTenantWatch();
$watch->client = $client;

// ── Mail: build a tenant, then walk the ladder ──────────────────────────────
section('mail: the ladder against the date');
JoineryServices::enrol($owner->key, $key_id, 'mail', $host, $client);
$mail = ServiceTenant::forKey($key_id, 'mail');
$cleanup[] = array('svt_service_tenants', 'svt_service_tenant_id', (int)$mail->key);
JoineryServices::grant($mail, '2026-10-01', $client);
$reply(array(array('subaccount_id' => 'sub-w'), array(),
	array('domains' => array(array('domain' => array('fulldomain' => 'mail.' . $host, 'dkim_selector' => 's', 'dkim_value' => 'v',
		'rpath_selector' => 'r', 'rpath_value' => 'w', 'dkim_verified' => true, 'rpath_verified' => true)))),
	array('username' => 'x'),
	array('domains' => array(array('domain' => array('fulldomain' => 'mail.' . $host, 'dkim_verified' => true, 'rpath_verified' => true))))));
JoineryServices::enrol($owner->key, $key_id, 'mail', $host, $client);
$drain();
$mail = ServiceTenant::forKey($key_id, 'mail');
check((string)$mail->get('svt_state') === 'active', 'the mail tenant is active');

// Entitled: checked, and the provider's count read.
$reply(array(array('sent' => 42)));
$acted = $watch->watch($mail, '2026-09-20 12:00:00');
$mail = ServiceTenant::forKey($key_id, 'mail');
check($paths($drain()) === array('stats/email_summary') && (int)$mail->get('svt_figure') === 42
	&& substr((string)$mail->get('svt_figure_time'), 0, 19) === '2026-09-20 12:00:00',
	'an entitled pass reads the provider\'s month-to-date count');
check(substr((string)$mail->get('svt_checked_time'), 0, 19) === '2026-09-20 12:00:00', 'the check time is stamped (Q1)');
$watch->watch($mail, '2026-09-20 12:30:00');
check(count($drain()) === 0, 'the count is not re-read inside the hour');
$reply(array(array('sent' => 43)));
$watch->watch($mail, '2026-09-20 13:00:01');
check($paths($drain()) === array('stats/email_summary'), 'and is re-read after it');

// The date passes: lapse, grace, suspend.
$reply(array(array('sent' => 43)));
$watch->watch($mail, '2026-10-02 00:00:00');
$drain();
$mail = ServiceTenant::forKey($key_id, 'mail');
check(substr((string)$mail->get('svt_lapse_time'), 0, 19) === '2026-10-02 00:00:00' && (string)$mail->get('svt_state') === 'active',
	'the day after the date: lapsed, still active');
$reply(array(array('sent' => 43)));
$watch->watch($mail, '2026-10-10 00:00:00');
$mail = ServiceTenant::forKey($key_id, 'mail');
check((string)$mail->get('svt_state') === 'active' && !in_array('subaccount/close', $paths($drain()), true), 'inside the grace window nothing closes');
$reply(array(array(), array('sent' => 43)));   // subaccount/close, then the figure
$watch->watch($mail, '2026-10-16 00:00:01');
$calls = $paths($drain());
$mail = ServiceTenant::forKey($key_id, 'mail');
check(in_array('subaccount/close', $calls, true) && (string)$mail->get('svt_state') === 'suspended'
	&& $mail->get('svt_revoked_time') !== null && strpos((string)$mail->get('svt_notice'), 'paid-through date has passed') !== false,
	'past the grace window the subaccount is closed and the row says why', json_encode($calls));

// A new date: reactivated by the reconcile.
$mail->set('svt_paid_until', '2027-01-01 00:00:00');
$mail->save();
$reply(array(array(), array('sent' => 0)));      // subaccount/reopen, then the figure
$watch->watch($mail, '2026-11-01 00:00:00');
$calls = $paths($drain());
$mail = ServiceTenant::forKey($key_id, 'mail');
check(in_array('subaccount/reopen', $calls, true) && (string)$mail->get('svt_state') === 'active'
	&& $mail->get('svt_lapse_time') === null && $mail->get('svt_revoked_time') === null && $mail->get('svt_notice') === null,
	'a new date reactivates in place and reopens the subaccount');

section('mail: the allowance re-sets the limit; a refused act is retried');
harness_set_setting_mem('server_manager_hosted_send_allowance', '2500');
$reply(array(array()));                          // subaccount/edit
$watch->watch($mail, '2026-11-01 00:30:00');
$calls = $drain();
check(($calls[0]['path'] ?? '') === 'subaccount/edit' && ($calls[0]['body']['limit'] ?? 0) === 2500
	&& (int)ServiceTenant::forKey($key_id, 'mail')->get('svt_allowance') === 2500, 'a changed allowance re-sets the subaccount limit');
$watch->watch($mail, '2026-11-01 00:31:00');
check(count($drain()) === 0, 'an unchanged allowance touches nothing');

// The provider refuses the close: the row is suspended, the act retried next pass.
$mail->set('svt_paid_until', '2026-01-01 00:00:00');
$mail->set('svt_lapse_time', '2026-01-02 00:00:00');
$mail->save();
$mock->append(new \GuzzleHttp\Psr7\Response(500, array(), json_encode(array('data' => array('error' => 'provider down')))));
try {
	$watch->watch($mail, '2026-02-01 00:00:00');
	check(false, 'the refused close surfaces');
} catch (\Throwable $e) {
	check(strpos($e->getMessage(), 'provider down') !== false, 'the refused close surfaces as the pass\'s error');
}
$drain();
$mail = ServiceTenant::forKey($key_id, 'mail');
check((string)$mail->get('svt_state') === 'suspended' && $mail->get('svt_revoked_time') === null,
	'the row is on the suspended rung with no revoked time: the act did not land');
$reply(array(array(), array('sent' => 0)));
$watch->watch($mail, '2026-02-01 00:15:00');
$calls = $paths($drain());
$mail = ServiceTenant::forKey($key_id, 'mail');
check(in_array('subaccount/close', $calls, true) && $mail->get('svt_revoked_time') !== null, 'the next pass retries the close until it lands');

// ── Shelf: ledger, listing, retention ───────────────────────────────────────
section('shelf: the ladder starts the retention clock; prune when its day comes');
JoineryServices::enrol($owner->key, $key_id, 'shelf', $host, $client);
$shelf = ServiceTenant::forKey($key_id, 'shelf');
$cleanup[] = array('svt_service_tenants', 'svt_service_tenant_id', (int)$shelf->key);
JoineryServices::grant($shelf, '2026-10-01', $client);
JoineryServices::enrol($owner->key, $key_id, 'shelf', $host, $client);
$shelf = ServiceTenant::forKey($key_id, 'shelf');
$slug = (string)$shelf->get('svt_slug');
$base = 'hb/' . $slug . '/';

// Three chains on the shelf through the broker, oldest first.
$run_ids = array();
foreach (array('chain-20260901_010000', 'chain-20260910_010000', 'chain-20260920_010000') as $chain) {
	$b = ShelfBroker::beginRun($shelf, 'site', $chain, array(array('name' => $chain . '/db', 'bytes' => 10), array('name' => $chain . '/files', 'bytes' => 20)));
	$run_ids[] = (int)$b['run_id'];
	$cleanup[] = array('svr_shelf_runs', 'svr_shelf_run_id', (int)$b['run_id']);
	foreach (array('db' => 10, 'files' => 20) as $name => $bytes) {
		ShelfBroker::sign($shelf, (int)$b['run_id'], $chain . '/' . $name, 'put', array('bytes' => $bytes));
		$put_raw($base . 'site/' . $chain . '/' . $name, str_repeat('x', $bytes));
	}
	ShelfBroker::finishRun($shelf, (int)$b['run_id'], array(array('name' => $chain . '/db', 'bytes' => 10), array('name' => $chain . '/files', 'bytes' => 20)));
}
$shelf = ServiceTenant::forKey($key_id, 'shelf');
check((int)$shelf->get('svt_figure') === 90, 'three chains: 90 bytes on the ledger');

section('shelf: retention keeps the newest chains per profile, whole');
$watch->watch($shelf, '2026-09-21 00:00:00');
$shelf = ServiceTenant::forKey($key_id, 'shelf');
$keys = s3fx_keys($fx);
check(!in_array('shelf/' . $base . 'site/chain-20260901_010000/db', $keys, true)
	&& !in_array('shelf/' . $base . 'site/chain-20260901_010000/files', $keys, true),
	'the oldest chain is gone from the shelf, both objects');
check(in_array('shelf/' . $base . 'site/chain-20260910_010000/db', $keys, true)
	&& in_array('shelf/' . $base . 'site/chain-20260920_010000/files', $keys, true), 'the newest two stay');
check((int)$shelf->get('svt_figure') === 60 && ShelfObject::completedBytes((int)$shelf->key) === 60, 'the figure follows: 60 bytes');
check(substr((string)$shelf->get('svt_reconciled_time'), 0, 19) === '2026-09-21 00:00:00', 'the first pass reconciled against the listing');

// A chain with an open run is never touched, and a manager chain is its own family.
$open = ShelfBroker::beginRun($shelf, 'site', 'chain-20260830_010000', array(array('name' => 'chain-20260830_010000/db', 'bytes' => 1)));
$cleanup[] = array('svr_shelf_runs', 'svr_shelf_run_id', (int)$open['run_id']);
ShelfBroker::sign($shelf, (int)$open['run_id'], 'chain-20260830_010000/db', 'put', array('bytes' => 1));
$put_raw($base . 'site/chain-20260830_010000/db', 'x');
$m = ShelfBroker::beginRun($shelf, 'manager', 'chain-20260801_010000', array(array('name' => 'chain-20260801_010000/db', 'bytes' => 5)));
$cleanup[] = array('svr_shelf_runs', 'svr_shelf_run_id', (int)$m['run_id']);
ShelfBroker::sign($shelf, (int)$m['run_id'], 'chain-20260801_010000/db', 'put', array('bytes' => 5));
$put_raw($base . 'manager/chain-20260801_010000/db', 'xxxxx');
ShelfBroker::finishRun($shelf, (int)$m['run_id'], array(array('name' => 'chain-20260801_010000/db', 'bytes' => 5)));
$watch->watch($shelf, '2026-09-21 00:10:00');
$keys = s3fx_keys($fx);
check(in_array('shelf/' . $base . 'site/chain-20260830_010000/db', $keys, true), 'an older chain with an open run is left alone');
check(in_array('shelf/' . $base . 'manager/chain-20260801_010000/db', $keys, true), 'the manager family is aged on its own');
check((string)(new ShelfRun((int)$open['run_id'], TRUE))->get('svr_state') === 'open', 'a young open run is not aborted');

section('shelf: an abandoned run is aborted; the listing corrects the ledger');
$db->exec("UPDATE svr_shelf_runs SET svr_create_time = now() - interval '48 hours' WHERE svr_shelf_run_id = " . (int)$open['run_id']);
$shelf->set('svt_reconciled_time', null);
$shelf->save();
// Something on the shelf the ledger never saw, and a ledger row for something gone.
$put_raw($base . 'site/chain-20260920_010000/stray', str_repeat('s', 7));
$db->exec("UPDATE svo_shelf_objects SET svo_key = '" . $base . "site/chain-20260920_010000/vanished' WHERE svo_key = '" . $base . "site/chain-20260910_010000/db'");
$watch->watch($shelf, '2026-09-22 00:00:00');
$shelf = ServiceTenant::forKey($key_id, 'shelf');
check((string)(new ShelfRun((int)$open['run_id'], TRUE))->get('svr_state') === 'aborted', 'a run open 48 hours is aborted');
check(ShelfObject::forKey((int)$shelf->key, $base . 'site/chain-20260920_010000/vanished') === null, 'a ledger row the shelf does not have is dropped');
$stray = ShelfObject::forKey((int)$shelf->key, $base . 'site/chain-20260920_010000/stray');
check($stray !== null && (int)$stray->get('svo_bytes') === 7 && $stray->get('svo_completed_time') !== null
	&& (string)$stray->get('svo_chain') === 'chain-20260920_010000', 'an object the ledger did not have is adopted at its listed size');
// db of 0910 was renamed away in the ledger and is on the shelf → adopted back (10); the aborted run's db (1) adopted too.
check((int)$shelf->get('svt_figure') === ShelfObject::completedBytes((int)$shelf->key), 'the figure is the reconciled ledger');

section('shelf: the ladder suspends, the day comes, the prefix is pruned once');
$watch->watch($shelf, '2026-10-02 00:00:00');
$watch->watch($shelf, '2026-10-17 00:00:00');
$shelf = ServiceTenant::forKey($key_id, 'shelf');
check((string)$shelf->get('svt_state') === 'suspended' && ShelfBroker::refusal($shelf) !== ''
	&& substr((string)$shelf->get('svt_prune_after_time'), 0, 10) === '2027-01-15', 'suspended past grace; prune-after is 90 days out');
$before = count(s3fx_keys($fx));
$watch->watch($shelf, '2026-12-01 00:00:00');
check(count(s3fx_keys($fx)) === $before && ServiceTenant::forKey($key_id, 'shelf')->get('svt_pruned_time') === null, 'before its day nothing is pruned');
$shelf = ServiceTenant::forKey($key_id, 'shelf');
$shelf->set('svt_paid_until', '2027-06-01 00:00:00');
$shelf->save();
$watch->watch($shelf, '2026-12-02 00:00:00');
$shelf = ServiceTenant::forKey($key_id, 'shelf');
check((string)$shelf->get('svt_state') === 'active' && $shelf->get('svt_prune_after_time') === null && ShelfBroker::refusal($shelf) === '',
	'a new date before the prune reactivates in place, clock cleared');
$shelf->set('svt_paid_until', '2026-01-01 00:00:00');
$shelf->set('svt_lapse_time', '2026-01-02 00:00:00');
$shelf->save();
$watch->watch($shelf, '2026-02-01 00:00:00');
$shelf = ServiceTenant::forKey($key_id, 'shelf');
$watch->watch($shelf, (string)$shelf->get('svt_prune_after_time'));
$watch->watch($shelf, LibraryFunctions::time_shift((string)$shelf->get('svt_prune_after_time'), '1 second', 'Y-m-d H:i:s'));
$shelf = ServiceTenant::forKey($key_id, 'shelf');
$left = array_filter(s3fx_keys($fx), function ($k) use ($base) { return strpos($k, 'shelf/' . $base) === 0; });
check($left === array(), 'on its day the whole prefix is gone', json_encode(array_values($left)));
check($shelf->get('svt_pruned_time') !== null && (int)$shelf->get('svt_figure') === 0
	&& ShelfObject::completedBytes((int)$shelf->key) === 0 && strpos((string)$shelf->get('svt_notice'), 'pruned on') !== false,
	'the row says it was pruned, the ledger is empty, the figure is 0');
$watch->watch($shelf, '2027-06-01 00:00:00');
check(ServiceTenant::forKey($key_id, 'shelf')->get('svt_pruned_time') !== null, 'pruning happens once');

section('the phase runs inside the provisioning task');
$reply(array(array('sent' => 1), array('sent' => 1), array('sent' => 1)));
$out = $watch->run(array());
check(in_array($out['status'], array('success', 'error'), true) && strpos($out['message'], 'Service tenants:') === 0,
	'run() reports the rows it walked', $out['message']);
$src = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/tasks/ServerManagerAdvanceProvisioning.php'));
check(strpos($src, "'Services'") !== false && strpos($src, 'ServiceTenantWatch') !== false, 'ServerManagerAdvanceProvisioning names the phase, last');

$db->exec("DELETE FROM svo_shelf_objects WHERE svo_svt_service_tenant_id = " . (int)$shelf->key);
harness_finish();

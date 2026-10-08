<?php
/** @joinery-test
 * name: destination_switch
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * A site switching backup targets (specs/storage_targets.md R2, R3, WP3), over
 * the loopback S3 fixture with two buckets standing in for two providers:
 *
 *   - every run records where it went (bkh_destination, the target's id)
 *   - a run on the same target extends the chain; the first run on another
 *     target starts a full chain there (destination_changed), and switching
 *     back starts another, never extending the old one
 *   - the new target is given the current epoch's envelope, so the offloaded
 *     files stored there open from there alone
 *   - a run is verified where it went, not on the target configured now
 *   - retention deletes each pruned chain from its own bucket, whichever
 *     target is current, and never touches the other bucket's kept chains
 *
 * Run: php tests/backups/destination_switch_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/s3_fixtures.php');
require_once(__DIR__ . '/../lib/cloud_fixtures.php');

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('destination switch', 'could not start a local PHP HTTP server on 127.0.0.1');
	harness_finish();
}
harness_defer(function() use ($fx) { s3fx_stop($fx); });

// ── A throwaway site: a tree with one offloaded file, a tiny database ──
$work = sys_get_temp_dir() . '/jy_dest_switch_' . getmypid();
$tree = $work . '/site';
$up   = $tree . '/static_files/uploads';
$out  = $work . '/backups';
@mkdir($tree . '/public_html', 0755, true);
@mkdir($up, 0755, true);
@mkdir($tree . '/config', 0755, true);
@mkdir($out, 0700, true);
file_put_contents($tree . '/public_html/index.php', '<?php echo "hello";');
file_put_contents($tree . '/config/Globalvars_site.php', "<?php\n\$this->settings['deployment_environment'] = 'docker';\n");
harness_defer(function() use ($work) {
	exec('chmod -R u+rwX ' . escapeshellarg($work) . ' 2>/dev/null; rm -rf ' . escapeshellarg($work));
});

$dbname = 'jy_dest_switch_' . getmypid();
$pdo = DbConnector::get_instance()->get_db_link();
$pdo->exec('CREATE DATABASE "' . $dbname . '" TEMPLATE template0');
harness_defer(function() use ($pdo, $dbname) { $pdo->exec('DROP DATABASE IF EXISTS "' . $dbname . '"'); });
$scratch = new PDO('pgsql:host=localhost dbname=' . $dbname, 'postgres', (string)Globalvars::get_instance()->get_setting('dbpassword', true, true));
$scratch->exec('CREATE TABLE t (id int); INSERT INTO t VALUES (1)');
$scratch = null;
putenv('PGPASSWORD=' . (string)Globalvars::get_instance()->get_setting('dbpassword', true, true));

$store = new InMemoryBlobDriver();
$a_bytes = random_bytes(5000);
file_put_contents($up . '/a.jpg', $a_bytes);
$store->objects['a.jpg'] = $a_bytes;
$blobs = array(array('id' => -9101, 'name' => 'a.jpg', 'original' => $up . '/a.jpg', 'paths' => array($up . '/a.jpg'),
	'remote_key' => 'a.jpg', 'content_type' => 'application/octet-stream', 'visibility' => 'private'));
BackupObjects::$test_hooks = array(
	'enumerator' => function () use ($blobs) { return $blobs; },
	'catchup'    => $store,
);
BackupProfile::$enabled_for_tests = array('site');
harness_defer(function () { BackupObjects::$test_hooks = array(); BackupProfile::$enabled_for_tests = null; });

// ── Two targets, one bucket each, on the same loopback provider ──
$suffix = bin2hex(random_bytes(3));
$make_target = function ($label, $bucket) use ($fx, $suffix) {
	$t = new BackupTarget(NULL);
	$t->set('bkt_name', 'HarnessTest Switch ' . $label . ' ' . $suffix);
	$t->set('bkt_provider', 'generic');
	$t->set('bkt_bucket', $bucket);
	$t->set('bkt_path_prefix', 'joinery-backups');
	$t->set('bkt_credentials', s3fx_creds($fx));
	$t->set('bkt_enabled', true);
	$t->save();
	harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $t->key);
	return new BackupTarget($t->key, TRUE);
};
$A = $make_target('A', 'bkt-a');
$B = $make_target('B', 'bkt-b');

// The site's own plan, aimed at the fixture: the manager plan's shape (it takes
// its credential as given), re-labelled as this site's profile with a real
// target row, listing and pruning its own backup storage as a site does.
$slug = 'dest-switch-' . getmypid();
$base_plan = BackupRunner::plan(array('profile' => 'manager', 'manager' => array(
	'bucket' => 'bkt-a', 'credentials' => s3fx_creds($fx), 'slug' => $slug,
	'type' => 'project', 'mode' => 'chain', 'delete_local_after_upload' => 0, 'keep_local_days' => 0,
)));
$base_plan = array_merge($base_plan, array(
	'profile' => 'site', 'destination' => 'target', 'target' => $A,
	'base_dir' => $out, 'output_dir' => $out . '/site', 'project' => 'site', 'project_dir' => $tree,
	'database' => $dbname, 'objects' => true, 'objects_source' => 'listing', 'prunes_cloud' => true,
	'keep_days' => 365, 'full_days' => 0,
));
$plan_on = function (BackupTarget $t) use (&$base_plan) {
	return BackupRunner::plan_for_target($base_plan, $t);
};

$execute_chain = new ReflectionMethod('BackupRunner', 'execute_chain');
$execute_chain->setAccessible(true);
// What BackupRunner::run() records before the engine starts.
$run = function (array $p) use ($execute_chain) {
	$history = new BackupHistory(NULL);
	$history->set('bkh_type', $p['type']);
	$history->set('bkh_outcome', 'running');
	$history->set('bkh_slug', $p['slug']);
	$history->set('bkh_profile', $p['profile']);
	$history->set('bkh_recovery_fpr', $p['recovery_fpr']);
	$history->set('bkh_encrypted', true);
	$history->set('bkh_destination', $p['destination']);
	$history->set('bkh_bkt_backup_target_id', $p['target']->key);
	$history->set('bkh_target_name', $p['target']->get('bkt_name'));
	$history->save();
	harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $history->key);
	$error = null;
	try {
		$result = $execute_chain->invoke(null, $p, $history);
	} catch (\Throwable $e) {
		$result = null;
		$error = $e->getMessage();
	}
	return array($result, $error, new BackupHistory($history->key, TRUE));
};
$keys_in = function ($bucket) use ($fx) {
	$out = array();
	foreach (s3fx_keys($fx) as $k) {
		if (strpos($k, $bucket . '/') === 0) { $out[] = substr($k, strlen($bucket) + 1); }
	}
	return $out;
};
$chain_keys = function ($bucket, $chain_id) use ($keys_in) {
	return array_values(array_filter($keys_in($bucket), function ($k) use ($chain_id) { return strpos($k, '/' . $chain_id . '/') !== false; }));
};
$base = 'joinery-backups/' . $slug . '/site/';

// ─────────────────────────────────────────────────────────────────────────
section('Runs on one target extend one chain, and record where they went');

list($r1, $e1, $h1) = $run($plan_on($A));
check($e1 === null && ($r1['status'] ?? '') === 'success', 'the first run on A succeeds', (string)$e1);
check($h1->get('bkh_destination') === 'target' && (int)$h1->get('bkh_bkt_backup_target_id') === (int)$A->key,
	'its row says it went to A');
$c1 = (string)$h1->get('bkh_chain_id');
list($r2, $e2, $h2) = $run($plan_on($A));
check($e2 === null && (string)$h2->get('bkh_chain_id') === $c1 && (int)$h2->get('bkh_chain_seq') === 1,
	'a second run on A extends the same chain', (string)$e2 . ' ' . $h2->get('bkh_message'));
$epoch = BackupObjects::read_epoch($base_plan);
check($epoch && in_array($base . 'objects/' . $epoch['id'] . '/envelope.json', $keys_in('bkt-a'), true)
	&& in_array($base . 'objects/' . $epoch['id'] . '/a.jpg.enc', $keys_in('bkt-a'), true),
	'A holds the offloaded file and its epoch\'s envelope');

// ─────────────────────────────────────────────────────────────────────────
section('The first run on another target starts a full chain there');

$a_before = $keys_in('bkt-a');
sleep(1);   // a chain id is to the second; a new chain in the same second would share one
list($r3, $e3, $h3) = $run($plan_on($B));
$c2 = (string)$h3->get('bkh_chain_id');
check($e3 === null && $c2 !== '' && $c2 !== $c1 && (int)$h3->get('bkh_chain_seq') === 0,
	'the run on B starts a new chain', (string)$e3);
check(strpos((string)$h3->get('bkh_message'), 'destination_changed') !== false, 'and says the destination changed', (string)$h3->get('bkh_message'));
check((int)$h3->get('bkh_bkt_backup_target_id') === (int)$B->key, 'its row says it went to B');
check($chain_keys('bkt-b', $c2) && !$chain_keys('bkt-b', $c1), 'its archives are in B, and none of A\'s chain is');
check($keys_in('bkt-a') === $a_before, 'nothing in A changed');
check(in_array($base . 'objects/' . $epoch['id'] . '/envelope.json', $keys_in('bkt-b'), true)
	&& in_array($base . 'objects/' . $epoch['id'] . '/a.jpg.enc', $keys_in('bkt-b'), true),
	'B is given the offloaded file and the envelope of the epoch it is sealed under', implode(', ', $keys_in('bkt-b')));

section('A run is read where it went');
try {
	$req = BackupVerifyLauncher::request($h1, 2);
	check(strpos((string)$req['manifest_url'], '/bkt-a/') !== false, 'verifying the first run signs links into A, not the current B',
		(string)$req['manifest_url']);
} catch (\Throwable $e) {
	check(false, 'verifying the first run signs links into A, not the current B', $e->getMessage());
}

section('Switching back starts another chain rather than extending the old one');
sleep(1);
list($r4, $e4, $h4) = $run($plan_on($A));
$c3 = (string)$h4->get('bkh_chain_id');
check($e4 === null && $c3 !== $c1 && $c3 !== $c2 && (int)$h4->get('bkh_chain_seq') === 0, 'back on A, a new chain', (string)$e4);
sleep(1);
list($r5, $e5, $h5) = $run($plan_on($B));
$c4 = (string)$h5->get('bkh_chain_id');
check($e5 === null && $c4 !== $c2 && (int)$h5->get('bkh_chain_seq') === 0, 'and back on B, another', (string)$e5);

// ─────────────────────────────────────────────────────────────────────────
section('Retention deletes each chain from its own bucket');

$age = function ($chain_id, $days) use ($pdo, $slug) {
	$pdo->prepare("UPDATE bkh_backup_history SET bkh_start_time = now() - (? || ' days')::interval
		WHERE bkh_slug = ? AND bkh_chain_id = ?")->execute(array((string)$days, $slug, $chain_id));
};
$age($c1, 50); $age($c2, 40); $age($c3, 30);
$prune_plan = $plan_on($B);
$prune_plan['keep_days'] = 1;
$pruned_indexes = array();
$pruned = BackupRunner::enforce_chain_retention($prune_plan, $pruned_indexes);
$objects_pruned = BackupRunner::enforce_object_retention($prune_plan, $pruned_indexes);

check($pruned === 2, 'the two chains older than the one that covers the window are pruned', 'pruned ' . $pruned);
check(!$chain_keys('bkt-a', $c1), 'A\'s old chain is gone from A, with B the current target', implode(', ', $chain_keys('bkt-a', $c1)));
check(!$chain_keys('bkt-b', $c2), 'B\'s old chain is gone from B');
check((bool)$chain_keys('bkt-a', $c3) && (bool)$chain_keys('bkt-b', $c4), 'the kept chains are untouched in both');
$h1 = new BackupHistory($h1->key, TRUE);
check($h1->get('bkh_pruned_time') !== null, 'the pruned runs are recorded as pruned');
check($objects_pruned === 0 && in_array($base . 'objects/' . $epoch['id'] . '/a.jpg.enc', $keys_in('bkt-a'), true)
	&& in_array($base . 'objects/' . $epoch['id'] . '/a.jpg.enc', $keys_in('bkt-b'), true),
	'the offloaded file stays in each bucket while a kept run there names it', 'removed ' . $objects_pruned);

harness_finish();

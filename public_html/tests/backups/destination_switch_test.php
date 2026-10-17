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
 *   - a profile that reads what is held from an index and held.json (the
 *     manager profile) stores its offloaded files again on the new
 *     destination: held.json names the destination it describes, and one that
 *     names another, or none, is not read as this one's
 *   - an object backup storage was proven not to hold (the management node's
 *     complete listing of the same destination) is not held: the next run
 *     stores exactly those again, under a new epoch rather than the lost key;
 *     the backup storage check then passes for the newest restore point and
 *     still names the earlier ones that cannot bring it back; a listing of another
 *     destination proves nothing; one the site has no copy of stays named,
 *     and the check keeps saying so
 *
 * Run: php tests/backups/destination_switch_test.php
 *
 * @version 1.3 - objects proven missing are stored again; a stale listing and a lost source are told apart
 * @version 1.2 - held.json is a picture of one destination: a manager-profile run on a new one stores the offloaded file there
 * @version 1.1 - retention deletes on the second pass that finds a chain surplus, a day after the first
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
$first = BackupRunner::enforce_chain_retention($prune_plan, $pruned_indexes);
check($first === 0 && $chain_keys('bkt-a', $c1) && $chain_keys('bkt-b', $c2),
	'the first pass that finds them surplus deletes nothing (it confirms a day later)', 'pruned ' . $first);
// The next pass, a day on.
$pruned_indexes = array();
$pruned = BackupRunner::enforce_chain_retention($prune_plan, $pruned_indexes, time() + 21 * 3600);
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

// ─────────────────────────────────────────────────────────────────────────
section('A picture of what one destination holds is not read as another\'s');

// The manager profile's shape: what is held comes from the newest index by
// link, union held.json; no listing. With no index link on the new
// destination, held.json is the only picture there is.
$mslug = 'dest-switch-m-' . getmypid();
$mbase_plan = array_merge($base_plan, array(
	'profile' => 'manager', 'destination' => 'service', 'slug' => $mslug,
	'output_dir' => $out . '/manager', 'objects_source' => 'index', 'objects_index_url' => '',
	'prunes_cloud' => false,
));
$mbase = 'joinery-backups/' . $mslug . '/manager/';
$m_on = function (BackupTarget $t) use (&$mbase_plan) {
	return BackupRunner::plan_for_target($mbase_plan, $t);
};
$held_json = function () use ($out) {
	return json_decode((string)@file_get_contents($out . '/manager/objects/held.json'), true);
};

list($m1, $em1, $hm1) = $run($m_on($A));
$mepoch = BackupObjects::read_epoch($mbase_plan);
check($em1 === null && $mepoch && in_array($mbase . 'objects/' . $mepoch['id'] . '/a.jpg.enc', $keys_in('bkt-a'), true),
	'a manager-profile run on A stores the offloaded file in A', (string)$em1);
check(($held_json()['destination'] ?? null) === BackupRunner::destination_of_plan($m_on($A)) && isset($held_json()['objects']['a.jpg']),
	'its held.json names A as the destination it describes', json_encode($held_json()));
check(BackupObjects::read_held($m_on($B)) === null, 'and is no picture of B');

sleep(1);
list($m2, $em2, $hm2) = $run($m_on($B));
check($em2 === null, 'the run on B succeeds', (string)$em2);
check(in_array($mbase . 'objects/' . $mepoch['id'] . '/a.jpg.enc', $keys_in('bkt-b'), true),
	'and stores the offloaded file in B, though A\'s held.json named it', implode(', ', $keys_in('bkt-b')));
check(($held_json()['destination'] ?? null) === BackupRunner::destination_of_plan($m_on($B)) && isset($held_json()['objects']['a.jpg']),
	'held.json now describes B', json_encode($held_json()));

// A held.json written before files named their destination is no picture at all.
$legacy = $held_json();
unset($legacy['destination']);
file_put_contents($out . '/manager/objects/held.json', json_encode($legacy));
check(BackupObjects::read_held($m_on($B)) === null, 'a held.json that names no destination is not read as B\'s');
BackupObjects::held_add($m_on($B), 'tick.jpg', array('epoch' => $mepoch['id'], 'bytes' => 1, 'sha256' => str_repeat('5', 64)));
check(array_keys((array)BackupObjects::read_held($m_on($B))) === array('tick.jpg'),
	'and an entry added on B starts B\'s picture afresh, keeping none of it', json_encode($held_json()));

// ─────────────────────────────────────────────────────────────────────────
section('An object backup storage was proven not to hold is stored again');

$drop = function ($bucket, $key) use ($fx) {
	$f = s3fx_object_file($fx['dir'], $bucket, $key);
	@unlink($f); @unlink($f . '.key');
};
$check_b = function () use ($fx, $mbase) {
	$listing = S3Signer::list(s3fx_creds($fx), 'bkt-b', $mbase);
	return FleetBackupRetention::check_shelf((array)$listing, $mbase, s3fx_creds($fx), 'bkt-b');
};
$newest_index_b = function () use ($fx, $mbase, $keys_in) {
	$idx = array_values(array_filter($keys_in('bkt-b'), function ($k) use ($mbase) {
		return strpos($k, $mbase . 'chain-') === 0 && preg_match('#/objects-\d{4}\.json\.gz$#', $k);
	}));
	usort($idx, function ($a, $b) { return strcmp(basename(dirname($a)) . basename($a), basename(dirname($b)) . basename($b)); });
	$by = array();
	foreach ((array)(BackupObjects::decode_index((string)s3fx_object($fx, 'bkt-b', '/' . end($idx)))['objects'] ?? array()) as $e) {
		$by[$e['name']] = $e;
	}
	return $by;
};

// A clean picture of B first: held.json names a.jpg where B holds it.
@unlink($out . '/manager/objects/held.json');
sleep(1);
list(, $em3) = $run($m_on($B));
$lost_key = $mbase . 'objects/' . $mepoch['id'] . '/a.jpg.enc';
check($em3 === null && in_array($lost_key, $keys_in('bkt-b'), true) && isset(BackupObjects::read_held($m_on($B))['a.jpg']),
	'B holds a.jpg, and held.json says so', (string)$em3);

$drop('bkt-b', $lost_key);
$r = $check_b();
check(strpos($r['problem'], 'names 1 offloaded file its backup storage does not hold (a.jpg)') !== false
	&& $r['missing'] === array($mepoch['id'] . '/a.jpg'),
	'the backup storage check finds it gone and names its location', json_encode($r));

section('A listing of another destination proves nothing here');
$stale = $m_on($B);
$stale['objects_missing'] = BackupObjects::missing_from_request(array('space' => 999999, 'locations' => $r['missing']));
check(BackupObjects::missing_locations($stale) === array(), 'locations proven on another space are not applied to this run');
$puts_before = count(s3fx_put_keys($fx));
list(, $es) = $run($stale);
$enc_puts = array_filter(array_slice(s3fx_put_keys($fx), $puts_before), function ($k) { return substr($k, -4) === '.enc' && strpos($k, '/objects/') !== false; });
check($es === null && !$enc_puts, 'so that run stores no object again', implode(', ', $enc_puts));
check(strpos($check_b()['problem'], 'does not hold (a.jpg)') !== false, 'and the check still finds a.jpg missing');

section('The next run stores exactly those, and the check then passes');
$proven = $m_on($B);
$proven['objects_missing'] = array('destination' => BackupRunner::destination_of_plan($proven), 'locations' => $r['missing']);
$puts_before = count(s3fx_put_keys($fx));
list(, $ep) = $run($proven);
$enc_puts = array_values(array_filter(array_slice(s3fx_put_keys($fx), $puts_before), function ($k) { return substr($k, -4) === '.enc' && strpos($k, '/objects/') !== false; }));
$new_epoch = BackupObjects::read_epoch($mbase_plan);
check($ep === null && $new_epoch && $new_epoch['id'] !== $mepoch['id'], 'the run succeeds, under a new epoch: the one that lost an object is retired', (string)$ep);
check($enc_puts === array('bkt-b/' . $mbase . 'objects/' . $new_epoch['id'] . '/a.jpg.enc'),
	'exactly the missing object is stored again, from the site\'s copy', json_encode($enc_puts));
check(!in_array($lost_key, $keys_in('bkt-b'), true),
	'and never to the lost key, whose earlier backup sets recorded another ciphertext');
$by = $newest_index_b();
check(($by['a.jpg']['epoch'] ?? '') === $new_epoch['id'] && !empty($by['a.jpg']['stored']), 'the run\'s index names it where it now is', json_encode($by['a.jpg'] ?? null));
$r2 = $check_b();
check(strpos($r2['problem'], 'does not hold (a.jpg) in 3 of its 4 restore points') !== false && $r2['missing'] === $r['missing'],
	'the backup storage check then passes for the newest restore point, and still names the three earlier ones that cannot bring a.jpg back', json_encode($r2));
check(BackupObjects::epoch_decision(array('id' => 'e1', 'envelope' => array('recipients' => array(array('kind' => 'site')))), '', true, array('e1')) === 'objects_missing',
	'epoch_decision(): an epoch backup storage lost an object from is retired');

section('An object the site no longer has a copy of stays named');
$r_key = $mbase . 'objects/' . $new_epoch['id'] . '/a.jpg.enc';
$drop('bkt-b', $r_key);
$saved_local = file_get_contents($up . '/a.jpg');
$saved_store = $store->objects['a.jpg'];
unlink($up . '/a.jpg');
unset($store->objects['a.jpg']);
$r3 = $check_b();
check(in_array($new_epoch['id'] . '/a.jpg', $r3['missing'], true) && strpos($r3['problem'], 'in all 4 of its restore points') !== false,
	'the check finds the re-stored copy gone too, and no restore point whole', json_encode($r3));
$gone = $m_on($B);
$gone['objects_missing'] = array('destination' => BackupRunner::destination_of_plan($gone), 'locations' => $r3['missing']);
list($rg, $eg) = $run($gone);
check($eg === null && strpos((string)($rg['message'] ?? ''), 'could not be read from the file store') !== false,
	'the run succeeds and says it could not read a.jpg from the file store', (string)$eg . ' ' . ($rg['message'] ?? ''));
$by = $newest_index_b();
check(($by['a.jpg']['epoch'] ?? '') === $new_epoch['id'] && !empty($by['a.jpg']['stored']),
	'its index still names a.jpg where backup storage lost it', json_encode($by['a.jpg'] ?? null));
check(strpos($check_b()['problem'], 'does not hold (a.jpg)') !== false, 'so the backup storage check keeps reporting it, never cleared');
check(!isset(BackupObjects::read_held($m_on($B))['a.jpg']), 'and held.json does not claim it');
file_put_contents($up . '/a.jpg', $saved_local);
$store->objects['a.jpg'] = $saved_store;

section('A request\'s list of missing locations is read strictly');
check(BackupObjects::missing_from_request(array('space' => 4, 'locations' => array('epoch-20260101_000000/a.jpg', 'epoch-20260101_000000/mailbox/12.eml')))
	=== array('destination' => 'space:4', 'locations' => array('epoch-20260101_000000/a.jpg', 'epoch-20260101_000000/mailbox/12.eml')), 'a readable list is carried with its space');
check(BackupObjects::missing_from_request(array('space' => 4, 'locations' => array('epoch-20260101_000000/a.jpg', '../x'))) === null
	&& BackupObjects::missing_from_request(array('space' => 0, 'locations' => array())) === null
	&& BackupObjects::missing_from_request('x') === null, 'one location that is not one, or no space, and none of it is read');

harness_finish();

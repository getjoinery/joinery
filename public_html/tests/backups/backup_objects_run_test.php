<?php
/** @joinery-test
 * name: backup_objects_run
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * A run that carries the site's offloaded files
 * (specs/implemented/backup_offloaded_files.md), against a local provider, a throwaway
 * tree and a throwaway database:
 *
 *   - the first run stores every cloud blob backup storage lacks — local originals
 *     and one fetched from the file store (catch-up) — under one epoch whose
 *     envelope the site key opens, writes the index as an artifact of the
 *     run (manifest, shelf, history), excludes every cloud blob's paths from
 *     the archive, records the held set, and releases the local bytes
 *   - a second run stores only what is new; a wiped held.json and a chain
 *     break re-store nothing (the listing and the newest index say what is
 *     held)
 *   - the store budget stops the step with at most one object's temporaries
 *   - a manager run reading an index by link, with no listing, stores only
 *     what the index lacks
 *   - a manager request without objects stores nothing and holds nothing
 *   - a database-only run carries no index
 *   - a standalone whole-site run carries an index named beside its archive
 *   - site retention deletes an object only when no retained index names it
 *
 * Run: php tests/backups/backup_objects_run_test.php
 *
 * @version 1.1 - one file store: an object's visibility reads private
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/s3_fixtures.php');
require_once(__DIR__ . '/../lib/cloud_fixtures.php');

require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('objects run', 'could not start a local PHP HTTP server on 127.0.0.1');
	harness_finish();
}
harness_defer(function() use ($fx) { s3fx_stop($fx); });

// ── A throwaway site with an uploads tree, a tiny database, a scratch backup dir ──
$work = sys_get_temp_dir() . '/jy_objects_run_' . getmypid();
$tree = $work . '/site';
$up   = $tree . '/static_files/uploads';
$out  = $work . '/backups';
@mkdir($tree . '/public_html', 0755, true);
@mkdir($up . '/thumb', 0755, true);
@mkdir($tree . '/uploads', 0755, true);
@mkdir($out, 0700, true);
file_put_contents($tree . '/public_html/index.php', '<?php echo "hello";');
file_put_contents($tree . '/public_html/kept.txt', 'a plain file that stays in the archive');
// A tree that says it is a container, so backup_project.sh skips the vhost lookup.
@mkdir($tree . '/config', 0755, true);
file_put_contents($tree . '/config/Globalvars_site.php', "<?php\n\$this->settings['deployment_environment'] = 'docker';\n"
	. "\$this->settings['dbusername'] = 'postgres';\n\$this->settings['dbname'] = '" . 'jy_objects_' . getmypid() . "';\n");
harness_defer(function() use ($work) {
	exec('chmod -R u+rwX ' . escapeshellarg($work) . ' 2>/dev/null; rm -rf ' . escapeshellarg($work));
});

$dbname = 'jy_objects_' . getmypid();
$pdo = DbConnector::get_instance()->get_db_link();
$pdo->exec('CREATE DATABASE "' . $dbname . '" TEMPLATE template0');
harness_defer(function() use ($pdo, $dbname) { $pdo->exec('DROP DATABASE IF EXISTS "' . $dbname . '"'); });
$scratch = new PDO('pgsql:host=localhost dbname=' . $dbname, 'postgres', (string)Globalvars::get_instance()->get_setting('dbpassword', true, true));
$scratch->exec('CREATE TABLE t (id int); INSERT INTO t VALUES (1)');
$scratch = null;
putenv('PGPASSWORD=' . (string)Globalvars::get_instance()->get_setting('dbpassword', true, true));

// The offloaded blobs, as the storage profiles would describe them. Ids are
// negative so the per-row advisory locks collide with no real blob's. The
// file store (the fake bucket offload pushed to) holds every blob's bytes,
// as it would; catch-up fetches from it when no local copy is left.
$blobs = array();
$store = new InMemoryBlobDriver();
$blob = function ($id, $name, $bytes = null, $variants = array('thumb')) use (&$blobs, $up, $tree, $store) {
	$paths = array($up . '/' . $name);
	foreach ($variants as $v) { $paths[] = $up . '/' . $v . '/' . $name; }
	$paths[] = $tree . '/uploads/' . $name;
	if ($bytes !== null) {
		file_put_contents($up . '/' . $name, $bytes);
		foreach ($variants as $v) { file_put_contents($up . '/' . $v . '/' . $name, 'variant of ' . $name); }
		$store->objects[$name] = $bytes;
	}
	$blobs[$name] = array('id' => $id, 'name' => $name, 'original' => $up . '/' . $name, 'paths' => $paths,
		'remote_key' => $name, 'content_type' => 'application/octet-stream', 'visibility' => 'private');
};
$a_bytes = random_bytes(70000);
$blob(-9001, 'a.jpg', $a_bytes);
$blob(-9002, 'b.bin', random_bytes(3000), array());
$blob(-9003, 'c.pdf', null, array());           // offloaded before the store existed: no local bytes
$store->objects['c.pdf'] = 'catch-up bytes of c ' . random_bytes(500);

BackupObjects::$test_hooks = array(
	'enumerator' => function () use (&$blobs) { return array_values($blobs); },
	'catchup'    => $store,
);
BackupProfile::$enabled_for_tests = array('manager');
harness_defer(function () { BackupObjects::$test_hooks = array(); BackupProfile::$enabled_for_tests = null; });

$slug = 'objects-run-' . getmypid();
$make_plan = function ($slug, array $over = array()) use ($fx, $out, $tree, $dbname) {
	$plan = BackupRunner::plan(array('profile' => 'manager', 'manager' => array(
		'bucket' => 'bkt', 'credentials' => s3fx_creds($fx), 'slug' => $slug,
		'type' => 'project', 'mode' => 'chain', 'delete_local_after_upload' => 0, 'keep_local_days' => 0,
		'target_name' => 'local fixture',
	)));
	$plan['base_dir']    = $out;
	$plan['output_dir']  = $out . '/manager';
	$plan['project']     = 'site';
	$plan['project_dir'] = $tree;
	$plan['database']    = $dbname;
	// A manager plan in backup storage this test can list: the site profile's way of
	// reading backup storage, and its own pruning, on the fixture.
	$plan['objects']        = true;
	$plan['objects_source'] = 'listing';
	$plan['prunes_cloud']   = true;
	$plan['keep_cloud']     = 5;
	return array_merge($plan, $over);
};
$plan = $make_plan($slug);
$base = 'joinery-backups/' . $slug . '/manager/';

$execute_chain = new ReflectionMethod('BackupRunner', 'execute_chain');
$execute_chain->setAccessible(true);
$execute_full = new ReflectionMethod('BackupRunner', 'execute_full');
$execute_full->setAccessible(true);
$run = function (array $p, $full = false) use ($execute_chain, $execute_full) {
	$history = new BackupHistory(NULL);
	$history->set('bkh_type', $p['type']);
	$history->set('bkh_outcome', 'running');
	$history->set('bkh_slug', $p['slug']);
	$history->set('bkh_profile', $p['profile']);
	$history->set('bkh_recovery_fpr', $p['recovery_fpr']);
	$history->set('bkh_encrypted', true);
	$history->set('bkh_target_name', 'local fixture');
	$history->save();
	harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $history->key);
	$error = null;
	try {
		$result = ($full ? $execute_full : $execute_chain)->invoke(null, $p, $history);
	} catch (\Throwable $e) {
		$result = null;
		$error = $e->getMessage();
	}
	return array($result, $error, $history);
};
$shelf_index = function ($key) use ($fx, $work) {
	$bytes = s3fx_object($fx, 'bkt', '/' . $key);
	if ($bytes === null) { return null; }
	file_put_contents($work . '/idx.json.gz', $bytes);
	return BackupObjects::read_index_file($work . '/idx.json.gz');
};
$object_keys = function ($prefix) use ($fx) {
	$out = array();
	foreach (s3fx_keys($fx) as $k) { if (strpos($k, $prefix) === 0) { $out[] = substr($k, strlen($prefix)); } }
	sort($out);
	return $out;
};
$list_archive = function ($bytes, $data_key) use ($work) {
	$enc = $work . '/dl.enc'; $keyf = $work . '/dl.key';
	file_put_contents($enc, $bytes);
	file_put_contents($keyf, $data_key);
	exec('( openssl enc -d -aes-256-cbc -pbkdf2 -pass fd:3 -in ' . escapeshellarg($enc) . ' 3< ' . escapeshellarg($keyf) . ' | tar -tz ) 2>&1', $lines, $rc);
	@unlink($enc); @unlink($keyf);
	return array($rc, $lines);
};
$chain_dir_of = function ($plan) {
	$dirs = glob($plan['output_dir'] . '/' . BackupChain::DIR_PREFIX . '*', GLOB_ONLYDIR) ?: array();
	usort($dirs, function ($a, $b) { return strcmp($b, $a); });
	return $dirs[0] ?? '';
};

// ─────────────────────────────────────────────────────────────────────────────
section('The first run stores every cloud blob backup storage lacks and indexes them');

list($result, $error, $history) = $run($plan);
check($error === null && ($result['status'] ?? '') === 'success', 'the run succeeds', (string)$error . ' ' . json_encode($result));
$chain_d = $chain_dir_of($plan);
$chain_id = basename($chain_d);
$manifest = BackupChain::read($chain_d . '/manifest.json');
$run0 = $manifest['runs'][0];

$objects_on_shelf = $object_keys('bkt/' . $base . 'objects/');
$epochs = array_values(array_unique(array_map(function ($k) { return explode('/', $k)[0]; }, $objects_on_shelf)));
check(count($epochs) === 1 && preg_match('/^epoch-\d{8}_\d{6}$/', $epochs[0]), 'everything went under one epoch', json_encode($epochs));
$epoch = $epochs[0] ?? '';
check($objects_on_shelf === array($epoch . '/a.jpg.enc', $epoch . '/b.bin.enc', $epoch . '/c.pdf.enc', $epoch . '/envelope.json'),
	'backup storage holds the three objects and the epoch envelope', json_encode($objects_on_shelf));

$epoch_file = json_decode(file_get_contents($plan['output_dir'] . '/objects/epoch.json'), true);
check(($epoch_file['id'] ?? '') === $epoch, 'epoch.json records the epoch', json_encode($epoch_file['id'] ?? null));
$env_shelf = json_decode((string)s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/envelope.json'), true);
check(is_array($env_shelf) && ($env_shelf['artifact'] ?? '') === $epoch && $env_shelf === $epoch_file['envelope'],
	'the envelope in backup storage is the epoch\'s, artifact = epoch id, and epoch.json holds a copy');
$epoch_key = BackupEnvelope::open_as_site($env_shelf);
$kinds = array_map(function ($r) { return $r['kind']; }, $env_shelf['recipients']);
check($kinds === array('recovery', 'site'), 'sealed to recovery and site, like a chain', json_encode($kinds));

$a_enc = s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/a.jpg.enc');
file_put_contents($work . '/a.enc', $a_enc);
BackupObjects::decrypt_file($work . '/a.enc', $work . '/a.plain', $epoch_key);
check(file_get_contents($work . '/a.plain') === $a_bytes, 'a stored object decrypts with the epoch key to the original');
$c_enc = s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/c.pdf.enc');
file_put_contents($work . '/c.enc', $c_enc);
BackupObjects::decrypt_file($work . '/c.enc', $work . '/c.plain', $epoch_key);
check(file_get_contents($work . '/c.plain') === $store->objects['c.pdf'], 'the catch-up object was fetched from the file store and stored');

$idx_art = $run0['artifacts']['objects'] ?? null;
check($idx_art !== null && $idx_art['name'] === 'objects-0000.json.gz', 'the manifest carries the objects index', json_encode($idx_art));
$idx_bytes = s3fx_object($fx, 'bkt', '/' . $base . $chain_id . '/objects-0000.json.gz');
check($idx_bytes !== null && strlen($idx_bytes) === (int)$idx_art['bytes'] && hash('sha256', $idx_bytes) === $idx_art['sha256'],
	'the index is in backup storage with the manifest\'s bytes and hash');
$index = $shelf_index($base . $chain_id . '/objects-0000.json.gz');
$by = array(); foreach ($index['objects'] as $e) { $by[$e['name']] = $e; }
check(count($by) === 3 && $by['a.jpg']['stored'] && $by['b.bin']['stored'] && $by['c.pdf']['stored'], 'the index marks all three stored');
check($by['a.jpg']['object_bytes'] === strlen($a_enc) && $by['a.jpg']['object_sha256'] === hash('sha256', $a_enc), 'with the encrypted size and hash');
check($index['epochs'] === array($epoch) && $index['run'] === $chain_id . '/0' && $index['profile'] === 'manager', 'epochs, run and profile');
$plan_r = BackupChain::restore_plan($manifest, 0);
check(($plan_r['objects']['name'] ?? '') === 'objects-0000.json.gz', 'restore_plan() returns it');

$hist_kinds = array_map(function ($a) { return $a['kind']; }, $history->artifacts());
check(in_array('objects', $hist_kinds, true), 'the history row records the index artifact', json_encode($hist_kinds));
$hist_obj = null; foreach ($history->artifacts() as $a) { if ($a['kind'] === 'objects') { $hist_obj = $a; } }
check(($hist_obj['key'] ?? '') === $base . $chain_id . '/objects-0000.json.gz', 'with its bucket key');
check(strpos((string)$history->get('bkh_message'), '3 of 3 offloaded files in backup storage, 3 copied this run') !== false,
	'the history message counts the objects', (string)$history->get('bkh_message'));
check(strpos((string)$result['message'], 'copied 3 offloaded files') !== false && strpos((string)$result['message'], 'released 2 local cop') !== false,
	'the task message says what was copied and released', $result['message']);

$chain_key = BackupEnvelope::open_as_site($manifest['envelope']);
$files0 = s3fx_object($fx, 'bkt', '/' . $base . $chain_id . '/files-0000.tar.gz.enc');
list($rc, $members) = $list_archive($files0, $chain_key);
check($rc === 0 && in_array('site/public_html/kept.txt', $members, true), 'the archive carries the plain tree', 'rc ' . $rc);
$leaked = array_values(array_filter($members, function ($m) { return strpos($m, 'uploads/') !== false && preg_match('#/(a\.jpg|b\.bin|c\.pdf)$#', $m); }));
check($leaked === array(), 'and none of the cloud blobs\' paths — original or variant', json_encode($leaked));
check(in_array('site/static_files/uploads/thumb/', $members, true) || in_array('site/static_files/uploads/', $members, true), 'the uploads directories themselves are archived');

$held = BackupObjects::read_held($plan);
check(is_array($held) && array_keys($held) === array('a.jpg', 'b.bin', 'c.pdf'), 'held.json names the three', json_encode(array_keys((array)$held)));
check($held['a.jpg']['object_sha256'] === hash('sha256', $a_enc) && $held['a.jpg']['epoch'] === $epoch, 'with hash and epoch');
check(!is_file($up . '/a.jpg') && !is_file($up . '/thumb/a.jpg') && !is_file($up . '/b.bin'), 'the local bytes were released: the only enabled profile holds them');
check(!glob($plan['output_dir'] . '/objects/tmp/*'), 'no temporary is left in objects/tmp');
check(!glob($plan['output_dir'] . '/objects/.exclude-*'), 'the exclude file was removed');
$st = stat($plan['output_dir'] . '/objects');
check(($st['mode'] & 02775) === 02775, 'the objects directory is 2775', decoct($st['mode'] & 07777));

// ─────────────────────────────────────────────────────────────────────────────
section('A second run stores only what is new');

$blob(-9004, 'd.jpg', random_bytes(2000));
$a_enc_before = $a_enc;
list($result, $error, $history2) = $run($plan);
check($error === null && ($result['status'] ?? '') === 'success', 'the second run succeeds', (string)$error);
$objects_on_shelf = $object_keys('bkt/' . $base . 'objects/');
check($objects_on_shelf === array($epoch . '/a.jpg.enc', $epoch . '/b.bin.enc', $epoch . '/c.pdf.enc', $epoch . '/d.jpg.enc', $epoch . '/envelope.json'),
	'd.jpg joined the same epoch', json_encode($objects_on_shelf));
check(s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/a.jpg.enc') === $a_enc_before, 'a.jpg was not re-stored (its ciphertext is byte-identical)');
$index = $shelf_index($base . $chain_id . '/objects-0001.json.gz');
check(count($index['objects']) === 4, 'the second index names four');
check(strpos((string)$result['message'], 'copied 1 offloaded file ') !== false, 'the message says one copied', $result['message']);
check(!is_file($up . '/d.jpg'), 'd.jpg\'s local bytes were released');

// ─────────────────────────────────────────────────────────────────────────────
section('A wiped held.json and a chain break re-store nothing');

@unlink($plan['output_dir'] . '/objects/held.json');
list($result, $error) = $run($plan);
check($error === null, 'the run succeeds without held.json', (string)$error);
check(s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/a.jpg.enc') === $a_enc_before, 'nothing re-stored: the listing and the newest index say what is held');
$held = BackupObjects::read_held($plan);
check(is_array($held) && count($held) === 4 && $held['a.jpg']['object_sha256'] === hash('sha256', $a_enc_before), 'held.json is rebuilt with the hashes the index recorded');
$index = $shelf_index($base . $chain_id . '/objects-0002.json.gz');
$by = array(); foreach ($index['objects'] as $e) { $by[$e['name']] = $e; }
check($by['a.jpg']['object_sha256'] === hash('sha256', $a_enc_before), 'the third index still carries a.jpg\'s hash');

@unlink($plan['output_dir'] . '/.' . $slug . '.snar');
sleep(1);
list($result, $error) = $run($plan);
check($error === null && strpos((string)$result['message'], 'Full backup') === 0, 'a new chain starts after the snapshot is lost', (string)$error . ' ' . ($result['message'] ?? ''));
$chain2_d = $chain_dir_of($plan);
check($chain2_d !== $chain_d, 'in a new directory');
check(s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/a.jpg.enc') === $a_enc_before, 'the new chain re-copies nothing');
$index = $shelf_index($base . basename($chain2_d) . '/objects-0000.json.gz');
check(count($index['objects']) === 4 && $index['epochs'] === array($epoch), 'its index names the same four objects in the same epoch');

// ─────────────────────────────────────────────────────────────────────────────
section('The store budget stops the step, with at most one object\'s temporaries');

$blob(-9005, 'e1.bin', random_bytes(1500), array());
$blob(-9006, 'e2.bin', random_bytes(1500), array());
$blob(-9007, 'e3.bin', random_bytes(1500), array());
$epoch_fn = function () use ($plan) { return BackupObjects::epoch($plan); };
$held_now = BackupObjects::read_held($plan);
$r = BackupObjects::store_missing($plan, $epoch_fn, array_values($blobs), $held_now, 1, 600);
check(count($r['stored']) === 1 && $r['budget_hit'] === true, 'a one-byte budget stores one object and reports the budget', json_encode(array_keys($r['stored'])) . ' ' . json_encode($r['budget_hit']));
check(!glob($plan['output_dir'] . '/objects/tmp/*'), 'no temporary remains after the step');
$r2 = BackupObjects::store_missing($plan, $epoch_fn, array_values($blobs), $held_now, 1, 0);
check(count($r2['stored']) === 0 && $r2['budget_hit'] === true, 'a zero-second budget stores nothing', json_encode(array_keys($r2['stored'])));
$r3 = BackupObjects::store_missing($plan, $epoch_fn, array_values($blobs), $held_now + $r['stored'], 1 << 30, 600);
check(count($r3['stored']) === 2 && $r3['budget_hit'] === false, 'the next step takes the rest', json_encode(array_keys($r3['stored'])));
$r4 = BackupObjects::store_missing($plan, $epoch_fn, array_values($blobs), $held_now + $r['stored'] + $r3['stored'], 1 << 30, 600);
check($r4['attempted'] === 0, 'and nothing remains to store');
// Bring held.json up to date so the later runs see these as held.
BackupObjects::write_held($plan, $held_now + $r['stored'] + $r3['stored']);
foreach (array('e1.bin', 'e2.bin', 'e3.bin') as $n) { @unlink($up . '/' . $n); }

section('The run\'s store step waits on the tick\'s row lock');
$blob(-9008, 'locked.bin', random_bytes(100), array());
$other = new PDO('pgsql:host=localhost dbname=' . Globalvars::get_instance()->get_setting('dbname', true, true), 'postgres',
	(string)Globalvars::get_instance()->get_setting('dbpassword', true, true));
$other->query('SELECT pg_advisory_lock(' . CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE . ', -9008)');
$r = BackupObjects::store_object($plan, BackupObjects::epoch($plan), $blobs['locked.bin'], $up . '/locked.bin');
check($r === null, 'store_object() returns null while another session holds the row lock');
check(s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/locked.bin.enc') === null, 'and nothing was uploaded');
$other->query('SELECT pg_advisory_unlock(' . CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE . ', -9008)');
$other = null;
$r = BackupObjects::store_object($plan, BackupObjects::epoch($plan), $blobs['locked.bin'], $up . '/locked.bin');
check(is_array($r) && $r['epoch'] === $epoch, 'once released, the store proceeds', json_encode($r));
BackupObjects::held_add($plan, 'locked.bin', $r);
@unlink($up . '/locked.bin');

// ─────────────────────────────────────────────────────────────────────────────
section('A manager run reading the index by link, with no listing, stores only what the index lacks');

// The newest index in backup storage names everything but a new blob; the link
// fetch is stubbed (the fixture speaks http; the real fetch insists on https).
// e1/e3 were stored outside any index: the manager profile has no listing to
// say so, and re-stores them from the file store (one run's worth). e2 is
// made unfetchable as well, so it can only be indexed as not stored.
$blob(-9009, 'f.jpg', random_bytes(1200));
unset($store->objects['e2.bin']);
$index_key = $base . basename($chain2_d) . '/objects-0000.json.gz';
$fetched = 0;
BackupObjects::$test_hooks['fetch'] = function ($url, $sink) use ($fx, $index_key, &$fetched) {
	$fetched++;
	if ($url !== 'https://shelf.invalid/' . $index_key . '?sig=test') { return false; }
	file_put_contents($sink, s3fx_object($fx, 'bkt', '/' . $index_key));
	return true;
};
$link_plan = $make_plan($slug, array('objects_source' => 'index', 'objects_index_url' => 'https://shelf.invalid/' . $index_key . '?sig=test'));
@unlink($link_plan['output_dir'] . '/objects/held.json');
$lists_before = s3fx_count($fx, 'list');
$puts_before = s3fx_count($fx, 'put');
list($result, $error) = $run($link_plan);
check($error === null, 'the run succeeds', (string)$error);
check($fetched === 1, 'the index link was fetched once');
check(s3fx_count($fx, 'list') === $lists_before, 'backup storage was never listed');
check(s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/f.jpg.enc') !== null, 'the blob the index lacked was stored');
check(s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/a.jpg.enc') === $a_enc_before, 'the ones it named were not');
$held = BackupObjects::read_held($link_plan);
check(isset($held['a.jpg']) && isset($held['f.jpg']) && isset($held['e1.bin']) && !isset($held['e2.bin']),
	'held.json = the index\'s stored set plus this run\'s store; e1.bin was re-stored from the file store, e2.bin could not be', json_encode(array_keys((array)$held)));
$idx = $shelf_index($base . basename($chain_dir_of($link_plan)) . '/' . BackupChain::artifact_name('objects', count(BackupChain::read($chain_dir_of($link_plan) . '/manifest.json')['runs']) - 1));
$by = array(); foreach ($idx['objects'] as $e) { $by[$e['name']] = $e; }
check(isset($by['e2.bin']) && $by['e2.bin']['stored'] === false, 'an object the index did not name and the run could not store is indexed as not stored');
check(isset($by['e1.bin']) && $by['e1.bin']['stored'] === true, 'one it could re-store is indexed as stored');
unset(BackupObjects::$test_hooks['fetch']);
$store->objects['e2.bin'] = 'e2 is back';

$nolink_plan = $make_plan($slug, array('objects_source' => 'index', 'objects_index_url' => ''));
@unlink($nolink_plan['output_dir'] . '/objects/held.json');
$blob(-9010, 'g.bin', random_bytes(64), array());
list($result, $error) = $run($nolink_plan);
check($error === null, 'a run with no index link succeeds', (string)$error);
$after = s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/a.jpg.enc');
check($after !== null && $after !== $a_enc_before, 'with no link and no held.json nothing is held, so backup storage\'s objects are stored again from the file store (bounded to one run\'s worth)');
$a_enc_before = $after;

// ─────────────────────────────────────────────────────────────────────────────
section('An object the file store no longer has does not fail the backup');

$blob(-9013, 'z.bin', null, array());            // cloud row, no local bytes, and not in the file store either
list($result, $error, $history_z) = $run($plan);
check($error === null && ($result['status'] ?? '') === 'success', 'the run succeeds', (string)$error);
check(strpos((string)$result['message'], '1 offloaded file could not be read from the file store') !== false, 'and says the file store could not supply it', $result['message']);
check(strpos((string)$history_z->get('bkh_message'), '1 not in the file store') !== false, 'the history row says so too', (string)$history_z->get('bkh_message'));
$idx = $shelf_index($base . basename($chain_dir_of($plan)) . '/' . BackupChain::artifact_name('objects', count(BackupChain::read($chain_dir_of($plan) . '/manifest.json')['runs']) - 1));
$by = array(); foreach ($idx['objects'] as $e) { $by[$e['name']] = $e; }
check(isset($by['z.bin']) && $by['z.bin']['stored'] === false, 'the index is honest about it');
check(!glob($plan['output_dir'] . '/objects/tmp/*'), 'and no temporary is left');
unset($blobs['z.bin']);

// ─────────────────────────────────────────────────────────────────────────────
section('A manager request without objects stores nothing and holds nothing');

$slug_off = $slug . '-off';
$off_plan = $make_plan($slug_off, array('objects' => false, 'output_dir' => $out . '/off/manager', 'base_dir' => $out . '/off'));
@mkdir($out . '/off/manager', 0700, true);
$blob(-9011, 'h.bin', random_bytes(64), array());
list($result, $error, $history_off) = $run($off_plan);
check($error === null, 'the run succeeds', (string)$error);
check($object_keys('bkt/joinery-backups/' . $slug_off . '/manager/objects/') === array(), 'nothing under objects/ on that shelf');
check(!is_dir($out . '/off/manager/objects'), 'no objects directory, no held.json');
$m_off = BackupChain::read($chain_dir_of($off_plan) . '/manifest.json');
check(!isset($m_off['runs'][0]['artifacts']['objects']), 'no index artifact in the manifest');
check(is_file($up . '/h.bin'), 'h.bin\'s local bytes stay (this profile never held it)');
$files_off = s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug_off . '/manager/' . basename($chain_dir_of($off_plan)) . '/files-0000.tar.gz.enc');
list($rc, $members) = $list_archive($files_off, BackupEnvelope::open_as_site($m_off['envelope']));
check(in_array('site/static_files/uploads/h.bin', $members, true), 'and its archive carries the waiting file, as it always did');
@unlink($up . '/h.bin');
unset($blobs['h.bin']);

// ─────────────────────────────────────────────────────────────────────────────
section('A database-only run carries no index');

$db_plan = $make_plan($slug . '-db', array('type' => 'database', 'mode' => 'full', 'objects' => false,
	'output_dir' => $out . '/dbonly/manager', 'base_dir' => $out . '/dbonly'));
@mkdir($out . '/dbonly/manager', 0700, true);
list($result, $error, $history_db) = $run($db_plan, true);
check($error === null && ($result['status'] ?? '') === 'success', 'the database-only run succeeds', (string)$error);
$kinds = array_map(function ($a) { return $a['kind']; }, $history_db->artifacts());
check(!in_array('objects', $kinds, true), 'no index among its artifacts', json_encode($kinds));
check(!is_dir($out . '/dbonly/manager/objects'), 'and no objects directory');

// ─────────────────────────────────────────────────────────────────────────────
section('A standalone whole-site run carries an index named beside its archive');

$full_plan = $make_plan($slug, array('mode' => 'full'));
$blob(-9012, 'i.bin', random_bytes(64), array());
list($result, $error, $history_full) = $run($full_plan, true);
check($error === null && ($result['status'] ?? '') === 'success', 'the standalone run succeeds', (string)$error . ' ' . json_encode($result));
$arts = $history_full->artifacts();
$archive = $arts[0]['name'] ?? '';
$idx_hist = null; foreach ($arts as $a) { if (($a['kind'] ?? '') === 'objects') { $idx_hist = $a; } }
check(preg_match('/^site-\d{8}_\d{6}\.tar\.gz\.enc$/', $archive) === 1 && $idx_hist !== null
	&& $idx_hist['name'] === BackupNaming::index_for_archive($archive), 'the index carries the archive\'s stamp', $archive . ' / ' . json_encode($idx_hist['name'] ?? null));
check(s3fx_object($fx, 'bkt', '/' . $base . $idx_hist['name']) !== null, 'and is in backup storage beside it');
$sidx = $shelf_index($base . $idx_hist['name']);
check($sidx['run'] === $archive, 'its run label is the archive name', $sidx['run']);
check(s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/i.bin.enc') !== null, 'the standalone run stored the new blob');
$archive_bytes = s3fx_object($fx, 'bkt', '/' . $base . $archive);
$env = BackupEnvelope::read_sidecar($full_plan['output_dir'] . '/' . $archive . BackupEnvelope::SIDECAR_SUFFIX);
list($rc, $members) = $list_archive($archive_bytes, BackupEnvelope::open_as_site($env));
$leaked = array_values(array_filter($members, function ($m) { return preg_match('#uploads/(thumb/)?(a\.jpg|i\.bin|f\.jpg)$#', $m); }));
check($rc === 0 && $leaked === array() && in_array(substr($archive, 0, -11) . '/project_files/public_html/kept.txt', $members, true),
	'the standalone archive excludes the cloud blobs and carries the rest', json_encode($leaked) . ' ' . implode(' ', array_slice($members, 0, 5)));

// ─────────────────────────────────────────────────────────────────────────────
section('Site retention deletes an object only when no retained index names it');

// Two chains exist under $plan (chain_d and chain2_d) plus the link-plan and
// no-link-plan runs, which extended chain 2. keep_cloud=1 prunes chain 1,
// whose indexes name a, b, c, d — all named by chain 2's newest index, so
// nothing is deleted.
$keep1 = $make_plan($slug, array('keep_cloud' => 1));
list($result, $error) = $run($keep1);
check($error === null, 'a run with keep_cloud=1 succeeds', (string)$error);
check(strpos((string)$result['message'], 'pruned 1 old backup') !== false, 'chain 1 was pruned', $result['message']);
check(s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/b.bin.enc') !== null, 'b.bin stays: the retained chain\'s index names it');
check(strpos((string)$result['message'], 'removed') === false, 'nothing removed', $result['message']);

// b.bin's blob is permanently deleted; the next index lacks it. The
// standalone full taken earlier still names it, so a second standalone full
// with keep_cloud=1 retires that one — and chain 2's older indexes still
// name b.bin, so nothing goes yet. Then a new chain: the prune of chain 2
// finds b.bin named by chain 2's indexes and by no retained index, and
// deletes the object.
unset($blobs['b.bin']);
list($result, $error) = $run($keep1);
check($error === null, 'a run after b.bin\'s blob is gone succeeds', (string)$error);
sleep(1);
list($result, $error) = $run($make_plan($slug, array('mode' => 'full', 'keep_cloud' => 1)), true);
check($error === null && strpos((string)$result['message'], 'pruned 1 old restore point') !== false,
	'a second standalone full retires the first', (string)$error . ' ' . ($result['message'] ?? ''));
check(s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/b.bin.enc') !== null, 'b.bin stays: chain 2\'s older indexes still name it');
@unlink($keep1['output_dir'] . '/.' . $slug . '.snar');
sleep(1);
list($result, $error) = $run($keep1);
check($error === null && strpos((string)$result['message'], 'Full backup') === 0, 'a fresh chain starts', (string)$error . ' ' . ($result['message'] ?? ''));
check(strpos((string)$result['message'], 'pruned 1 old backup') !== false, 'chain 2 was pruned', $result['message']);
check(s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/b.bin.enc') === null, 'b.bin\'s object is gone from backup storage');
check(s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/a.jpg.enc') !== null, 'a.jpg\'s stays');
check(s3fx_object($fx, 'bkt', '/' . $base . 'objects/' . $epoch . '/envelope.json') !== null, 'the epoch envelope stays while the epoch has objects');
check(strpos((string)$result['message'], 'removed 1 offloaded file no kept backup names') !== false, 'the message says so', $result['message']);

// ─────────────────────────────────────────────────────────────────────────────
section('plan_manager() reads the three request fields');

$req = array('bucket' => 'bkt', 'credentials' => s3fx_creds($fx), 'slug' => $slug, 'type' => 'project', 'mode' => 'chain',
	'objects' => true, 'objects_index_url' => 'https://shelf.invalid/' . $base . 'x/objects-0001.json.gz?sig=1',
	'epoch_envelope_urls' => array(
		'epoch-20260901_000000' => 'https://shelf.invalid/' . $base . 'objects/epoch-20260901_000000/envelope.json?sig=2',
		'epoch-2026' => 'https://shelf.invalid/bad-key',
		'epoch-20260902_000000' => 'http://shelf.invalid/plain-link',
	));
$pm = BackupRunner::plan(array('profile' => 'manager', 'manager' => $req));
check($pm['objects'] === true && $pm['objects_source'] === 'index', 'a request carrying objects enables the store, read by index');
check($pm['objects_index_url'] === $req['objects_index_url'], 'the index link is kept');
check(array_keys($pm['epoch_envelope_urls']) === array('epoch-20260901_000000'), 'envelope links are kept only under an epoch id and only over https', json_encode($pm['epoch_envelope_urls']));
$pm = BackupRunner::plan(array('profile' => 'manager', 'manager' => array_merge($req, array('objects_index_url' => 'http://shelf.invalid/plain'))));
check($pm['objects_index_url'] === '', 'a plain-http index link is dropped: a signature is a bearer token');
$pm = BackupRunner::plan(array('profile' => 'manager', 'manager' => array_merge($req, array('type' => 'database'))));
check($pm['objects'] === false, 'a database-only request carries no object store');
$pm = BackupRunner::plan(array('profile' => 'manager', 'manager' => array_diff_key($req, array('objects' => 1))));
check($pm['objects'] === false && $pm['objects_index_url'] === '' && $pm['epoch_envelope_urls'] === array(), 'a request without objects: off, whatever else it carries');

section('The enabled marker: written by a manager run carrying objects, removed on disconnect');
check(is_file($plan['output_dir'] . '/objects/enabled'), 'the manager runs above wrote objects/enabled');
BackupObjects::clear_enabled($plan['base_dir']);
check(!is_file($plan['output_dir'] . '/objects/enabled'), 'clear_enabled() removes it');
check(!is_file($out . '/off/manager/objects/enabled'), 'a run without objects never writes it');

section('Epoch envelopes arriving by link are re-sealed after a rotation');
$env_key = $base . 'objects/' . $epoch . '/envelope.json';
$env_before = json_decode((string)s3fx_object($fx, 'bkt', '/' . $env_key), true);
$fetched_env = 0;
BackupObjects::$test_hooks['fetch'] = function ($url, $sink) use ($fx, $env_key, &$fetched_env) {
	if (strpos($url, 'envelope.json') !== false) { $fetched_env++; }
	$key = substr(parse_url($url, PHP_URL_PATH), 1);
	$bytes = s3fx_object($fx, 'bkt', '/' . $key);
	if ($bytes === null) { return false; }
	file_put_contents($sink, $bytes);
	return true;
};
$rot_plan = $make_plan($slug, array('objects_source' => 'index', 'objects_index_url' => 'https://shelf.invalid/' . $index_key . '?sig=test',
	'epoch_envelope_urls' => array($epoch => 'https://shelf.invalid/' . $env_key . '?sig=env'),
	'recovery_fpr' => str_repeat('f', 64)));   // "the recovery key changed": nothing in backup storage is sealed to this
sleep(1);
list($result, $error) = $run($rot_plan);
check($error === null, 'the run succeeds', (string)$error);
check($fetched_env === 1, 'the envelope link was fetched once');
$env_after = json_decode((string)s3fx_object($fx, 'bkt', '/' . $env_key), true);
check(is_array($env_after) && $env_after['created'] !== $env_before['created'] && $env_after['artifact'] === $epoch,
	'the epoch envelope in backup storage was re-sealed and uploaded again under the same name', json_encode(array($env_before['created'] ?? null, $env_after['created'] ?? null)));
check(BackupEnvelope::open_as_site($env_after) === BackupEnvelope::open_as_site($env_before), 'with the same data key');
check(strpos((string)$result['message'], 're-sealed 1 epoch envelope') !== false, 'and the run says so', $result['message']);
unset(BackupObjects::$test_hooks['fetch']);

section('An epoch the site key cannot open is written down as retired, and the run says so');
// The envelope in backup storage is replaced by one sealed to the recovery key and
// a site key this machine does not hold: after another rotation the run can
// neither re-seal it nor open it, so it stays sealed to the retired key alone.
$foreign = sodium_crypto_box_keypair();
$lost_env = BackupEnvelope::build(BackupEnvelope::open_as_site($env_after), $epoch, array(
	array('kind' => 'recovery', 'pub' => $rot_plan['recipients'][0]['pub']),
	array('kind' => 'site',     'pub' => sodium_crypto_box_publickey($foreign)),
));
file_put_contents(s3fx_object_file($fx['dir'], 'bkt', '/' . $env_key), json_encode($lost_env));
BackupObjects::$test_hooks['fetch'] = function ($url, $sink) use ($fx) {
	$bytes = s3fx_object($fx, 'bkt', '/' . substr(parse_url($url, PHP_URL_PATH), 1));
	if ($bytes === null) { return false; }
	file_put_contents($sink, $bytes);
	return true;
};
$lost_plan = $make_plan($slug, array('objects_source' => 'index', 'objects_index_url' => 'https://shelf.invalid/' . $index_key . '?sig=test',
	'epoch_envelope_urls' => array($epoch => 'https://shelf.invalid/' . $env_key . '?sig=env'),
	'recovery_fpr' => str_repeat('e', 64)));
sleep(1);
list($result, $error) = $run($lost_plan);
check($error === null, 'the run succeeds: an epoch it cannot open is degraded, not fatal', (string)$error);
check(strpos((string)$result['message'], '1 epoch envelope (' . $epoch . ') opens only with a retired recovery key') !== false,
	'the run message names the epoch only a retired key opens', $result['message']);
$retired_file = $lost_plan['output_dir'] . '/objects/' . BackupObjects::RETIRED_FILE;
check(BackupObjects::read_retired_file($retired_file) === array($epoch), 'retired-epochs.json names it', @file_get_contents($retired_file));
$retired = BackupObjects::retired_summary(array(BackupProfile::MANAGER), $lost_plan['base_dir']);
check($retired['epochs'] === array($epoch) && $retired['count'] > 0 && $retired['bytes'] > 0,
	'Recovery Readiness counts the objects in it from held.json', json_encode($retired));
check(json_decode((string)s3fx_object($fx, 'bkt', '/' . $env_key), true) === $lost_env, 'the envelope in backup storage is left as it was');
unset(BackupObjects::$test_hooks['fetch']);

// Debris check on the machine.
check(!glob('/tmp/jy_backup_*'), 'no engine temp files');
putenv('PGPASSWORD');

harness_finish();

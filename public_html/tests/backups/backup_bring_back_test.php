<?php
/** @joinery-test
 * name: backup_bring_back
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * This site bringing its own offloaded files back from its own backup storage
 * (specs/backup_offloaded_files.md § Verification, "The file store is
 * checked too"; BackupObjectRestoreLauncher), against a real run on the
 * local-provider fixture:
 *
 *   - a run stores three offloaded files; the file bucket then serves one.
 *     Bring them back (missing mode) reads the run's index from backup storage,
 *     signs its own links, brings the two home, records them local, marks
 *     the inventory record, and drops them from the missing list; a second
 *     run has nothing to do
 *   - all mode brings every offloaded file home, served or not
 *   - a run with no index fails by name and the record says so; a second
 *     Bring them back while one holds the lock is refused; a request is
 *     pinned to a run and refuses a mode it does not know
 *   - the page's start refuses to start a second one while one is running
 *   - the script refuses what it cannot understand
 *
 * The fixture rows are this test's own and are removed at the end; the
 * inventory record lives in memory (the harness's hook), never in the
 * settings table.
 *
 * Run: php tests/backups/backup_bring_back_test.php
 *
 * @version 1.2 - the run dumps a scratch database, not the site's
 * @version 1.1 - one file store: the cloud rows are private blobs, the store seam takes no argument
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/s3_fixtures.php');
require_once(__DIR__ . '/../lib/cloud_fixtures.php');

require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
require_once(PathHelper::getIncludePath('includes/BackupStaging.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjectRestore.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjectRestoreLauncher.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStoreInventory.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('data/backup_history_class.php'));

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('bring back', 'could not start a local PHP HTTP server on 127.0.0.1');
	harness_finish();
}
harness_defer(function() use ($fx) { s3fx_stop($fx); });

// ── A throwaway site tree, a scratch backup dir, a home for restored files ──
$work = sys_get_temp_dir() . '/jy_bring_back_' . getmypid();
$tree = $work . '/site';
$up   = $tree . '/static_files/uploads';
$out  = $work . '/backups';
$home = $work . '/home';
@mkdir($tree . '/public_html', 0755, true);
@mkdir($up, 0755, true);
@mkdir($out, 0700, true);
@mkdir($home, 0755, true);
file_put_contents($tree . '/public_html/index.php', '<?php echo "hello";');
@mkdir($tree . '/config', 0755, true);
file_put_contents($tree . '/config/Globalvars_site.php', "<?php\n\$this->settings['deployment_environment'] = 'docker';\n");
harness_defer(function() use ($work) {
	exec('chmod -R u+rwX ' . escapeshellarg($work) . ' 2>/dev/null; rm -rf ' . escapeshellarg($work));
});

$pdo = DbConnector::get_instance()->get_db_link();

// The run dumps a database of its own, not this site's: the dump is the
// run's first artifact and nothing here reads it, and the site's is most of
// a gigabyte on dev — a real full dump was most of this suite's wall clock.
$scratch_db = 'jy_bring_back_' . getmypid();
$pdo->exec('CREATE DATABASE "' . $scratch_db . '" TEMPLATE template0');
harness_defer(function() use ($pdo, $scratch_db) { $pdo->exec('DROP DATABASE IF EXISTS "' . $scratch_db . '" WITH (FORCE)'); });
$scratch = new PDO('pgsql:host=localhost dbname=' . $scratch_db, 'postgres', (string)Globalvars::get_instance()->get_setting('dbpassword', true, true));
$scratch->exec('CREATE TABLE t (id int); INSERT INTO t VALUES (1)');
$scratch = null;
$tag = 'jybb' . getmypid() . '_';
$plain = array($tag . 'big.jpg' => random_bytes(40000), $tag . 'mid.bin' => random_bytes(9000), $tag . 'tiny.pdf' => 'tiny ' . random_bytes(300));
$ids = array();
$blobs = array();
foreach ($plain as $name => $bytes) {
	file_put_contents($up . '/' . $name, $bytes);
	$blob = new FileBlob();
	$blob->set('fbb_stored_name', $name);
	$blob->set('fbb_size_bytes', strlen($bytes));
	$blob->set('fbb_sha256', hash('sha256', $bytes));
	$blob->set('fbb_mime_type', 'application/octet-stream');
	$blob->set('fbb_is_private', true);
	$blob->set('fbb_storage_driver', 'cloud');
	$blob->save();
	$ids[$name] = (int)$blob->key;
	$blobs[$name] = array('id' => (int)$blob->key, 'name' => $name, 'original' => $up . '/' . $name, 'paths' => array($up . '/' . $name),
		'remote_key' => $name, 'content_type' => 'application/octet-stream', 'visibility' => 'private');
}
harness_defer(function () use ($pdo, $ids) {
	$q = $pdo->prepare('DELETE FROM fbb_file_blobs WHERE fbb_file_blob_id = ?');
	foreach ($ids as $id) { $q->execute(array($id)); }
});
$row = function ($name) use ($pdo, $ids) {
	$q = $pdo->prepare('SELECT fbb_storage_driver FROM fbb_file_blobs WHERE fbb_file_blob_id = ?');
	$q->execute(array($ids[$name]));
	return (string)$q->fetchColumn();
};
$set_cloud = function ($name) use ($pdo, $ids) {
	$q = $pdo->prepare("UPDATE fbb_file_blobs SET fbb_storage_driver = 'cloud' WHERE fbb_file_blob_id = ?");
	$q->execute(array($ids[$name]));
};

// The file bucket still serves big.jpg and nothing else.
$store = new InMemoryBlobDriver();
$store->objects[$tag . 'big.jpg'] = $plain[$tag . 'big.jpg'];
BackupObjectRestore::$test_hooks = array(
	'store'     => function () use ($store) { return $store; },
	'placement' => function (FileBlob $b) use ($home) { return $home . '/' . $b->get('fbb_stored_name'); },
);
BackupObjects::$test_hooks = array('enumerator' => function () use (&$blobs) { return array_values($blobs); });
BackupProfile::$enabled_for_tests = array();
CloudStoreInventory::$test_hooks = array('record' => array());
harness_defer(function () { BackupObjects::$test_hooks = array(); BackupObjectRestore::$test_hooks = array();
	BackupProfile::$enabled_for_tests = null; BackupStaging::$fetch_for_tests = null;
	BackupObjectRestoreLauncher::$plan_for_tests = null; CloudStoreInventory::$test_hooks = array('record' => array()); });

$slug = 'bring-back-' . getmypid();
$plan = BackupRunner::plan(array('profile' => 'manager', 'manager' => array(
	'bucket' => 'bkt', 'credentials' => s3fx_creds($fx), 'slug' => $slug,
	'type' => 'project', 'mode' => 'chain', 'delete_local_after_upload' => 0, 'keep_local_days' => 7,
	'target_name' => 'local fixture',
)));
$plan['base_dir'] = $out; $plan['output_dir'] = $out . '/manager';
$plan['project'] = 'site'; $plan['project_dir'] = $tree; $plan['database'] = $scratch_db;
$plan['objects'] = true; $plan['objects_source'] = 'listing';
$base = 'joinery-backups/' . $slug . '/manager/';

$execute_chain = new ReflectionMethod('BackupRunner', 'execute_chain');
$execute_chain->setAccessible(true);
$history = new BackupHistory(NULL);
$history->set('bkh_type', 'project'); $history->set('bkh_outcome', 'running');
$history->set('bkh_slug', $plan['slug']); $history->set('bkh_profile', 'manager');
$history->set('bkh_recovery_fpr', $plan['recovery_fpr']); $history->set('bkh_encrypted', true);
$history->set('bkh_target_name', 'local fixture');
$history->save();
harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $history->key);
$shelf = function ($key) use ($fx) { return s3fx_object($fx, 'bkt', '/' . $key); };

// The links the launcher signs point at the fixture over plain http, which
// BackupFetch refuses by design; the hook reads the same key off the fixture.
$fetched = array();
BackupStaging::$fetch_for_tests = function ($url, $sink, $max) use ($shelf, &$fetched) {
	$path = (string)parse_url($url, PHP_URL_PATH);
	$key = preg_replace('#^/bkt/#', '', $path);
	$fetched[] = $key;
	$bytes = $shelf($key);
	if ($bytes === null) { return array('ok' => false, 'error' => 'HTTP 404 from storage (the response body is not repeated here)'); }
	if ($max > 0 && strlen($bytes) > $max) { return array('ok' => false, 'error' => 'refusing this download: larger than recorded'); }
	file_put_contents($sink, $bytes);
	return array('ok' => true, 'error' => '');
};

// ─────────────────────────────────────────────────────────────────────────────
section('A run stores the three objects');

try { $result = $execute_chain->invoke(null, $plan, $history); $error = null; } catch (\Throwable $e) { $result = null; $error = $e->getMessage(); }
check($error === null && ($result['status'] ?? '') === 'success', 'the run succeeds', (string)$error . ' ' . json_encode($result));
$dirs = glob($plan['output_dir'] . '/' . BackupChain::DIR_PREFIX . '*', GLOB_ONLYDIR);
$chain_id = basename($dirs[0]);
$index = BackupObjects::decode_index($shelf($base . $chain_id . '/objects-0000.json.gz'), 'objects-0000.json.gz');
$epoch = $index['epochs'][0] ?? '';
check(count(BackupObjects::index_entries($index)) === 3, 'the index marks the three stored');
foreach ($plain as $name => $bytes) { check(!is_file($up . '/' . $name), $name . ' has left the disk'); }

// The daily check has already named the two the bucket lost (and one stranger).
$rec = CloudStoreInventory::blank_record();
$rec['last'] = array('started' => '2026-09-21 03:00:00', 'finished' => '2026-09-21 03:00:00', 'checked' => 3, 'unchecked' => 0,
	'missing' => array(
		$tag . 'mid.bin'  => array('id' => $ids[$tag . 'mid.bin'], 'size' => 9000, 'reason' => 'absent'),
		$tag . 'tiny.pdf' => array('id' => $ids[$tag . 'tiny.pdf'], 'size' => 305, 'reason' => 'absent'),
		'stranger.bin'    => array('id' => 0, 'size' => 1, 'reason' => 'absent'),
	));
CloudStoreInventory::write($rec);

// ─────────────────────────────────────────────────────────────────────────────
section('Bring them back, missing mode');

BackupObjectRestoreLauncher::$plan_for_tests = $plan;
$log = array();
$progress = function ($what, $name, $detail = '') use (&$log) { $log[] = $what . ' ' . $name; };
$r = BackupObjectRestoreLauncher::run(array('chain_id' => $chain_id, 'seq' => 0, 'mode' => 'missing'), $progress);
check(($r['result'] ?? '') === 'ok', 'the run finishes', json_encode($r));
check($r['restored'] === 2 && $r['bytes'] === 9000 + 305 && $r['kept'] === 0 && $r['skipped'] === 1 && $r['wanted'] === 2 && $r['indexed'] === 3,
	'two brought home, the served one skipped, counts as the contract has them', json_encode($r));
check($r['run'] === (string)$index['run'] && $r['mode'] === 'missing', 'the result names the run the index describes', json_encode($r['run']));
check(file_get_contents($home . '/' . $tag . 'mid.bin') === $plain[$tag . 'mid.bin'] && file_get_contents($home . '/' . $tag . 'tiny.pdf') === $plain[$tag . 'tiny.pdf'],
	'each landed at its placement with the original bytes');
check(!is_file($home . '/' . $tag . 'big.jpg'), 'the one the file bucket serves was not fetched');
check($row($tag . 'mid.bin') === 'local' && $row($tag . 'tiny.pdf') === 'local' && $row($tag . 'big.jpg') === 'cloud', 'their rows say local; the served one still says cloud');
check(in_array('objects/' . $epoch . '/envelope.json', array_map(function ($k) use ($base) { return substr($k, strlen($base)); }, $fetched), true)
	&& count(array_filter($fetched, function ($k) { return substr($k, -4) === '.enc'; })) === 2,
	'the epoch envelope and exactly two objects were fetched, by links under the profile\'s base key', json_encode($fetched));
check(count(glob($plan['output_dir'] . '/objects/tmp/bring-back-*')) === 0, 'the working directory is gone');
check(!is_file($plan['output_dir'] . '/objects/bring-back.lock') || flock(fopen($plan['output_dir'] . '/objects/bring-back.lock', 'c'), LOCK_EX | LOCK_NB), 'the lock is released');
check(in_array('restored ' . $tag . 'mid.bin', $log, true) && in_array('restored ' . $tag . 'tiny.pdf', $log, true), 'progress named each', json_encode($log));

$rec = CloudStoreInventory::read();
$bb = $rec['bring_back'];
check(is_array($bb) && $bb['result'] === 'ok' && $bb['restored'] === 2 && $bb['bytes'] === 9305 && $bb['wanted'] === 2 && !empty($bb['finished']) && $bb['mode'] === 'missing',
	'the inventory record carries what it did', json_encode($bb));
check(array_keys($rec['last']['missing']) === array('stranger.bin'), 'the two it brought home left the missing list; the stranger stays', json_encode(array_keys($rec['last']['missing'])));
$words = BackupObjectRestoreLauncher::describe_last($bb);
check(strpos($words, 'Brought 2 offloaded files home') !== false && strpos($words, '1 skipped') !== false, 'and reads in words', $words);

$r = BackupObjectRestoreLauncher::run(array('chain_id' => $chain_id, 'seq' => 0, 'mode' => 'missing'));
check($r['result'] === 'ok' && $r['restored'] === 0 && $r['wanted'] === 0 && $r['skipped'] === 3, 'a second run finds nothing to do', json_encode($r));

// ─────────────────────────────────────────────────────────────────────────────
section('All mode brings every offloaded file home');

foreach (array_keys($plain) as $name) { $set_cloud($name); @unlink($home . '/' . $name); }
$r = BackupObjectRestoreLauncher::run(array('chain_id' => $chain_id, 'seq' => 0, 'mode' => 'all'));
check($r['result'] === 'ok' && $r['restored'] === 3 && $r['skipped'] === 0 && $row($tag . 'big.jpg') === 'local'
	&& file_get_contents($home . '/' . $tag . 'big.jpg') === $plain[$tag . 'big.jpg'], 'the served one comes home too', json_encode($r));

// ─────────────────────────────────────────────────────────────────────────────
section('Refusals');

$r = BackupObjectRestoreLauncher::run(array('chain_id' => $chain_id, 'seq' => 7, 'mode' => 'missing'));
check($r['result'] === 'fail' && strpos($r['reason'], 'run 7 of ' . $chain_id) !== false && strpos($r['reason'], 'no offloaded-files index') !== false,
	'a run with no index fails by name', json_encode($r));
check(CloudStoreInventory::read()['bring_back']['result'] === 'fail' && CloudStoreInventory::read()['bring_back']['reason'] === $r['reason'], 'and the record says so');
$r = BackupObjectRestoreLauncher::run(array('chain_id' => $chain_id, 'seq' => 0, 'mode' => 'sideways'));
check($r['result'] === 'fail' && strpos($r['reason'], 'mode must be missing or all') !== false, 'an unknown mode is refused', json_encode($r));
$r = BackupObjectRestoreLauncher::run(array('chain_id' => 'not-a-chain', 'seq' => 0));
check($r['result'] === 'fail' && strpos($r['reason'], 'names no run') !== false, 'a bad chain id is refused', json_encode($r));

$lock = fopen($plan['output_dir'] . '/objects/bring-back.lock', 'c');
flock($lock, LOCK_EX);
// The run holding the lock owns the record: it says running, and stays so.
CloudStoreInventory::note_bring_back(array('started' => gmdate('Y-m-d H:i:s', time() - 30), 'finished' => null, 'result' => null, 'reason' => '', 'restored' => 1));
$r = BackupObjectRestoreLauncher::run(array('chain_id' => $chain_id, 'seq' => 0, 'mode' => 'missing'));
check($r['result'] === 'fail' && strpos($r['reason'], 'already being brought back') !== false, 'a second run while one holds the lock is refused', json_encode($r));
$bb = CloudStoreInventory::read()['bring_back'];
check($bb['finished'] === null && $bb['result'] === null && $bb['restored'] === 1, 'and leaves the record to the run that holds the lock', json_encode($bb));
flock($lock, LOCK_UN); fclose($lock);
CloudStoreInventory::note_bring_back(array('finished' => gmdate('Y-m-d H:i:s'), 'result' => 'ok'));

$hist = new BackupHistory(NULL);
$hist->set('bkh_chain_id', $chain_id); $hist->set('bkh_chain_seq', 0); $hist->set('bkh_start_time', '2026-09-21 04:00:00');
$req = BackupObjectRestoreLauncher::request($hist, 'missing');
check($req['chain_id'] === $chain_id && $req['seq'] === 0 && $req['mode'] === 'missing' && $req['when'] === '2026-09-21 04:00:00', 'a request is pinned to the run', json_encode($req));
$threw = '';
try { BackupObjectRestoreLauncher::request($hist, 'sideways'); } catch (BackupObjectRestoreLauncherException $e) { $threw = $e->getMessage(); }
check(strpos($threw, '"missing"') !== false && strpos($threw, '"all"') !== false, 'a request refuses a mode it does not know', $threw);
$hist->set('bkh_chain_id', 'db-only');
$threw = '';
try { BackupObjectRestoreLauncher::request($hist, 'missing'); } catch (BackupObjectRestoreLauncherException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'not part of a set') !== false, 'a run outside a chain has nothing to read from', $threw);

// The page's start: a second one while one is running is refused before
// anything is started (only checked where this database has a run to name;
// nothing here ever detaches a process).
if (BackupObjectRestoreLauncher::newest_run() !== null) {
	CloudStoreInventory::note_bring_back(array('started' => gmdate('Y-m-d H:i:s'), 'finished' => null));
	$threw = '';
	try { BackupObjectRestoreLauncher::start_newest('missing'); } catch (BackupObjectRestoreLauncherException $e) { $threw = $e->getMessage(); }
	check(strpos($threw, 'already being brought back') !== false, 'the page refuses to start a second one while one is running', $threw);
}

// ─────────────────────────────────────────────────────────────────────────────
section('The script refuses what it cannot understand');

$script = PathHelper::getIncludePath('utils/bring_back_objects.php');
$run_script = function ($stdin, $args = '') use ($script) {
	$d = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$p = proc_open('php ' . escapeshellarg($script) . ' ' . $args, $d, $pipes);
	fwrite($pipes[0], $stdin); fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	return array(proc_close($p), $out, $err);
};
list($rc, $o, $e) = $run_script('not json');
check($rc === 2 && strpos($e, 'BRING_BACK_FAIL') === 0, 'not JSON on stdin: exit 2', $e);
list($rc, $o, $e) = $run_script('{"chain_id":"chain-20260901_000000","seq":0,"bucket_key":"x"}');
check($rc === 2 && strpos($e, 'unrecognised key(s): bucket_key') !== false, 'a request carrying a key it does not know is refused', $e);
list($rc, $o, $e) = $run_script('', '--help');
check($rc === 0 && strpos($o, 'Usage:') === 0, '--help prints usage');
list($rc, $o, $e) = $run_script('', '--bogus');
check($rc === 2 && strpos($e, 'unknown argument') !== false, 'an unknown argument is refused');

harness_finish();

<?php
/** @joinery-test
 * name: backup_runner_stream
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * A chain run whose tree archives never land on disk.
 *
 * The files engine streams tar's output straight into the bucket; the run
 * learns whether tar succeeded only after the bytes have gone, and must
 * complete the upload only then. What is pinned here, against a local
 * provider and a throwaway tree:
 *
 *   - a chain run leaves no data or code archive in the chain directory
 *   - the manifest's bytes and sha256 equal the objects the bucket holds
 *   - the data object decrypts to the site less public_html, the code
 *     object to public_html
 *   - a second run extends the chain as an incremental, also streamed
 *   - an engine that fails after streaming (tar exit 2 on an unreadable file)
 *     leaves nothing on the fixture, and the run fails under the discard
 *     rule: snapshots and manifest put back as they stood, no history row
 *   - a failure after the tree objects went up puts the snapshots back, so
 *     the next run extends the same chain
 *   - full_size_warning() sees the streamed byte count
 *
 * Run: php tests/backups/backup_runner_stream_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/s3_fixtures.php');

require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('runner stream', 'could not start a local PHP HTTP server on 127.0.0.1');
	harness_finish();
}
harness_defer(function() use ($fx) { s3fx_stop($fx); });

// ── A throwaway site: a tree, a tiny database, a scratch backup directory ──
$work = sys_get_temp_dir() . '/jy_runner_stream_' . getmypid();
$tree = $work . '/site';
$out  = $work . '/backups';
@mkdir($tree . '/public_html/sub', 0755, true);
@mkdir($tree . '/config', 0755, true);
@mkdir($out, 0700, true);
file_put_contents($tree . '/public_html/index.php', '<?php echo "hello";');
file_put_contents($tree . '/public_html/sub/data.bin', random_bytes(40000));
file_put_contents($tree . '/config/site.txt', 'config travels');
harness_defer(function() use ($work) {
	exec('chmod -R u+rwX ' . escapeshellarg($work) . ' 2>/dev/null; rm -rf ' . escapeshellarg($work));
});

$dbname = 'jy_stream_' . getmypid();
$pdo = DbConnector::get_instance()->get_db_link();
$pdo->exec('CREATE DATABASE "' . $dbname . '" TEMPLATE template0');
harness_defer(function() use ($pdo, $dbname) { $pdo->exec('DROP DATABASE IF EXISTS "' . $dbname . '"'); });
$scratch = new PDO('pgsql:host=localhost dbname=' . $dbname, 'postgres', (string)Globalvars::get_instance()->get_setting('dbpassword', true, true));
$scratch->exec('CREATE TABLE t (id int); INSERT INTO t VALUES (1), (2), (3)');
$scratch = null;

$slug = 'runner-stream-' . getmypid();
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

$execute = new ReflectionMethod('BackupRunner', 'execute_chain');
$execute->setAccessible(true);
$run = function () use ($execute, $plan) {
	$history = new BackupHistory(NULL);
	$history->set('bkh_type', 'project');
	$history->set('bkh_outcome', 'running');
	$history->set('bkh_slug', $plan['slug']);
	$history->set('bkh_profile', $plan['profile']);
	$history->set('bkh_recovery_fpr', $plan['recovery_fpr']);
	$history->set('bkh_encrypted', true);
	$history->set('bkh_target_name', 'local fixture');
	$history->save();
	harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $history->key);
	$error = null;
	try {
		$result = $execute->invoke(null, $plan, $history);
	} catch (\Throwable $e) {
		$result = null;
		$error = $e->getMessage();
	}
	return array($result, $error, $history);
};

$decrypt_list = function ($bytes, $data_key) use ($work) {
	$enc = $work . '/dl.enc'; $keyf = $work . '/dl.key';
	file_put_contents($enc, $bytes);
	file_put_contents($keyf, $data_key);
	$cmd = '( openssl enc -d -aes-256-cbc -pbkdf2 -pass fd:3 -in ' . escapeshellarg($enc) . ' 3< ' . escapeshellarg($keyf)
		. ' | tar -tz ) 2>&1';
	exec($cmd, $lines, $rc);
	@unlink($enc); @unlink($keyf);
	return array($rc, $lines);
};

// ─────────────────────────────────────────────────────────────────────────────
section('A chain run streams its data and code archives: nothing lands in the chain directory');

list($result, $error, $history) = $run();
check($error === null && ($result['status'] ?? '') === 'success', 'the run succeeds', (string)$error . ' ' . json_encode($result));

$chain_dirs = glob($plan['output_dir'] . '/' . BackupChain::DIR_PREFIX . '*', GLOB_ONLYDIR) ?: array();
check(count($chain_dirs) === 1, 'one chain directory was made', count($chain_dirs) . ' found');
$chain_d = $chain_dirs[0] ?? '';
$chain_id = basename($chain_d);
check(!glob($chain_d . '/data-0000*') && !glob($chain_d . '/code-0000*'), 'no tree archive is on disk', implode(',', array_map('basename', glob($chain_d . '/*') ?: array())));
check(!glob($chain_d . '/.data-report-*') && !glob($chain_d . '/.code-report-*'), 'the engine reports were removed');
check(is_file($chain_d . '/manifest.json'), 'the manifest is on disk');

$manifest = BackupChain::read($chain_d . '/manifest.json');
$run0 = $manifest['runs'][0] ?? array();
check((int)$manifest['version'] === 2, 'the chain is a version-2 chain');
$data0 = $run0['artifacts']['data'] ?? array();
$code0 = $run0['artifacts']['code'] ?? array();
$key = 'joinery-backups/' . $slug . '/manager/' . $chain_id . '/data-0000.tar.gz.enc';
$object = s3fx_object($fx, 'bkt', '/' . $key);
$code_object = s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug . '/manager/' . $chain_id . '/code-0000.tar.gz.enc');
check($object !== null && $code_object !== null, 'the data and code objects are in backup storage under the chain key', $key);
check($object !== null && (int)$data0['bytes'] === strlen($object) && $code_object !== null && (int)$code0['bytes'] === strlen($code_object),
	'the manifest\'s bytes equal the objects\'', ($data0['bytes'] ?? '?') . ' vs ' . strlen((string)$object));
check($object !== null && $data0['sha256'] === hash('sha256', $object) && $code_object !== null && $code0['sha256'] === hash('sha256', $code_object),
	'the manifest\'s sha256 equals the objects\'');
check((int)$run0['level'] === 0 && (int)$data0['level'] === 0 && (int)$code0['level'] === 0, 'the first run is a full of both parts');
$run0_total = 0;
foreach (($run0['artifacts'] ?? array()) as $a) { $run0_total += (int)($a['bytes'] ?? 0); }
check(($result['level'] ?? null) === 0 && ($result['bytes'] ?? null) === $run0_total && $run0_total > (int)($data0['bytes'] ?? 0) + (int)($code0['bytes'] ?? 0),
	'the run\'s result carries its level and its whole size — every artifact, not the tree alone', json_encode($result));
$db0_bytes = (int)($run0['artifacts']['db']['bytes'] ?? 0);
check(strpos((string)$result['message'], 'Full backup ' . BackupRunner::human($run0_total)
		. ' (data ' . BackupRunner::human($data0['bytes'] ?? 0) . ', code ' . BackupRunner::human($code0['bytes'] ?? 0)
		. ', database ' . BackupRunner::human($db0_bytes) . ')') === 0,
	'the run message states the whole size and names the data, code and database parts', (string)$result['message']);
check(BackupRunner::human(4658000000) === '4.7 GB' && BackupRunner::human(999) === '999 B' && BackupRunner::human(81400000) === '81.4 MB',
	'sizes read in decimal units, as backup storage bills them');
$db_object = s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug . '/manager/' . $chain_id . '/db-0000.sql.gz.enc');
check($db_object !== null, 'the database dump is in backup storage');
check(!is_file($chain_d . '/db-0000.sql.gz.enc') && !glob($chain_d . '/*.sql.gz.enc'), 'and no dump is on disk',
	implode(',', array_map('basename', glob($chain_d . '/*') ?: array())));
$db0 = $run0['artifacts']['db'] ?? array();
check($db_object !== null && (int)$db0['bytes'] === strlen($db_object) && $db0['sha256'] === hash('sha256', $db_object),
	'the manifest\'s dump bytes and sha256 equal the object\'s');
check(!glob('/tmp/jy_backup_*'), 'no plaintext dump temp file exists');
check(s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug . '/manager/' . $chain_id . '/manifest.json') !== null, 'the manifest is in backup storage');

$history_artifacts = $history->artifacts();
$data_hist = null;
foreach ($history_artifacts as $a) { if (($a['kind'] ?? '') === 'data') { $data_hist = $a; } }
check($data_hist !== null && ($data_hist['key'] ?? '') === $key && !isset($data_hist['path']) && (int)($data_hist['level'] ?? -1) === 0,
	'the history records the data artifact by key, with its level and no local path', json_encode($data_hist));
check(strpos((string)$history->get('bkh_message'), 'Full run 0') === 0, 'the history message names the run', (string)$history->get('bkh_message'));

$data_key = BackupEnvelope::open_as_site($manifest['envelope']);
$decrypt_sql = function ($bytes, $data_key) use ($work) {
	$enc = $work . '/dl.enc'; $keyf = $work . '/dl.key';
	file_put_contents($enc, $bytes);
	file_put_contents($keyf, $data_key);
	$cmd = '( openssl enc -d -aes-256-cbc -pbkdf2 -pass fd:3 -in ' . escapeshellarg($enc) . ' 3< ' . escapeshellarg($keyf) . ' | gunzip ) 2>&1';
	exec($cmd, $sql_lines, $rc);
	@unlink($enc); @unlink($keyf);
	return array($rc, implode("\n", $sql_lines));
};
list($rc, $sql) = $decrypt_sql($db_object, $data_key);
check($rc === 0 && strpos($sql, 'COPY public.t') !== false && preg_match('/^3$/m', $sql), 'the dump decrypts to the throwaway database\'s rows', 'rc ' . $rc);
list($rc, $lines) = $decrypt_list($object, $data_key);
check($rc === 0 && in_array('site/config/site.txt', $lines, true) && !preg_grep('#^site/public_html#', $lines),
	'the data object decrypts to the site less public_html', 'rc ' . $rc . ': ' . implode(' ', array_slice($lines, 0, 6)));
list($rc, $lines) = $decrypt_list($code_object, $data_key);
check($rc === 0 && in_array('public_html/sub/data.bin', $lines, true) && !preg_grep('#^site/#', $lines),
	'the code object decrypts to public_html, rooted there', 'rc ' . $rc . ': ' . implode(' ', array_slice($lines, 0, 6)));

$snars = array($plan['output_dir'] . '/.' . $slug . '.data.snar', $plan['output_dir'] . '/.' . $slug . '.code.snar');
check(is_file($snars[0]) && filesize($snars[0]) > 0 && is_file($snars[1]) && filesize($snars[1]) > 0, 'both snapshots advanced');
check(!glob($plan['output_dir'] . '/.*.held') && !glob($plan['output_dir'] . '/.*.none'), 'and nothing is left held');
$snap_state = function () use ($snars) {
	$h = array();
	foreach ($snars as $s) { foreach (array($s, $s . '.tree') as $f) { $h[$f] = is_file($f) ? hash_file('sha256', $f) : null; } }
	return $h;
};

// ─────────────────────────────────────────────────────────────────────────────
section('A second run extends the chain as a streamed incremental');

file_put_contents($tree . '/public_html/new.txt', 'added after the full');
list($result, $error, $history2) = $run();
check($error === null && ($result['status'] ?? '') === 'success', 'the second run succeeds', (string)$error);
$manifest = BackupChain::read($chain_d . '/manifest.json');
check(count($manifest['runs']) === 2 && (int)$manifest['runs'][1]['level'] === 1, 'the chain has two runs, the second incremental',
	json_encode(array_map(function ($r) { return $r['level']; }, $manifest['runs'])));
$object1 = s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug . '/manager/' . $chain_id . '/code-0001.tar.gz.enc');
check($object1 !== null && (int)$manifest['runs'][1]['artifacts']['code']['bytes'] === strlen($object1), 'the code incremental is in backup storage with its recorded size');
check(!glob($chain_d . '/code-0001*') && !glob($chain_d . '/data-0001*'), 'and not on disk');
list($rc, $lines) = $decrypt_list($object1, $data_key);
check($rc === 0 && in_array('public_html/new.txt', $lines, true) && !in_array('public_html/sub/data.bin', $lines, true),
	'the incremental carries the new file and not the unchanged one', implode(' ', $lines));
check(strpos((string)$result['message'], 'Incremental backup') === 0, 'the run message says incremental', $result['message']);

// ─────────────────────────────────────────────────────────────────────────────
section('An engine that fails after streaming leaves nothing in backup storage and the run is discarded');

$has_sudo = false;
exec('sudo -n -l 2>/dev/null', $sudo_out, $sudo_rc);
foreach ($sudo_out as $l) { if (preg_match('/NOPASSWD:([[:space:]]*[A-Z]+:)*[[:space:]]*ALL([[:space:]]|$)/', $l)) { $has_sudo = true; } }
if ($has_sudo) {
	harness_skip('tar failure after streaming', 'this account has passwordless sudo, so no file is unreadable to the engine');
} else {
	// In the data tree, whose engine runs first: nothing reaches backup storage.
	$unreadable = $tree . '/config/secret.bin';
	file_put_contents($unreadable, random_bytes(3000));
	chmod($unreadable, 0000);
	$manifest_before = file_get_contents($chain_d . '/manifest.json');
	$snaps_before = $snap_state();
	$objects_before = s3fx_keys($fx);
	$aborts_before = s3fx_count($fx, 'abort');
	$puts_before = s3fx_count($fx, 'put');

	list($result, $error, $history3) = $run();
	chmod($unreadable, 0600);
	@unlink($unreadable);

	check($result === null && $error !== null, 'the run fails', json_encode($result));
	check(stripos((string)$error, 'tar exit 2') !== false, 'and names tar\'s exit status', (string)$error);
	check(s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug . '/manager/' . $chain_id . '/data-0002.tar.gz.enc') === null,
		'no data-0002 object is in backup storage');
	check(s3fx_keys($fx) === $objects_before, 'backup storage holds exactly what it held before the failed run');
	check(s3fx_count($fx, 'put') === $puts_before, 'nothing was PUT for the refused archive',
		'the small-stream path holds its buffer until the engine\'s verdict');
	check($snap_state() === $snaps_before && !glob($plan['output_dir'] . '/.*.held') && !glob($plan['output_dir'] . '/.*.none'),
		'the snapshots were put back as they stood before the run, so the next run increments on the last committed one');
	check(file_get_contents($chain_d . '/manifest.json') === $manifest_before, 'the manifest was put back');
	check(!glob($chain_d . '/*-0002*'), 'no run-2 artifact is on disk');
	check($history3->get('bkh_outcome') === 'running' || $history3->get('bkh_outcome') === 'failed',
		'the run never became a success row', (string)$history3->get('bkh_outcome'));
}

// ─────────────────────────────────────────────────────────────────────────────
section('A database failure after the tree objects went up');

// The chain continues (a failed run puts its snapshots back): data-0002 and
// code-0002 stream and complete, then the dump fails (no such database).
// Under the manager credential those two objects are the bounded orphans the
// spec accepts; a site-profile plan deletes them.
$bad_plan = $plan;
$bad_plan['database'] = 'jy_no_such_db_' . getmypid();
$run_bad = function (array $p) use ($execute, $slug) {
	$history = new BackupHistory(NULL);
	$history->set('bkh_type', 'project');
	$history->set('bkh_outcome', 'running');
	$history->set('bkh_slug', $slug);
	$history->set('bkh_profile', $p['profile']);
	$history->set('bkh_recovery_fpr', $p['recovery_fpr']);
	$history->set('bkh_encrypted', true);
	$history->set('bkh_target_name', 'local fixture');
	$history->save();
	harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $history->key);
	try { $execute->invoke(null, $p, $history); return null; } catch (\Throwable $e) { return $e->getMessage(); }
};
$keys_before = s3fx_keys($fx);
$manifest_before = file_get_contents($chain_d . '/manifest.json');
$snaps_before = $snap_state();
$err = $run_bad($bad_plan);
check($err !== null && stripos($err, 'pg_dump exit') !== false, 'the run fails naming pg_dump', (string)$err);
$new_keys = array_values(array_diff(s3fx_keys($fx), $keys_before));
sort($new_keys);
check(count($new_keys) === 2 && preg_match('#/' . preg_quote($chain_id, '#') . '/code-0002\.tar\.gz\.enc$#', $new_keys[0])
	&& preg_match('#/' . preg_quote($chain_id, '#') . '/data-0002\.tar\.gz\.enc$#', $new_keys[1]),
	'under the write-only manager credential the completed data and code objects stay (bounded orphans); nothing else landed', implode(',', $new_keys));
check($snap_state() === $snaps_before, 'the snapshots were put back');
check(file_get_contents($chain_d . '/manifest.json') === $manifest_before, 'the chain\'s manifest was put back, so the next run extends it');

$site_like = $bad_plan;
$site_like['prunes_cloud'] = true;
sleep(1);   // chain ids carry a one-second stamp; a second failed run in the same second would reuse the first's
$keys_before = s3fx_keys($fx);
$deletes_before = s3fx_count($fx, 'delete');
$err = $run_bad($site_like);
check($err !== null, 'the site-shaped run fails the same way');
// The same run number in the same chain: it writes over the manager run's
// two orphans and then deletes its own, so those names are gone and nothing
// else changed.
$removed = array_values(array_diff($keys_before, s3fx_keys($fx)));
sort($removed);
check(!array_diff(s3fx_keys($fx), $keys_before) && count($removed) === 2
	&& preg_match('#/code-0002\.tar\.gz\.enc$#', $removed[0]) && preg_match('#/data-0002\.tar\.gz\.enc$#', $removed[1]),
	'a credential that can delete leaves no orphan behind: its run-2 objects are gone and nothing else changed',
	'added: ' . implode(',', array_diff(s3fx_keys($fx), $keys_before)) . ' removed: ' . implode(',', $removed));
check(s3fx_count($fx, 'delete') === $deletes_before + 2, 'exactly one delete was issued for each tree object');

// ─────────────────────────────────────────────────────────────────────────────
section('An upgrade swaps public_html: the code starts over inside the chain');

// utils/upgrade.php's deploy: every child of public_html moves out and the
// staged ones move in. The data (config/) is untouched and keeps incrementing.
$stage = $work . '/staged_public_html';
@mkdir($stage . '/sub', 0755, true);
file_put_contents($stage . '/index.php', '<?php echo "upgraded";');
file_put_contents($stage . '/sub/data.bin', random_bytes(40000));
$aside = $work . '/public_html_last';
@mkdir($aside, 0755, true);
exec('find ' . escapeshellarg($tree . '/public_html') . ' -mindepth 1 -maxdepth 1 -exec mv -t ' . escapeshellarg($aside) . ' {} +');
exec('find ' . escapeshellarg($stage) . ' -mindepth 1 -maxdepth 1 -exec mv -t ' . escapeshellarg($tree . '/public_html') . ' {} +');
file_put_contents($tree . '/config/after_upgrade.txt', 'written after the upgrade');
$runs_before = count(BackupChain::read($chain_d . '/manifest.json')['runs']);

list($result, $error) = $run();
check($error === null && ($result['status'] ?? '') === 'success', 'the run after the upgrade succeeds', (string)$error);
check(count(glob($plan['output_dir'] . '/' . BackupChain::DIR_PREFIX . '*', GLOB_ONLYDIR) ?: array()) === 1
	&& basename($chain_dirs[0]) === $chain_id, 'in the same chain — an upgrade no longer starts one');
$manifest = BackupChain::read($chain_d . '/manifest.json');
$up_run = $manifest['runs'][$runs_before] ?? array();
check((int)($up_run['artifacts']['code']['level'] ?? -1) === 0 && ($up_run['artifacts']['code']['rebased_because'] ?? '') === 'tree_changed',
	'the code started over, saying why', json_encode($up_run['artifacts']['code'] ?? null));
check((int)($up_run['artifacts']['data']['level'] ?? -1) === 1, 'the data incremented across the upgrade');
check((int)($up_run['level'] ?? -1) === 1, 'the run is not a full: its data depends on the runs before it');
check(strpos((string)$result['message'], 'Incremental backup') === 0 && strpos((string)$result['message'], ' full,') !== false,
	'the message says incremental and marks the code full', (string)$result['message']);
list($rc, $lines) = $decrypt_list(s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug . '/manager/' . $chain_id . '/'
	. $up_run['artifacts']['data']['name']), $data_key);
check($rc === 0 && in_array('site/config/after_upgrade.txt', $lines, true) && !in_array('site/config/site.txt', $lines, true),
	'the data increment carries only what changed', implode(' ', $lines));
$plan_up = BackupChain::restore_plan($manifest);
check(count($plan_up['trees']['code']) === 1 && count($plan_up['trees']['data']) === $runs_before + 1,
	'a restore of it takes the code from its re-base and the data from the chain\'s full');

list($result, $error) = $run();
$manifest = BackupChain::read($chain_d . '/manifest.json');
$next = $manifest['runs'][$runs_before + 1] ?? array();
check($error === null && (int)($next['artifacts']['code']['level'] ?? -1) === 1 && (int)($next['artifacts']['data']['level'] ?? -1) === 1,
	'the next run increments both', (string)$error);

// ─────────────────────────────────────────────────────────────────────────────
section('A run killed outright is undone by the next one');

// A run killed after its engines advanced the snapshots and before it could
// put them back leaves its held copies on disk. Simulated: the held copies of
// the committed snapshots, and the snapshots themselves overwritten with junk.
$committed = $snap_state();
foreach ($snars as $sn) {
	copy($sn, $sn . '.held'); copy($sn . '.tree', $sn . '.tree.held');
	file_put_contents($sn, 'junk an advanced snapshot would be'); file_put_contents($sn . '.tree', 'junk');
}
file_put_contents($tree . '/config/after_kill.txt', 'written after the killed run');
$runs_before = count(BackupChain::read($chain_d . '/manifest.json')['runs']);
list($result, $error) = $run();
$manifest = BackupChain::read($chain_d . '/manifest.json');
$after_kill = $manifest['runs'][$runs_before] ?? array();
check($error === null && (int)($after_kill['artifacts']['data']['level'] ?? -1) === 1 && (int)($after_kill['artifacts']['code']['level'] ?? -1) === 1,
	'the next run puts the held snapshots back and increments both parts', (string)$error . ' ' . json_encode($after_kill['artifacts'] ?? null));
check(!glob($plan['output_dir'] . '/.*.held') && !glob($plan['output_dir'] . '/.*.none'), 'and nothing is left held');
list($rc, $lines) = $decrypt_list(s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug . '/manager/' . $chain_id . '/'
	. $after_kill['artifacts']['data']['name']), $data_key);
check($rc === 0 && in_array('site/config/after_kill.txt', $lines, true) && !in_array('site/config/after_upgrade.txt', $lines, true),
	'its data increment is against the last committed run', implode(' ', $lines));

// ─────────────────────────────────────────────────────────────────────────────
section('A version-1 chain ends with layout_split');

// A site whose last chain was written before code and data were split: one
// files archive a run and a single snapshot. The runner does not extend it.
$v1_slug = $slug . '-v1';
$v1_plan = $plan;
$v1_plan['slug'] = $v1_slug;
$v1_plan['output_dir'] = $out . '/v1';
@mkdir($v1_plan['output_dir'], 0700, true);
$v1_mint = BackupEnvelope::mint('chain-20260901_040000', $v1_plan['recipients']);
$v1_manifest = BackupChain::add_run(BackupChain::start('chain-20260901_040000', $v1_slug, $v1_mint['envelope'], 'no_chain'), 0, 0,
	array('files' => array('name' => 'files-0000.tar.gz.enc', 'bytes' => 10, 'sha256' => str_repeat('a', 64))));
@mkdir($v1_plan['output_dir'] . '/chain-20260901_040000', 0700, true);
BackupChain::write($v1_manifest, $v1_plan['output_dir'] . '/chain-20260901_040000/manifest.json');
$old_snar = $v1_plan['output_dir'] . '/.' . $v1_slug . '.snar';
file_put_contents($old_snar, 'old snapshot'); file_put_contents($old_snar . '.tree', 'old tree');
$v1_hist = new BackupHistory(NULL);
$v1_hist->set('bkh_type', 'project'); $v1_hist->set('bkh_outcome', 'success'); $v1_hist->set('bkh_slug', $v1_slug);
$v1_hist->set('bkh_profile', $v1_plan['profile']); $v1_hist->set('bkh_chain_id', 'chain-20260901_040000');
$v1_hist->set('bkh_chain_seq', 0); $v1_hist->set('bkh_encrypted', true); $v1_hist->set('bkh_target_name', 'local fixture');
$v1_hist->save();
harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $v1_hist->key);

$v1_run = function () use ($execute, $v1_plan) {
	$history = new BackupHistory(NULL);
	$history->set('bkh_type', 'project'); $history->set('bkh_outcome', 'running'); $history->set('bkh_slug', $v1_plan['slug']);
	$history->set('bkh_profile', $v1_plan['profile']); $history->set('bkh_recovery_fpr', $v1_plan['recovery_fpr']);
	$history->set('bkh_encrypted', true); $history->set('bkh_target_name', 'local fixture');
	$history->save();
	harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $history->key);
	try { return array($execute->invoke(null, $v1_plan, $history), null, $history); } catch (\Throwable $e) { return array(null, $e->getMessage(), $history); }
};
list($result, $error, $v1_new) = $v1_run();
check($error === null && strpos((string)$v1_new->get('bkh_message'), 'new chain: layout_split') !== false,
	'the run starts a new chain, saying layout_split', (string)$error . ' ' . (string)$v1_new->get('bkh_message'));
check((string)$v1_new->get('bkh_chain_id') !== 'chain-20260901_040000', 'not the version-1 chain');
check(!is_file($old_snar) && !is_file($old_snar . '.tree'), 'the version-1 snapshot is deleted: nothing reads it any more');
$v2_m = BackupChain::read($v1_plan['output_dir'] . '/' . $v1_new->get('bkh_chain_id') . '/manifest.json');
check((int)$v2_m['version'] === 2 && ($v2_m['started_because'] ?? '') === 'layout_split', 'the new chain is version 2 and says why it started');

// ─────────────────────────────────────────────────────────────────────────────
section('A database-only run streams the dump; only the sidecar is written');

$db_dir = $out . '/dbonly';
@mkdir($db_dir, 0700, true);
$db_plan = BackupRunner::plan(array('profile' => 'manager', 'manager' => array(
	'bucket' => 'bkt', 'credentials' => s3fx_creds($fx), 'slug' => $slug,
	'type' => 'database', 'delete_local_after_upload' => 0, 'keep_local_days' => 0,
	'target_name' => 'local fixture',
)));
$db_plan['base_dir']   = $out;
$db_plan['output_dir'] = $db_dir;
$db_plan['database']   = $dbname;
check($db_plan['mode'] === 'full' && $db_plan['type'] === 'database', 'a database-only plan is a standalone run');
$execute_full = new ReflectionMethod('BackupRunner', 'execute_full');
$execute_full->setAccessible(true);
$hist_db = new BackupHistory(NULL);
$hist_db->set('bkh_type', 'database');
$hist_db->set('bkh_outcome', 'running');
$hist_db->set('bkh_slug', $slug);
$hist_db->set('bkh_profile', 'manager');
$hist_db->set('bkh_recovery_fpr', $db_plan['recovery_fpr']);
$hist_db->set('bkh_encrypted', true);
$hist_db->set('bkh_target_name', 'local fixture');
$hist_db->save();
harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $hist_db->key);
$db_error = null;
try { $db_result = $execute_full->invoke(null, $db_plan, $hist_db); } catch (\Throwable $e) { $db_result = null; $db_error = $e->getMessage(); }
check($db_error === null && ($db_result['status'] ?? '') === 'success', 'the database-only run succeeds', (string)$db_error);
$left = array_values(array_diff(scandir($db_dir) ?: array(), array('.', '..')));
check(count($left) === 1 && preg_match('/^' . preg_quote($dbname, '/') . '-\d{8}_\d{6}\.sql\.gz\.enc\.keys\.json$/', $left[0]),
	'the output directory holds exactly the envelope sidecar', implode(',', $left));
$db_obj_name = substr($left[0] ?? '', 0, -strlen(BackupEnvelope::SIDECAR_SUFFIX));
$db_obj = s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug . '/manager/' . $db_obj_name);
check($db_obj !== null && strlen($db_obj) > 64, 'the dump object is in backup storage', $db_obj_name);
check(s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug . '/manager/' . $db_obj_name . BackupEnvelope::SIDECAR_SUFFIX) !== null, 'with its sidecar');
$db_env = BackupEnvelope::read_sidecar($db_dir . '/' . $left[0]);
list($rc, $sql) = $decrypt_sql($db_obj, BackupEnvelope::open_as_site($db_env));
check($rc === 0 && strpos($sql, 'COPY public.t') !== false, 'the dump opens with the sidecar\'s key and carries the table', 'rc ' . $rc);
check(!glob('/tmp/jy_backup_*'), 'no plaintext dump temp file exists');

// ─────────────────────────────────────────────────────────────────────────────
section('A standalone whole-site run streams its archive; only the sidecar is written');

// The tree says it is a container (no vhost to find) and names the throwaway
// database, so backup_project.sh dumps it and records a shape.
file_put_contents($tree . '/config/Globalvars_site.php', "<?php\n"
	. "\$this->settings['deployment_environment'] = 'docker';\n"
	. "\$this->settings['dbusername'] = 'postgres';\n"
	. "\$this->settings['dbname'] = '" . $dbname . "';\n");
$full_dir = $out . '/full';
@mkdir($full_dir, 0700, true);
$full_plan = BackupRunner::plan(array('profile' => 'manager', 'manager' => array(
	'bucket' => 'bkt', 'credentials' => s3fx_creds($fx), 'slug' => $slug,
	'type' => 'project', 'mode' => 'full', 'delete_local_after_upload' => 0, 'keep_local_days' => 0,
	'target_name' => 'local fixture',
)));
$full_plan['base_dir']    = $out;
$full_plan['output_dir']  = $full_dir;
$full_plan['project']     = $dbname;      // the engine finds the database by the project name
$full_plan['project_dir'] = $tree;
$full_plan['database']    = $dbname;

// backup_project.sh asks psql whether the database exists before it dumps;
// the throwaway tree's config carries no password, so hand it the one the
// site uses, the way a shell run would.
$pw = (string)Globalvars::get_instance()->get_setting('dbpassword', true, true);
putenv('PGPASSWORD=' . $pw);
$hist_full = new BackupHistory(NULL);
$hist_full->set('bkh_type', 'project');
$hist_full->set('bkh_outcome', 'running');
$hist_full->set('bkh_slug', $slug);
$hist_full->set('bkh_profile', 'manager');
$hist_full->set('bkh_recovery_fpr', $full_plan['recovery_fpr']);
$hist_full->set('bkh_encrypted', true);
$hist_full->set('bkh_target_name', 'local fixture');
$hist_full->save();
harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $hist_full->key);
$full_error = null;
try {
	$full_result = $execute_full->invoke(null, $full_plan, $hist_full);
} catch (\Throwable $e) {
	$full_result = null;
	$full_error = $e->getMessage();
}
putenv('PGPASSWORD');

check($full_error === null && ($full_result['status'] ?? '') === 'success', 'the standalone run succeeds', (string)$full_error);
$left = array_values(array_diff(scandir($full_dir) ?: array(), array('.', '..')));
check(count($left) === 1 && preg_match('/^' . preg_quote($dbname, '/') . '-\d{8}_\d{6}\.tar\.gz\.enc\.keys\.json$/', $left[0]),
	'the output directory holds exactly the envelope sidecar', implode(',', $left));
check(!glob($full_dir . '/.staging_*'), 'no staging directory was left behind');
$sidecar_name = $left[0] ?? '';
$object_name = substr($sidecar_name, 0, -strlen(BackupEnvelope::SIDECAR_SUFFIX));
$full_key = 'joinery-backups/' . $slug . '/manager/' . $object_name;
$full_object = s3fx_object($fx, 'bkt', '/' . $full_key);
check($full_object !== null && strlen($full_object) > 64, 'the archive object is in backup storage', $full_key);
check(s3fx_object($fx, 'bkt', '/' . $full_key . BackupEnvelope::SIDECAR_SUFFIX) !== null, 'and its sidecar beside it');
$full_arts = $hist_full->artifacts();
check(count($full_arts) === 2 && ($full_arts[0]['kind'] ?? '') === 'archive' && !isset($full_arts[0]['path'])
	&& (int)$full_arts[0]['bytes'] === strlen((string)$full_object) && ($full_arts[0]['key'] ?? '') === $full_key,
	'the history records the streamed archive by key and size', json_encode($full_arts));
$env = BackupEnvelope::read_sidecar($full_dir . '/' . $sidecar_name);
check(($env['artifact'] ?? '') === $object_name, 'the envelope names the object', (string)($env['artifact'] ?? ''));
$full_data_key = BackupEnvelope::open_as_site($env);
list($rc, $lines) = $decrypt_list($full_object, $full_data_key);
$top = substr($object_name, 0, -strlen('.tar.gz.enc'));
check($rc === 0 && in_array($top . '/project_files/public_html/index.php', $lines, true),
	'the archive carries the live tree under project_files/', 'rc ' . $rc . ': ' . implode(' ', array_slice($lines, 0, 8)));
$dump_members = array_filter($lines, function ($l) use ($top) { return preg_match('#^' . preg_quote($top, '#') . '/[^/]+\.sql\.gz\.enc$#', $l); });
check(count($dump_members) === 1, 'and the database dump at its root', implode(' ', $lines));
check(in_array($top . '/shape.json', $lines, true), 'and shape.json');
check(strpos((string)$full_result['message'], 'Backed up ' . $object_name) === 0, 'the run message names the object', $full_result['message']);

// ─────────────────────────────────────────────────────────────────────────────
section('full_size_warning() sees the streamed byte count');

// The first run's full is on record with the streamed size. A "full" a tenth
// of it is flagged; one of the same size is not.
$full_bytes = (int)$data0['bytes'];
check($full_bytes > 0, 'the recorded full has a size', (string)$full_bytes);
$w = BackupRunner::full_size_warning($plan, (int)floor($full_bytes / 20), 0);
check($w !== '' && strpos($w, BackupRunner::human($full_bytes)) !== false, 'a full a twentieth the streamed size is flagged against it', $w);
check(BackupRunner::full_size_warning($plan, $full_bytes, 0) === '', 'a full the same size is not');

harness_finish();

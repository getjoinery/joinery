<?php
/** @joinery-test
 * name: backup_runner_stream
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * A chain run whose files archive never lands on disk.
 *
 * The files engine streams tar's output straight into the bucket; the run
 * learns whether tar succeeded only after the bytes have gone, and must
 * complete the upload only then. What is pinned here, against a local
 * provider and a throwaway tree:
 *
 *   - a chain run leaves no files archive in the chain directory
 *   - the manifest's bytes and sha256 equal the object the bucket holds
 *   - the object decrypts to the tree that was archived
 *   - a second run extends the chain as an incremental, also streamed
 *   - an engine that fails after streaming (tar exit 2 on an unreadable file)
 *     leaves nothing on the fixture, and the run fails under the existing
 *     discard rule: snapshot cleared, manifest restored, no history row
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
section('A chain run streams its files archive: nothing lands in the chain directory');

list($result, $error, $history) = $run();
check($error === null && ($result['status'] ?? '') === 'success', 'the run succeeds', (string)$error . ' ' . json_encode($result));

$chain_dirs = glob($plan['output_dir'] . '/' . BackupChain::DIR_PREFIX . '*', GLOB_ONLYDIR) ?: array();
check(count($chain_dirs) === 1, 'one chain directory was made', count($chain_dirs) . ' found');
$chain_d = $chain_dirs[0] ?? '';
$chain_id = basename($chain_d);
check(!is_file($chain_d . '/files-0000.tar.gz.enc'), 'no files archive is on disk', implode(',', array_map('basename', glob($chain_d . '/*') ?: array())));
check(!glob($chain_d . '/.files-report-*'), 'the engine report was removed');
check(is_file($chain_d . '/manifest.json'), 'the manifest is on disk');

$manifest = BackupChain::read($chain_d . '/manifest.json');
$run0 = $manifest['runs'][0] ?? array();
$files0 = $run0['artifacts']['files'] ?? array();
$key = 'joinery-backups/' . $slug . '/manager/' . $chain_id . '/files-0000.tar.gz.enc';
$object = s3fx_object($fx, 'bkt', '/' . $key);
check($object !== null, 'the files object is on the shelf under the chain key', $key);
check($object !== null && (int)$files0['bytes'] === strlen($object), 'the manifest\'s bytes equal the object\'s', ($files0['bytes'] ?? '?') . ' vs ' . strlen((string)$object));
check($object !== null && $files0['sha256'] === hash('sha256', $object), 'the manifest\'s sha256 equals the object\'s');
check((int)$run0['level'] === 0, 'the first run is a full');
$db_object = s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug . '/manager/' . $chain_id . '/db-0000.sql.gz.enc');
check($db_object !== null, 'the database dump is on the shelf');
check(!is_file($chain_d . '/db-0000.sql.gz.enc') && !glob($chain_d . '/*.sql.gz.enc'), 'and no dump is on disk',
	implode(',', array_map('basename', glob($chain_d . '/*') ?: array())));
$db0 = $run0['artifacts']['db'] ?? array();
check($db_object !== null && (int)$db0['bytes'] === strlen($db_object) && $db0['sha256'] === hash('sha256', $db_object),
	'the manifest\'s dump bytes and sha256 equal the object\'s');
check(!glob('/tmp/jy_backup_*'), 'no plaintext dump temp file exists');
check(s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug . '/manager/' . $chain_id . '/manifest.json') !== null, 'the manifest is on the shelf');

$history_artifacts = $history->artifacts();
$files_hist = null;
foreach ($history_artifacts as $a) { if (($a['kind'] ?? '') === 'files') { $files_hist = $a; } }
check($files_hist !== null && ($files_hist['key'] ?? '') === $key && !isset($files_hist['path']),
	'the history records the files artifact by key, with no local path', json_encode($files_hist));
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
check($rc === 0 && in_array('site/public_html/sub/data.bin', $lines, true) && in_array('site/config/site.txt', $lines, true),
	'the object decrypts to the archived tree', 'rc ' . $rc . ': ' . implode(' ', array_slice($lines, 0, 6)));

$snar = $plan['output_dir'] . '/.' . $slug . '.snar';
check(is_file($snar) && filesize($snar) > 0, 'the snapshot advanced');

// ─────────────────────────────────────────────────────────────────────────────
section('A second run extends the chain as a streamed incremental');

file_put_contents($tree . '/public_html/new.txt', 'added after the full');
list($result, $error, $history2) = $run();
check($error === null && ($result['status'] ?? '') === 'success', 'the second run succeeds', (string)$error);
$manifest = BackupChain::read($chain_d . '/manifest.json');
check(count($manifest['runs']) === 2 && (int)$manifest['runs'][1]['level'] === 1, 'the chain has two runs, the second incremental',
	json_encode(array_map(function ($r) { return $r['level']; }, $manifest['runs'])));
$object1 = s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug . '/manager/' . $chain_id . '/files-0001.tar.gz.enc');
check($object1 !== null && (int)$manifest['runs'][1]['artifacts']['files']['bytes'] === strlen($object1), 'the incremental is on the shelf with its recorded size');
check(!is_file($chain_d . '/files-0001.tar.gz.enc'), 'and not on disk');
list($rc, $lines) = $decrypt_list($object1, $data_key);
check($rc === 0 && in_array('site/public_html/new.txt', $lines, true) && !in_array('site/public_html/sub/data.bin', $lines, true),
	'the incremental carries the new file and not the unchanged one', implode(' ', $lines));
check(strpos((string)$result['message'], 'Incremental backup') === 0, 'the run message says incremental', $result['message']);

// ─────────────────────────────────────────────────────────────────────────────
section('An engine that fails after streaming leaves nothing on the shelf and the run is discarded');

$has_sudo = false;
exec('sudo -n -l 2>/dev/null', $sudo_out, $sudo_rc);
foreach ($sudo_out as $l) { if (preg_match('/NOPASSWD:([[:space:]]*[A-Z]+:)*[[:space:]]*ALL([[:space:]]|$)/', $l)) { $has_sudo = true; } }
if ($has_sudo) {
	harness_skip('tar failure after streaming', 'this account has passwordless sudo, so no file is unreadable to the engine');
} else {
	$unreadable = $tree . '/public_html/secret.bin';
	file_put_contents($unreadable, random_bytes(3000));
	chmod($unreadable, 0000);
	$manifest_before = file_get_contents($chain_d . '/manifest.json');
	$objects_before = s3fx_keys($fx);
	$aborts_before = s3fx_count($fx, 'abort');
	$puts_before = s3fx_count($fx, 'put');

	list($result, $error, $history3) = $run();
	chmod($unreadable, 0600);
	@unlink($unreadable);

	check($result === null && $error !== null, 'the run fails', json_encode($result));
	check(stripos((string)$error, 'tar exit 2') !== false, 'and names tar\'s exit status', (string)$error);
	check(s3fx_object($fx, 'bkt', '/joinery-backups/' . $slug . '/manager/' . $chain_id . '/files-0002.tar.gz.enc') === null,
		'no files-0002 object is on the shelf');
	check(s3fx_keys($fx) === $objects_before, 'the shelf holds exactly what it held before the failed run');
	check(s3fx_count($fx, 'put') === $puts_before, 'nothing was PUT for the refused archive',
		'the small-stream path holds its buffer until the engine\'s verdict');
	check(!is_file($snar), 'the snapshot was cleared, so the next run starts a fresh chain');
	check(file_get_contents($chain_d . '/manifest.json') === $manifest_before, 'the manifest was put back');
	check(!glob($chain_d . '/files-0002*') && !glob($chain_d . '/db-0002*'), 'no run-2 artifact is on disk');
	check($history3->get('bkh_outcome') === 'running' || $history3->get('bkh_outcome') === 'failed',
		'the run never became a success row', (string)$history3->get('bkh_outcome'));
}

// ─────────────────────────────────────────────────────────────────────────────
section('A database failure after the files object went up');

// The chain was abandoned by the failed run above, so this starts a fresh
// one: files-0000 streams and completes, then the dump fails (no such
// database). Under the manager credential the files object is the bounded
// orphan the spec accepts; a site-profile plan deletes it.
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
$err = $run_bad($bad_plan);
check($err !== null && stripos($err, 'pg_dump exit') !== false, 'the run fails naming pg_dump', (string)$err);
$new_keys = array_values(array_diff(s3fx_keys($fx), $keys_before));
check(count($new_keys) === 1 && preg_match('#/chain-\d{8}_\d{6}/files-0000\.tar\.gz\.enc$#', $new_keys[0]),
	'under the write-only manager credential the completed files object stays (bounded orphan); nothing else landed', implode(',', $new_keys));
check(!is_file($snar), 'the snapshot was cleared');
$orphan_chain = dirname($new_keys[0] ?? '');
check(!is_dir($plan['output_dir'] . '/' . basename($orphan_chain)), 'the new chain\'s empty local directory was removed');

$site_like = $bad_plan;
$site_like['prunes_cloud'] = true;
sleep(1);   // chain ids carry a one-second stamp; a second failed run in the same second would reuse the first's
$keys_before = s3fx_keys($fx);
$deletes_before = s3fx_count($fx, 'delete');
$err = $run_bad($site_like);
check($err !== null, 'the site-shaped run fails the same way');
check(s3fx_keys($fx) === $keys_before, 'a credential that can delete leaves no orphan behind',
	'added: ' . implode(',', array_diff(s3fx_keys($fx), $keys_before)) . ' removed: ' . implode(',', array_diff($keys_before, s3fx_keys($fx))));
check(s3fx_count($fx, 'delete') === $deletes_before + 1, 'exactly one delete was issued for the files object');

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
check($db_obj !== null && strlen($db_obj) > 64, 'the dump object is on the shelf', $db_obj_name);
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
check($full_object !== null && strlen($full_object) > 64, 'the archive object is on the shelf', $full_key);
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
$full_bytes = (int)$files0['bytes'];
check($full_bytes > 0, 'the recorded full has a size', (string)$full_bytes);
$w = BackupRunner::full_size_warning($plan, (int)floor($full_bytes / 20), 0);
check($w !== '' && strpos($w, BackupRunner::human($full_bytes)) !== false, 'a full a twentieth the streamed size is flagged against it', $w);
check(BackupRunner::full_size_warning($plan, $full_bytes, 0) === '', 'a full the same size is not');

harness_finish();

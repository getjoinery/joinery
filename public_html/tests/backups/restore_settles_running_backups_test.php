<?php
/** @joinery-test
 * name: restore_settles_running_backups
 * tier: db
 * env: dev-only
 * needs: []
 * covers: [maintenance_scripts/sysadmin_tools/restore_database.sh, maintenance_scripts/sysadmin_tools/restore_chain.sh]
 */
/**
 * A restored database does not list the backup that made it as running (B19).
 *
 * A backup run records itself as running before it dumps the database, so
 * every dump holds that run as running, and any run that was stopped before it
 * recorded an end. Loaded on another machine (a site copy, a disaster
 * restore), nothing would ever finish them. restore_database.sh settles them
 * after the load:
 *
 *   - the run a chain restore names (--restored-run) is recorded as finished,
 *     with its chain, run, size and time: its archive is what was loaded;
 *   - every other running row is marked never finished;
 *   - finished rows are left as they are, and a dump with no backup history
 *     table restores as before;
 *   - restore_chain.sh passes the run it restores from the manifest.
 *
 * Runs the script, from a copy in a throwaway tree, into a scratch database.
 *
 * Run: php tests/backups/restore_settles_running_backups_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$settings = Globalvars::get_instance();
$db_user  = (string)$settings->get_setting('dbusername');
$db_pass  = (string)$settings->get_setting('dbpassword');
$live_db  = (string)$settings->get_setting('dbname');

// Not jy_restore_*: restore_database.sh sweeps that prefix in /tmp.
$tmp = sys_get_temp_dir() . '/jy_rsrb_' . bin2hex(random_bytes(4));
$tools = $tmp . '/tree/maintenance_scripts/sysadmin_tools';
foreach (array($tools, $tmp . '/home', $tmp . '/backups') as $dir) {
	if (!mkdir($dir, 0777, true)) { throw new Exception("could not create {$dir}"); }
}
harness_defer(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });
copy(PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/restore_database.sh', $tools . '/restore_database.sh');
$engine = $tools . '/restore_database.sh';

$scratch = 'jy_rsrb_' . bin2hex(random_bytes(4));
function rsrb_psql($sql, $db, $user, $pass) {
	$out = array(); $rc = 0;
	exec(sprintf('PGPASSWORD=%s psql -U %s -d %s -XtAc %s 2>&1', escapeshellarg($pass), escapeshellarg($user),
		escapeshellarg($db), escapeshellarg($sql)), $out, $rc);
	return array($rc, trim(implode("\n", $out)));
}
list($rc, $out) = rsrb_psql("CREATE DATABASE {$scratch}", $live_db, $db_user, $db_pass);
if ($rc !== 0) {
	check(false, 'a scratch database can be created for the restore to land in', $out);
	harness_finish();
	return;
}
harness_defer(function () use ($scratch, $live_db, $db_user, $db_pass) {
	rsrb_psql("DROP DATABASE IF EXISTS {$scratch}", $live_db, $db_user, $db_pass);
});

/** A dump holding the backup history a source had when its run dumped it. */
function rsrb_dump($path, $with_table = true) {
	$sql = "CREATE TABLE marker (id int);\n";
	if ($with_table) {
		$sql .= "CREATE TABLE bkh_backup_history (bkh_backup_history_id serial PRIMARY KEY, bkh_type varchar(20),
			bkh_outcome varchar(20), bkh_start_time timestamp(6), bkh_finish_time timestamp(6), bkh_upload_time timestamp(6),
			bkh_chain_id varchar(64), bkh_chain_seq int4, bkh_bytes int8 DEFAULT 0, bkh_message text);\n"
			// An earlier run that finished, one stopped long ago, and the run taking this dump.
			. "INSERT INTO bkh_backup_history (bkh_type, bkh_outcome, bkh_start_time, bkh_finish_time, bkh_chain_id, bkh_chain_seq, bkh_message)
				VALUES ('project', 'success', '2026-10-19 23:00:00', '2026-10-19 23:04:00', 'chain-20261019_230000', 0, 'Full run 0');\n"
			. "INSERT INTO bkh_backup_history (bkh_type, bkh_outcome, bkh_start_time) VALUES ('project', 'running', '2026-10-10 01:00:00');\n"
			. "INSERT INTO bkh_backup_history (bkh_type, bkh_outcome, bkh_start_time) VALUES ('project', 'running', '2026-10-20 03:49:00');\n";
	}
	file_put_contents($path, $sql);
}
function rsrb_restore($engine, $scratch, $dump, $home, $db_user, $db_pass, array $extra = array()) {
	$cmd = 'bash ' . escapeshellarg($engine) . ' ' . escapeshellarg($scratch) . ' ' . escapeshellarg($dump)
		. ' --non-interactive --db-user ' . escapeshellarg($db_user);
	foreach ($extra as $a) { $cmd .= ' ' . escapeshellarg($a); }
	$pipes = array();
	$proc = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null,
		array('PATH' => getenv('PATH'), 'HOME' => $home, 'PGPASSWORD' => $db_pass));
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
	$stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
	proc_close($proc);
	return array(trim($stdout), $stderr);
}
$rows = function () use ($scratch, $db_user, $db_pass) {
	list(, $out) = rsrb_psql("SELECT bkh_start_time::text, bkh_outcome, coalesce(bkh_chain_id, ''), coalesce(bkh_chain_seq::text, ''),
		bkh_bytes, coalesce(bkh_finish_time::text, ''), coalesce(bkh_upload_time::text, ''), coalesce(bkh_message, '')
		FROM bkh_backup_history ORDER BY bkh_start_time", $scratch, $db_user, $db_pass);
	$by = array();
	foreach (array_filter(explode("\n", $out)) as $line) {
		$f = explode('|', $line);
		$by[substr($f[0], 0, 16)] = array('outcome' => $f[1], 'chain' => $f[2], 'seq' => $f[3], 'bytes' => $f[4],
			'finish' => $f[5], 'upload' => $f[6], 'message' => $f[7]);
	}
	return $by;
};
$dump = $tmp . '/backups/source.sql';
$recorded = gmmktime(3, 55, 30, 10, 20, 2026);

section('The run a chain restore names is recorded as finished, and any other as never finished');

rsrb_dump($dump);
list($marker, $err) = rsrb_restore($engine, $scratch, $dump, $tmp . '/home', $db_user, $db_pass,
	array('--restored-run', 'chain-20261019_235211:7:1:123456:' . $recorded));
check($marker === 'RESTORE_OK', 'the restore succeeds', $marker . ' ' . mb_substr($err, -400));
$r = $rows();
$run = $r['2026-10-20 03:49'] ?? array();
check(($run['outcome'] ?? '') === 'success' && $run['chain'] === 'chain-20261019_235211' && $run['seq'] === '7'
		&& $run['bytes'] === '123456' && strpos($run['finish'], '2026-10-20 03:55:30') === 0 && $run['upload'] !== '',
	'the run that took the dump is finished, with its chain, run, size and time', json_encode($run));
check(strpos($run['message'] ?? '', 'Incremental run 7 of chain-20261019_235211') === 0,
	'and says what it was and where it finished', (string)($run['message'] ?? ''));
$stale = $r['2026-10-10 01:00'] ?? array();
check(($stale['outcome'] ?? '') === 'failed' && strpos($stale['message'], 'Never finished') === 0,
	'a run stopped long before is marked never finished', json_encode($stale));
check(($r['2026-10-19 23:00'] ?? array())['message'] === 'Full run 0', 'a finished run is left as it was');
check(strpos($err, 'as finished') !== false && strpos($err, '1 other backup run') !== false,
	'the restore says what it settled', mb_substr($err, -600));

section('A restore that names no run marks every running row never finished');

rsrb_dump($dump);
list($marker, ) = rsrb_restore($engine, $scratch, $dump, $tmp . '/home', $db_user, $db_pass);
$r = $rows();
check($marker === 'RESTORE_OK' && $r['2026-10-20 03:49']['outcome'] === 'failed' && $r['2026-10-10 01:00']['outcome'] === 'failed',
	'nothing is left running', json_encode($r));

section('A malformed run is not used, and a dump with no backup history restores as before');

rsrb_dump($dump);
list($marker, ) = rsrb_restore($engine, $scratch, $dump, $tmp . '/home', $db_user, $db_pass,
	array('--restored-run', "chain-x';DROP TABLE marker;--:7:1:1:" . $recorded));
list(, $marker_table) = rsrb_psql("SELECT to_regclass('public.marker') IS NOT NULL", $scratch, $db_user, $db_pass);
check($marker === 'RESTORE_OK' && $rows()['2026-10-20 03:49']['outcome'] === 'failed' && $marker_table === 't',
	'a run that is not a chain, run, level, size and time is ignored, and runs nothing');
rsrb_dump($dump, false);
list($marker, $err) = rsrb_restore($engine, $scratch, $dump, $tmp . '/home', $db_user, $db_pass,
	array('--restored-run', 'chain-20261019_235211:7:1:123456:' . $recorded));
check($marker === 'RESTORE_OK', 'a dump with no backup history table restores', $marker . ' ' . mb_substr($err, -300));

section('The chain restore names the run it restores');

$chain = (string)file_get_contents(PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/restore_chain.sh');
check(strpos($chain, 'lines.append("RUN\\t') !== false && strpos($chain, '--restored-run "${CHAIN_ID}:${RESTORE_SEQ}:') !== false,
	'restore_chain.sh reads the run from its manifest and passes it to the database restore');

harness_finish();

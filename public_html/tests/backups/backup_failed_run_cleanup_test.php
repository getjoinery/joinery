<?php
/** @joinery-test
 * name: backup_failed_run_cleanup
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A failed chain run cleans up after itself.
 *
 * The failure this pins: jeremytunnell.com's 2026-08-23 run failed at upload
 * and left 7 GB of unusable artifacts in the chain directory — local deletion
 * only ran after success, and nothing else would reclaim the space until chain
 * retention removed the whole chain weeks later. The run had also written a
 * manifest describing itself, one run ahead of what the bucket held.
 *
 * discard_failed_run() must delete exactly the failed run's artifacts (earlier
 * runs' files are not its to touch), and put the manifest back to its pre-run
 * state — or delete it entirely when the run was starting a brand-new chain and
 * there was no pre-run state.
 *
 * Run: php tests/backups/backup_failed_run_cleanup_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));

$discard = new ReflectionMethod('BackupRunner', 'discard_failed_run');
$discard->setAccessible(true);

$dir = sys_get_temp_dir() . '/jy_failed_run_cleanup_' . getmypid();
@mkdir($dir, 0700, true);
harness_defer(function() use ($dir) {
	foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
	@rmdir($dir);
});

// A chain two runs old, about to fail its third (seq 2).
$manifest_pre = BackupChain::start('chain-20260801_000000', 'testslug', ['v' => 1]);
$manifest_pre = BackupChain::add_run($manifest_pre, 0, 0, [
	'files' => ['name' => 'files-0000.tar.gz.enc', 'bytes' => 10, 'sha256' => str_repeat('a', 64)],
]);
$manifest_pre = BackupChain::add_run($manifest_pre, 1, 1, [
	'files' => ['name' => 'files-0001.tar.gz.enc', 'bytes' => 10, 'sha256' => str_repeat('b', 64)],
]);
$manifest_path = $dir . '/' . BackupChain::MANIFEST_NAME;

$seed = function() use ($dir, $manifest_pre, $manifest_path) {
	foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
	// A survivor from the previous successful run, and the failed run's output.
	file_put_contents($dir . '/files-0001.tar.gz.enc', 'earlier run');
	file_put_contents($dir . '/files-0002.tar.gz.enc', 'failed files artifact');
	file_put_contents($dir . '/db-0002.sql.gz.enc', 'failed db artifact');
	file_put_contents($dir . '/meta-0002.tar.gz.enc', 'failed meta artifact');
	// The failed run got as far as writing its own manifest (one run ahead).
	$advanced = BackupChain::add_run($manifest_pre, 2, 1, [
		'files' => ['name' => 'files-0002.tar.gz.enc', 'bytes' => 10, 'sha256' => str_repeat('c', 64)],
	]);
	BackupChain::write($advanced, $manifest_path);
};

// ─────────────────────────────────────────────────────────────────────────────
section('An extending run: its artifacts go, the chain\'s history stays');

$seed();
$artifacts = ['files' => ['name' => 'files-0002.tar.gz.enc', 'path' => $dir . '/files-0002.tar.gz.enc',
	'bytes' => 21, 'sha256' => str_repeat('c', 64), 'level' => 1, 'kind' => 'files']];
$discard->invoke(null, $dir, 2, $artifacts, $manifest_path, $manifest_pre);

check(!is_file($dir . '/files-0002.tar.gz.enc'), 'the recorded files artifact is deleted');
check(!is_file($dir . '/db-0002.sql.gz.enc'),
	'an artifact written but never recorded is deleted by name',
	'an engine can write its file and throw before the runner records it');
check(!is_file($dir . '/meta-0002.tar.gz.enc'), 'the meta artifact is deleted by name');
check(is_file($dir . '/files-0001.tar.gz.enc'), 'the previous run\'s artifact is untouched',
	'earlier runs are not the failed run\'s to delete');

$restored = BackupChain::read($manifest_path);
check(count($restored['runs']) === 2, 'the manifest is back to its pre-run state',
	'holds ' . count($restored['runs']) . ' runs — one run ahead of the bucket means the next '
	. 'manifest upload describes artifacts the bucket does not hold');

// ─────────────────────────────────────────────────────────────────────────────
section('A brand-new chain: there is no pre-run state, so the manifest goes too');

$seed();
$discard->invoke(null, $dir, 2, [], $manifest_path, null);

check(!is_file($manifest_path), 'the manifest is deleted when the run was starting the chain');
check(!is_file($dir . '/files-0002.tar.gz.enc'), 'the failed artifacts are deleted here too');

// ─────────────────────────────────────────────────────────────────────────────
section('Nothing there to clean is not an error');

foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
$discard->invoke(null, $dir, 0, [], $manifest_path, null);
check(true, 'discarding an empty directory completes quietly',
	'this runs on the failure path — the failure being reported must stay the real one');

harness_finish();

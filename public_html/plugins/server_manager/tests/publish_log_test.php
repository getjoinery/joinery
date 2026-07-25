<?php
/** @joinery-test
 * name: publish_log
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Publish log — a publish must leave a readable record of itself.
 *
 * The value of this log is entirely in the runs that go wrong. A publish that
 * finishes cleanly can be reconstructed from its release row and archives; a
 * publish that refused, died on a missing prerequisite, or hit a fatal leaves
 * nothing behind but whatever the operator happened to still have on screen.
 * So the cases that matter here are the partial ones: lines collected before an
 * abort, a version that was never determined, and a fatal appended after the
 * fact.
 *
 * Everything runs against a throwaway directory.
 *
 * Run: php plugins/server_manager/tests/publish_log_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/PublishLog.php'));

$tmp_root = sys_get_temp_dir() . '/publish_log_' . bin2hex(random_bytes(4));
mkdir($tmp_root, 0777, true);
harness_defer(function () use ($tmp_root) {
	foreach (glob($tmp_root . '/*/*') ?: array() as $f) { @unlink($f); }
	foreach (glob($tmp_root . '/*') ?: array() as $d) { is_dir($d) ? @rmdir($d) : @unlink($d); }
	@rmdir($tmp_root);
});

// ---------------------------------------------------------------------------
section('A completed run is written in full');

$dir_a = $tmp_root . '/a';
PublishLog::reset();
PublishLog::start($dir_a);
PublishLog::setVersion('9.9.9');
PublishLog::record('Creating core archive...');
PublishLog::record('All archives created successfully!');
$path_a = PublishLog::write();

check($path_a !== null, 'write() returns the path it wrote');
check(is_file((string)$path_a), 'log file exists');
check(strpos(basename((string)$path_a), 'publish-9.9.9-') === 0,
	'filename carries the version', 'got ' . basename((string)$path_a));

$body_a = (string)@file_get_contents((string)$path_a);
check(strpos($body_a, 'Creating core archive...') !== false, 'log contains the first line');
check(strpos($body_a, 'All archives created successfully!') !== false, 'log contains the last line');
check(strpos($body_a, 'version 9.9.9') !== false, 'header names the version');
check(preg_match('/\| (cli|web) as \S+/', $body_a) === 1,
	'header records how and as whom the publish ran');

// The directory is created on demand — a fresh install has no logs/publish yet
// and the first publish must not be the one that discovers that.
check(is_dir($dir_a), 'log directory is created when missing');

// ---------------------------------------------------------------------------
section('A run that dies before a version is chosen is still recorded');

$dir_b = $tmp_root . '/b';
PublishLog::reset();
PublishLog::start($dir_b);
PublishLog::record('Refusing to publish - VERSION file is already at 1.2.3');
$path_b = PublishLog::write();

check($path_b !== null, 'early exit still writes a log');
check(strpos(basename((string)$path_b), 'publish-unknown-') === 0,
	'unknown version is filed as such rather than dropped',
	'got ' . basename((string)$path_b));
check(strpos((string)@file_get_contents((string)$path_b), 'Refusing to publish') !== false,
	'the refusal reason is in the log');

// ---------------------------------------------------------------------------
section('A fatal error is appended to whatever was collected');

$dir_c = $tmp_root . '/c';
PublishLog::reset();
PublishLog::start($dir_c);
PublishLog::setVersion('1.0.0');
PublishLog::record('Creating core archive...');
$path_c = PublishLog::write(array(
	'type' => E_ERROR, 'message' => 'Allowed memory size exhausted',
	'file' => '/some/file.php', 'line' => 42,
));
$body_c = (string)@file_get_contents((string)$path_c);
check(strpos($body_c, 'Creating core archive...') !== false, 'lines before the fatal survive');
check(strpos($body_c, 'FATAL: Allowed memory size exhausted') !== false, 'the fatal is recorded');
check(strpos($body_c, '/some/file.php:42') !== false, 'the fatal names file and line');

// A warning is not a fatal — appending one would misreport a healthy run.
PublishLog::reset();
PublishLog::start($tmp_root . '/c2');
PublishLog::record('done');
$path_c2 = PublishLog::write(array(
	'type' => E_WARNING, 'message' => 'harmless', 'file' => '/x.php', 'line' => 1,
));
check(strpos((string)@file_get_contents((string)$path_c2), 'FATAL') === false,
	'a trailing warning is not reported as a fatal');

// ---------------------------------------------------------------------------
section('Nothing to say, nothing written');

$dir_d = $tmp_root . '/d';
PublishLog::reset();
PublishLog::start($dir_d);
check(PublishLog::write() === null, 'a run that emitted no output writes no log');
check(!is_dir($dir_d) || count(glob($dir_d . '/*.log') ?: array()) === 0,
	'no stray empty log file is left behind');

// ---------------------------------------------------------------------------
section('Retention keeps the newest and removes the rest');

$dir_e = $tmp_root . '/e';
mkdir($dir_e, 0777, true);
// Distinct mtimes so "newest" is unambiguous.
for ($i = 1; $i <= 8; $i++) {
	$f = $dir_e . '/publish-1.0.' . $i . '-2026010' . $i . '-000000.log';
	file_put_contents($f, 'run ' . $i);
	touch($f, 1800000000 + $i);
}
$removed = PublishLog::prune($dir_e, 3);

$left = glob($dir_e . '/publish-*.log') ?: array();
check(count($left) === 3, 'prune keeps exactly the requested count', 'kept ' . count($left));
check(count($removed) === 5, 'prune reports what it removed', 'removed ' . count($removed));
check(is_file($dir_e . '/publish-1.0.8-20260108-000000.log'), 'the newest log survives');
check(!is_file($dir_e . '/publish-1.0.1-20260101-000000.log'), 'the oldest log is gone');

// Below the threshold nothing is touched — pruning is not a reason to lose history.
$dir_f = $tmp_root . '/f';
mkdir($dir_f, 0777, true);
file_put_contents($dir_f . '/publish-2.0.0-20260101-000000.log', 'only one');
check(PublishLog::prune($dir_f, 3) === array(), 'prune removes nothing when under the limit');
check(is_file($dir_f . '/publish-2.0.0-20260101-000000.log'), 'the single log is untouched');

// Files that are not publish logs are none of its business.
file_put_contents($dir_e . '/notes.txt', 'unrelated');
PublishLog::prune($dir_e, 1);
check(is_file($dir_e . '/notes.txt'), 'prune ignores files that are not publish logs');

harness_finish();

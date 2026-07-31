<?php
/** @joinery-test
 * name: deploy_syntax_sweep
 * tier: deploy
 * env: any
 * needs: []
 * timeout: 240
 */
/**
 * Every PHP file the deployed site could load, compiled.
 *
 * This is the check the deploy gate exists for. A release archive is built from
 * whatever was on the publishing box's disk at that moment — `publish_upgrade.php`
 * does not consult git — so an edit left half-finished ships. Nothing between
 * there and here reads a line of it, and a parse error in a file that is loaded
 * only on one page waits, silently, for someone to visit that page.
 *
 * Compilation is not execution. `opcache_compile_file()` parses and compiles a
 * file into the opcode cache without running a statement of it, so this is safe
 * to point at a live production tree — no side effects, no output, no database
 * writes, no autoloaded constructors.
 *
 * Two passes, because each alone is wrong:
 *
 *   1. Compile all of them in one process. About a second for the whole tree,
 *      against roughly a minute for `php -l` per file, which matters on the 1 GB
 *      instances this platform is meant to run on.
 *   2. Anything that failed pass 1 is re-checked with `php -l` in its own
 *      process. One process compiling everything shares one symbol table, so two
 *      files that legitimately declare the same function name — `logic/profile_logic.php`
 *      and a plugin's own `profile_logic.php`, which are never loaded together —
 *      collide and look broken. Isolation is the only way to tell a real parse
 *      error from that, and it is affordable on the handful of files involved.
 *
 * `vendor/` is excluded: it is Composer's to vouch for, it is large, and a
 * dependency's own test fixtures routinely contain deliberately-invalid PHP.
 * `cache/` is generated. `tests/` is excluded for the same reason as vendor —
 * the suites are full of same-named helpers by design and are never loaded by
 * the running site.
 *
 * Run: php tests/deploy/syntax_sweep_test.php
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = PathHelper::getRootDir();

section('Every deployable PHP file compiles');

// ---------------------------------------------------------------------------
// Collect
// ---------------------------------------------------------------------------

$skip_dirs = array('/vendor/', '/cache/', '/tests/', '/node_modules/', '/.git/');

$files = array();
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
	RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $entry) {
	if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
		continue;
	}
	$path = $entry->getPathname();
	$relative = str_replace($root, '', $path);
	foreach ($skip_dirs as $skip) {
		if (strpos($relative, $skip) !== false) {
			continue 2;
		}
	}
	$files[] = $path;
}

sort($files);
check(count($files) > 100, 'the deployed tree holds a plausible number of PHP files',
	count($files) . ' files under ' . $root);

// ---------------------------------------------------------------------------
// Pass 1 — compile everything in this process
// ---------------------------------------------------------------------------

// Pass 1 runs in its own process, with opcache forced on for the CLI. Both
// halves of that matter. opcache.enable_cli is off by default on every distro
// build, and it cannot be turned on at runtime — without the subprocess,
// opcache_compile_file() returns false for every file and pass 1 silently
// degrades into "check all 1500 with php -l", which is the minute-plus this
// design exists to avoid. Running it detached also means a file that manages to
// fatal at compile time cannot take this test down with it.
$list_file = tempnam(sys_get_temp_dir(), 'jy_sweep_');
file_put_contents($list_file, implode("\n", $files));
harness_defer(function () use ($list_file) { @unlink($list_file); });

$compiler = <<<'COMPILER'
$list = file(getenv('JY_SWEEP_LIST'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!function_exists('opcache_compile_file') || !ini_get('opcache.enable_cli')) { echo "UNAVAILABLE\n"; exit(0); }
set_error_handler(function () { return true; });
foreach ($list as $path) {
    try { if (@opcache_compile_file($path) === false) echo $path . "\n"; }
    catch (\Throwable $e) { echo $path . "\n"; }
}
exit(0);
COMPILER;

$compile_output = array();
$compile_exit = 0;
exec('JY_SWEEP_LIST=' . escapeshellarg($list_file) . ' '
	. escapeshellarg(PHP_BINARY) . ' -d opcache.enable=1 -d opcache.enable_cli=1 '
	. '-r ' . escapeshellarg($compiler) . ' 2>/dev/null', $compile_output, $compile_exit);

$compile_output = array_values(array_filter(array_map('trim', $compile_output)));
$can_compile = ($compile_exit === 0)
	&& !in_array('UNAVAILABLE', $compile_output, true);

// No opcache on this build, or the subprocess died: check everything the slow
// way rather than skipping the one check the gate exists for.
$suspect = $can_compile ? $compile_output : $files;

// ---------------------------------------------------------------------------
// Pass 2 — confirm each suspect in its own process
// ---------------------------------------------------------------------------

$broken = array();
foreach ($suspect as $path) {
	$output = array();
	$exit = 0;
	exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $exit);
	if ($exit !== 0) {
		$broken[$path] = trim(implode(' ', $output));
	}
}

$detail = '';
if (!empty($broken)) {
	$lines = array();
	foreach ($broken as $path => $message) {
		$lines[] = str_replace($root, '', $path) . ': ' . $message;
	}
	$detail = implode(' | ', array_slice($lines, 0, 10));
}

check(empty($broken),
	count($files) . ' PHP files parse',
	$detail !== '' ? $detail : ($can_compile ? '' : 'opcache_compile_file unavailable — every file was checked with php -l'));

// Worth stating rather than leaving to inference: a green result here means the
// files compile, not that the code is correct.
if ($can_compile && !empty($suspect) && empty($broken)) {
	check(true, count($suspect) . ' file(s) collided on a shared symbol table and passed in isolation',
		'expected — two files may declare the same function name when they are never loaded together');
}

harness_finish();

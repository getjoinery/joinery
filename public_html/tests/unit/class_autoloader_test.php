<?php
/** @joinery-test
 * name: class_autoloader
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The class map is data, and nothing executes it.
 *
 * The map that resolves every platform class by name is cached. On CLI, where
 * APCu is not enabled, that cache is a file under the site root's cache/
 * directory — a directory the web user writes. It used to be class_map.php,
 * loaded with include(): a file the pool wrote and then executed on every
 * request, which is the exact shape specs/read_only_tree.md exists to remove.
 * One bug that let an attacker write one file there was a PHP shell that ran
 * itself on the next page view.
 *
 * It is class_map.json now, read with json_decode. This suite holds that: the
 * cache is JSON, nothing include()s it, and a class_map.php left behind by an
 * older release is removed rather than left sitting in the tree's cache.
 *
 * Run: php tests/unit/class_autoloader_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$cache_dir  = PathHelper::getSiteRoot() . '/cache';
$json_map   = $cache_dir . '/class_map.json';
$legacy_map = $cache_dir . '/class_map.php';
$src        = file_get_contents(PathHelper::getIncludePath('includes/ClassAutoloader.php'));

section('The cache is JSON, not PHP');

// APCu under the web server, a file on CLI. This suite is the CLI case, which
// is the one that writes a file at all.
check(strpos($src, "'/cache/class_map.json'") !== false,
	'the cache file is class_map.json');
check(strpos($src, 'json_decode') !== false && strpos($src, 'json_encode') !== false,
	'it is written and read as JSON');
check(preg_match('/function flush\(\).*?unlink\(\$file\)/s', $src) === 1,
	'flush() removes the cache file');

// The point of the whole change: no path through this class hands the cache to
// the PHP parser. include/require of a cache file is what made a writable
// cache an executable one.
check(preg_match('/\b(include|require)(_once)?\s*\(\s*\$file/', $src) !== 1,
	'no include() or require() of the cache file remains',
	'a cache the web user writes must never reach the parser');

check(class_exists('Product'), 'a model class resolves by name');

// Everything below works on the cache FILE, and that file is shared with every
// other suite the runner has in flight — several of which resolve their model
// classes through it. Removing it in-process made them rebuild mid-run, so the
// file work happens in subprocesses and this process never flushes.
//
// APCu off, because the file cache is the CLI backend: under APCu (which the
// runner enables, and the web server has) nothing is written to disk at all.
// The no-APCu case is what a cron run and a hand-run script have, and the one
// where a writable, executable cache file used to exist.
$php_no_apcu = escapeshellarg(PHP_BINARY) . ' -d apc.enable_cli=0 -r '
	. escapeshellarg('require_once("' . PathHelper::getIncludePath('includes/PathHelper.php') . '");'
		. ' class_exists("Product");');
shell_exec($php_no_apcu . ' 2>/dev/null');
check(is_file($json_map), 'a lookup with no APCu writes the cache to disk');

$raw = (string)file_get_contents($json_map);
check(strpos($raw, '<?php') === false,
	'the cache carries no PHP open tag',
	'first 20 bytes: ' . substr($raw, 0, 20));
$decoded = json_decode($raw, true);
check(is_array($decoded) && $decoded !== [], 'it parses as a non-empty JSON object');
check(isset($decoded['Product']) && strpos((string)$decoded['Product'], 'products_class.php') !== false,
	'and maps a class to the file that declares it');

section('The cache is not writable by the whole machine');

// 0666 was the old mode. Any local account could then rewrite the map — and a
// map is a list of files the autoloader will require by name, so a writable map
// chooses what code every later request loads.
$mode = fileperms($json_map) & 0777;
check(($mode & 0002) === 0,
	'the cache file is not world-writable', sprintf('%o', $mode));
check(preg_match('/chmod\s*\([^)]*0666/', $src) !== 1,
	'and the autoloader chmods nothing to 0666');

section('A class_map.php left by an older release is removed');

// An upgraded site carries one until something clears it. It is inert the
// moment cache_file() stops naming it, but an executable file the web user owns
// does not get to sit in the tree's cache because nothing happens to read it.
file_put_contents($legacy_map, "<?php\n// left by an older release\nreturn ['Marker' => 'x'];\n");
check(is_file($legacy_map), 'a legacy class_map.php can be planted');

// The read path removes it, whichever backend is in use, so a site that never
// flushes still loses it on the first request after the upgrade. In a
// subprocess, so the removal is attributable to the read.
$read = escapeshellarg(PHP_BINARY) . ' -r '
	. escapeshellarg('require_once("' . PathHelper::getIncludePath('includes/PathHelper.php') . '");'
		. ' class_exists("MultiProduct");');
shell_exec($read . ' 2>/dev/null');
check(!is_file($legacy_map), 'the first cache read after an upgrade removes it');

// And flush() removes it too, for the paths that call that instead.
file_put_contents($legacy_map, "<?php\nreturn ['Marker' => 'x'];\n");
$flush = escapeshellarg(PHP_BINARY) . ' -r '
	. escapeshellarg('require_once("' . PathHelper::getIncludePath('includes/PathHelper.php') . '");'
		. ' ClassAutoloader::flush();');
shell_exec($flush . ' 2>/dev/null');
check(!is_file($legacy_map), 'and so does flush()');

// Nothing from it ever reached the resolver.
check(!class_exists('Marker', false), 'nothing declared in it was ever loaded');

// Leave the shared cache as it was found: warm, so no other suite pays for a
// rebuild this one caused.
shell_exec($php_no_apcu . ' 2>/dev/null');

harness_finish();

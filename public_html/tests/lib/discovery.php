<?php
/**
 * Shared test discovery — used by the CLI runner (tests/run.php), the web
 * dashboard (tests/index.php), and the tests_run API action. Finds every file
 * under tests/ and plugins/{plugin}/tests/ that carries a @joinery-test header
 * (declared tests) and, alongside them, files that look like tests but have no
 * header (undeclared). Reads headers only — never executes a test.
 */

require_once(__DIR__ . '/harness.php'); // harness_parse_metadata()

/**
 * The PHP CLI binary, regardless of the SAPI we are running under. Under
 * php-fpm (how the web dashboard is served) PHP_BINARY is the fpm binary
 * (e.g. /usr/sbin/php-fpm8.3), which prints usage instead of executing a
 * script — so a spawned `tests/run.php` never runs. Derive the CLI binary
 * instead: PHP_BINARY when we ARE the CLI, else PHP_BINDIR/php if executable,
 * else the bare 'php' (resolved via PATH). No new setting — zero-config.
 */
function harness_php_cli() {
	if (PHP_SAPI === 'cli') return PHP_BINARY;
	$candidate = PHP_BINDIR . '/php';
	return is_executable($candidate) ? $candidate : 'php';
}

/** Recursively collect *.php and *.sh files under a directory. */
function harness_discover_dir($dir) {
	$out = array();
	if (!is_dir($dir)) return $out;
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $file) {
		$path = $file->getPathname();
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if ($ext === 'php' || $ext === 'sh') $out[] = $path;
	}
	return $out;
}

/** Harness infrastructure, fixtures, tooling, dashboards — never tests. NOTE:
 *  'run.php' is deliberately NOT excluded by basename — the product and
 *  subscription-tier suites are named run.php and are declared live tests. The
 *  discovery runner itself (tests/run.php) is excluded by exact path in
 *  harness_discover() instead. */
function harness_is_excluded_path($path) {
	foreach (array('/lib/', '/fixtures/', '/tools/', '/manual/') as $d) {
		if (strpos($path, $d) !== false) return true;
	}
	$excl_files = array('api_test_harness.php', 'index.php', 'run_all.php',
		'run_multi.php', 'run_automated.php', 'ModelTester.php', 'MultiModelTester.php',
		'setting_ctl.php', 'menu_probe.php');
	return in_array(basename($path), $excl_files, true);
}

/** Repo-relative path for display. */
function harness_rel($path, $root) {
	return (strpos($path, $root) === 0) ? ltrim(substr($path, strlen($root)), '/') : $path;
}

/**
 * Discover tests under $root (public_html). Returns
 * ['declared' => [{path, meta}], 'undeclared' => [path, ...]] with paths sorted.
 */
function harness_discover($root) {
	$scan = harness_discover_dir($root . '/tests');
	foreach (glob($root . '/plugins/*/tests', GLOB_ONLYDIR) as $ptests) {
		$scan = array_merge($scan, harness_discover_dir($ptests));
	}
	sort($scan);

	$runner_path = $root . '/tests/run.php'; // the discovery runner itself, never a test
	$declared = array();
	$undeclared = array();
	foreach ($scan as $path) {
		if ($path === $runner_path) continue;
		if (harness_is_excluded_path($path)) continue;
		$meta = harness_parse_metadata($path);
		if ($meta) {
			$declared[] = array('path' => $path, 'meta' => $meta);
		} else {
			$base = basename($path);
			if (preg_match('/_test\.php$/', $base) || preg_match('/^test_.*\.php$/', $base)
				|| preg_match('/_gate\.sh$/', $base)) {
				$undeclared[] = $path;
			}
		}
	}
	return array('declared' => $declared, 'undeclared' => $undeclared);
}

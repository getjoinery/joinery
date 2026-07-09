<?php
/**
 * Test discovery runner — the tier-aware, subprocess-isolated aggregate gate.
 *
 * Discovers every declared test (a file carrying a @joinery-test header) under
 * tests/ and plugins/{plugin}/tests/, reads each header WITHOUT executing the
 * file, enforces its env against this environment, runs the selected tier batch
 * each in its own subprocess (a fatal in one file cannot take down the run),
 * aggregates the per-test result contracts, and exits non-zero if any test
 * failed. This is the pre-deploy gate and the CI entry point.
 *
 *   php tests/run.php                 # the `safe` tier
 *   php tests/run.php db              # safe + db
 *   php tests/run.php test-db         # only test-db (never implied)
 *   php tests/run.php live            # only live (never implied)
 *   php tests/run.php db --filter=api # narrow by name or path substring
 *   php tests/run.php --json          # emit the aggregate JSON contract
 *   php tests/run.php --list          # list discovered tests, run nothing
 *
 * tier and env are separate axes: tier (safe|db|test-db|live) is blast radius
 * and drives which batch runs; env (any|prod-verify|dev-only) is where a test
 * may execute. dev-only tests are skipped (locked) when the `debug` setting is
 * off. prod-verify and live tests are never part of a batch run — each is run
 * explicitly by naming its tier or filtering to it.
 */

if (php_sapi_name() !== 'cli') {
	echo "tests/run.php is the CLI runner. The web dashboard is at /tests/.\n";
	exit(1);
}

require_once(__DIR__ . '/lib/harness.php');    // parser + Globalvars, no side effects until harness_boot()
require_once(__DIR__ . '/lib/discovery.php');  // harness_discover(), harness_rel()

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------
$args = array_slice($argv, 1);
$want_json = in_array('--json', $args, true);
$want_list = in_array('--list', $args, true);
$filter = '';
$only = '';                  // exact repo-relative path — run just this one declared test
$tier_arg = 'safe';
$timeout_override = null;     // --timeout= overrides every test's declared timeout
foreach ($args as $a) {
	if (strpos($a, '--filter=') === 0)  { $filter = substr($a, strlen('--filter=')); continue; }
	if (strpos($a, '--only=') === 0)    { $only = substr($a, strlen('--only=')); continue; }
	if (strpos($a, '--timeout=') === 0) { $timeout_override = max(1, (int)substr($a, strlen('--timeout='))); continue; }
	if ($a === '--json' || $a === '--list') continue;
	if (strpos($a, '--') === 0) continue;
	$tier_arg = $a; // first bare positional is the tier
}

$valid_tiers = array('safe', 'db', 'test-db', 'live');
if (!in_array($tier_arg, $valid_tiers, true)) {
	fwrite(STDERR, "Unknown tier '$tier_arg'. Use one of: " . implode(', ', $valid_tiers) . "\n");
	exit(2);
}

// Which tiers a batch request includes. safe⊂db are cumulative; test-db and
// live run alone and never pull in the others.
$tiers_to_run = array(
	'safe'    => array('safe'),
	'db'      => array('safe', 'db'),
	'test-db' => array('test-db'),
	'live'    => array('live'),
);
$selected_tiers = $tiers_to_run[$tier_arg];

$debug_on = (bool)Globalvars::get_instance()->get_setting('debug');
$ROOT = dirname(__DIR__); // public_html

// ---------------------------------------------------------------------------
// Discovery (shared with the dashboard + API action via lib/discovery.php)
// ---------------------------------------------------------------------------
$discovered = harness_discover($ROOT);
$declared = $discovered['declared'];     // [{path, meta}]
$undeclared = $discovered['undeclared']; // paths that look like tests but carry no header

// ---------------------------------------------------------------------------
// --list
// ---------------------------------------------------------------------------
if ($want_list) {
	if ($want_json) {
		echo json_encode(array(
			'declared' => array_map(function ($d) use ($ROOT) {
				return array('path' => harness_rel($d['path'], $ROOT)) + $d['meta'];
			}, $declared),
			'undeclared' => array_map(function ($p) use ($ROOT) { return harness_rel($p, $ROOT); }, $undeclared),
		), JSON_PRETTY_PRINT) . "\n";
		exit(0);
	}
	echo "Discovered tests (" . count($declared) . " declared, " . count($undeclared) . " undeclared):\n\n";
	$by_tier = array();
	foreach ($declared as $d) $by_tier[$d['meta']['tier']][] = $d;
	foreach ($valid_tiers as $t) {
		if (empty($by_tier[$t])) continue;
		echo "[$t]\n";
		foreach ($by_tier[$t] as $d) {
			$needs = $d['meta']['needs'] ? ' needs=' . implode(',', $d['meta']['needs']) : '';
			echo sprintf("  %-28s %-12s %s%s\n", $d['meta']['name'], 'env=' . $d['meta']['env'], harness_rel($d['path'], $ROOT), $needs);
		}
	}
	if ($undeclared) {
		echo "\n[undeclared — no @joinery-test header]\n";
		foreach ($undeclared as $p) echo "  " . harness_rel($p, $ROOT) . "\n";
	}
	exit(0);
}


// ---------------------------------------------------------------------------
// Select + run
// ---------------------------------------------------------------------------
$to_run = array();
$skipped_env = array(); // [{path, meta, reason}]
foreach ($declared as $d) {
	// --only selects one exact test by repo-relative path, ignoring tier batching
	// (but still subject to the env gate below). Otherwise filter by tier + substring.
	if ($only !== '') {
		if (harness_rel($d['path'], $ROOT) !== $only) continue;
	} else {
		if (!in_array($d['meta']['tier'], $selected_tiers, true)) continue;
		if ($filter !== '' && stripos($d['meta']['name'], $filter) === false && stripos($d['path'], $filter) === false) continue;
	}

	// env gate (pre-spawn): dev-only tests refuse to run when debug is off.
	if ($d['meta']['env'] === 'dev-only' && !$debug_on) {
		$skipped_env[] = $d + array('reason' => "dev-only, but 'debug' setting is off (production)");
		continue;
	}
	$to_run[] = $d;
}

$results = array();
foreach ($to_run as $d) {
	// A test uses its own declared timeout unless --timeout= overrides all tests.
	$effective_timeout = $timeout_override !== null ? $timeout_override : ($d['meta']['timeout'] ?? 180);
	$results[] = run_one($d, $ROOT, $effective_timeout);
	if (!$want_json) print_human_line(end($results), $ROOT);
}

/** Run a single test in a subprocess and normalize it to a result record. A
 *  hung test is killed after $timeout_s (coreutils `timeout`, exit 124/137). */
function run_one($d, $root, $timeout_s) {
	$path = $d['path'];
	$is_sh = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'sh';
	$inner = $is_sh
		? 'bash ' . escapeshellarg($path)
		: escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' --json';
	// -k 5s sends SIGKILL 5s after SIGTERM if the test ignores the term.
	$cmd = 'timeout -k 5s ' . (int)$timeout_s . 's ' . $inner;

	// Capture stdout/stderr to temp files, not pipes: a child that writes more
	// than the ~64KB pipe buffer to one stream before we drain it would deadlock.
	// With files the child never blocks, and proc_close() waits for exit.
	$out = tmpfile();
	$err = tmpfile();
	$descriptors = array(1 => $out, 2 => $err);
	$start = microtime(true);
	$proc = proc_open($cmd, $descriptors, $pipes, $root);
	$exit = proc_close($proc);
	$ms = (int)round((microtime(true) - $start) * 1000);
	rewind($out); $stdout = stream_get_contents($out); fclose($out);
	rewind($err); $stderr = stream_get_contents($err); fclose($err);

	// timeout(1) exits 124 (killed after grace) or 137 (SIGKILL) on expiry.
	if ($exit === 124 || $exit === 137) {
		return array(
			'name' => $d['meta']['name'], 'path' => harness_rel($path, $root),
			'tier' => $d['meta']['tier'], 'env' => $d['meta']['env'],
			'status' => 'fail',
			'stats' => array('total' => 0, 'passed' => 0, 'failed' => 1, 'skipped' => 0),
			'sections' => array(), 'duration_ms' => $ms, 'exit' => $exit,
			'note' => "timed out after {$timeout_s}s (killed)",
		);
	}

	$contract = extract_contract($stdout);

	if ($contract && isset($contract['stats'])) {
		$failed = ($contract['stats']['failed'] > 0) || ($exit !== 0 && $exit !== 1);
		return array(
			'name' => $contract['name'], 'path' => harness_rel($path, $root),
			'tier' => $d['meta']['tier'], 'env' => $d['meta']['env'],
			'status' => $failed ? 'fail' : 'pass',
			'stats' => $contract['stats'], 'sections' => $contract['sections'] ?? array(),
			'duration_ms' => $contract['duration_ms'] ?? $ms, 'exit' => $exit,
		);
	}

	// A declared .php test MUST emit a result contract. If it doesn't, it either
	// crashed or was never converted to the harness — always a failure, no matter
	// what it exited with (an unconverted test that prints "Failed" but exits 0
	// must not read as green). Shell gates carry no contract by design and keep
	// exit-code semantics — that is their contract.
	if ($is_sh) {
		$status = ($exit === 0) ? 'pass' : 'fail';
		$note = 'shell gate (exit-code only)' . ($exit !== 0 ? '; exit=' . $exit : '');
	} else {
		$status = 'fail';
		$note = 'no result contract emitted' . ($exit !== 0 ? '; exit=' . $exit : '');
	}
	$tail = trim(substr($stderr !== '' ? $stderr : $stdout, -400));
	return array(
		'name' => $d['meta']['name'], 'path' => harness_rel($path, $root),
		'tier' => $d['meta']['tier'], 'env' => $d['meta']['env'],
		'status' => $status, 'stats' => array('total' => 0, 'passed' => 0, 'failed' => $status === 'fail' ? 1 : 0, 'skipped' => 0),
		'sections' => array(), 'duration_ms' => $ms, 'exit' => $exit,
		'note' => $note, 'output_tail' => $tail,
	);
}

/** Pull the JSON contract from a subprocess's stdout via the sentinel. */
function extract_contract($stdout) {
	$pos = strrpos($stdout, JOINERY_RESULT_SENTINEL);
	if ($pos === false) return null;
	$json = substr($stdout, $pos + strlen(JOINERY_RESULT_SENTINEL));
	$nl = strpos($json, "\n");
	if ($nl !== false) $json = substr($json, 0, $nl);
	$decoded = json_decode(trim($json), true);
	return is_array($decoded) ? $decoded : null;
}

function print_human_line($r, $root) {
	$mark = $r['status'] === 'pass' ? 'PASS' : 'FAIL';
	$s = $r['stats'];
	$counts = $s['total'] > 0 ? "{$s['passed']}/{$s['total']}" . ($s['failed'] ? " ({$s['failed']} failed)" : '') : '—';
	$line = sprintf("  %-4s %-28s %-9s %6dms  %s", $mark, $r['name'], $counts, $r['duration_ms'], $r['path']);
	echo $line . "\n";
	if ($r['status'] === 'fail' && !empty($r['note'])) echo "         ↳ " . $r['note'] . "\n";
	if ($r['status'] === 'fail' && !empty($r['sections'])) {
		foreach ($r['sections'] as $sec) foreach ($sec['checks'] as $c) {
			if (isset($c['passed']) && $c['passed'] === false) {
				echo "         ✗ " . $c['label'] . ($c['detail'] ? ' — ' . $c['detail'] : '') . "\n";
			}
		}
	}
}

// ---------------------------------------------------------------------------
// Aggregate + report
// ---------------------------------------------------------------------------
$tests_total = count($results);
$tests_failed = count(array_filter($results, function ($r) { return $r['status'] === 'fail'; }));
$tests_passed = $tests_total - $tests_failed;
$checks_passed = array_sum(array_map(function ($r) { return $r['stats']['passed']; }, $results));
$checks_failed = array_sum(array_map(function ($r) { return $r['stats']['failed']; }, $results));
$checks_skipped = array_sum(array_map(function ($r) { return $r['stats']['skipped'] ?? 0; }, $results));

if ($want_json) {
	echo json_encode(array(
		'tier_requested' => $tier_arg,
		'tiers_run' => $selected_tiers,
		'filter' => $filter,
		'debug_env' => $debug_on,
		'totals' => array(
			'tests' => $tests_total, 'tests_passed' => $tests_passed, 'tests_failed' => $tests_failed,
			'checks_passed' => $checks_passed, 'checks_failed' => $checks_failed, 'checks_skipped' => $checks_skipped,
		),
		'results' => $results,
		'skipped_env' => array_map(function ($s) use ($ROOT) {
			return array('name' => $s['meta']['name'], 'path' => harness_rel($s['path'], $ROOT), 'reason' => $s['reason']);
		}, $skipped_env),
		'undeclared' => array_map(function ($p) use ($ROOT) { return harness_rel($p, $ROOT); }, $undeclared),
	)) . "\n";
	exit($tests_failed > 0 ? 1 : 0);
}

echo "\n================================================================\n";
echo "Tier: $tier_arg" . ($filter ? " (filter: $filter)" : '') . "   Environment: " . ($debug_on ? 'dev (debug on)' : 'production (debug off)') . "\n";
echo "Tests: $tests_passed passed, $tests_failed failed of $tests_total   |   Checks: $checks_passed passed, $checks_failed failed" . ($checks_skipped ? ", $checks_skipped skipped" : '') . "\n";

if ($skipped_env) {
	echo "\nSkipped (environment):\n";
	foreach ($skipped_env as $s) echo "  - " . $s['meta']['name'] . " (" . $s['reason'] . ")\n";
}
if ($undeclared) {
	echo "\nUndeclared (no @joinery-test header — not run):\n";
	foreach ($undeclared as $p) echo "  - " . harness_rel($p, $ROOT) . "\n";
}
if ($tests_total === 0) echo "\n(no tests matched this tier/filter)\n";

echo "\n" . ($tests_failed > 0 ? "RESULT: FAIL" : "RESULT: PASS") . "\n";
exit($tests_failed > 0 ? 1 : 0);

<?php
/**
 * API action: tests_run — execute a test tier batch or a single declared test
 * and return its result contract as JSON. Powers the superadmin test dashboard
 * at /tests/.
 *
 * POST /api/v1/action/tests_run (browser session, permission 10). Params:
 *   tier  — safe | db | test-db | live   (batch run; default safe)
 *   test  — a discovered test's repo-relative path (single-test run; optional)
 *   filter — substring to narrow a tier batch (optional)
 * A `test` path is validated against discovery before it is ever passed to a
 * subprocess, so the action can only ever run a file the runner already knows —
 * it is not a general shell.
 *
 * Everything is executed through tests/run.php --json in a subprocess (the same
 * tier enforcement, env gate, and subprocess isolation the CLI gets), and the
 * runner's aggregate contract is returned verbatim under `run`.
 *
 * @version 1.1.0
 * @changelog 1.1.0 - Spawn via the CLI binary (harness_php_cli) so it works
 *   under php-fpm; capture child output to temp files to avoid pipe deadlock.
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function tests_run_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('tests/lib/discovery.php'));

	$session = SessionControl::get_instance();
	if ((int)$session->get_permission() < 10) {
		return LogicResult::error('Superadmin permission required.');
	}

	$root = PathHelper::getIncludePath(''); // public_html root, trailing slash
	$root = rtrim($root, '/');

	$valid_tiers = array('safe', 'db', 'test-db', 'live');
	$tier = isset($input['tier']) && in_array($input['tier'], $valid_tiers, true) ? $input['tier'] : 'safe';
	$filter = isset($input['filter']) ? (string)$input['filter'] : '';
	$test = isset($input['test']) ? (string)$input['test'] : '';

	// Use the CLI binary, not PHP_BINARY — under php-fpm the latter is the fpm
	// binary and would print usage instead of running the runner.
	$cmd = escapeshellarg(harness_php_cli()) . ' ' . escapeshellarg($root . '/tests/run.php');

	if ($test !== '') {
		// Validate the single test against discovery — never trust the path.
		$discovered = harness_discover($root);
		$match = null;
		foreach ($discovered['declared'] as $d) {
			if (harness_rel($d['path'], $root) === $test) { $match = $d; break; }
		}
		if ($match === null) {
			return LogicResult::error('Unknown test: ' . $test);
		}
		$cmd .= ' ' . escapeshellarg($match['meta']['tier'])
			. ' --only=' . escapeshellarg($test) . ' --json';
	} else {
		$cmd .= ' ' . escapeshellarg($tier) . ' --json';
		if ($filter !== '') $cmd .= ' --filter=' . escapeshellarg($filter);
	}

	// Capture to temp files, not pipes: with no request-side timeout, a child
	// that fills a pipe buffer before we drain it would hang the request forever.
	// Files never block the child; proc_close() waits for exit.
	$out = tmpfile();
	$err = tmpfile();
	$proc = proc_open($cmd, array(1 => $out, 2 => $err), $pipes, $root);
	if (!is_resource($proc)) {
		fclose($out); fclose($err);
		return LogicResult::error('Could not start the test runner.');
	}
	$exit = proc_close($proc);
	rewind($out); $stdout = stream_get_contents($out); fclose($out);
	rewind($err); $stderr = stream_get_contents($err); fclose($err);

	$run = json_decode(trim($stdout), true);
	if (!is_array($run)) {
		return LogicResult::error('Runner produced no parseable result'
			. ($stderr !== '' ? ': ' . substr(trim($stderr), -300) : '.'));
	}

	return LogicResult::render(array(
		'run'  => $run,
		'exit' => $exit,
	));
}

function tests_run_logic_api() {
	return array(
		'requires_session' => true,
		// Superadmin only; browser sessions carry no API key, so skip the
		// apk_permission capability axis and gate on the user role floor.
		'auth' => array('capability' => null, 'min_user_permission' => 10),
		'description' => 'Run a test tier batch or a single declared test and return its result contract',
	);
}

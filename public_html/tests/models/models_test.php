<?php
/** @joinery-test
 * name: models_crud
 * tier: test-db         # runs against the copied test database
 * env: dev-only
 * needs: []
 */

/**
 * Model CRUD/validation suite, contract-emitting.
 *
 * Drives ModelTester over every discovered data model exactly as the web
 * run_all page does (single-model tests, full CRUD against the copied TEST
 * database — never live), but instead of echoing HTML spans it records one
 * harness check per model class and emits the shared result contract. This is
 * what makes the model suite CI-able: `php tests/models/models_test.php --json`
 * (or `php tests/run.php test-db`). ModelTester's internals are unchanged; its
 * per-class output is captured and surfaced only as failure detail.
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(PathHelper::getIncludePath('tests/models/ModelTester.php'));
harness_boot();

set_time_limit(120);

// Single-model tests only (Multi tests have their own path); mirrors run_all.php.
if (!defined('SINGLE_TESTS_ONLY')) define('SINGLE_TESTS_ONLY', true);
if (!defined('TEST_MULTI')) define('TEST_MULTI', false);

$classes = LibraryFunctions::discover_model_classes();
section('Model CRUD (' . count($classes) . ' classes, test database)');

foreach ($classes as $class) {
	$before = ModelTester::get_test_stats();
	ob_start();
	$status = 'fail';
	$err = '';
	try {
		// ($debug=false, $verbose=false, $read_only=false) → CRUD on the test DB.
		$res = $class::test(false, false, false);
		$status = ($res === 'SKIPPED') ? 'skip' : ($res ? 'pass' : 'fail');
	} catch (Throwable $e) {
		$status = 'fail';
		$err = $e->getMessage();
	}
	$output = ob_get_clean();
	$after = ModelTester::get_test_stats();
	$delta_pass = $after['passed'] - $before['passed'];
	$delta_fail = $after['failed'] - $before['failed'];
	$detail = "$delta_pass sub-checks";
	if ($status === 'fail') {
		$detail = ($delta_fail ? "$delta_fail sub-checks failed. " : '')
			. ($err !== '' ? $err : trim(strip_tags(str_replace('<br>', ' ', substr($output, -400)))));
	}

	if ($status === 'skip') {
		harness_skip($class, 'configuration required');
	} else {
		check($status === 'pass', $class, $detail);
	}
}

harness_finish();

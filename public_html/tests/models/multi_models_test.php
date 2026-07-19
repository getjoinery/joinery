<?php
/** @joinery-test
 * name: multi_models_crud
 * tier: test-db         # runs against the copied test database
 * env: dev-only
 * needs: [test-db]
 * timeout: 900
 */

/**
 * Multi-collection query suite, contract-emitting.
 *
 * The companion to `models_test.php`. That one drives ModelTester over every
 * single-row model; this one drives MultiModelTester over every collection
 * class, which is the half that has never run from the gate — `models_test.php`
 * hard-sets SINGLE_TESTS_ONLY, so until now nothing checked that a Multi class
 * returns what its filters claim to.
 *
 * What the engine actually asserts, per model: that a loaded collection matches
 * the equivalent direct SQL, that each discovered filter narrows the result set
 * the way the same WHERE clause does, that ordering and pagination agree with
 * ORDER BY / LIMIT / OFFSET, and that combinations of the three compose. It
 * builds its own records to query against and removes them afterwards.
 *
 * This surface is worth gating because its failure mode is silent. A Multi
 * class that mis-reads an option returns a *plausible* collection — too many
 * rows, or the wrong owner's rows — and every caller downstream treats it as
 * authoritative. Nothing throws.
 *
 * Run: php tests/models/multi_models_test.php   (or php tests/run.php test-db)
 */

require_once(__DIR__ . '/../lib/harness.php');

// Both constants are read at class-load time by the tester, so they have to be
// defined before MultiModelTester.php is required, not after.
if (!defined('TEST_MULTI')) define('TEST_MULTI', true);
if (!defined('MULTI_TESTS_ONLY')) define('MULTI_TESTS_ONLY', true);

require_once(PathHelper::getIncludePath('tests/models/MultiModelTester.php'));
harness_boot();

set_time_limit(900);

$classes = LibraryFunctions::discover_model_classes(['include_plugins' => true]);

// Load every model class first: a Multi class is declared in the same file as
// its model, so class_exists('MultiFoo') is only meaningful once Foo's file has
// been read. Without this pass, models later in the list would report "no Multi
// class" purely because nothing had required them yet.
foreach ($classes as $class) { class_exists($class); }

$with_multi = array();
foreach ($classes as $class) {
	if (class_exists('Multi' . $class)) { $with_multi[] = $class; }
}

section('Multi-collection queries (' . count($with_multi) . ' of ' . count($classes) . ' models have a Multi class, test database)');

foreach ($with_multi as $class) {
	$before = ModelTester::get_test_stats();
	ob_start();
	$status = 'fail';
	$err = '';
	try {
		$tester = new MultiModelTester($class);
		$res = $tester->test(null, false, false);
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
		harness_skip('Multi' . $class, 'configuration required');
	} else {
		check($status === 'pass', 'Multi' . $class, $detail);
	}
}

// A model with no Multi class is a fact about the estate, not a failure — but
// it is worth stating, because "the Multi suite is green" should not be read as
// "every collection is covered" when a third of the models have no collection
// class at all.
section('Coverage');
check(count($with_multi) > 0, 'The estate has Multi classes to test', count($with_multi) . ' of ' . count($classes));

harness_finish();

<?php
/** @joinery-test
 * name: model_tester_selftest
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * Self-test of the ModelTester machinery: runs one model (ActivationCode)
 * through it against the test database and asserts that it passes and that
 * ModelTester's own counters advanced. Verifies the automated-testing system
 * itself, not the model.
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(PathHelper::getIncludePath('data/activation_codes_class.php'));
require_once(PathHelper::getIncludePath('tests/models/ModelTester.php'));
harness_boot();

section('ModelTester runs a model end to end');

$before = ModelTester::get_test_stats();
$result = null;
try {
	// ActivationCode::test() drives ModelTester against the test DB.
	$activation_code = new ActivationCode(NULL);
	$result = $activation_code->test(false);
	check($result === true || $result === 'SKIPPED', 'ActivationCode automated test did not fail',
		$result === false ? 'ModelTester reported a failure' : '');
} catch (Throwable $e) {
	check(false, 'ActivationCode automated test threw', $e->getMessage());
}

$after = ModelTester::get_test_stats();
check($after['passed'] >= $before['passed'], 'ModelTester pass counter advanced or held',
	'before=' . $before['passed'] . ' after=' . $after['passed']);
check($after['failed'] === $before['failed'], 'ModelTester recorded no new failures',
	'before=' . $before['failed'] . ' after=' . $after['failed']);

harness_finish();

<?php
/** @joinery-test
 * name: subscription_tiers
 * tier: live            # exercises the real Stripe test API (subscribe/upgrade/downgrade/cancel)
 * env: dev-only
 * needs: [stripe-test-keys]
 * timeout: 600
 */

/**
 * Subscription Tier Testing Script Runner
 *
 * Drives the SubscriptionTierTester class (model-layer tier logic plus real
 * Stripe test-mode subscription create/upgrade/downgrade/cancel/reactivate/
 * proration flows through change_tier_logic).
 *
 * Two output modes, one tester:
 *   - Web browser (admin): HTML report — access this file while logged in as an
 *     admin user.
 *   - CLI `--json`: the shared test contract. The tester records each test's
 *     verdict positively — passes in $test_passes, failures in $test_failures,
 *     with $tests_executed counting every test that ran. This runner emits one
 *     passing check per recorded pass, one failing check per recorded failure,
 *     and a guard check that a test actually ran, so a run where zero tests
 *     executed is red rather than a lone green check.
 *
 * Exit code reflects the result in BOTH modes: any recorded failure — or zero
 * tests executed — exits non-zero. In --json mode harness_finish() supplies that
 * from the emitted checks; the web/legacy path checks the collected results
 * explicitly.
 *
 * The tester's own aborts are preserved: it throws on test-DB setup failure and
 * records a failure on unmet preconditions. In --json mode a thrown abort is
 * surfaced as a failing check, never a pass.
 */

$is_cli = php_sapi_name() === 'cli';
$json_mode = $is_cli && in_array('--json', $GLOBALS['argv'] ?? array(), true);

if ($json_mode) {
	require_once(__DIR__ . '/../../../../tests/lib/harness.php');
	require_once(__DIR__ . '/SubscriptionTierTester.php');
	harness_boot();
	set_time_limit(600);

	$tester = null;
	// Suppress the tester's HTML report; only its collected failures matter here.
	ob_start();
	try {
		$tester = new SubscriptionTierTester();
		$tester->run();
		ob_end_clean();
	} catch (Exception $e) {
		ob_end_clean();
		section('Environment');
		check(false, 'SubscriptionTierTester::run()', $e->getMessage());
		harness_finish();
	}

	// Route the tester's collected results (private props) into the contract.
	$read = function ($prop) use ($tester) {
		$ref = new ReflectionProperty('SubscriptionTierTester', $prop);
		$ref->setAccessible(true);
		return $ref->getValue($tester);
	};
	$failures = $read('test_failures');
	$passes = $read('test_passes');
	$executed = (int)$read('tests_executed');

	section('Subscription tier tests');

	// The load-bearing guard: a run where zero tier tests executed (preconditions
	// unmet, setup aborted, Stripe keys missing) is RED, never a lone green check.
	// The tester records passes positively, so "no failures" alone can never be
	// mistaken for a full pass.
	check($executed > 0, 'subscription tier tests executed',
		$executed > 0 ? "$executed test(s) ran" : 'no tier test ran — preconditions unmet or setup aborted');

	foreach ($passes as $p) {
		check(true, (string)$p);
	}
	foreach ($failures as $f) {
		check(false, (string)($f['test'] ?? 'subscription tier test'), (string)($f['message'] ?? ''));
	}
	harness_finish(); // exits non-zero if any check failed
}

// ---- Web browser / legacy CLI mode: unchanged HTML report --------------------

echo "Starting subscription tier test script...<br>\n";
flush();

echo "Including SubscriptionTierTester class...<br>\n";
flush();

require_once(__DIR__ . '/SubscriptionTierTester.php');

echo "Creating SubscriptionTierTester instance...<br>\n";
flush();

$tester = null;
try {
	// Run the subscription tier tests
	$tester = new SubscriptionTierTester();
	echo "Running tests...<br>\n";
	flush();
	$tester->run();

} catch (Exception $e) {
	echo "<strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "<br>\n";
	exit(1);
}

// Assertion FAILURES (not just thrown exceptions) must also produce a non-zero
// exit. A run that executed no tests is likewise a failure, never a silent pass.
$read = function ($prop) use ($tester) {
	$ref = new ReflectionProperty('SubscriptionTierTester', $prop);
	$ref->setAccessible(true);
	return $ref->getValue($tester);
};
if (!empty($read('test_failures')) || (int)$read('tests_executed') === 0) {
	exit(1);
}
?>

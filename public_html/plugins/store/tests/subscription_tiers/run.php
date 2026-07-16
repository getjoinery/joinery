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
 *   - Web browser (admin): unchanged HTML report — access this file while
 *     logged in as an admin user.
 *   - CLI `--json`: the shared test contract. The tester records only failures
 *     (in $test_failures); this runner routes each recorded failure through the
 *     harness as a failing check(), and emits a single passing check when the
 *     run recorded no failures — mirroring the tester's own "no failures =
 *     success" verdict.
 *
 * Exit code reflects failures in BOTH modes: any recorded failure (an assertion
 * failure, not only a thrown exception) exits non-zero. In --json mode
 * harness_finish() supplies that for free; the web/legacy path checks the
 * collected failures explicitly.
 *
 * The tester's own aborts are preserved: it throws on test-DB setup failure and
 * returns early on unmet preconditions. In --json mode a thrown abort is
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

	// Route the tester's collected failures (private $test_failures) into the contract.
	$ref = new ReflectionProperty('SubscriptionTierTester', 'test_failures');
	$ref->setAccessible(true);
	$failures = $ref->getValue($tester);

	section('Subscription tier tests');
	if (empty($failures)) {
		check(true, 'all subscription tier tests', 'no failures recorded');
	} else {
		foreach ($failures as $f) {
			check(false, (string)($f['test'] ?? 'subscription tier test'), (string)($f['message'] ?? ''));
		}
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
// exit. Inspect the collected failures and exit(1) if any test failed.
$ref = new ReflectionProperty('SubscriptionTierTester', 'test_failures');
$ref->setAccessible(true);
$failures = $ref->getValue($tester);
if (!empty($failures)) {
	exit(1);
}
?>

<?php
/** @joinery-test
 * name: products
 * tier: live            # exercises the real Stripe test API + product/cart flow
 * env: dev-only
 * needs: [stripe-test-keys]
 * timeout: 600
 */

/**
 * Product Testing Script Runner
 *
 * Drives the ProductTester class (product creation via the admin_product_edit
 * endpoint, cart, coupons, and a Stripe test-mode payment flow).
 *
 * Two output modes, one tester:
 *   - Web browser (admin): unchanged HTML report — access this file while
 *     logged in as an admin user.
 *   - CLI `--json`: the shared test contract. ProductTester's collected
 *     pass/fail results are routed through the harness (section/check) so the
 *     discovery runner and dashboard can consume them.
 *
 * Exit code reflects failures in BOTH modes: any FAILED result (an assertion
 * failure, not only a thrown exception) exits non-zero. In --json mode
 * harness_finish() supplies that for free; the web/legacy path checks the
 * collected results explicitly.
 *
 * The live-Stripe-test-keys abort is preserved: if live keys are detected the
 * tester throws and this runner surfaces it as a skip (--json) or a safety
 * termination message (web), never a pass.
 */

$is_cli = php_sapi_name() === 'cli';
$json_mode = $is_cli && in_array('--json', $GLOBALS['argv'] ?? array(), true);

if ($json_mode) {
	require_once(__DIR__ . '/../../lib/harness.php');
	require_once(__DIR__ . '/ProductTester.php');
	harness_boot();
	set_time_limit(600);

	$tester = null;
	// Suppress the tester's HTML report; only its collected results matter here.
	ob_start();
	try {
		$tester = new ProductTester();
		$tester->run();
		ob_end_clean();
	} catch (Exception $e) {
		ob_end_clean();
		if (strpos($e->getMessage(), 'LIVE KEYS DETECTED') !== false) {
			section('Environment');
			harness_skip('stripe test keys', 'Live Stripe keys detected — test aborted for safety (configure pk_test_/sk_test_ keys).');
			harness_finish();
		}
		section('Product tests');
		check(false, 'ProductTester::run()', $e->getMessage());
		harness_finish();
	}

	// Route the tester's collected results (private $test_results) into the contract.
	$ref = new ReflectionProperty('ProductTester', 'test_results');
	$ref->setAccessible(true);
	$results = $ref->getValue($tester);

	section('Product tests');
	if (empty($results)) {
		check(false, 'product results', 'no test results were collected');
	} else {
		foreach ($results as $r) {
			$passed = (($r['status'] ?? '') === 'PASSED');
			$detail = !empty($r['errors']) ? implode('; ', $r['errors']) : '';
			check($passed, (string)($r['name'] ?? 'product'), $detail);
		}
	}
	harness_finish(); // exits non-zero if any check failed
}

// ---- Web browser / legacy CLI mode: unchanged HTML report --------------------

echo "Starting test script...<br>\n";
flush();

echo "Including ProductTester class...<br>\n";
flush();

require_once(__DIR__ . '/ProductTester.php');

echo "Creating ProductTester instance...<br>\n";
flush();

$tester = null;
try {
	// Run the product tests
	$tester = new ProductTester();
	echo "Running tests...<br>\n";
	flush();
	$tester->run();

} catch (Exception $e) {
	// Check if this is a safety termination
	if (strpos($e->getMessage(), 'LIVE KEYS DETECTED') !== false) {
		echo "<strong>SAFETY TERMINATION:</strong> " . htmlspecialchars($e->getMessage()) . "<br>\n";
		exit(1); // Exit with error code when live keys detected
	}

	echo "<strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "<br>\n";
	exit(1); // Exit with error code for any exception
}

// Assertion FAILURES (not just thrown exceptions) must also produce a non-zero
// exit. Inspect the collected results and exit(1) if any test failed.
$ref = new ReflectionProperty('ProductTester', 'test_results');
$ref->setAccessible(true);
$results = $ref->getValue($tester);
$failures = 0;
foreach (($results ?: array()) as $r) {
	if (($r['status'] ?? '') !== 'PASSED') $failures++;
}
if ($failures > 0) {
	exit(1);
}
?>

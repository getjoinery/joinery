<?php
/** @joinery-test
 * name: email_suites
 * tier: live            # sends real mail / hits real Mailgun + SMTP + DNS
 * env: prod-verify
 * needs: [mailgun]
 * timeout: 600
 */

/**
 * Email suites, contract-emitting.
 *
 * Drives the existing EmailTestRunner (service/template/delivery/authentication
 * suites) exactly as the web index page does, but instead of rendering HTML it
 * translates the nested per-suite result arrays into the shared harness contract:
 * one section() per suite group, one check() per test. The suite classes and
 * EmailTestRunner are unchanged — this runner only re-shapes their output.
 *
 *   php tests/email/email_suite_test.php --json
 *
 * Because these suites send real mail and hit live services, the header tier is
 * `live` / env `prod-verify` — it is deliberately not part of the safe batch.
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/EmailTestRunner.php');
require_once(__DIR__ . '/suites/ServiceTests.php');
require_once(__DIR__ . '/suites/TemplateTests.php');
require_once(__DIR__ . '/suites/DeliveryTests.php');
require_once(__DIR__ . '/suites/AuthenticationTests.php');

harness_boot();

set_time_limit(300);

$runner = new EmailTestRunner();
$results = $runner->runAllTests();

// $results: ['service' => ['testName' => ['passed'=>bool,'message'=>str,...]], ...]
foreach ($results as $suite => $tests) {
	section(ucfirst(str_replace('_', ' ', (string)$suite)) . ' tests');
	if (!is_array($tests)) {
		check(false, (string)$suite, 'suite returned a non-array result');
		continue;
	}
	foreach ($tests as $testName => $r) {
		$passed = is_array($r) && !empty($r['passed']);
		$detail = is_array($r) ? ($r['message'] ?? '') : '';
		check($passed, (string)$testName, (string)$detail);
	}
}

harness_finish();

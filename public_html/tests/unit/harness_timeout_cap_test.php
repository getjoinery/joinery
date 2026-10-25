<?php
/** @joinery-test
 * name: harness_timeout_cap
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A suite's declared timeout is capped by its tier: thirty minutes, ninety for
 * a live suite (HARNESS_TIMEOUT_MAX, HARNESS_TIMEOUT_MAX_LIVE), and the stale
 * fixture floor outlasts both, so no run's fixtures are swept while it lives.
 *
 * Run: php tests/unit/harness_timeout_cap_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('The timeout a header declares, capped by its tier');

$dir = harness_scratch_dir('timeout_cap');
$header = function (string $tier, string $timeout) use ($dir): array {
	$f = $dir . '/t_' . md5($tier . $timeout) . '.php';
	file_put_contents($f, "<?php\n/** @joinery-test\n * name: t\n * tier: {$tier}\n * env: dev-only\n * needs: []\n"
		. ($timeout !== '' ? " * timeout: {$timeout}\n" : '') . " */\n");
	return harness_parse_metadata($f);
};

check($header('db', '600')['timeout'] === 600, 'a timeout under the cap stands');
check($header('db', '5400')['timeout'] === HARNESS_TIMEOUT_MAX && HARNESS_TIMEOUT_MAX === 1800,
	'a db suite is held to thirty minutes');
check($header('live', '5400')['timeout'] === 5400, 'a live suite may declare ninety minutes');
check($header('live', '99999')['timeout'] === HARNESS_TIMEOUT_MAX_LIVE && HARNESS_TIMEOUT_MAX_LIVE === 5400,
	'and no more');
check($header('Live', '5400')['timeout'] === 5400, 'the tier is read before the cap, whatever its case');
check($header('nonsense', '5400')['tier'] === 'live', 'an unknown tier reads as live (never run unless named), and so takes the live cap');
check($header('safe', '')['timeout'] === 180 && $header('safe', 'soon')['timeout'] === 180, 'no number is the 180-second default');
check($header('db', '0')['timeout'] === 1, 'and a zero is one second, never none');
check(HARNESS_STALE_FIXTURE_SECONDS > HARNESS_TIMEOUT_MAX_LIVE,
	'the stale fixture floor outlasts the longest suite, so a live run\'s fixtures are never swept from under it');

harness_finish();

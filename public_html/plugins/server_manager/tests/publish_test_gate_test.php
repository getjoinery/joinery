<?php
/** @joinery-test
 * name: publish_test_gate
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A publish refuses a tree that fails its own tests.
 *
 * publish_upgrade.php archives whatever is on disk, so before it writes anything
 * it runs the test runner and stops on a failure. PublishTestGate owns the
 * mechanics; this test drives it with fake runners (a script that passes, one
 * that fails, one that is missing) and then reads publish_upgrade.php to pin
 * that the gate sits before the first write and has no bypass flag.
 *
 * Run: php tests/run.php safe --filter=publish_test_gate
 *
 * @version 1.1 - the safe tier is accepted from the runner's stamp, never run by the publisher
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/PublishTestGate.php'));

$dir = sys_get_temp_dir() . '/publish_gate_' . getmypid();
@mkdir($dir, 0700, true);
$made = array();
$fake = function ($name, $body) use ($dir, &$made) {
	$path = $dir . '/' . $name;
	file_put_contents($path, "<?php\n" . $body);
	$made[] = $path;
	return $path;
};

try {
	// -----------------------------------------------------------------------
	section('A passing runner lets the publish through, minus the per-suite PASS noise');
	// -----------------------------------------------------------------------
	$passing = $fake('pass_runner.php', <<<'PHP'
echo "Tier: safe\n";
echo "  PASS alpha_suite             12/12         3ms  tests/alpha_test.php\n";
echo "  PASS beta_suite              —          40ms  tests/beta_gate.sh\n";
echo "  SKIP gamma_suite (env)\n";
echo "Tests: 2 passed, 0 failed of 2\n";
echo "RESULT: PASS\n";
exit(0);
PHP
	);
	$lines = array();
	$r = PublishTestGate::run($passing, 'safe', function ($l) use (&$lines) { $lines[] = $l; });
	check($r['started'] === true && $r['ok'] === true && $r['exit_code'] === 0, 'exit 0 → ok');
	check(!in_array('  PASS alpha_suite             12/12         3ms  tests/alpha_test.php', $lines, true)
		&& count(array_filter($lines, function ($l) { return preg_match('/^\s*PASS /', $l); })) === 0,
		'per-suite PASS lines are not relayed');
	check(in_array('  SKIP gamma_suite (env)', $lines, true), 'skips are relayed');
	check(in_array('RESULT: PASS', $lines, true) && in_array('Tests: 2 passed, 0 failed of 2', $lines, true), 'summary is relayed');
	check(in_array('Tier: safe', $lines, true), 'the tier header is relayed');

	// -----------------------------------------------------------------------
	section('A failing runner refuses the publish, and says which suite');
	// -----------------------------------------------------------------------
	$failing = $fake('fail_runner.php', <<<'PHP'
echo "  PASS alpha_suite             12/12         3ms  tests/alpha_test.php\n";
echo "  FAIL beta_suite              3/4 (1 failed)   9ms  tests/beta_test.php\n";
echo "         ✗ the thing that broke\n";
echo "Failed:\n  - beta_suite  (tests/beta_test.php)\n";
echo "RESULT: FAIL\n";
exit(1);
PHP
	);
	$lines = array();
	$r = PublishTestGate::run($failing, 'deploy', function ($l) use (&$lines) { $lines[] = $l; });
	check($r['started'] === true && $r['ok'] === false && $r['exit_code'] === 1, 'exit 1 → not ok, exit code carried');
	check(in_array('  FAIL beta_suite              3/4 (1 failed)   9ms  tests/beta_test.php', $lines, true), 'the FAIL line is relayed');
	check(in_array('         ✗ the thing that broke', $lines, true), 'the failing check is relayed');
	check(in_array('RESULT: FAIL', $lines, true), 'the verdict is relayed');

	// stderr rides along too (2>&1), so a runner that dies before printing a verdict is still legible.
	$dying = $fake('dying_runner.php', "fwrite(STDERR, \"PHP Fatal error: boom\\n\"); exit(255);\n");
	$lines = array();
	$r = PublishTestGate::run($dying, 'deploy', function ($l) use (&$lines) { $lines[] = $l; });
	check($r['ok'] === false && $r['exit_code'] === 255, 'a crashed runner is a refusal, exit code carried');
	check(in_array('PHP Fatal error: boom', $lines, true), 'its stderr is relayed');

	// -----------------------------------------------------------------------
	section('A missing runner is a refusal, not a pass');
	// -----------------------------------------------------------------------
	$r = PublishTestGate::run($dir . '/no_such_runner.php', 'deploy', function ($l) {});
	check($r['started'] === false && $r['ok'] === false, 'no runner → started=false, ok=false');

	$threw = false;
	try {
		PublishTestGate::run($passing, 'safe; rm -rf /', function ($l) {});
	} catch (InvalidArgumentException $e) {
		$threw = true;
	}
	check($threw, 'a tier that is not a tier name is refused before any shell sees it');

	check(PublishTestGate::isSuitePassLine('  PASS foo  1/1  2ms  x.php') && !PublishTestGate::isSuitePassLine('RESULT: PASS')
		&& !PublishTestGate::isSuitePassLine('PASSED: 3   FAILED: 0') && !PublishTestGate::isSuitePassLine('  FAIL foo'),
		'only a per-suite PASS line counts as noise');

	// -----------------------------------------------------------------------
	section('publish_upgrade.php gates before it writes, with no bypass');
	// -----------------------------------------------------------------------
	$src = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/publish_upgrade.php'));
	$gate_at   = strpos($src, 'PublishTestGate::run(');
	$bundle_at = strpos($src, 'Bundle the management agent artifact');
	$version_at = strpos($src, "file_put_contents(\$version_file");
	check($gate_at !== false, 'publish_upgrade.php runs PublishTestGate');
	check($bundle_at !== false && $gate_at < $bundle_at, 'the gate runs before the agent bundle is built');
	check($version_at === false || $gate_at < $version_at, 'the gate runs before the VERSION file is written');
	check(strpos($src, "\$gate_tiers = array('deploy')") !== false, 'the deploy tier runs on every publisher');
	check(strpos($src, "DeploymentHelper::mayMintReleaseVersion()") !== false && strpos($src, "\$gate_tiers[] = 'safe'") !== false,
		'the safe tier is required where the site authored the code');
	// The publisher is root on the local job queue. It must never RUN a
	// development tier (2026-09-02: a safe-tier gate run as root installed the
	// agent over itself and stopped it); it accepts the runner's stamp instead.
	check(strpos($src, "PublishTestGate::verifyStamp(") !== false, 'development tiers are accepted from the stamp');
	check(preg_match('/if \(\$gate_tier !== \'deploy\'\) \{\s*publish_output\([^;]*;\s*\$gate = PublishTestGate::verifyStamp\(/s', $src) === 1,
		'every tier but deploy goes through verifyStamp, so run() only ever sees deploy');
	check(!preg_match('/skip[-_]tests|no[-_]tests|without[-_]tests/i', $src), 'no flag skips the gate');

} finally {
	foreach ($made as $p) { @unlink($p); }
	@rmdir($dir);
}

harness_finish();

<?php
/** @joinery-test
 * name: bundle_manifest_fetch
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [includes/DeploymentHelper.php, maintenance_scripts/sysadmin_tools/install_bundle.php, maintenance_scripts/install_tools/_site_init.sh, maintenance_scripts/install_tools/install.sh, includes/InstallReport.php]
 */
/**
 * A fresh install's plugin bundle reads the upgrade source's published-archives
 * manifest (B7, 2026-11-27: one unanswered read cost a test install Mailbox and
 * the AI assistant, with no reason given and nothing in the summary).
 *
 *   - DeploymentHelper::fetchReleaseManifest asks again on a failure that can
 *     pass, stops on one that cannot, and names what failed;
 *   - a bundle that did not install is recorded for the install summary and the
 *     setup wizard, with the command that runs it again.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

function bmf_run(array $answers, array $waits = array(5, 15, 30)) {
	$slept = array();
	$calls = 0;
	$r = DeploymentHelper::fetchReleaseManifest('https://src.example/', $waits,
		function ($url) use (&$answers, &$calls) { $calls++; return array_shift($answers); },
		function ($s) use (&$slept) { $slept[] = $s; });
	return array($r, $calls, $slept);
}
$ok = array(200, '{"published_plugins":[{"name":"mailbox","url":"https://src.example/p.tgz"}]}', '');

section('A failure that can pass is asked again');
list($r, $calls, $slept) = bmf_run(array(array(503, 'busy', ''), array(0, '', 'Connection timed out'), $ok));
check(is_array($r['manifest']) && $calls === 3 && $slept === array(5, 15), 'a 503 then a timeout, then the manifest: three tries, 5 and 15 s apart', json_encode(array($r, $calls, $slept)));
check($r['url'] === 'https://src.example/utils/upgrade?serve-upgrade=1', 'the manifest URL is built from the source', $r['url']);
list($r, $calls) = bmf_run(array(array(429, '', ''), array(200, '<html>holding</html>', ''), $ok));
check(is_array($r['manifest']) && $calls === 3, 'a 429 and an answer that is not JSON are asked again too');

section('A failure that cannot pass, or that does not pass, is named');
list($r, $calls) = bmf_run(array(array(404, '{"error":"no releases"}', '')));
check($r['manifest'] === null && $calls === 1 && $r['error'] === 'HTTP 404', 'a 404 is final at once and says its status', $r['error']);
list($r, $calls, $slept) = bmf_run(array(array(503, '', ''), array(503, '', ''), array(503, '', ''), array(503, '', '')));
check($r['manifest'] === null && $calls === 4 && $slept === array(5, 15, 30) && $r['error'] === 'HTTP 503 (after 4 tries)',
	'four 503s: gives up after the third retry and says how many tries', $r['error']);
list($r) = bmf_run(array(array(0, '', 'Could not resolve host: src.example')), array());
check($r['error'] === 'could not connect: Could not resolve host: src.example', 'a network error is named as it is', $r['error']);

section('install_bundle.php uses it and passes the reason on');
$ib = (string)file_get_contents(dirname(PathHelper::getBasePath()) . '/maintenance_scripts/sysadmin_tools/install_bundle.php');
check(strpos($ib, 'DeploymentHelper::fetchReleaseManifest($source)') !== false && strpos($ib, 'file_get_contents($manifest_url)') === false,
	'the bundle fetch is the retrying one, not a bare file_get_contents');
check(strpos($ib, "published-archives manifest at {\$fetched['url']}: {\$fetched['error']}") !== false, 'and its refusal carries the reason');

section('A bundle that did not install reaches the summary and the wizard');
$si = (string)file_get_contents(dirname(PathHelper::getBasePath()) . '/maintenance_scripts/install_tools/_site_init.sh');
if (preg_match('/^            BUNDLE_MISSING=.*\n            BUNDLE_WHY=.*\n            PLUGINS_OUTCOME=.*\n/m', $si, $m)) {
	$out = "Fetching from https://getjoinery.com: mailbox, joinery_ai\n"
		. "  mailbox: could not read the published-archives manifest at https://getjoinery.com/utils/upgrade?serve-upgrade=1: HTTP 503 (after 4 tries) — skipped\n"
		. "  joinery_ai: could not read the published-archives manifest at https://getjoinery.com/utils/upgrade?serve-upgrade=1: HTTP 503 (after 4 tries) — skipped\n\n"
		. "2 plugin(s) did not install: mailbox, joinery_ai\nThe site is usable without them. Install from /admin/admin_plugins.\n";
	$script = 'BUNDLE_OUT=$(cat); BUNDLE_TOOL=/x/install_bundle.php; BUNDLE_NAME=personal' . "\n" . $m[0] . 'printf "%s" "$PLUGINS_OUTCOME"';
	$p = proc_open(array('bash', '-c', $script), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w')), $pipes);
	fwrite($pipes[0], $out); fclose($pipes[0]);
	$got = stream_get_contents($pipes[1]); proc_close($p);
	check($got === 'failed: mailbox, joinery_ai did not install (could not read the published-archives manifest at https://getjoinery.com/utils/upgrade?serve-upgrade=1: HTTP 503 (after 4 tries)). Run again: sudo php /x/install_bundle.php --bundle=personal',
		'_site_init.sh records which plugins, why, and the command that runs it again', $got);
} else {
	check(false, '_site_init.sh records the bundle outcome');
}
check(strpos($si, "printf 'plugins=%s\\n' \"\$PLUGINS_OUTCOME\"") !== false, 'as plugins= in install_services.txt');
$tmp = harness_scratch_dir('bundle_manifest_fetch');
file_put_contents($tmp . '/install_services.txt', "plugins=failed: mailbox did not install (HTTP 503). Run again: sudo php /x/install_bundle.php --bundle=personal\n");
$o = InstallReport::outcomes($tmp . '/install_services.txt');
check(isset($o['plugins']) && $o['plugins']['label'] === 'Plugins' && $o['plugins']['outcome'] === 'failed' && strpos($o['plugins']['text'], 'Run again') !== false,
	"the setup wizard's first screen lists it", json_encode($o));
$inst = (string)file_get_contents(dirname(PathHelper::getBasePath()) . '/maintenance_scripts/install_tools/install.sh');
if (preg_match('/^print_services_outcome\(\) \{.*?^\}\n/ms', $inst, $m)) {
	file_put_contents($tmp . '/fn.sh', "YELLOW=; NC=; BLUE=; RED=; GREEN=\nprint_email_setup_notice() { :; }\n" . $m[0]);
	$out = (string)shell_exec('bash -c ' . escapeshellarg('. ' . $tmp . '/fn.sh; print_services_outcome ' . $tmp . '/install_services.txt') . ' 2>&1');
	check(strpos($out, 'Plugins: NOT all installed — mailbox did not install (HTTP 503). Run again:') !== false, 'and the install summary says it', $out);
} else {
	check(false, 'print_services_outcome is found in install.sh');
}

harness_finish();

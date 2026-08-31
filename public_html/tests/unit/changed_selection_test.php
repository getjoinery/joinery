<?php
/** @joinery-test
 * name: changed_selection
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The --changed selection logic, fed fabricated maps and change lists.
 *
 * The rule under guard: a scoped run may only ever run LESS than the full
 * gate, and only for a reason the map can state — never skip a suite it
 * cannot account for. So: an unknown suite runs; a harness change runs
 * everything; a suite runs when a changed file is in its recorded reach, its
 * covers globs, its fixtures, or (for a dev-web suite) core; and a changed
 * code file nothing reaches is named, while prose is not.
 *
 * Run: php tests/unit/changed_selection_test.php
 */
require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/coverage.php');
harness_boot();

$T = function ($path, $needs = array(), $covers = array()) {
	return array('path' => $path, 'meta' => array('needs' => $needs, 'covers' => $covers));
};
$mailbox = $T('public_html/plugins/mailbox/tests/drafts_test.php');
$vault   = $T('public_html/tests/vault/registry_test.php');
$web     = $T('public_html/tests/functional/api/member_screens_test.php', array('dev-web'));
$gate    = $T('public_html/tests/functional/sync/sync_sim_gate.sh', array('rust'), array('sync/**'));
$tests = array($mailbox, $vault, $web, $gate);
$map = array(
	'public_html/plugins/mailbox/tests/drafts_test.php' => array(
		'files' => array('public_html/plugins/mailbox/includes/Drafts.php'), 'covers' => array()),
	'public_html/tests/vault/registry_test.php' => array(
		'files' => array('public_html/includes/VaultCrypto.php'), 'covers' => array()),
	'public_html/tests/functional/api/member_screens_test.php' => array(
		'files' => array('public_html/tests/lib/http.php'), 'covers' => array()),
	'public_html/tests/functional/sync/sync_sim_gate.sh' => array('files' => array(), 'covers' => array()),
);

section('A change selects exactly what reaches it');

$r = coverage_select($tests, $map, array('public_html/plugins/mailbox/includes/Drafts.php'));
check(array_keys($r['selected']) === array('public_html/plugins/mailbox/tests/drafts_test.php'),
	'a mailbox file selects the suite that loads it, and nothing else', json_encode(array_keys($r['selected'])));
check($r['uncovered'] === array(), 'a reached file is not "uncovered"');

$r = coverage_select($tests, $map, array('public_html/includes/VaultCrypto.php'));
check(isset($r['selected']['public_html/tests/vault/registry_test.php']), 'a core file selects the suite that loads it');
check(isset($r['selected']['public_html/tests/functional/api/member_screens_test.php']),
	'and every dev-web suite — Apache-side reach is not in the include list');
check(!isset($r['selected']['public_html/plugins/mailbox/tests/drafts_test.php']),
	'but not a suite whose recorded reach misses it');

section('Unknown means run, never skip');

$r = coverage_select($tests, array(), array('public_html/docs/whatever.md'));
check(count($r['selected']) === count($tests),
	'with no map at all, everything runs — a missing map can only widen', count($r['selected']) . ' selected');

$r = coverage_select($tests, $map, array());
check($r['selected'] === array(), 'a clean tree selects nothing');

section('The overrides');

$r = coverage_select($tests, $map, array('public_html/tests/lib/harness.php'));
check($r['run_all'] !== '', 'a harness change runs the whole batch, with the reason stated', $r['run_all']);

$r = coverage_select($tests, $map, array('public_html/tests/vault/registry_test.php'));
check(isset($r['selected']['public_html/tests/vault/registry_test.php']), "a suite's own file selects it");

$r = coverage_select($tests, $map, array('public_html/tests/vault/fixtures/sealed.bin'));
check(isset($r['selected']['public_html/tests/vault/registry_test.php']), 'an area fixture selects its area');
check(!isset($r['selected']['public_html/plugins/mailbox/tests/drafts_test.php']), 'and only its area');

$r = coverage_select($tests, $map, array('public_html/tests/fixtures/shared.eml'));
check(count($r['selected']) === count($tests), 'the shared fixtures select everything');

section('covers: globs — reach without loading');

check(coverage_glob_match('sync/**', 'sync/jd-core/src/reconcile.rs'), '** crosses directories');
check(coverage_glob_match('public_html/plugins/*/plugin.json', 'public_html/plugins/mailbox/plugin.json'),
	'* matches one segment');
check(!coverage_glob_match('public_html/plugins/*/plugin.json', 'public_html/plugins/mailbox/sub/plugin.json'),
	'and only one segment');
$r = coverage_select($tests, $map, array('sync/jd-core/src/order.rs'));
check(array_keys($r['selected']) === array('public_html/tests/functional/sync/sync_sim_gate.sh'),
	'a Rust change selects the Rust gate through covers:, and nothing else', json_encode(array_keys($r['selected'])));

section('Uncovered names code, not prose');

$r = coverage_select($tests, $map, array(
	'public_html/assets/js/orphan.js', 'public_html/specs/some_spec.md', 'docs/notes.md',
));
check($r['uncovered'] === array('public_html/assets/js/orphan.js'),
	'an unreached code file is named; specs and markdown are not', json_encode($r['uncovered']));

section('The contract records reach');

// The map is only as honest as the contract underneath it: a suite's emitted
// JSON must carry the repo files its process loaded.
$out = (string)shell_exec(escapeshellarg(PHP_BINARY)
	. ' ' . escapeshellarg(dirname(__DIR__) . '/unit/box_variants_test.php') . ' --json 2>/dev/null');
$pos = strrpos($out, JOINERY_RESULT_SENTINEL);
$contract = $pos === false ? null : json_decode(trim(substr($out, $pos + strlen(JOINERY_RESULT_SENTINEL))), true);
check(is_array($contract) && !empty($contract['files']), 'a suite contract carries its loaded files');
check(is_array($contract) && !in_array(true, array_map(function ($f) {
	return strpos($f, 'vendor/') === 0 || strpos($f, '/') === 0;
}, $contract['files'] ?? array()), true), 'repo-relative, vendor excluded');

harness_finish();

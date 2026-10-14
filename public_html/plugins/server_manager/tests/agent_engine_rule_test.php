<?php
/** @joinery-test
 * name: agent_engine_rule
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [maintenance_scripts/install_tools/_site_engine.sh]
 */
/**
 * The agent names a host's container engine by the shell helper's rule.
 *
 * maintenance_scripts/install_tools/_site_engine.sh says which engine a host's
 * sites run on (specs/site_container_engine.md D7); the agent's engine.go says
 * the same thing in Go, and records the helper Version its rule matches
 * (siteEngineRule). A helper that moves on without the agent would leave the
 * agent misnaming the engine on some host, silently (reviewer2 T5). Read from
 * the agent checkout named by server_manager_agent_source_path, as
 * agent_bundle_drift reads it; a box with no agent source skips.
 *
 * Run: php plugins/server_manager/tests/agent_engine_rule_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentDistPublisher.php'));

section('The agent\'s engine rule is the helper\'s');

$helper = dirname(PathHelper::getIncludePath('')) . '/maintenance_scripts/install_tools/_site_engine.sh';
$helper_src = is_file($helper) ? (string)file_get_contents($helper) : '';
check(preg_match('/^# Version: ([0-9]+\.[0-9]+)\b/m', $helper_src, $hm) === 1, 'the helper names its Version', $helper);
$helper_version = $hm[1] ?? '';

$src = AgentDistPublisher::sourcePath();
$engine_go = $src ? rtrim($src, '/') . '/engine.go' : '';
if ($engine_go === '' || !is_file($engine_go)) {
	harness_skip('the agent\'s engine.go is read from its source checkout', 'no agent source on this box');
} else {
	$go = (string)file_get_contents($engine_go);
	check(preg_match('/^const siteEngineRule = "([0-9]+\.[0-9]+)"$/m', $go, $gm) === 1,
		'engine.go records the helper Version its rule matches (siteEngineRule)', $engine_go);
	check(($gm[1] ?? '') === $helper_version,
		'and it is the helper\'s Version',
		'engine.go matches _site_engine.sh ' . ($gm[1] ?? '?') . ', the helper is ' . $helper_version
		. ': bring engine.go\'s rule to the helper\'s, then siteEngineRule');
	check(strpos($go, '"/usr/local/lib/joinery-site-engine/current"') !== false
		&& strpos($helper_src, '/usr/local/lib/joinery-site-engine/current') !== false,
		'both read the engine\'s current link at the same path');
}

harness_finish();

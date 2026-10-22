<?php
/** @joinery-test
 * name: agent_live_proofs_live
 * tier: live
 * env: dev-only
 * needs: [test-cloud, agent-source]
 * timeout: 1800
 * covers: [plugins/server_manager/includes/AgentLiveProofRun.php, plugins/server_manager/includes/AgentLiveProofs.php]
 */
/**
 * A changed agent word runs once on a real machine before release
 * (specs/fleet_security_patching.md WP7), with an agent built for the run.
 *
 * The words: those this release changes and no proof covers yet
 * (AgentLiveProofs), or the ones LIVE_PROOF_WORDS names (comma-separated).
 * Of those, the ones a bare Ubuntu machine can prove (AgentLiveProofRun::
 * RECIPES) are run; a changed word with no recipe is named, to be proven by
 * hand with record_live_proof.php. With nothing to prove, no server is made.
 *
 * The run (AgentLiveProofRun): the agent is built from the source being
 * prepared and the bundle from this tree, signed with a key made for the run
 * (Q8); a throwaway server in the test cloud account; the agent installed and
 * joined to this management node as a test build, which the plane offers
 * none of its own agents; each word queued as a job and judged; a passing
 * word recorded in agent_live_proofs.json with the build's agent version;
 * then the node, the server, its DNS and the key are removed, whatever
 * happened. A run killed part way leaves its server to the test cloud's
 * nightly cleanup, which removes the node with it.
 *
 * Run: php tests/run.php live --only=plugins/server_manager/tests/agent_live_proofs_live_test.php
 *      LIVE_PROOF_WORDS=check_status,install_security_updates php plugins/server_manager/tests/agent_live_proofs_live_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$say = function ($m) { echo '    ' . $m . "\n"; };
$src = AgentDistPublisher::sourcePath();

section('The words to prove');

$named = array_values(array_filter(array_map('trim', explode(',', (string)getenv('LIVE_PROOF_WORDS'))), 'strlen'));
if ($named) {
	$words = $named;
	$why = 'named in LIVE_PROOF_WORDS';
} else {
	$manifest = AgentDistPublisher::readManifest(PathHelper::getIncludePath('agent_dist'));
	$since = is_array($manifest) ? ($manifest['source_commit'] ?? null) : null;
	$changed = AgentLiveProofs::changed($src, is_string($since) ? $since : null, dirname(PathHelper::getRootDir()));
	$unproven = AgentLiveProofs::unproven($changed, (string)(AgentDistPublisher::readCommittedVersion($src) ?? ''), AgentLiveProofs::proofs());
	$words = array_keys($unproven);
	$why = 'changed since the last release and not yet proven';
}
$runnable = array_values(array_filter($words, function ($w) { return isset(AgentLiveProofRun::RECIPES[$w]); }));
$by_hand = array_values(array_diff($words, $runnable));
echo '    ' . ($words ? implode(', ', $words) : 'none') . " ({$why})\n";
if ($by_hand) {
	echo '    not provable on a bare machine, prove by hand (record_live_proof.php): ' . implode(', ', $by_hand) . "\n";
}
check($named === array() || $by_hand === array(), 'every word named has a recipe', implode(', ', $by_hand));
if (!$runnable) {
	check(true, 'nothing here for a bare machine to prove: no server made');
	harness_finish();
}

$work = harness_scratch_dir('live-proof');
$label = AgentLiveProofRun::LABEL_PREFIX . bin2hex(random_bytes(3));
if (($dead = AgentLiveProofRun::forget_dead_runs()) > 0) {
	echo "    removed {$dead} root password file(s) of earlier runs killed before their teardown\n";
}
$server = null;
$node = null;
harness_defer(function () use (&$node, &$server, $work) {
	$left = AgentLiveProofRun::teardown($node, $server, $work);
	echo $left ? '    TEARDOWN LEFT: ' . implode('; ', $left) . "\n" : "    torn down: node, server, its DNS, and the run's key\n";
});

try {

section('Build, with a key made for the run');
$build = AgentLiveProofRun::build($work, $say);
check($build['version'] !== '', 'agent ' . $build['version'] . ' built from ' . substr($build['commit'], 0, 12) . ($build['dirty'] ? ' and uncommitted changes' : ''));
$m = json_decode((string)file_get_contents($build['dist'] . '/manifest.json'), true);
check(($m['baked_keys']['release_keys'] ?? null) === array($m['signing_public_key'] ?? '') && ($m['baked_keys']['statement_keys'] ?? null) === array(),
	'the only key it trusts is the run\'s own (' . $build['key'] . ')', json_encode($m['baked_keys'] ?? null));

section('A throwaway server, the agent, the join');
$server = AgentLiveProofRun::make_server($label);
check(true, 'server ' . $label . ' (' . $server['id'] . ') at ' . $server['ipv4']);
$fingerprint = AgentLiveProofRun::install($server, $build, $say);
check(preg_match('/^[0-9a-f]{16}$/', $fingerprint) === 1, 'the machine asked to join, fingerprint ' . $fingerprint);
$node = AgentLiveProofRun::adopt($fingerprint, $server);
check((bool)$node->get('mgn_agent_test_build'), 'adopted as node #' . $node->key . ', from the server\'s own address under its label, marked a test build');
AgentLiveProofRun::await_agent($node, $build['version']);
check(true, 'its agent reports itself at ' . $build['version']);

section('Each word, through the agent');
$where = 'test server ' . $label . ' (Linode ' . $server['id'] . '), Ubuntu, agent built for the run from '
	. substr($build['commit'], 0, 12) . ($build['dirty'] ? ' and uncommitted changes' : '');
foreach ($runnable as $word) {
	$r = AgentLiveProofRun::prove($node, $word);
	check($r['ok'], $word . ': ' . $r['what'] . ($r['job_id'] ? ' (job ' . $r['job_id'] . ')' : ''));
	if ($r['ok']) {
		AgentLiveProofs::record($word, $build['version'], $where, 'job ' . $r['job_id'] . ' through the agent: ' . $r['what']
			. ' (agent_live_proofs_live)');
	}
}

section('The plane offered the test build nothing of its own');
$node->load();
check((new IncidentSourceAgentUpdateRefused())->evaluate($node) === null && (new IncidentSourceAgentBundleRefused())->evaluate($node) === null,
	'it refused no update and no bundle, so neither incident would open (update ' . ((string)$node->get('mgn_agent_update_state') ?: 'none')
	. ', bundle ' . ((string)$node->get('mgn_agent_bundle_state') ?: 'none') . ')');

} catch (Exception $e) {
	// The run stopped: said as a failure naming why. The teardown deferred
	// above removes whatever it had made.
	check(false, 'the run went to its end', $e->getMessage());
}

harness_finish();

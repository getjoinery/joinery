<?php
/** @joinery-test
 * name: agent_live_proofs_site_live
 * tier: live
 * env: dev-only
 * needs: [test-cloud, agent-source]
 * timeout: 1800
 * covers: [plugins/server_manager/includes/AgentLiveProofRun.php, public_html/utils/upgrade.php]
 */
/**
 * The agent words a site runs, once on a real site before release
 * (specs/fleet_security_patching.md WP7; Q9, the owner's yes of 2026-10-22):
 * apply_update, the word that upgrades every site, and upgrade_preflight, the
 * readiness check a staged rollout asks first.
 *
 * Runs when either is changed and unproven (AgentLiveProofs), or named in
 * LIVE_PROOF_WORDS. Otherwise no server is made.
 *
 * The run (AgentLiveProofRun): the agent built from the source being prepared
 * with the run's key and the real release keys, and a candidate core archive
 * built from this tree as a publish would ship it, at the next version,
 * signed with the run's key; a throwaway server with the newest published
 * release installed as the provisioning flow installs a site; the run's agent
 * in place of the release's, joined here as a test build; then
 * upgrade_preflight, apply_update onto the candidate (served from the server
 * itself, trusted by that site alone), and upgrade_preflight on the upgraded
 * site, each judged as a staged rollout judges it. Each word that passes is
 * recorded with the build's agent version. The node, the server, its DNS and
 * the key are removed whatever happened.
 *
 * Run: php tests/run.php live --only=plugins/server_manager/tests/agent_live_proofs_site_live_test.php
 *      LIVE_PROOF_WORDS=apply_update php plugins/server_manager/tests/agent_live_proofs_site_live_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$say = function ($m) { echo '    ' . $m . "\n"; };
$src = AgentDistPublisher::sourcePath();

section('The site words to prove');

$named = array_values(array_filter(array_map('trim', explode(',', (string)getenv('LIVE_PROOF_WORDS'))), 'strlen'));
if ($named) {
	$words = $named;
	$why = 'named in LIVE_PROOF_WORDS';
} else {
	$manifest = AgentDistPublisher::readManifest(PathHelper::getIncludePath('agent_dist'));
	$since = is_array($manifest) ? ($manifest['source_commit'] ?? null) : null;
	$changed = AgentLiveProofs::changed($src, is_string($since) ? $since : null, dirname(PathHelper::getRootDir()));
	$unproven = AgentLiveProofs::unproven($changed, (string)(AgentDistPublisher::readCommittedVersion($src) ?? ''), AgentLiveProofs::proofs());
	$words = array_values(array_filter(array_keys($unproven), function ($w) { return isset(AgentLiveProofRun::SITE_RECIPES[$w]); }));
	$why = 'changed since the last release and not yet proven';
}
echo '    ' . ($words ? implode(', ', $words) : 'none') . " ({$why})\n";
$not_site = array_values(array_diff($words, array_keys(AgentLiveProofRun::SITE_RECIPES)));
check($not_site === array(), 'every word named is a site word', implode(', ', $not_site));
if (!$words) {
	check(true, 'no site word to prove: no server made');
	harness_finish();
}

$published = AgentLiveProofRun::newest_published();
check($published !== null, 'this plane has a published release to start from', (string)$published);
if ($published === null) {
	harness_finish();
}
$candidate = AgentLiveProofRun::next_version($published);

$work = harness_scratch_dir('live-proof-site');
$hex = bin2hex(random_bytes(3));
$label = AgentLiveProofRun::LABEL_PREFIX . $hex;
$site = 'wp7proof' . $hex;
$server = null;
$node = null;
if (($dead = AgentLiveProofRun::forget_dead_runs()) > 0) {
	echo "    removed {$dead} root password file(s) of earlier runs killed before their teardown\n";
}
// LIVE_PROOF_KEEP_ON_FAILURE=1 keeps a failed run's server and node to look at
// over SSH (its password file is named); the nightly cleanup takes both.
harness_defer(function () use (&$node, &$server, $work) {
	if (getenv('LIVE_PROOF_KEEP_ON_FAILURE') === '1' && !empty($GLOBALS['__harness']['failed']) && $server) {
		echo '    KEPT for a look: ssh root@' . $server['ipv4'] . ' (sshpass -f ' . $server['password_file'] . '), node #'
			. ($node ? $node->key : '?') . "; the nightly test cloud cleanup removes both\n";
		AgentLiveProofRun::teardown(null, null, $work);
		return;
	}
	$left = AgentLiveProofRun::teardown($node, $server, $work);
	echo $left ? '    TEARDOWN LEFT: ' . implode('; ', $left) . "\n" : "    torn down: node, join requests, server, its DNS, and the run's key\n";
});

try {

section('Build: the agent and a candidate release, with a key made for the run');
$build = AgentLiveProofRun::build($work, $say, true);
$m = json_decode((string)file_get_contents($build['dist'] . '/manifest.json'), true);
check(in_array($m['signing_public_key'] ?? '', $m['baked_keys']['release_keys'] ?? array(), true) && count($m['baked_keys']['release_keys'] ?? array()) >= 2
		&& ($m['baked_keys']['statement_keys'] ?? null) === array(),
	'agent ' . $build['version'] . ' trusts the run\'s key and the release keys, and holds no statement key', json_encode($m['baked_keys'] ?? null));
$archive = AgentLiveProofRun::build_candidate($build, $candidate, $say);
check(is_file($archive), 'candidate ' . $candidate . ' built from ' . substr((string)ReleaseCommit::head(dirname(PathHelper::getRootDir())), 0, 12) . ' and this tree');

section('A throwaway server with the newest published release');
$server = AgentLiveProofRun::make_server($label, AgentLiveProofRun::SITE_SERVER_TYPE);
$server['site'] = $site;
check(true, 'server ' . $label . ' (' . $server['id'] . ') at ' . $server['ipv4']);
$fingerprint = AgentLiveProofRun::install_site($server, $site, $published, $say);
check(preg_match('/^[0-9a-f]{16}$/', $fingerprint) === 1, $published . ' installed as ' . $site . '; its agent asked to join, fingerprint ' . $fingerprint);
AgentLiveProofRun::swap_agent($server, $build, $say);
$node = AgentLiveProofRun::adopt($fingerprint, $server);
check((bool)$node->get('mgn_agent_test_build'), 'adopted as node #' . $node->key . ', from the server\'s own address as ' . $site . ', marked a test build');
AgentLiveProofRun::await_agent($node, $build['version']);
AgentLiveProofRun::await_site($node);
check(true, 'its agent reports itself at ' . $build['version'] . ' and names its site');

section('upgrade_preflight, apply_update onto the candidate, upgrade_preflight again');
$where = 'test server ' . $label . ' (Linode ' . $server['id'] . '), site ' . $site . ' installed at ' . $published
	. ', agent built for the run from ' . substr($build['commit'], 0, 12) . ($build['dirty'] ? ' and uncommitted changes' : '');
$before = AgentLiveProofRun::prove_site($node, 'upgrade_preflight', $candidate);
check($before['ok'], 'upgrade_preflight on ' . $published . ': ' . $before['what'] . ' (job ' . $before['job_id'] . ')');
AgentLiveProofRun::stage_candidate($server, $site, $archive, $candidate, $build['key_b64'], $say);
$apply = AgentLiveProofRun::prove_site($node, 'apply_update', $candidate);
check($apply['ok'], 'apply_update: ' . $apply['what'] . ($apply['job_id'] ? ' (job ' . $apply['job_id'] . ')' : ''));
$after = AgentLiveProofRun::prove_site($node, 'upgrade_preflight', $candidate);
check($after['ok'], 'upgrade_preflight on the upgraded site: ' . $after['what'] . ' (job ' . $after['job_id'] . ')');

section('site_log on the upgraded site');
$log = AgentLiveProofRun::prove_site($node, 'site_log', $candidate);
check($log['ok'], 'site_log of the access log: ' . $log['what'] . ($log['job_id'] ? ' (job ' . $log['job_id'] . ')' : ''));

if ($apply['ok']) {
	AgentLiveProofs::record('apply_update', $build['version'], $where, 'job ' . $apply['job_id'] . ': ' . $apply['what']
		. ' onto candidate ' . $candidate . ' built from this tree (agent_live_proofs_site_live)');
}
if ($before['ok'] && $after['ok']) {
	AgentLiveProofs::record('upgrade_preflight', $build['version'], $where, 'jobs ' . $before['job_id'] . ' and ' . $after['job_id']
		. ': ready before and after the upgrade (agent_live_proofs_site_live)');
}
if ($log['ok']) {
	AgentLiveProofs::record('site_log', $build['version'], $where, 'job ' . $log['job_id'] . ': ' . $log['what']
		. ', the access log of the site upgraded to ' . $candidate . ' (agent_live_proofs_site_live)');
}

} catch (Exception $e) {
	// The run stopped: said as a failure naming why. The teardown deferred
	// above removes whatever it had made.
	check(false, 'the run went to its end', $e->getMessage());
}

harness_finish();

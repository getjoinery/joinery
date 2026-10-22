#!/usr/bin/env php
<?php
/**
 * Record that an agent word ran once on a real machine (specs/fleet_security_patching.md
 * WP7): a row of plugins/server_manager/agent_live_proofs.json, which the publish page
 * reads to say which changed words a release would ship without such a run.
 *
 * Usage:
 *   php record_live_proof.php list                 the words this release changes, proven or not
 *   php record_live_proof.php WORD --where="test server and job" --what="what ran and what it did"
 *                             [--agent-version=X.Y.Z]
 *
 * The row records the word's script as it is now (its sha256) and the agent version it
 * ran on: the agent source's committed version unless --agent-version names the build
 * the run used. Run it after the live run, on the files that ran.
 *
 * @version 1.1 - the row is written by AgentLiveProofs::record(), which the live test also uses
 * @version 1.0
 */

$bootstrap_path = __DIR__ . '/../../public_html/includes/PathHelper.php';
if (!file_exists($bootstrap_path)) {
	fwrite(STDERR, "ERROR: Cannot find PathHelper.php at: $bootstrap_path\n");
	exit(2);
}
require_once($bootstrap_path);

$args = array_slice($argv, 1);
$word = '';
$opts = array();
foreach ($args as $a) {
	if (preg_match('/^--([a-z-]+)=(.*)$/s', $a, $m)) { $opts[$m[1]] = $m[2]; }
	elseif ($word === '') { $word = $a; }
}
$src = AgentDistPublisher::sourcePath();
$site_root = dirname(PathHelper::getRootDir());

if ($word === 'list' || $word === '') {
	$manifest = AgentDistPublisher::readManifest(PathHelper::getIncludePath('agent_dist'));
	$since = is_array($manifest) ? ($manifest['source_commit'] ?? null) : null;
	$version = (string)(AgentDistPublisher::readCommittedVersion($src) ?? '');
	$changed = AgentLiveProofs::changed($src, is_string($since) ? $since : null, $site_root);
	$unproven = AgentLiveProofs::unproven($changed, $version, AgentLiveProofs::proofs());
	if (!$changed) { echo "No agent word changes since the last release.\n"; exit(0); }
	foreach ($changed as $w => $c) {
		echo str_pad($w, 32) . (isset($unproven[$w]) ? 'NOT PROVEN: ' . $unproven[$w] : 'proven') . "\n";
	}
	exit(0);
}

if (trim($opts['where'] ?? '') === '' || trim($opts['what'] ?? '') === '') {
	fwrite(STDERR, "ERROR: say --where (the test server, and the job if the agent ran it) and --what (what ran, and what it did)\n");
	exit(2);
}
try {
	$row = AgentLiveProofs::record($word, $opts['agent-version'] ?? (string)(AgentDistPublisher::readCommittedVersion($src) ?? ''),
		$opts['where'], $opts['what']);
} catch (Exception $e) {
	fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
	exit(2);
}
echo "Recorded: {$word} on agent {$row['agent_version']}" . ($row['script'] !== '' ? ", {$row['script']} at " . substr($row['script_sha256'], 0, 12) : '') . "\n";

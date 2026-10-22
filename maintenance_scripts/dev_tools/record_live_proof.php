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

$words = AgentLiveProofs::words($src);
if (!isset($words[$word])) {
	fwrite(STDERR, "ERROR: the agent source at {$src} registers no word '{$word}'\n");
	exit(2);
}
if (trim($opts['where'] ?? '') === '' || trim($opts['what'] ?? '') === '') {
	fwrite(STDERR, "ERROR: say --where (the test server, and the job if the agent ran it) and --what (what ran, and what it did)\n");
	exit(2);
}
$script = $words[$word]['script'];
$row = array(
	'word'          => $word,
	'agent_version' => $opts['agent-version'] ?? (string)(AgentDistPublisher::readCommittedVersion($src) ?? ''),
	'script'        => $script,
	'script_sha256' => $script !== '' && is_file($site_root . '/' . $script) ? hash_file('sha256', $site_root . '/' . $script) : '',
	'where'         => trim($opts['where']),
	'what'          => trim($opts['what']),
	'date'          => gmdate('Y-m-d'),
);
$path = AgentLiveProofs::record_path();
$d = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
if (!is_array($d)) {
	$d = array('_about' => 'Agent words run once on a real machine before release (specs/fleet_security_patching.md WP7). '
		. 'Added with maintenance_scripts/dev_tools/record_live_proof.php; the publish page warns about a changed word with no row here.', 'proofs' => array());
}
$kept = array();
foreach ((array)($d['proofs'] ?? array()) as $p) {
	// One row per word, agent version and script: a later run replaces it.
	if (($p['word'] ?? '') === $row['word'] && ($p['agent_version'] ?? '') === $row['agent_version'] && ($p['script_sha256'] ?? '') === $row['script_sha256']) { continue; }
	$kept[] = $p;
}
$kept[] = $row;
$d['proofs'] = $kept;
file_put_contents($path, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Recorded: {$word} on agent {$row['agent_version']}" . ($script !== '' ? ", {$script} at " . substr($row['script_sha256'], 0, 12) : '') . "\n";

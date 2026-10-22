<?php
/** @joinery-test
 * name: agent_live_proofs
 * tier: safe
 * env: any
 * needs: []
 * covers: [plugins/server_manager/includes/AgentLiveProofs.php, maintenance_scripts/dev_tools/record_live_proof.php]
 */
/**
 * Which agent words a release changes, and whether each has run on a real
 * machine (specs/fleet_security_patching.md WP7). A throwaway agent source
 * (a git repository of two commits) and a site root with a signed manifest
 * stand in for the real ones.
 *
 * Run: php plugins/server_manager/tests/agent_live_proofs_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$T = sys_get_temp_dir() . '/alp_' . bin2hex(random_bytes(4));
$src = $T . '/agent';
$site = $T . '/site';
mkdir($src . '/primitives', 0755, true);
mkdir($site . '/maintenance_scripts/sysadmin_tools', 0755, true);
$git = function (...$args) use ($src) {
	$cmd = 'git -C ' . escapeshellarg($src) . ' -c user.email=t@example.com -c user.name=t';
	foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
	exec($cmd . ' 2>&1', $out, $rc);
	return $rc === 0 ? implode("\n", $out) : null;
};
$word_go = function ($name, $script_const, $script, $extra = '') {
	return "package primitives\n\nfunc init() {\n\tRegister(Primitive{\n\t\tName:        \"{$name}\",\n\t\tClass:       ClassOperate,\n"
		. ($script_const !== '' ? "\t\tScript: &ScriptSpec{\n\t\t\tScriptPath:  {$script_const},\n\t\t},\n" : "\t\tRun: run_{$name},\n")
		. "\t})\n}\n" . ($script_const !== '' ? "\nconst {$script_const} = \"{$script}\"\n" : '') . $extra;
};
file_put_contents($src . '/primitives/operate_alpha.go', $word_go('alpha', 'alphaScript', 'maintenance_scripts/sysadmin_tools/alpha.sh'));
file_put_contents($src . '/primitives/observe_beta.go', $word_go('beta', '', ''));
file_put_contents($src . '/primitives/operate_beta_host.go', $word_go('beta_host', '', ''));
file_put_contents($src . '/primitives/operate_gamma.go', $word_go('gamma', 'gammaScript', 'maintenance_scripts/sysadmin_tools/gamma.sh'));
file_put_contents($src . '/primitives/operate_alpha_test.go', "package primitives\n// Register(Primitive{ Name: \"not_a_word\" })\n");
file_put_contents($src . '/main.go', "package main\nvar version = \"1.88.0\"\n");
$git('init', '-q');
$git('add', '-A');
$git('commit', '-q', '-m', 'one');
$first = trim((string)$git('rev-parse', 'HEAD'));

// The release that was published signed alpha.sh and gamma.sh as they were.
file_put_contents($site . '/maintenance_scripts/sysadmin_tools/alpha.sh', "#!/bin/bash\necho one\n");
file_put_contents($site . '/maintenance_scripts/sysadmin_tools/gamma.sh', "#!/bin/bash\necho gamma\n");
file_put_contents($site . '/RELEASE_MANIFEST',
	hash_file('sha256', $site . '/maintenance_scripts/sysadmin_tools/alpha.sh') . '  maintenance_scripts/sysadmin_tools/alpha.sh' . "\n"
	. hash_file('sha256', $site . '/maintenance_scripts/sysadmin_tools/gamma.sh') . '  maintenance_scripts/sysadmin_tools/gamma.sh' . "\n");

// Since then: alpha's script changed; beta gained a helper file of its own;
// beta_host's own file changed (not beta's); gamma is untouched.
file_put_contents($site . '/maintenance_scripts/sysadmin_tools/alpha.sh', "#!/bin/bash\necho two\n");
file_put_contents($src . '/primitives/observe_beta_php.go', "package primitives\n// a helper of beta's\n");
file_put_contents($src . '/primitives/operate_beta_host.go', $word_go('beta_host', '', '', "// changed\n"));
$git('add', '-A');
$git('commit', '-q', '-m', 'two');

section('The words, and which a release changes');

$words = AgentLiveProofs::words($src);
check(array_keys($words) === array('alpha', 'beta', 'beta_host', 'gamma') && $words['alpha']['script'] === 'maintenance_scripts/sysadmin_tools/alpha.sh'
		&& $words['beta']['script'] === '' && $words['beta']['file'] === 'primitives/observe_beta.go',
	'each Register block\'s name, its file, and its script through the const; a test file registers nothing', json_encode($words));

$changed = AgentLiveProofs::changed($src, $first, $site);
check(array_keys($changed) === array('alpha', 'beta', 'beta_host'), 'alpha (its script), beta (a helper file of its own) and beta_host (its own file); gamma untouched', json_encode($changed));
check($changed['alpha']['go'] === false && $changed['alpha']['script'] === true && $changed['alpha']['script_sha256'] === hash_file('sha256', $site . '/maintenance_scripts/sysadmin_tools/alpha.sh'),
	'alpha changed by its script alone, with the script\'s hash now');
check($changed['beta']['go'] === true && $changed['beta_host']['go'] === true,
	'a file extending a word\'s file name is that word\'s; one that is another word\'s own file is only that word\'s');
check(AgentLiveProofs::changed($src, null, $site) === array('alpha' => $changed['alpha']), 'with no bundled agent to compare, only scripts count');

section('Proven, or not');

$un = AgentLiveProofs::unproven($changed, '1.88.0', array());
check(array_keys($un) === array('alpha', 'beta', 'beta_host') && strpos($un['alpha'], 'its script maintenance_scripts/sysadmin_tools/alpha.sh changed') === 0
		&& strpos($un['beta'], 'its agent code changed (agent 1.88.0)') === 0,
	'with no proofs, every changed word is listed, saying what changed', json_encode($un));
$proofs = array(
	array('word' => 'alpha', 'agent_version' => '1.87.0', 'script_sha256' => $changed['alpha']['script_sha256']),
	array('word' => 'beta', 'agent_version' => '1.87.0', 'script_sha256' => ''),
	array('word' => 'beta_host', 'agent_version' => '1.88.0', 'script_sha256' => ''),
);
$un = AgentLiveProofs::unproven($changed, '1.88.0', $proofs);
check(array_keys($un) === array('beta'), 'a script change is covered by a run of that script on any agent; a code change only by a run on this agent', json_encode($un));
$proofs[0]['script_sha256'] = str_repeat('0', 64);
check(isset(AgentLiveProofs::unproven($changed, '1.88.0', $proofs)['alpha']), 'a run of another version of the script covers nothing');

section('The record');

$rec = $T . '/proofs.json';
file_put_contents($rec, json_encode(array('_about' => 'x', 'proofs' => array(array('word' => 'alpha', 'agent_version' => '1.88.0'), 'junk'))));
check(AgentLiveProofs::proofs($rec) === array(array('word' => 'alpha', 'agent_version' => '1.88.0')), 'the record\'s rows, a row that is not one dropped');
check(AgentLiveProofs::proofs($T . '/absent.json') === array(), 'no record: no proofs');
$real = json_decode((string)file_get_contents(AgentLiveProofs::record_path()), true);
check(is_array($real) && is_array($real['proofs'] ?? null), 'the plugin\'s own record reads as one');

// record(): the one writer, for record_live_proof.php and the live test.
if (is_dir(AgentDistPublisher::sourcePath() . '/primitives')) {
	$out = $T . '/written.json';
	$row = AgentLiveProofs::record('host_report', '9.9.1', 'a test server', 'job 1', $out);
	AgentLiveProofs::record('host_report', '9.9.1', 'another test server', 'job 2', $out);
	AgentLiveProofs::record('host_report', '9.9.2', 'a test server', 'job 3', $out);
	$rows = AgentLiveProofs::proofs($out);
	check($row['word'] === 'host_report' && $row['agent_version'] === '9.9.1' && $row['date'] === gmdate('Y-m-d') && $row['script'] !== ''
			&& preg_match('/^[0-9a-f]{64}$/', $row['script_sha256']) === 1,
		'a row names the word, the agent version, its script and the script\'s hash now', json_encode($row));
	check(count($rows) === 2 && $rows[0]['agent_version'] === '9.9.1' && $rows[0]['what'] === 'job 2' && $rows[1]['agent_version'] === '9.9.2',
		'one row per word, version and script: a later run of the same replaces it, another version is its own', json_encode($rows));
	$threw = '';
	try { AgentLiveProofs::record('no_such_word', '9.9.1', 'x', 'y', $out); } catch (Exception $e) { $threw = $e->getMessage(); }
	check(strpos($threw, "registers no word 'no_such_word'") !== false, 'a word the agent source does not register is refused', $threw);
}

section('The site run\'s candidate stages the core as publish does');

// AgentLiveProofRun::build_candidate() stages public_html with publish's own
// exclusions; were the two to drift, the candidate would ship what a release
// does not (or leave out what it does), and the proof would be of another tree.
$pub_src = (string)file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/publish_upgrade.php'));
$rs_at = strpos($pub_src, "'rsync -av --exclude=.git");
$rs_line = $rs_at !== false ? substr($pub_src, $rs_at, strpos($pub_src, "\n", $rs_at) - $rs_at) : '';
preg_match_all('/--exclude=("?)([^ "%]+)\1/', $rs_line, $em);
$publish_excludes = $em[2] ?? array();
$candidate_excludes = AgentLiveProofRun::CORE_EXCLUDES;
sort($publish_excludes);
sort($candidate_excludes);
check($rs_line !== '' && $publish_excludes === $candidate_excludes,
	'the candidate leaves out of public_html exactly what publish_upgrade.php does', implode(' ', $publish_excludes) . ' | ' . implode(' ', $candidate_excludes));
check(AgentLiveProofRun::next_version('0.8.522') === '0.8.523' && AgentLiveProofRun::next_version('1.9.0') === '1.9.1',
	'the candidate is the version after the newest published');

exec('rm -rf ' . escapeshellarg($T));
harness_finish();

<?php
/** @joinery-test
 * name: copy_probes_builder
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * copy_probes_builder (specs/site_copy_final_test.md, the rig): the job that
 * asks a machine's agent to prove a site's walls by acting as it.
 *
 *  - a site, a neighbour and the old server's address go out as the word's
 *    params, the address in its canonical spelling;
 *  - a name that is not a site's, a site as its own neighbour, and an address
 *    that is not public are refused here, before any job exists;
 *  - an agent older than the word, or of no known version, is refused with
 *    the version it needs.
 *  - a container site's own node is refused, pointing at its host: inside
 *    the container the probes see no engine.
 *
 * @version 1.1 - a container site's own node is refused, pointing at its host
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$refused = function (callable $fn): string {
	try {
		$fn();
		return '';
	} catch (Exception $e) {
		return $e->getMessage();
	}
};

section('The params');
$b = JobCommandBuilder::build_copy_probes_primitive('src1');
check($b === array('primitive' => 'copy_probes', 'params' => array('site' => 'src1')), 'a site alone', json_encode($b));
$b = JobCommandBuilder::build_copy_probes_primitive('src1', 'other_site', '2606:4700:0:0::1');
check($b['params'] === array('site' => 'src1', 'neighbour' => 'other_site', 'old_address' => '2606:4700::1'),
	'a neighbour and the old address, which is passed canonical', json_encode($b));
$b = JobCommandBuilder::build_copy_probes_primitive('src1', '', '198.51.100.7');
check($b['params'] === array('site' => 'src1', 'old_address' => '198.51.100.7'), 'the old address with no neighbour', json_encode($b));

section('Refused before any job exists');
foreach (array('', '-x', '--loosen', 'Src1', '../etc', 'a b', 'a;b') as $bad) {
	check($refused(function () use ($bad) { JobCommandBuilder::build_copy_probes_primitive($bad); }) !== '', "site '{$bad}' is refused");
}
check($refused(function () { JobCommandBuilder::build_copy_probes_primitive('src1', 'src1'); }) !== '', 'a site is not its own neighbour');
check($refused(function () { JobCommandBuilder::build_copy_probes_primitive('src1', '-y'); }) !== '', 'a neighbour that reads as a flag is refused');
foreach (array('10.0.0.5', '127.0.0.1', '100.90.219.38', '203.0.113.0/24', '1.2.3.4;x') as $bad) {
	check($refused(function () use ($bad) { JobCommandBuilder::build_copy_probes_primitive('src1', '', $bad); }) !== '',
		"old address '{$bad}' is refused");
}

section('Only an agent that knows the word');
$node = function (string $version): ManagedNode {
	$n = new ManagedNode(NULL);
	$n->set('mgn_slug', 'harness-probes');
	$n->set('mgn_agent_version', $version);
	return $n;
};
$msg = $refused(function () use ($node) { JobCommandBuilder::build_copy_probes($node('1.84.1'), 'src1'); });
check(strpos($msg, '1.84.1') !== false && strpos($msg, JobCommandBuilder::COPY_PROBES_MIN_AGENT_VERSION) !== false,
	'an older agent is refused, naming its version and the one needed', $msg);
check($refused(function () use ($node) { JobCommandBuilder::build_copy_probes($node(''), 'src1'); }) !== '', 'an agent of no known version is refused');
$b = JobCommandBuilder::build_copy_probes($node(JobCommandBuilder::COPY_PROBES_MIN_AGENT_VERSION), 'src1');
check(($b['primitive'] ?? '') === 'copy_probes', 'an agent that knows it gets the job', json_encode($b));

section('A container site is probed from its host');
// Inside the container the probes see no engine, and read its database as
// listening nowhere (C10, 2026-10-23).
$in = $node(JobCommandBuilder::COPY_PROBES_MIN_AGENT_VERSION);
$in->set('mgn_name', 'HarnessTest probes');
$in->set('mgn_container_name', 'src1');
$msg = $refused(function () use ($in) { JobCommandBuilder::build_copy_probes($in, 'src1'); });
check(strpos($msg, 'runs in a container') !== false && strpos($msg, 'from its host') !== false,
	'a container site\'s own node is refused, pointing at its host', $msg);

harness_finish();

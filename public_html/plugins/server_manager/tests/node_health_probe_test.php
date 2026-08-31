<?php
/** @joinery-test
 * name: node_health_probe
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * NodeHealthProbe — reading a machine that has no agent and no site.
 *
 * The DNS servers and the mail relay will never carry an agent. Until check_status
 * gained a probe transport the only way to learn anything about them was to SSH in
 * and run df, free and uptime, which stopped being possible when SSH left the agent.
 *
 * What replaced it reads a health document the machine publishes about itself. Two
 * properties carry that design, and both are asserted here.
 *
 * The first is the key contract. Two Go implementations produce these figures —
 * the agent's check_status primitive and the ScrollDaddy resolver's
 * internal/machine/facts.go — and this plane folds one key set whichever answered.
 * A name that drifts on either side does not show up as a wrong number on the
 * dashboard; it shows up as a fact that quietly stops being reported. So the names
 * are pinned here as well as in the Go tests.
 *
 * The second is that a reading which could not be taken must be ABSENT, never zero.
 * A node reporting 0% disk used because statfs failed reads as the healthiest box
 * in the fleet, and that is the failure this whole path exists to catch.
 *
 * Nothing here reaches the network. The probe's parsing is pure and is fed
 * documents directly.
 *
 * Run: php plugins/server_manager/tests/node_health_probe_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeHealthProbe.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

// ---------------------------------------------------------------------------
section('The machine key contract');

// Exactly what the two Go collectors emit. Written out rather than read from the
// constant, so that editing the constant cannot quietly edit the expectation.
$machine_keys = array(
	'disk_usage_percent', 'disk_total', 'disk_used', 'disk_available',
	'memory_total_mb', 'memory_free_mb', 'memory_used_mb',
);
check(NodeHealthProbe::MACHINE_KEYS === $machine_keys,
	'the machine key set matches what the agent primitive and the resolver publish',
	'got: ' . implode(', ', NodeHealthProbe::MACHINE_KEYS));

// A real answer from a ScrollDaddy DNS box, machine facts included.
$live = json_encode(array(
	'status'             => 'ok',
	'db_connected'       => true,
	'uptime_seconds'     => 2958346,
	'last_reload'        => '2026-08-31T12:06:35Z',
	'disk_usage_percent' => 20,
	'disk_total'         => '24.0G',
	'disk_used'          => '4.7G',
	'disk_available'     => '19.0G',
	'memory_total_mb'    => 961,
	'memory_free_mb'     => 126,
	'memory_used_mb'     => 835,
));
$facts = NodeHealthProbe::facts_from_body($live);

foreach ($machine_keys as $k) {
	check(array_key_exists($k, $facts), "a health document contributes {$k}");
}
check($facts['disk_used'] === '4.7G', 'and the value arrives unaltered', var_export($facts['disk_used'] ?? null, true));
check($facts['db_connected'] === true, 'a service fact arrives as its own type, not stringified');

// The status blob's uptime is a machine figure everywhere else in the fleet. A
// daemon restart must not read as a rebooted box.
check(!array_key_exists('uptime', $facts), 'a service uptime is not folded as machine uptime');
check(($facts['service_uptime_seconds'] ?? null) === 2958346,
	'it is kept under its own name instead');

// ---------------------------------------------------------------------------
section('A reading that could not be taken is absent, never zero');

$partial = NodeHealthProbe::facts_from_body(json_encode(array('status' => 'ok')));
check(($partial['status'] ?? null) === 'ok', 'a document with only a service fact still contributes it');
foreach ($machine_keys as $k) {
	check(!array_key_exists($k, $partial), "and invents no {$k}");
}

check(NodeHealthProbe::facts_from_body('') === array(), 'an empty body contributes nothing');
check(NodeHealthProbe::facts_from_body('<html>not json</html>') === array(),
	'a web page contributes nothing');
check(NodeHealthProbe::facts_from_body('[1,2,3]') === array(),
	'a JSON array is not a health document');
check(NodeHealthProbe::facts_from_body(str_repeat('x', NodeHealthProbe::MAX_BODY_BYTES + 1)) === array(),
	'a body past the cap is not parsed at all');

// An endpoint is free to publish anything for its own operators. A key this
// plane cannot date or display would age in the blob as furniture.
$noise = NodeHealthProbe::facts_from_body(json_encode(array(
	'status' => 'ok', 'goroutines' => 41, 'nested' => array('a' => 1),
)));
check(!array_key_exists('goroutines', $noise), 'an unrecognised key is ignored');
check(!array_key_exists('nested', $noise), 'and so is a structured one');

// ---------------------------------------------------------------------------
section('check_status has no SSH implementation anywhere');

check(!method_exists('JobCommandBuilder', 'build_check_status_ssh'),
	'build_check_status_ssh no longer exists');
$transports = JobCommandBuilder::transports_for('check_status');
check(in_array('probe', $transports, true), 'check_status offers the probe transport');
check(!in_array('ssh', $transports, true), 'check_status offers no SSH transport',
	implode(', ', $transports));
check(JobCommandBuilder::build_check_status_probe(null) === array('probe' => 'check_status'),
	'the probe builder returns an envelope, not steps');

// ---------------------------------------------------------------------------
section('What SSH still owns');

// The inventory this migration is working through, derived from the code rather
// than counted by hand in a spec that goes stale.
//
// An operation is SSH-only when its single builder emits ssh steps and it has no
// primitive, api or probe variant to route to instead. The list may SHRINK as
// operations cross. If it GROWS this fails and names the newcomer, because a new
// SSH-only operation is a step backwards taken by accident.
//
// Five of these are relay builders that die at the cutover rather than crossing
// (agent_machine_posture_and_relay_converge.md). The four that have to be
// answered are install_node, enable_agent, decommission_node and provision_ssl.
$expected_ssh_only = array(
	'decommission_node',
	'enable_agent',
	'install_node',
	'provision_relay',
	'provision_ssl',
	'rebuild_relay',
	'relay_add_tenant',
	'relay_remove_tenant',
	'relay_set_domains',
);

$source   = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
$suffixes = array('_ssh', '_api', '_primitive', '_probe');
preg_match_all('/function build_([a-z0-9_]+)\(/', $source, $m);

$variants = array();
$plain    = array();
foreach (array_unique($m[1]) as $name) {
	$is_variant = false;
	foreach ($suffixes as $suffix) {
		if (substr($name, -strlen($suffix)) === $suffix) {
			$variants[substr($name, 0, -strlen($suffix))][ltrim($suffix, '_')] = true;
			$is_variant = true;
		}
	}
	if (!$is_variant) { $plain[] = $name; }
}

$ssh_only = array();
foreach ($plain as $op) {
	if (!empty($variants[$op]) && array_diff(array_keys($variants[$op]), array('ssh'))) {
		continue; // it has somewhere else to go
	}
	if (preg_match('/function build_' . preg_quote($op, '/') . '\(.*?\n\t\}/s', $source, $body)
			&& strpos($body[0], "'type' => 'ssh'") !== false) {
		$ssh_only[] = $op;
	}
}
sort($ssh_only);
sort($expected_ssh_only);

check(!in_array('check_status', $ssh_only, true),
	'check_status is no longer among the operations SSH owns');
check($ssh_only === $expected_ssh_only,
	'the SSH-only inventory is exactly ' . count($expected_ssh_only) . ' operations',
	"expected:\n  " . implode("\n  ", $expected_ssh_only)
	. "\ngot:\n  " . implode("\n  ", $ssh_only));

harness_finish();

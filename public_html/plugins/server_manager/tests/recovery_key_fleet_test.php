<?php
/** @joinery-test
 * name: recovery_key_fleet
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Which nodes can be backed up at all.
 *
 * Every backup of a node seals to the recovery key that node holds and has
 * proven, read there — the copies this control plane takes as much as the copies
 * the node takes for itself. Nothing supplies a key from here: sealing to a
 * public key always appears to succeed, so a key sent over the wire would let
 * whoever sent it decide who can read a node's database and mail, with nothing
 * anywhere looking wrong until a restore was attempted.
 *
 * The consequence this class reports, and this file pins: a node with no proven
 * key of its own is an UN-BACKED-UP node, not a node exercising a preference.
 *
 * What is pinned here:
 *
 *   - proof is the whole test — an unproven key is indistinguishable from a
 *     mistyped one until the moment the answer can no longer be acted on;
 *   - whose key it is is not compared against this control plane's: a node
 *     holding a key this machine has never seen is the intended arrangement,
 *     not a discrepancy to correct;
 *   - a node that hosts no Joinery site (a DNS box, a relay) is not applicable
 *     rather than outstanding, so it never shows up as a gap someone should
 *     chase;
 *   - a node nothing has looked at yet is "unknown", not "missing" — claiming
 *     absence from an absence of evidence would misreport a node that may
 *     already hold a key — and is still not dispatched to;
 *   - every blocked state names where it is fixed, which is never here;
 *   - and that no code path exists to write a node's key at all.
 *
 * Nothing here is saved: the nodes are in-memory model objects, so the whole
 * table can be exercised without standing up sites in each state.
 *
 * Run: php plugins/server_manager/tests/recovery_key_fleet_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/RecoveryKeyFleet.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

/**
 * An unsaved node fixture. $recovery is what the last status check recorded:
 * null for "never looked", otherwise a [state, fingerprint] pair.
 */
function rkf_node(array $fields = array(), $recovery = null) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'Fixture');
	$node->set('mgn_slug', 'fixture');
	$node->set('mgn_host', '192.0.2.10');
	$node->set('mgn_web_root', '/var/www/html/fixture/public_html');
	foreach ($fields as $k => $v) {
		$node->set($k, $v);
	}
	if ($recovery !== null) {
		$node->set('mgn_last_status_data', json_encode(['backup_recovery_state' => $recovery[0]]));
		$node->set('mgn_backup_recovery_fpr', $recovery[1]);
	}
	return $node;
}

$theirs = str_repeat('b2', 32);   // a key this control plane has never seen
$ours   = str_repeat('c3', 32);   // one it happens to recognise

// ── Not applicable ──────────────────────────────────────────────────────────
section('Nodes that host no Joinery site are not a gap');

$state = RecoveryKeyFleet::node_state(rkf_node(['mgn_web_root' => '']));
check($state['state'] === 'n/a', 'a node with no web root is not applicable', $state['state']);

$state = RecoveryKeyFleet::node_state(rkf_node(['mgn_skip_joinery_checks' => true]));
check($state['state'] === 'n/a', 'a node with Joinery checks switched off is not applicable', $state['state']);

// ── Nothing known yet ───────────────────────────────────────────────────────
section('A node nothing has looked at is unknown, not missing');

$state = RecoveryKeyFleet::node_state(rkf_node());
check($state['state'] === 'unknown', 'no status check yet reads as unknown', $state['state']);
check(!RecoveryKeyFleet::has_own_key($state),
	'and it is not dispatched to on the strength of a guess');
check(strpos(RecoveryKeyFleet::blocker_summary($state), 'status check') !== false,
	'the way out of unknown is naming the check that resolves it',
	RecoveryKeyFleet::blocker_summary($state));

// ── No key at all ───────────────────────────────────────────────────────────
section('A node with no key takes no backups, from anyone');

foreach (array('unconfigured', 'invalid') as $reported) {
	$state = RecoveryKeyFleet::node_state(rkf_node([], array($reported, '')));
	check($state['state'] === 'missing', "a node reporting '{$reported}' has no key", $state['state']);
	check(!RecoveryKeyFleet::has_own_key($state), "and '{$reported}' cannot be backed up");
	check(strpos($state['summary'], 'including the ones taken from here') !== false,
		"the '{$reported}' summary says the control plane's own copies stop too", $state['summary']);
}

$state = RecoveryKeyFleet::node_state(rkf_node([], array('unconfigured', '')));
check(strpos(RecoveryKeyFleet::blocker_summary($state), 'cannot supply one') !== false,
	'and the fix is named as the node\'s, explicitly not this control plane\'s',
	RecoveryKeyFleet::blocker_summary($state));

check(!method_exists('JobCommandBuilder', 'build_push_recovery_key'),
	'there is no way to build a job that writes a node\'s recovery key');

// ── Unproven ────────────────────────────────────────────────────────────────
section('An unproven key is not a usable one');

// The dangerous state, not merely the unfinished one. A mistyped public key
// seals exactly as happily as a real one and produces archives nobody can ever
// open; the possession ceremony is the only thing that tells them apart, and it
// has to happen on the node.
foreach (array($ours, $theirs) as $fpr) {
	$state = RecoveryKeyFleet::node_state(rkf_node([], array('unproven', $fpr)));
	check($state['state'] === 'unproven', 'an unproven key reads as unproven', $state['state']);
	check(!RecoveryKeyFleet::has_own_key($state), 'and the node still cannot be backed up');
	check(strpos(RecoveryKeyFleet::blocker_summary($state), 'challenge') !== false,
		'the fix named is the verification challenge, on the node',
		RecoveryKeyFleet::blocker_summary($state));
}

// ── Proven ──────────────────────────────────────────────────────────────────
section('A proven key is the whole test, whoever holds it');

// Whose key it is is deliberately not compared. A node holding a key this
// control plane has never seen is a node whose operator holds their own recovery
// key — which is the point of the arrangement, not a discrepancy.
foreach (array('the control plane recognises' => $ours, 'it has never seen' => $theirs) as $label => $fpr) {
	$state = RecoveryKeyFleet::node_state(rkf_node([], array('proven', $fpr)));
	check($state['state'] === 'proven', "a proven key {$label} reads as proven", $state['state']);
	check(RecoveryKeyFleet::has_own_key($state), "and a node with a key {$label} can be backed up");
	check($state['fingerprint'] === $fpr, 'the fingerprint reported is the node\'s own', $state['fingerprint']);
	check(RecoveryKeyFleet::blocker_summary($state) === '', 'and nothing is outstanding');
}

check(!method_exists('RecoveryKeyFleet', 'manager_fingerprint'),
	'this control plane\'s own key is not consulted at all — there is nothing to compare against');

harness_finish();

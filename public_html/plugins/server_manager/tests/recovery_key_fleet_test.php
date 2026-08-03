<?php
/** @joinery-test
 * name: recovery_key_fleet
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Which managed sites the control plane will push its backup recovery key to.
 *
 * The push exists because a site that runs its own scheduled backups reads its
 * OWN recovery key setting, so a site nobody has visited makes no encrypted
 * backups at all — silently. Filling those slots automatically is only safe
 * because of what it refuses to do, and this is where the refusals are pinned:
 *
 *   - a site holding a DIFFERENT key is never a push target, however that key
 *     got there, because archives on its shelf may open only with the private
 *     half of that one;
 *   - a node that hosts no Joinery site (a DNS box, a relay) is not applicable
 *     rather than outstanding, so it never shows up as a gap someone should
 *     chase;
 *   - a node nothing has looked at yet is "unknown", not "missing" — reporting
 *     absence from an absence of evidence would push at a site that may already
 *     be holding a key.
 *
 * Nothing here is saved: the nodes are in-memory model objects, so the whole
 * eligibility table can be exercised without standing up sites in each state.
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

$ours   = RecoveryKeyFleet::manager_fingerprint();
$theirs = str_repeat('b2', 32);

if ($ours === '') {
	// Without a proven key of its own the control plane has nothing to push, and
	// the whole table collapses to "offer nothing" — which is itself the right
	// answer, and the only one that can be checked here.
	harness_skip('this control plane has no proven recovery key of its own',
		'the eligibility table needs one to compare against');

	$node = rkf_node([], ['unconfigured', '']);
	check(!RecoveryKeyFleet::is_pushable(RecoveryKeyFleet::node_state($node)),
		'with no key of its own, the control plane offers no push anywhere');
	harness_finish();
}

// ── Not applicable ──────────────────────────────────────────────────────────
section('Nodes that host no Joinery site are not a gap');

$state = RecoveryKeyFleet::node_state(rkf_node(['mgn_web_root' => '']));
check($state['state'] === 'n/a', 'a node with no web root is not applicable', $state['state']);
check(!RecoveryKeyFleet::is_pushable($state), 'and is never offered a push');

$state = RecoveryKeyFleet::node_state(rkf_node(['mgn_skip_joinery_checks' => true]));
check($state['state'] === 'n/a', 'a node with Joinery checks switched off is not applicable', $state['state']);
check(!RecoveryKeyFleet::is_pushable($state), 'and is never offered a push');

// ── Nothing known yet ───────────────────────────────────────────────────────
section('A node nothing has looked at is unknown, not missing');

$state = RecoveryKeyFleet::node_state(rkf_node());
check($state['state'] === 'unknown', 'no status check yet reads as unknown', $state['state']);
check(RecoveryKeyFleet::is_pushable($state),
	'and is still offered the push — the node itself decides, and it never overwrites');

// ── Empty slots ─────────────────────────────────────────────────────────────
section('Empty slots are the push targets');

foreach (array('unconfigured', 'invalid') as $reported) {
	$state = RecoveryKeyFleet::node_state(rkf_node([], array($reported, '')));
	check($state['state'] === 'missing', "a node reporting '{$reported}' is missing a key", $state['state']);
	check(RecoveryKeyFleet::is_pushable($state), "and '{$reported}' is offered the push");
}

// ── Ours ────────────────────────────────────────────────────────────────────
section('A node already holding our key is left alone');

$state = RecoveryKeyFleet::node_state(rkf_node([], array('proven', $ours)));
check($state['state'] === 'has', 'our key, proven there: nothing outstanding', $state['state']);
check(!RecoveryKeyFleet::is_pushable($state), 'and no push is offered');

// Our key that never got proven on the node is not finished: the node's own
// backups still refuse to run, so the push has something left to do.
$state = RecoveryKeyFleet::node_state(rkf_node([], array('unproven', $ours)));
check($state['state'] === 'missing', 'our key that is unproven there is still outstanding', $state['state']);
check(RecoveryKeyFleet::is_pushable($state), 'and the push is offered so the proof can be completed');

// ── Somebody else's ─────────────────────────────────────────────────────────
section("A node holding somebody else's key is never a push target");

foreach (array('proven', 'unproven') as $reported) {
	$state = RecoveryKeyFleet::node_state(rkf_node([], array($reported, $theirs)));
	check($state['state'] === 'different',
		"a different key reported '{$reported}' is flagged as different", $state['state']);
	check(!RecoveryKeyFleet::is_pushable($state),
		"and '{$reported}' is never offered the push");
	check(strpos($state['summary'], 'Left alone') !== false,
		'and the operator is told it was left alone rather than fixed', $state['summary']);
}

harness_finish();

<?php
/** @joinery-test
 * name: node_install_states
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * ManagedNode::is_operational(): a node in any install state is one no
 * automation acts on (specs/site_copy.md WP5). The site copy adds three states
 * to the two installs already had - copy, switching, retired - and each of the
 * five is skipped by the fleet backups (and so their pruning), staged
 * rollouts, pruning itself, the uptime monitor and the status dot, while the
 * node stays in every list with its words.
 *
 * Run: php plugins/server_manager/tests/node_install_states_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

function nis_node(?string $state, bool $save = false) {
	$node = new ManagedNode(NULL);
	$suffix = bin2hex(random_bytes(3));
	$node->set('mgn_name', 'HarnessTest Node ' . $suffix);
	$node->set('mgn_slug', 'harnesstest-' . $suffix);
	$node->set('mgn_host', '192.0.2.10');
	$node->set('mgn_web_root', '/var/www/html/harnesstest/public_html');
	$node->set('mgn_site_url', 'https://harnesstest-' . $suffix . '.example.invalid');
	$node->set('mgn_uptime_enabled', true);
	$node->set('mgn_install_state', $state);
	if ($save) {
		$node->prepare();
		$node->save();
		harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	}
	return $node;
}

$not_working = array_keys(ManagedNode::INSTALL_STATES);

section('The list of states');
check($not_working === array('installing', 'install_failed', 'copy', 'switching', 'retired'),
	'the five install states, installs\' and the site copy\'s', implode(',', $not_working));
check(nis_node(null)->is_operational() && nis_node('')->is_operational(), 'a node with no install state is a working node');
foreach ($not_working as $state) {
	$node = nis_node($state);
	check(!$node->is_operational(), "$state is not a working node");
	check($node->install_state_label() === ManagedNode::INSTALL_STATES[$state], "$state has its words for the fleet list");
}
check(!nis_node('something_new')->is_operational(), 'a state this code does not know is not a working node either');
check(nis_node(null)->install_state_label() === '', 'a working node has no install-state words');

section('Fleet backups (and with them the pruning that runs before each)');
$live = nis_node(null, true);
$held = array();
foreach ($not_working as $state) { $held[$state] = nis_node($state, true); }
$eligible = array();
foreach (FleetBackupPolicy::eligible_nodes() as $n) { $eligible[(int)$n->key] = true; }
check(isset($eligible[(int)$live->key]), 'a working node is backed up');
foreach ($held as $state => $n) {
	check(!isset($eligible[(int)$n->key]), "a $state node is not backed up");
}

section('Pruning, called directly');
$target = new class {
	public function get_credentials() { return array('key' => 'x'); }
	public function get($f) { return $f === 'bkt_bucket' ? 'harness-bucket' : ''; }
};
foreach ($not_working as $state) {
	$r = FleetBackupRetention::prune(nis_node($state), $target, 7);
	check(!$r['listed'] && strpos($r['error'], 'not a working node') === 0,
		"a $state node's storage is never listed or pruned from here", $r['error']);
}

section('Staged rollouts');
foreach ($not_working as $state) {
	$why = StagedRolloutRunner::node_refusal(nis_node($state));
	check(is_string($why) && strpos($why, 'not a working site') !== false, "a $state node takes no rollout", (string)$why);
}
$why = StagedRolloutRunner::node_refusal(nis_node(null));
check($why === null || strpos($why, 'not a working site') === false, 'a working node is not refused for its state', (string)$why);

section('Uptime monitoring');
foreach ($not_working as $state) {
	$h = NodeMonitorHealth::evaluate(nis_node($state));
	check($h['state'] === NodeMonitorHealth::STATE_DISABLED && !$h['is_problem'], "a $state node is not monitored and is no problem");
}
$task = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/tasks/RunNodeUptimeChecks.php'));
check(preg_match('/if \(!\$node->is_operational\(\)\)\s*\{\s*continue;/', $task) === 1
	&& strpos($task, '->is_operational()') < strpos($task, '$this->run_check($node)'),
	'the uptime task skips a node in an install state before probing it');

section('The status dot');
$colours = array();
foreach ($not_working as $state) { $colours[$state] = JobCommandBuilder::status_color_for_node(nis_node($state), null, false); }
check($colours === array('installing' => 'info', 'install_failed' => 'danger', 'copy' => 'info', 'switching' => 'info', 'retired' => 'secondary'),
	'installing, copy and switching blue; a failed install red; a retired source grey', json_encode($colours));

harness_finish();

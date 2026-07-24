<?php
/** @joinery-test
 * name: management_job_latest_for_node
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * ManagementJob::latestForNode() — newest non-deleted job of a type for a node.
 *
 * The property under test: the helper returns the most recent job of the
 * requested type for the given node (highest mjb_id wins), returns null when
 * that node has no job of the type, ignores soft-deleted jobs, and does not
 * cross node boundaries.
 *
 * Throwaway node + job rows are created and permanently removed in cleanup;
 * real rows are untouched.
 *
 * Run: php plugins/server_manager/tests/management_job_latest_for_node_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

$db = DbConnector::get_instance()->get_db_link();

$node_id = null;
$other_node_id = null;
$job_ids = [];

/** Minimal step list; latestForNode never inspects commands. */
$steps = [['label' => 'noop', 'type' => 'ssh', 'cmd' => 'true']];

try {

	// A throwaway node and a second node to prove the query does not leak across
	// node boundaries.
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'zz-latest-test-node');
	$n->set('mgn_slug', 'zz-latest-test-' . getmypid());
	$n->set('mgn_host', '203.0.113.10');
	$n->save();
	$node_id = (int)$n->key;

	$other = new ManagedNode(NULL);
	$other->set('mgn_name', 'zz-latest-test-other');
	$other->set('mgn_slug', 'zz-latest-other-' . getmypid());
	$other->set('mgn_host', '203.0.113.11');
	$other->save();
	$other_node_id = (int)$other->key;

	check($node_id > 0 && $other_node_id > 0, 'throwaway nodes created');

	// -----------------------------------------------------------------------
	section('null when the node has no job of the type');
	// -----------------------------------------------------------------------

	check(ManagementJob::latestForNode($node_id, 'check_status') === null,
		'no jobs yet → null');

	// -----------------------------------------------------------------------
	section('returns the newest job of the requested type');
	// -----------------------------------------------------------------------

	$older = ManagementJob::createJob($node_id, 'check_status', $steps, null, null);
	$newer = ManagementJob::createJob($node_id, 'check_status', $steps, null, null);
	// An interleaved job of a different type must not be picked.
	$other_type = ManagementJob::createJob($node_id, 'backup_database', $steps, null, null);
	$job_ids[] = (int)$older->key;
	$job_ids[] = (int)$newer->key;
	$job_ids[] = (int)$other_type->key;

	$latest = ManagementJob::latestForNode($node_id, 'check_status');
	check($latest !== null, 'a check_status job is found');
	check((int)$latest->key === (int)$newer->key, 'newest (highest mjb_id) check_status wins');
	check($latest->get('mjb_job_type') === 'check_status', 'returned model is the right type');

	$latest_backup = ManagementJob::latestForNode($node_id, 'backup_database');
	check($latest_backup !== null && (int)$latest_backup->key === (int)$other_type->key,
		'a different type resolves independently');

	// -----------------------------------------------------------------------
	section('ignores soft-deleted jobs');
	// -----------------------------------------------------------------------

	$db->prepare('UPDATE mjb_management_jobs SET mjb_delete_time = now() WHERE mjb_id = ?')
	   ->execute([(int)$newer->key]);

	$after_delete = ManagementJob::latestForNode($node_id, 'check_status');
	check($after_delete !== null && (int)$after_delete->key === (int)$older->key,
		'soft-deleted newest is skipped; next-newest is returned');

	// -----------------------------------------------------------------------
	section('does not cross node boundaries');
	// -----------------------------------------------------------------------

	check(ManagementJob::latestForNode($other_node_id, 'check_status') === null,
		'the other node has no check_status job of its own → null');

} finally {
	foreach ($job_ids as $jid) {
		$db->prepare('DELETE FROM mjb_management_jobs WHERE mjb_id = ?')->execute([$jid]);
	}
	if ($node_id) {
		$db->prepare('DELETE FROM mgn_managed_nodes WHERE mgn_id = ?')->execute([$node_id]);
	}
	if ($other_node_id) {
		$db->prepare('DELETE FROM mgn_managed_nodes WHERE mgn_id = ?')->execute([$other_node_id]);
	}
}

harness_finish();

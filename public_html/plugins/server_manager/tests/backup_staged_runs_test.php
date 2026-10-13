<?php
/** @joinery-test
 * name: backup_staged_runs
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Which runs a Prepare has put on a node (BackupChainListHelper::staged_runs).
 *
 * Staging is per run: a run needs its own full and incrementals, not the
 * newest run's, and the node's workspace for a chain keeps what each Prepare
 * fetched. The Backups tab offers Restore only for the runs read here, and
 * the restore action refuses any other, so a restore is never queued and
 * approved for the node to refuse (B39, B40).
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$made_nodes = array();

// Debris from a run that crashed before its rollback.
foreach ($db->query("SELECT mgn_managed_node_id FROM mgn_managed_nodes WHERE mgn_slug LIKE 'stagedruns-%'")->fetchAll(PDO::FETCH_COLUMN) as $sid) {
	$db->prepare('DELETE FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ?')->execute(array($sid));
	$db->prepare('DELETE FROM mgn_managed_nodes WHERE mgn_managed_node_id = ?')->execute(array($sid));
}

// Everything this test writes lives inside one transaction that is rolled
// back: no other test, and no worker, ever sees its rows.
$db->beginTransaction();

$node = new ManagedNode(NULL);
$node->set('mgn_name', 'HarnessTest staged runs');
$node->set('mgn_slug', 'stagedruns-' . bin2hex(random_bytes(3)));
$node->set('mgn_host', '203.0.113.7');
$node->set('mgn_uptime_enabled', false);
$node->save();
$made_nodes[] = $node->key;

// A stage job as the agent leaves it: the script's text inside its envelope.
$stage_job = function (string $status, string $text) use ($node) {
	$job = ManagementJob::createFromBuild($node->key, 'stage_chain',
		array('primitive' => 'stage_chain', 'params' => array('chain_id' => 'chain-20261013_040501')), null, null);
	$job->set('mjb_status', $status);
	$job->set('mjb_output', "=== [Step 1/1] Agent primitive: stage_chain ===\n"
		. json_encode(array('api_version' => 1, 'data' => array('output' => $text))));
	$job->save();
	return $job;
};

section('A finished Prepare names its chain and run');
$stage_job('completed', "fetching code-0000.tar.gz.enc\nSTAGE_RESULT=ok\nSTAGE_CHAIN=chain-20261013_040501\nSTAGE_SEQ=1\nSTAGE_ARTIFACTS=4\n");
$runs = BackupChainListHelper::staged_runs($node);
check(($runs['chain-20261013_040501'] ?? null) === array(1), 'the newest run, staged: run 1 only, not run 0', json_encode($runs));

$stage_job('completed', "STAGE_RESULT=ok\nSTAGE_CHAIN=chain-20261013_040501\nSTAGE_SEQ=0\n");
$runs = BackupChainListHelper::staged_runs($node);
$have = $runs['chain-20261013_040501'] ?? array();
sort($have);
check($have === array(0, 1), 'a Prepare of run 0 adds it beside run 1: the chain\'s workspace keeps both', json_encode($runs));

section('A Prepare that failed or is still running stages nothing');
$stage_job('failed', "STAGE_RESULT=error\nSTAGE_CHAIN=chain-20261014_010101\nSTAGE_SEQ=3\n");
$stage_job('running', "fetching data-0000.tar.gz.enc\n");
$runs = BackupChainListHelper::staged_runs($node);
check(!isset($runs['chain-20261014_010101']), 'neither is offered for a restore', json_encode($runs));

section('Cleanup');
$db->rollBack();
$left = (int)$db->query("SELECT COUNT(*) FROM mgn_managed_nodes WHERE mgn_slug LIKE 'stagedruns-%'")->fetchColumn();
check(count($made_nodes) > 0 && $left === 0, 'the test node and its jobs are gone', $left . ' left');

harness_finish();

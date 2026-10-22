<?php
/** @joinery-test
 * name: apply_and_publish
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * Apply and publish (ApplyAndPublish): the apply is queued with the publish
 * that follows it recorded on the job; once the apply has finished, a good
 * apply queues the publish of the version the node reached, once, and a bad
 * one is refused with the gate's reason. Nothing is published while the
 * apply runs.
 *
 * The fixture jobs are dated outside ApplyAndPublish::WINDOW_HOURS, so the
 * scheduled task's scan (advanceAll) never picks them up while the test
 * drives advance() directly.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$node = new ManagedNode(NULL);
$node->set('mgn_name', 'HarnessTest AAP');
$node->set('mgn_slug', 'harnessaap-' . bin2hex(random_bytes(2)));
$node->set('mgn_host', '192.0.2.51');
$node->set('mgn_web_root', '/var/www/html/aap/public_html');
$node->set('mgn_agent_public_key', base64_encode(random_bytes(32)));
$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
$node->set('mgn_agent_primitives', 'apply_update,publish_upgrade,check_status');
$node->set('mgn_agent_server_manager', 'active');
$node->save();
$node->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);

$apply_result = function (array $over = array()) {
	return array_replace_recursive(array(
		'version_before' => '0.8.899', 'version_after' => '0.8.900', 'self_updated' => false, 'outcome' => 'completed',
		'migrations' => array(), 'schema_changes' => array(), 'plugins' => array(),
		'deploy_tier' => array('verdict' => 'passed', 'failed_tests' => array()),
		'rolled_back' => array('rolled_back' => false, 'step' => null, 'schema_ahead_of_code' => false),
		'duration_seconds' => 80,
	), $over);
};

/** An apply job Apply and publish queued, moved to $status with $apply as its result. */
$finished = function ($status, $apply, $error = '') use ($node) {
	$job = ApplyAndPublish::request($node, "- Better things\n- Faster things", '0.8.900', false, null);
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
	$job->set('mjb_create_time', gmdate('Y-m-d H:i:s', time() - (ApplyAndPublish::WINDOW_HOURS + 1) * 3600));
	$job->set('mjb_status', $status);
	$job->set('mjb_error_message', $error !== '' ? $error : null);
	$job->set('mjb_result', json_encode(array('probed' => true, 'apply' => $apply)));
	$job->save();
	return $job;
};

// ---------------------------------------------------------------------------
section('The request');

foreach (array(array('', '0.8.900', 'carries release notes'), array('notes', 'latest', 'not known')) as $c) {
	$threw = '';
	try { ApplyAndPublish::request($node, $c[0], $c[1], false, null); } catch (Exception $e) { $threw = $e->getMessage(); }
	check(strpos($threw, $c[2]) !== false, 'refused before anything is queued: ' . $c[2], $threw);
}

$job = ApplyAndPublish::request($node, '  - Better things  ', '0.8.900', true, null);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
$then = ApplyAndPublish::followUp($job);
check($job->get('mjb_job_type') === 'apply_update' && (string)$job->get('mjb_status') === 'pending',
	'an apply is queued on the node', $job->get('mjb_job_type') . ' ' . $job->get('mjb_status'));
check(is_array($then) && $then['release_notes'] === '- Better things' && $then['target'] === '0.8.900'
	&& $then['deploy_all'] === false && !isset($then['outcome']),
	'the publish to follow is recorded on it; deploy to all only ever for this management node itself', json_encode($then));
check(strpos((string)$job->get('mjb_commands'), 'Better things') === false,
	'nothing about the publish is sent to the node with the apply');

check(ApplyAndPublish::advance($job) === false && !isset(ApplyAndPublish::followUp($job)['outcome']),
	'while the apply has not finished nothing is published and nothing recorded');

// ---------------------------------------------------------------------------
section('A good apply is followed by its publish, once');

$good = $finished('completed', $apply_result(array('version_after' => '0.8.901')));
check(ApplyAndPublish::advance($good) === true, 'a completed, good apply queues the publish');
$then = ApplyAndPublish::followUp($good);
$publish = new ManagementJob((int)($then['publish_job_id'] ?? 0), TRUE);
if ($publish->key) {
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $publish->key);
}
$pp = StagedRolloutRunner::job_params($publish);
check(($then['outcome'] ?? '') === 'started' && ($then['version'] ?? '') === '0.8.901',
	'the apply job records the publish it started and the version', json_encode($then));
check($publish->get('mjb_job_type') === 'publish_upgrade' && (int)$publish->get('mjb_mgn_managed_node_id') === (int)$node->key
	&& ($pp['version'] ?? '') === '0.8.901' && ($pp['notes'] ?? '') === "- Better things\n- Faster things",
	'the publish is of the version the node reached, with the notes, on the same node', json_encode($pp));
check(ApplyAndPublish::advance($good) === false, 'an apply starts its publish once');

check(ApplyAndPublish::followUp($publish) === null, 'a publish job is followed by nothing');

// ---------------------------------------------------------------------------
section('A bad apply is not published, and says why');

$cases = array(
	array('the apply job failed', $finished('failed', $apply_result(), 'boom'), 'the apply job failed: boom'),
	array('the deploy tier failed', $finished('completed', $apply_result(array('deploy_tier' => array('verdict' => 'failed', 'failed_tests' => array('boot'))))), 'deploy tier failed (boot)'),
	array('it rolled back', $finished('completed', $apply_result(array('outcome' => 'failed', 'rolled_back' => array('rolled_back' => true, 'step' => 'migrations')))), 'rolled back at migrations'),
	array('it fell short of the version offered', $finished('completed', $apply_result(array('version_after' => '0.8.899'))), 'not 0.8.900 or later'),
);
foreach ($cases as $c) {
	check(ApplyAndPublish::advance($c[1]) === false, 'nothing is published when ' . $c[0]);
	$t = ApplyAndPublish::followUp($c[1]);
	check(($t['outcome'] ?? '') === 'refused' && strpos((string)($t['reason'] ?? ''), $c[2]) !== false && !isset($t['publish_job_id']),
		'and the apply job says why: ' . $c[2], json_encode($t));
}

harness_finish();

<?php
/** @joinery-test
 * name: staged_rollout
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * staged_rollout (specs/agent_recipes_and_vocabulary.md): one node at a
 * time, moving on only when the node's structured apply result says the job
 * completed, the deploy tier passed, the release's version is reported and
 * nothing rolled back; halting at the first miss and naming the node and why;
 * stoppable between nodes; and refusing, up front, a node that cannot take an
 * apply at all. And "Deploy to all managed nodes": a completed publish that
 * asked for it starts one rollout over every eligible node, management nodes
 * last, and writes what became of it onto the publish job.
 *
 * @version 1.3 - the readiness check (upgrade_preflight) before a node's apply: passes, fails by name, is skipped for an agent without it
 * @version 1.2 - a node refusing a modified upgrade script halts with its own reason
 * @version 1.1 - deploy to all managed nodes
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

/** A stand-in finished job: only what the gate reads. */
class SrlJob {
	private $f;
	public function __construct(array $f) { $this->f = $f; }
	public function get($k) { return $this->f[$k] ?? null; }
}

function srl_apply(array $over = array()) {
	return array_replace_recursive(array(
		'version_before' => '0.8.410', 'version_after' => '0.8.411', 'self_updated' => false, 'outcome' => 'completed',
		'migrations' => array(), 'schema_changes' => array(), 'plugins' => array(),
		'deploy_tier' => array('verdict' => 'passed', 'failed_tests' => array()),
		'rolled_back' => array('rolled_back' => false, 'step' => null, 'schema_ahead_of_code' => false),
		'duration_seconds' => 80,
	), $over);
}
function srl_job($status, $apply, $error = '') {
	return new SrlJob(array('mjb_status' => $status, 'mjb_error_message' => $error,
		'mjb_result' => json_encode(array('probed' => true, 'apply' => $apply))));
}

// ---------------------------------------------------------------------------
section('The gate between nodes');

check(StagedRolloutRunner::gate(srl_job('completed', srl_apply()), '0.8.411') === null,
	'completed, deploy tier passed, the release reported, no rollback: the next node may start');
$cases = array(
	array('the job failed', srl_job('failed', srl_apply(), 'boom'), 'failed'),
	array('the node refused a modified upgrade script', srl_job('failed', null, 'Refused by the node: primitive "apply_update" refused: file does not match its signed hash — it has been modified since release: public_html/utils/upgrade.php'), 'public_html/utils/upgrade.php on the node is not the file the installed release signed'),
	array('the node cannot use its release manifest', srl_job('failed', null, 'Refused by the node: primitive "apply_update" refused: no signed manifest can be verified before running as root'), 'release manifest cannot be used'),
	array('no structured result', srl_job('completed', null), 'no structured apply result'),
	array('rolled back', srl_job('completed', srl_apply(array('outcome' => 'failed', 'rolled_back' => array('rolled_back' => true, 'step' => 'deploy_tier', 'schema_ahead_of_code' => true)))), 'rolled back at deploy_tier'),
	array('deploy tier failed', srl_job('completed', srl_apply(array('deploy_tier' => array('verdict' => 'failed', 'failed_tests' => array('boot'))))), 'deploy tier failed (boot)'),
	array('wrong version', srl_job('completed', srl_apply(array('version_after' => '0.8.410'))), 'not 0.8.411'),
	array('did not complete', srl_job('completed', srl_apply(array('outcome' => 'failed'))), 'did not complete'),
);
foreach ($cases as $c) {
	$why = StagedRolloutRunner::gate($c[1], '0.8.411');
	check($why !== null && strpos($why, $c[2]) !== false, 'the gate halts on: ' . $c[0], (string)$why);
}

// ---------------------------------------------------------------------------
section('The apply result is read from the transcript, bounded');

$line = 'APPLY_RESULT: ' . json_encode(srl_apply(array('migrations' => array(array('version' => '192', 'outcome' => 'applied', 'rows' => 3)),
	'plugins' => array(array('name' => 'Mail<box>', 'before' => '1.0', 'after' => '1.1')), 'surprise' => 'x')));
$envelope = "=== [Step 1/1] apply_update ===\n" . json_encode(array('api_version' => '1.0',
	'data' => array('output' => "lots of transcript\n" . $line . "\n", 'output_bytes' => 10)));
$a = JobResultProcessor::apply_result($envelope);
check(is_array($a) && $a['version_after'] === '0.8.411' && $a['migrations'][0]['rows'] === 3 && !isset($a['surprise'])
	&& $a['plugins'][0]['name'] === 'mailbox', 'the known keys are kept and sanitised, the rest dropped', var_export($a, true));
check(JobResultProcessor::apply_result("=== [Step 1/1] apply_update ===\nno result line here\n") === null,
	'a transcript from an older upgrade.php carries no result: not reported');

// ---------------------------------------------------------------------------
section('A rollout: one node at a time, halting at the first miss');

$release = (string)StagedRolloutRunner::published_release();
check(preg_match('/^\d+\.\d+\.\d+$/', $release) === 1, 'the rollout targets the newest published release', $release);
$mk = function ($suffix, $primitives = null) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest SRL ' . $suffix);
	$n->set('mgn_slug', 'harnesssrl-' . $suffix . '-' . bin2hex(random_bytes(2)));
	$n->set('mgn_host', '192.0.2.50');
	$n->set('mgn_web_root', '/var/www/html/srl/public_html');
	$n->set('mgn_agent_public_key', base64_encode(random_bytes(32)));
	$n->set('mgn_agent_version', AgentVocabulary::FLOOR);
	$n->set('mgn_agent_primitives', $primitives ?? 'apply_update,check_status');
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$a1 = $mk('one');
$a2 = $mk('two');
$a3 = $mk('three');
$no_word = $mk('noword', 'check_status');

$prior = StagedRollout::running();
if ($prior) { StagedRolloutRunner::stop($prior, null); }

$threw = '';
try { StagedRolloutRunner::start(array($a1->key, $no_word->key), null); } catch (StagedRolloutException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'update this node') !== false, 'a node that does not report apply_update is refused up front, with the standard state', $threw);
check(StagedRollout::running() === null, 'and no rollout was recorded');

$r = StagedRolloutRunner::start(array($a2->key, $a1->key, $a3->key, $a2->key), null);
harness_register_row('srl_staged_rollouts', 'srl_staged_rollout_id', $r->key);
$steps = $r->steps();
check(count($steps) === 3 && $steps[0]['node_id'] === (int)$a2->key && $steps[1]['node_id'] === (int)$a1->key,
	'the order is kept and a repeated node counted once');
check($steps[0]['verdict'] === 'running' && !empty($steps[0]['job_id']) && empty($steps[1]['job_id']),
	'only the first node\'s apply is queued');
$job1 = new ManagementJob($steps[0]['job_id'], TRUE);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job1->key);

StagedRolloutRunner::advance($r); $r->load();
check((int)$r->get('srl_position') === 0 && $r->is_running(), 'while the first apply runs, nothing moves');

$finish = function ($job, $status, $apply) {
	$job->set('mjb_status', $status);
	$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
	$job->set('mjb_result', json_encode(array('probed' => true, 'apply' => $apply)));
	$job->save();
};
$finish($job1, 'completed', srl_apply(array('version_after' => $release)));
StagedRolloutRunner::advance($r); $r->load();
check((int)$r->get('srl_position') === 1 && $r->steps()[0]['verdict'] === 'passed', 'a good apply moves the rollout on');
StagedRolloutRunner::advance($r); $r->load();
$steps = $r->steps();
check(!empty($steps[1]['job_id']) && $steps[1]['verdict'] === 'running', 'and the next tick queues the second node');
$job2 = new ManagementJob($steps[1]['job_id'], TRUE);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job2->key);
$finish($job2, 'completed', srl_apply(array('version_after' => $release,
	'deploy_tier' => array('verdict' => 'failed', 'failed_tests' => array('deploy_boot')),
	'rolled_back' => array('rolled_back' => true, 'step' => 'deploy_tier', 'schema_ahead_of_code' => true))));
StagedRolloutRunner::advance($r); $r->load();
check((string)$r->get('srl_status') === StagedRollout::STATUS_HALTED
	&& strpos((string)$r->get('srl_halt_reason'), 'HarnessTest SRL one') !== false
	&& strpos((string)$r->get('srl_halt_reason'), 'rolled back at deploy_tier') !== false,
	'the first miss halts the rollout, naming the node and why', (string)$r->get('srl_halt_reason'));
check(empty($r->steps()[2]['job_id']) && $r->steps()[2]['verdict'] === 'skipped', 'and the third node is never started, and says skipped');

// Stop between nodes.
$s = StagedRolloutRunner::start(array($a3->key, $a1->key), null);
harness_register_row('srl_staged_rollouts', 'srl_staged_rollout_id', $s->key);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $s->steps()[0]['job_id']);
StagedRolloutRunner::stop($s, null); $s->load();
$job3 = new ManagementJob($s->steps()[0]['job_id'], TRUE);
$finish($job3, 'completed', srl_apply(array('version_after' => $release)));
StagedRolloutRunner::advance($s); $s->load();
check((string)$s->get('srl_status') === StagedRollout::STATUS_STOPPED && empty($s->steps()[1]['job_id'])
	&& $s->steps()[1]['verdict'] === 'skipped',
	'a stopped rollout starts no further node, even when the one in flight succeeds; the rest say skipped');

// A node that never finishes (never claims) halts the rollout after the budget.
$w = StagedRolloutRunner::start(array($a3->key), null);
harness_register_row('srl_staged_rollouts', 'srl_staged_rollout_id', $w->key);
$wjob = new ManagementJob($w->steps()[0]['job_id'], TRUE);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $wjob->key);
$wjob->set('mjb_create_time', gmdate('Y-m-d H:i:s', time() - (StagedRolloutRunner::APPLY_WAIT_MINUTES + 5) * 60));
$wjob->save();
StagedRolloutRunner::advance($w); $w->load();
check((string)$w->get('srl_status') === StagedRollout::STATUS_HALTED
	&& strpos((string)$w->get('srl_halt_reason'), 'has not finished in') !== false,
	'an apply that never finishes halts the rollout by name after the budget', (string)$w->get('srl_halt_reason'));

// ---------------------------------------------------------------------------
section('Deploy to all managed nodes');

$mgr = $mk('mgr');
$mgr->set('mgn_agent_server_manager', 'active');
$mgr->save();
$off = $mk('off');
$off->set('mgn_enabled', false);
$off->save();
$nosite = $mk('nosite');
$nosite->set('mgn_web_root', '');
$nosite->save();

$plan = StagedRolloutRunner::deploy_all_plan(array($mgr, $a1, $off, $no_word, $nosite, $a2));
$plan_ids = array_map(function ($n) { return (int)$n->key; }, $plan['nodes']);
check($plan_ids === array((int)$a1->key, (int)$a2->key, (int)$mgr->key),
	'the plan covers every eligible site, with the management node last', json_encode($plan_ids));
$left = array_column($plan['left_out'], 'reason', 'name');
check(isset($left[$off->get('mgn_name')]) && $left[$off->get('mgn_name')] === 'it is disabled'
	&& isset($left[$no_word->get('mgn_name')]) && count($left) === 2,
	'a disabled node and one that cannot take an apply are listed as left out; a node with no site is not listed', json_encode($left));

foreach (StagedRolloutRunner::deploy_all_plan()['nodes'] as $n) {
	check(!ManagedNode::is_fixture_name((string)$n->get('mgn_name')) && !$n->is_self(),
		'the real plan never includes a test fixture or this management node: ' . $n->get('mgn_name'));
}

// A publish job on a fixture node: the task's scan reads only this management
// node's own publishes, so a tick cannot pick this one up and roll out to the
// real fleet; the test drives it directly with fixture candidates.
$publish = function ($status, $version) use ($a1) {
	$j = new ManagementJob(NULL);
	$j->set('mjb_mgn_managed_node_id', $a1->key);
	$j->set('mjb_job_type', 'publish_upgrade');
	$j->set('mjb_status', $status);
	$j->set('mjb_commands', json_encode(array('primitive' => 'publish_upgrade', 'params' => new stdClass(), 'steps' => array())));
	$j->set('mjb_parameters', json_encode(array('version' => $version, 'notes' => 'x', 'deploy_all' => true)));
	$j->set('mjb_total_steps', 1);
	$j->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
	$j->save();
	$j->load();
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $j->key);
	return $j;
};
$candidates = array($a1, $off, $mgr);

$prior = StagedRollout::running();
if ($prior) { StagedRolloutRunner::stop($prior, null); }

$running_pub = $publish('running', $release);
check(StagedRolloutRunner::deploy_all_for_publish($running_pub, $candidates) === false
	&& !isset(StagedRolloutRunner::job_params($running_pub)['deploy_all_outcome']),
	'a publish still running starts nothing and records nothing');

$wrong = $publish('completed', '0.0.1');
StagedRolloutRunner::deploy_all_for_publish($wrong, $candidates);
$wp = StagedRolloutRunner::job_params($wrong);
check(($wp['deploy_all_outcome'] ?? '') === 'refused' && strpos((string)$wp['deploy_all_reason'], 'not 0.0.1') !== false,
	'a publish that is not the newest published release is refused, saying why', json_encode($wp));

$good = $publish('completed', $release);
check(StagedRolloutRunner::deploy_all_for_publish($good, $candidates) === true, 'a completed publish starts its rollout');
$gp = StagedRolloutRunner::job_params($good);
$dr = new StagedRollout((int)($gp['deploy_all_rollout_id'] ?? 0), TRUE);
harness_register_row('srl_staged_rollouts', 'srl_staged_rollout_id', $dr->key);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $dr->steps()[0]['job_id']);
check(($gp['deploy_all_outcome'] ?? '') === 'started' && $dr->is_running()
	&& array_column($dr->steps(), 'node_id') === array((int)$a1->key, (int)$mgr->key)
	&& ($gp['deploy_all_left_out'][0]['name'] ?? '') === $off->get('mgn_name'),
	'the rollout covers the plan in order, and the job records it and who was left out', json_encode($gp));
check(StagedRolloutRunner::deploy_all_for_publish($good, $candidates) === false,
	'a publish starts its rollout once');

$second = $publish('completed', $release);
check(StagedRolloutRunner::deploy_all_for_publish($second, $candidates) === false
	&& !isset(StagedRolloutRunner::job_params($second)['deploy_all_outcome']),
	'while a rollout is running, another publish waits rather than being refused');
StagedRolloutRunner::stop($dr, null);

// ---------------------------------------------------------------------------
section('The readiness check before a node\'s apply (upgrade_preflight)');

$pf = function ($status, $result) {
	return new SrlJob(array('mjb_status' => $status, 'mjb_error_message' => '',
		'mjb_result' => json_encode($result)));
};
check(StagedRolloutRunner::preflight_verdict($pf('completed', array('read' => true, 'ok' => true, 'checks' => array()))) === null,
	'every check passing lets the apply go');
$why = (string)StagedRolloutRunner::preflight_verdict($pf('completed', array('read' => true, 'ok' => false, 'checks' => array(
	array('check' => 'files', 'ok' => false, 'reason' => 'these deployment files differ from the signed release: public_html/utils/upgrade.php'),
	array('check' => 'disk', 'ok' => true, 'reason' => 'room')))));
check(strpos($why, 'files: these deployment files differ') !== false && strpos($why, 'nothing has been changed') !== false
	&& strpos($why, 'disk') === false, 'a failing check halts the node by name and says nothing was changed', $why);
check(strpos((string)StagedRolloutRunner::preflight_verdict($pf('completed', array('read' => false))), 'no readable readiness answer') !== false,
	'an unreadable answer is not a pass');
check(strpos((string)StagedRolloutRunner::preflight_verdict($pf('failed', array())), 'readiness check failed') !== false,
	'a check that did not run is not a pass');

// The whole path: a node whose agent offers the word is asked first, and its
// apply is queued only after it answers well.
$prior = StagedRollout::running();
if ($prior) { StagedRolloutRunner::stop($prior, null); }
$p1 = $mk('pre', 'apply_update,check_status,upgrade_preflight');
$p2 = $mk('post', 'apply_update,check_status');
$r = StagedRolloutRunner::start(array($p1->key, $p2->key), null);
harness_register_row('srl_staged_rollouts', 'srl_staged_rollout_id', $r->key);
$steps = $r->steps();
check(!empty($steps[0]['preflight_job_id']) && empty($steps[0]['job_id']) && $steps[0]['verdict'] === 'running',
	'the node is asked whether it can take an upgrade before its apply is queued', json_encode($steps[0]));
$pre = new ManagementJob($steps[0]['preflight_job_id'], TRUE);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $pre->key);
check((string)$pre->get('mjb_job_type') === 'upgrade_preflight', 'the job is an upgrade_preflight');

StagedRolloutRunner::advance($r); $r->load(); $steps = $r->steps();
check(empty($steps[0]['job_id']) && $r->is_running(), 'while the check runs, the apply waits');

$pre->set('mjb_status', 'completed');
$pre->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
$pre->set('mjb_result', json_encode(array('read' => true, 'ok' => true, 'checks' => array(array('check' => 'files', 'ok' => true, 'reason' => 'ok')))));
$pre->save();
StagedRolloutRunner::advance($r); $r->load(); $steps = $r->steps();
check(!empty($steps[0]['preflight_passed']) && !empty($steps[0]['job_id']),
	'a good answer queues the apply in the same step', json_encode($steps[0]));
$apply_job = new ManagementJob($steps[0]['job_id'], TRUE);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $apply_job->key);
StagedRolloutRunner::stop($r, null);

// A bad answer halts before any apply exists.
$p3 = $mk('bad', 'apply_update,check_status,upgrade_preflight');
$r2 = StagedRolloutRunner::start(array($p3->key), null);
harness_register_row('srl_staged_rollouts', 'srl_staged_rollout_id', $r2->key);
$steps = $r2->steps();
$bad = new ManagementJob($steps[0]['preflight_job_id'], TRUE);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $bad->key);
$bad->set('mjb_status', 'completed');
$bad->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
$bad->set('mjb_result', json_encode(array('read' => true, 'ok' => false, 'checks' => array(
	array('check' => 'files', 'ok' => false, 'reason' => 'these deployment files differ from the signed release: public_html/utils/upgrade.php')))));
$bad->save();
StagedRolloutRunner::advance($r2); $r2->load(); $steps = $r2->steps();
check((string)$r2->get('srl_status') === StagedRollout::STATUS_HALTED && empty($steps[0]['job_id'])
	&& strpos((string)$r2->get('srl_halt_reason'), 'public_html/utils/upgrade.php') !== false,
	'a failing check halts the rollout at that node with no apply ever queued', (string)$r2->get('srl_halt_reason'));

harness_finish();

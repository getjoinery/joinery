<?php
/** @joinery-test
 * name: install_job_executor
 * tier: db
 * env: any
 * needs: []
 */
/**
 * InstallJobExecutor — the plane-side bootstrap runner for install_node.
 *
 * Proven without a live target: the step loop, output contract, terminal
 * status, the queued-not-pending routing that keeps it off the node agent's
 * claim, and that every install shape is a job it runs. The ssh transport
 * itself needs a real box and is a live-acceptance item.
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/InstallJobExecutor.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));

$db = DbConnector::get_instance()->get_db_link();
$made_nodes = array();
$made_provisions = array();
$made_jobs = array();

// Clean any debris from a crashed run.
$db->exec("DELETE FROM cvp_customer_cloud_provisions WHERE cvp_slug LIKE 'ijetest-%'");
foreach ($db->query("SELECT mgn_id FROM mgn_managed_nodes WHERE mgn_slug LIKE 'ijetest-%'")->fetchAll(PDO::FETCH_COLUMN) as $sid) {
	$db->prepare('DELETE FROM mjb_management_jobs WHERE mjb_mgn_node_id = ?')->execute([$sid]);
	$db->prepare('DELETE FROM mgn_managed_nodes WHERE mgn_id = ?')->execute([$sid]);
}

// Everything this test writes lives inside ONE transaction that is rolled back
// at the end. The rows never commit, so the live install worker — which claims
// any committed 'queued' install_node job, whoever created it — cannot pick up
// a fixture addressed to a TEST-NET host and spend its readiness budget probing
// an address that never answers while a real install waits behind it. (That
// happened: a cron-spawned worker claimed a fixture job between its creation
// and this test's cleanup.) The executor itself never opens a transaction on
// this path — claim_next() does, and this test drives execute() directly.
$db->beginTransaction();

/** A throwaway managed node, optionally with a sealed-password provision. */
function ije_node($slug, $seal_password = null) {
	global $made_nodes, $made_provisions;
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest IJE ' . $slug);
	$node->set('mgn_slug', $slug);
	$node->set('mgn_host', '203.0.113.' . random_int(2, 250));
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_ssh_port', 22);
	$node->set('mgn_install_state', 'installing');
	$node->set('mgn_uptime_enabled', false);
	$node->save();
	$node->load();
	$made_nodes[] = $node->key;

	if ($seal_password !== null) {
		$prov = new CustomerCloudProvision(NULL);
		$prov->set('cvp_origin', 'admin');
		$prov->set('cvp_usr_user_id', 990000 + random_int(0, 9999));
		$prov->set('cvp_domain', $slug . '.example.com');
		$prov->set('cvp_slug', $slug);
		$prov->set('cvp_mgn_node_id', $node->key);
		$prov->set('cvp_status', 'installing');
		$box = new SecretBox();
		$prov->set('cvp_root_pass_sealed',
			$box->seal('cvp_customer_cloud_provisions.cvp_root_pass_sealed', $seal_password));
		$prov->save();
		$made_provisions[] = $prov->key;
	}
	return $node;
}

function ije_job($node, $steps) {
	global $made_jobs;
	$job = ManagementJob::createJob($node->key, 'install_node', $steps, array('mode' => 'fresh'), null);
	$made_jobs[] = $job->key;
	return $job;
}

// ---------------------------------------------------------------------------
section('install_node is queued, not pending — the agent never sees it');

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$node = ije_node('ijetest-ok-' . $suffix, 'Aa1!' . bin2hex(random_bytes(10)));
$job = ije_job($node, array(
	array('type' => 'local', 'label' => 'Preflight',       'cmd' => 'echo PREFLIGHT_OK'),
	array('type' => 'local', 'label' => 'Create the site', 'cmd' => 'echo INSTALL_SUCCESS; echo CONTAINER_PORT=8091'),
	array('type' => 'local', 'label' => 'Cleanup', 'cmd' => 'echo cleaning up', 'teardown' => true),
));

$job->load();
check($job->get('mjb_status') === 'queued',
	'a new install_node job starts in status queued');

// A non-install_node job still starts pending.
$other = ManagementJob::createJob($node->key, 'check_status', array(array('type' => 'local', 'label' => 'x', 'cmd' => 'true')), array(), null);
$made_jobs[] = $other->key;
$other->load();
check($other->get('mjb_status') === 'pending', 'a non-install_node job still starts pending');

// The node agent claims WHERE mjb_status = 'pending'; the executor claims
// WHERE mjb_status = 'queued'. Prove both predicates against this exact job.
$agent_sees = $db->prepare("SELECT COUNT(*) FROM mjb_management_jobs WHERE mjb_id = ? AND mjb_status = 'pending'");
$agent_sees->execute([$job->key]);
check((int)$agent_sees->fetchColumn() === 0, 'the node agent claim predicate does not match it');

$exec_claim = $db->prepare("UPDATE mjb_management_jobs SET mjb_status = 'running', mjb_started_time = now() WHERE mjb_id = ? AND mjb_status = 'queued'");
$exec_claim->execute([$job->key]);
check($exec_claim->rowCount() === 1, 'the executor claim predicate matches it exactly once');

// ---------------------------------------------------------------------------
section('A fresh install runs its steps and writes the runner contract');

(new InstallJobExecutor())->execute($job);
$job->load();
$out = (string)$job->get('mjb_output');

check($job->get('mjb_status') === 'completed', 'the job completes');
check((string)$job->get('mjb_result') !== '', 'and its result is processed by the executor itself, not left for a watcher');
check(strpos($out, '=== [Step 1/2] Preflight ===') !== false, 'step 1 header, teardown excluded from the count');
check(strpos($out, 'PREFLIGHT_OK') !== false, 'step 1 output captured');
check(strpos($out, '=== [Step 2/2] Create the site ===') !== false, 'step 2 header');
check(strpos($out, 'INSTALL_SUCCESS') !== false, 'the success marker JobResultProcessor reads is present');
check(strpos($out, '=== Teardown ===') !== false && strpos($out, 'cleaning up') !== false,
	'teardown ran after the main phase');

// The result processor reads a completed job exactly as it read the agent's.
JobResultProcessor::process($job);
$node->load();
check($node->get('mgn_install_state') === null, 'JobResultProcessor clears install_state on INSTALL_SUCCESS');
check((int)$node->get('mgn_port') === 8091, 'and reads CONTAINER_PORT back into mgn_port');

// ---------------------------------------------------------------------------
section('No sealed password — the job is failed, not silently skipped');

$node2 = ije_node('ijetest-nopass-' . $suffix); // no provision, no sealed password
$job2 = ije_job($node2, array(array('type' => 'local', 'label' => 'x', 'cmd' => 'echo INSTALL_SUCCESS')));
(new InstallJobExecutor())->execute($job2);
$job2->load();
check($job2->get('mjb_status') === 'failed', 'a job with no sealed password fails');
check(strpos((string)$job2->get('mjb_error_message'), 'No sealed root password') !== false,
	'and says why, naming the missing credential');

// ---------------------------------------------------------------------------
section('Every install shape is a job the executor runs');

// The bootstrap is one session whatever the shape (specs/ssh_single_bootstrap.md):
// a clone pulls its source over HTTPS inside it, bare metal runs install.sh
// server inside it, a bare instance is its docker half. None is refused on its
// parameters, and none carries a step type the executor lacks.
$node3 = ije_node('ijetest-shapes-' . $suffix, 'Aa1!' . bin2hex(random_bytes(10)));
foreach (array(
	array('mode' => 'from_backup', 'docker_mode' => 'docker'),
	array('mode' => 'fresh',       'docker_mode' => 'bare-metal'),
	array('mode' => 'bare',        'docker_mode' => 'docker'),
) as $shape) {
	$job3 = ManagementJob::createJob($node3->key, 'install_node',
		array(array('type' => 'local', 'label' => 'x', 'cmd' => 'echo INSTALL_SUCCESS')),
		$shape, null);
	$made_jobs[] = $job3->key;
	(new InstallJobExecutor())->execute($job3);
	$job3->load();
	check($job3->get('mjb_status') === 'completed',
		$shape['mode'] . ' on ' . $shape['docker_mode'] . ' runs rather than being refused',
		(string)$job3->get('mjb_error_message'));
}

// A step type the bootstrap no longer emits fails by name, so a builder that
// regressed to it is caught on the first job rather than half-run.
$job4 = ije_job($node3, array(
	array('type' => 'scp', 'label' => 'Fetch backup', 'direction' => 'download',
		'local_path' => '/tmp/x', 'remote_path' => '/tmp/y'),
));
(new InstallJobExecutor())->execute($job4);
$job4->load();
check($job4->get('mjb_status') === 'failed'
	&& strpos((string)$job4->get('mjb_error_message'), "unknown step type 'scp'") !== false,
	'an scp step is an unknown step type, and the failure names it');

// ---------------------------------------------------------------------------
section('The first ssh step waits for the target to answer, and gives up with a clear message');

// 203.0.113.0/24 is TEST-NET-3: never routed, so ssh cannot connect. With a
// two-second budget the wait fails fast and the job says why.
putenv('JOINERY_INSTALL_SSH_READY_TIMEOUT=2');
$node6 = ije_node('ijetest-noready-' . $suffix, 'Aa1!' . bin2hex(random_bytes(10)));
$job6 = ije_job($node6, array(array('type' => 'ssh', 'label' => 'Ensure curl is installed', 'cmd' => 'echo never')));
$t0 = microtime(true);
(new InstallJobExecutor())->execute($job6);
$took = microtime(true) - $t0;
putenv('JOINERY_INSTALL_SSH_READY_TIMEOUT');
$job6->load();
check($job6->get('mjb_status') === 'failed', 'an unreachable target fails the job');
check(strpos((string)$job6->get('mjb_error_message'), 'did not accept SSH') !== false,
	'and the failure says the target never answered SSH, not a bare exit code');
check(strpos((string)$job6->get('mjb_output'), 'waiting for the target to accept SSH') !== false,
	'the output shows it was waiting, so a watcher knows what the pause is');
check($took < 60, 'the budget is honoured', round($took) . 's');

// ---------------------------------------------------------------------------
section('The worker script: one instance at a time, and it says which case it hit');

$worker = PathHelper::getIncludePath('plugins/server_manager/utils/run_install_executor.php');
$lock_path = PathHelper::getSiteRoot() . '/logs/install_executor.lock';
$held = fopen($lock_path, 'c');
check($held !== false, 'the worker lock file opens', $lock_path);
// A live worker may legitimately hold the lock while this runs (an install in
// progress on this box). Either way someone holds it now — this test or that
// worker — and the property under test is the same: a second worker exits
// quietly, saying so.
$we_hold = $held !== false && flock($held, LOCK_EX | LOCK_NB);
$out = array(); $code = 0;
exec('php ' . escapeshellarg($worker) . ' 2>&1', $out, $code);
check($code === 0 && strpos(implode("\n", $out), 'already running') !== false,
	'a second worker while one holds the lock exits quietly, saying so' . ($we_hold ? '' : ' (a live worker holds it)'),
	implode(' | ', $out));
if ($we_hold) { flock($held, LOCK_UN); }
if ($held !== false) { fclose($held); }
$perms = substr(sprintf('%o', fileperms($lock_path)), -3);
check($perms === '666', 'the lock file is openable by any user, so a hand run never locks the cron user out', $perms);

// ---------------------------------------------------------------------------
section('retire_install_password is the second bootstrap job, and a doubt keeps the password');

// The closing half of the bootstrap (specs/keyless_provisioning.md WP2/WP3):
// queued like install_node, claimed by nobody but this executor, and not
// finished on its own exit code — the machine has to refuse the password.
$retire_pw = 'Aa1!' . bin2hex(random_bytes(10));
$node7 = ije_node('ijetest-retire-' . $suffix, $retire_pw);
$built = JobCommandBuilder::build_retire_install_password($node7);
check(count($built) === 1 && ($built[0]['type'] ?? '') === 'ssh',
	'retiring the password is one ssh session');
check(strpos($built[0]['cmd'], 'host-harden --agent-managed') !== false
	&& strpos($built[0]['cmd'], 'sshd -T') !== false
	&& strpos($built[0]['cmd'], '/opt/joinery-install/') !== false,
	'it runs host-harden --agent-managed from the release the bootstrap left, and reads sshd back');
$rjob = ManagementJob::createJob($node7->key, 'retire_install_password', $built, array('provision_id' => 0), null);
$made_jobs[] = $rjob->key;
$rjob->load();
check($rjob->get('mjb_status') === 'queued', 'a retire_install_password job starts queued, like install_node');
check(in_array('retire_install_password', ManagementJob::BOOTSTRAP_JOB_TYPES, true)
	&& count(ManagementJob::BOOTSTRAP_JOB_TYPES) === 2,
	'the bootstrap set is exactly install_node and retire_install_password');

// A job outside that set is refused by the executor by name, so a bootstrap
// runner handed anything else fails loudly instead of running it.
$bad = ManagementJob::createJob($node7->key, 'check_status',
	array(array('type' => 'local', 'label' => 'x', 'cmd' => 'echo never')), array(), null);
$made_jobs[] = $bad->key;
$db->prepare("UPDATE mjb_management_jobs SET mjb_status = 'running' WHERE mjb_id = ?")->execute([$bad->key]);
(new InstallJobExecutor())->execute($bad);
$bad->load();
check($bad->get('mjb_status') === 'failed'
	&& strpos((string)$bad->get('mjb_error_message'), 'retire_install_password') !== false,
	'a job of another type is refused, naming the bootstrap set');

// The harden step "succeeds" (a local echo stands in for it), but the
// machine at TEST-NET never answers the refusal probe. No answer is a doubt,
// a doubt fails the job, and a failed job leaves the password on the row.
putenv('JOINERY_INSTALL_REFUSAL_CONFIRM_TIMEOUT=2');
$doubt = ManagementJob::createJob($node7->key, 'retire_install_password',
	array(array('type' => 'local', 'label' => 'Retire the install password', 'cmd' => 'echo INSTALL_PASSWORD_RETIRED')),
	array('provision_id' => 0), null);
$made_jobs[] = $doubt->key;
$t0 = microtime(true);
(new InstallJobExecutor())->execute($doubt);
$took = microtime(true) - $t0;
putenv('JOINERY_INSTALL_REFUSAL_CONFIRM_TIMEOUT');
$doubt->load();
check($doubt->get('mjb_status') === 'failed', 'a retire job whose refusal cannot be confirmed FAILS, even though its steps passed');
check(strpos((string)$doubt->get('mjb_error_message'), 'Could not confirm') !== false
	&& strpos((string)$doubt->get('mjb_error_message'), 'password is kept') !== false,
	'and says the password is kept and why', (string)$doubt->get('mjb_error_message'));
check(strpos((string)$doubt->get('mjb_output'), 'Confirming the machine refuses the install password') !== false,
	'the output shows the confirmation attempt, so a watcher knows what the job was waiting on');
$result = json_decode((string)$doubt->get('mjb_result'), true);
check(is_array($result) && $result['retired'] === false, 'the recorded result says not retired');
$sealed_q = $db->prepare('SELECT cvp_root_pass_sealed FROM cvp_customer_cloud_provisions WHERE cvp_mgn_node_id = ?');
$sealed_q->execute([$node7->key]);
$sealed_still = (string)$sealed_q->fetchColumn();
check($sealed_still !== '' && (new SecretBox())->open($sealed_still)['value'] === $retire_pw,
	'the sealed password is still on the provision row — nothing erased it');
check($took < 90, 'the confirmation budget is honoured', round($took) . 's');

// ---------------------------------------------------------------------------
section('Cleanup');

// Nothing committed, so nothing to delete: the rollback takes every fixture
// row with it, and the ids the test collected prove there were rows to take.
check(count($made_nodes) > 0 && count($made_jobs) > 0, 'fixtures were created inside the transaction');
$db->rollBack();
$left = (int)$db->query("SELECT COUNT(*) FROM mgn_managed_nodes WHERE mgn_slug LIKE 'ijetest-%'")->fetchColumn();
check($left === 0, 'every node this test created is gone', $left . ' left');

harness_finish();

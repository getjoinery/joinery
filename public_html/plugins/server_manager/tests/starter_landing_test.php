<?php
/** @joinery-test
 * name: starter_landing
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Where a hosted site lands: an instance of its own, or a container on a
 * shared host (specs/starter_tier.md, Launch scope step 1).
 *
 * The properties, each one a way a starter site could reach its neighbours:
 *
 *  - **A container site's row never holds an instance id.** The only instance
 *    in sight is its host's; anything that acts on the row's instance (power
 *    off, transfer, delete) would act on every site on the box.
 *  - **A site lands only on a host of its own tier.** A starter site never goes
 *    onto a general host, nor a general one onto a starter box.
 *  - **The instance processor never takes a container row.** It creates
 *    instances; a container site is its host's to install.
 *  - **The end of grace holds the container, never "the instance".** An empty
 *    instance id used to read as already powered off, marking a site that kept
 *    serving shut down. A container site's grace end asks its host's agent to
 *    stop and hold that one container, and until it can, the site stays in grace.
 *
 * Everything runs inside one transaction that is rolled back.
 *
 * Run: php plugins/server_manager/tests/starter_landing_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_hosts_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/hosted_trials_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/HostedTrialWatch.php'));

$db = DbConnector::get_instance()->get_db_link();
$db->beginTransaction();

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);

function sl_node($slug, array $extra = array()) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest starter ' . $slug);
	$node->set('mgn_slug', $slug);
	$node->set('mgn_host', '203.0.113.' . random_int(2, 250));
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_uptime_enabled', false);
	$node->set('mgn_agent_public_key', 'sl-agent-' . $slug);
	$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
	foreach ($extra as $k => $v) { $node->set($k, $v); }
	$node->save();
	$node->load();
	return $node;
}

function sl_host($slug, $tier, $host_node = null) {
	$host = new ManagedHost(NULL);
	$host->set('mgh_slug', $slug);
	$host->set('mgh_name', 'HarnessTest host ' . $slug);
	$host->set('mgh_host', '203.0.113.' . random_int(2, 250));
	$host->set('mgh_max_sites', 12);
	$host->set('mgh_provisioning_enabled', true);
	$host->set('mgh_tier', $tier);
	if ($host_node) { $host->set('mgh_mgn_managed_node_id', $host_node->key); }
	$host->save();
	$host->load();
	return $host;
}

function sl_provision($slug, array $extra = array()) {
	$provision = new CustomerCloudProvision(NULL);
	$provision->set('cvp_origin', 'admin');
	$provision->set('cvp_usr_user_id', 990000 + random_int(0, 9999));
	$provision->set('cvp_domain', $slug . '.example.com');
	$provision->set('cvp_slug', $slug);
	$provision->set('cvp_hosting_mode', 'operator');
	$provision->set('cvp_buyer_email', $slug . '@example.com');
	foreach ($extra as $k => $v) { $provision->set($k, $v); }
	$provision->save();
	$provision->load();
	return $provision;
}

function sl_refused(callable $fn) {
	try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
	return null;
}

// Other hosts on this plane would answer pick_for_provisioning first; take
// them out of the running inside the rolled-back transaction.
$db->exec("UPDATE mgh_managed_hosts SET mgh_provisioning_enabled = false");

// ---------------------------------------------------------------------------
section('A container site\'s row never holds an instance id');

$host_node = sl_node('sl-hostagent-' . $suffix, array('mgn_agent_primitives' => 'hold_container'));
$starter_host = sl_host('sl-starter-' . $suffix, 'starter', $host_node);

$ok = sl_provision('sl-ok-' . $suffix, array(
	'cvp_landing' => 'container', 'cvp_mgh_managed_host_id' => $starter_host->key));
check($ok->key && $ok->is_container(), 'a container site on a shared host saves and says it is in a container');

$refused = sl_refused(function () use ($suffix, $starter_host) {
	sl_provision('sl-withid-' . $suffix, array('cvp_landing' => 'container',
		'cvp_mgh_managed_host_id' => $starter_host->key, 'cvp_instance_id' => '12345678'));
});
check($refused !== null, 'a container row carrying an instance id is refused', (string)$refused);

$refused = sl_refused(function () use ($suffix) {
	sl_provision('sl-cust-' . $suffix, array('cvp_landing' => 'container', 'cvp_hosting_mode' => 'customer'));
});
check($refused !== null, 'a container site in a customer\'s own account is refused: our hosts are ours', (string)$refused);

$refused = sl_refused(function () use ($suffix, $starter_host) {
	sl_provision('sl-insthost-' . $suffix, array('cvp_mgh_managed_host_id' => $starter_host->key));
});
check($refused !== null, 'a site on its own instance naming a shared host is refused', (string)$refused);

$refused = sl_refused(function () use ($suffix) {
	sl_provision('sl-badlanding-' . $suffix, array('cvp_landing' => 'shelf'));
});
check($refused !== null, 'an unknown landing is refused', (string)$refused);

$plain = sl_provision('sl-plain-' . $suffix);
check(!$plain->is_container() && (string)$plain->get('cvp_landing') === 'instance',
	'a row that says nothing lands on an instance of its own, as every site before it did');

// ---------------------------------------------------------------------------
section('A site lands only on a host of its own tier');

$general_host = sl_host('sl-general-' . $suffix, 'general');
$picked = ManagedHost::pick_for_provisioning('starter');
check($picked && (int)$picked->key === (int)$starter_host->key, 'a starter site is placed on the starter host');
$picked = ManagedHost::pick_for_provisioning();
check($picked && (int)$picked->key === (int)$general_host->key,
	'a general placement never picks the starter host');
$general_host->set('mgh_provisioning_enabled', false);
$general_host->save();
check(ManagedHost::pick_for_provisioning('general') === null,
	'with no general host taking sites there is no general placement, even with a starter box free');
check(sl_refused(function () { ManagedHost::pick_for_provisioning('shelf'); }) !== null,
	'an unknown tier is refused rather than read as any host');
check(sl_refused(function () use ($general_host) {
	$general_host->set('mgh_tier', 'shelf');
	$general_host->save();
}) !== null, 'a host cannot be saved with an unknown tier');

// ---------------------------------------------------------------------------
section('The instance processor never takes a container row');

$ok->set('cvp_status', 'ready');
$ok->save();
$plain->set('cvp_status', 'ready');
$plain->save();
$ids = array();
foreach (new MultiCustomerCloudProvision(array('statuses' => array('ready'), 'landing' => 'instance', 'deleted' => false)) as $p) {
	$ids[(int)$p->key] = true;
}
check(!isset($ids[(int)$ok->key]), 'a container row at ready is not offered to the instance processor');
check(isset($ids[(int)$plain->key]), 'an instance row at ready still is');
$src = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionCustomerCloud.php'));
check(preg_match("/'landing'\\s*=>\\s*'instance'/", $src) === 1,
	'the processor\'s own query filters on the instance landing');

// ---------------------------------------------------------------------------
section('The end of grace holds the container, never "the instance"');

$site_node = sl_node('sl-site-' . $suffix, array(
	'mgn_container_name' => 'slsite' . $suffix, 'mgn_mgh_managed_host_id' => $starter_host->key));
// Watched, as a serving site is: the end of grace is what switches it off.
$site_node->set('mgn_uptime_enabled', true);
$site_node->save();
$ok->set('cvp_mgn_managed_node_id', $site_node->key);
$ok->set('cvp_status', 'done');
$ok->save();

function sl_grace_ended($provision) {
	$t = new HostedTrial(NULL);
	$t->set('htr_cvp_customer_cloud_provision_id', (int)$provision->key);
	$t->set('htr_state', HostedTrial::STATE_GRACE);
	$t->set('htr_grace_ends_time', gmdate('Y-m-d H:i:s', time() - 3600));
	$t->save();
	$t->load();
	return $t;
}

$watch = new HostedTrialWatch();
$shut = (new ReflectionClass('HostedTrialWatch'))->getMethod('shut_down_if_due');

$trial = sl_grace_ended($ok);
check($shut->invoke($watch, $ok, $trial) === 0, 'a container site past its grace: its host is asked to hold it');
$trial->load();
check((string)$trial->get('htr_state') === HostedTrial::STATE_GRACE,
	'and it is not recorded as shut down until the host says the container is held');
$q = $db->prepare("SELECT mjb_job_type, mjb_mgn_managed_node_id, mjb_commands FROM mjb_management_jobs
	WHERE mjb_mgn_managed_node_id IN (?, ?) ORDER BY mjb_management_job_id DESC");
$q->execute(array($host_node->key, $site_node->key));
$jobs = $q->fetchAll(PDO::FETCH_ASSOC);
$hold = $jobs[0] ?? array();
check(($hold['mjb_job_type'] ?? '') === 'hold_container'
		&& (int)($hold['mjb_mgn_managed_node_id'] ?? 0) === (int)$host_node->key,
	'the stop is a hold_container job addressed to the HOST\'s agent, not the site inside the container',
	json_encode($hold));
check(strpos((string)($hold['mjb_commands'] ?? ''), 'slsite' . $suffix) !== false
		&& strpos((string)($hold['mjb_commands'] ?? ''), '"stop"') !== false,
	'it stops that one container by name', (string)($hold['mjb_commands'] ?? ''));
check(count($jobs) === 1, 'and it is the only job: nothing is sent at the box as a whole');
check($shut->invoke($watch, $ok, $trial) === 0 && count($db->query("SELECT 1 FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = " . (int)$host_node->key . " AND mjb_job_type = 'hold_container'")->fetchAll()) === 1,
	'while the host has not answered, nothing more is sent');

// The hold failed: not taken for a held site, and asked again an hour later,
// not on the next tick (a host that keeps refusing is not sent a job a minute).
$sl_holds = function () use ($db, $host_node) {
	return $db->query("SELECT mjb_status FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = " . (int)$host_node->key
		. " AND mjb_job_type = 'hold_container' ORDER BY mjb_management_job_id")->fetchAll(PDO::FETCH_COLUMN);
};
$sl_age_holds = function () use ($db, $host_node) {
	$db->prepare("UPDATE mjb_management_jobs SET mjb_completed_time = now() - interval '2 hours'
		WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'hold_container' AND mjb_status IN ('failed', 'completed')")
		->execute(array($host_node->key));
};
$sl_answer = function (bool $done) use ($db, $host_node, $suffix) {
	$answer = json_encode(array('api_version' => '1.0', 'data' => array('output' => json_encode(array(
		'container' => 'slsite' . $suffix, 'action' => 'stop', 'done' => $done, 'held' => $done,
		'state' => $done ? 'exited' : 'running')) . "\n", 'output_bytes' => 200)));
	$db->prepare("UPDATE mjb_management_jobs SET mjb_status = 'completed', mjb_completed_time = now(), mjb_output = ?
		WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'hold_container' AND mjb_status = 'pending'")
		->execute(array($answer, $host_node->key));
};
$db->prepare("UPDATE mjb_management_jobs SET mjb_status = 'failed', mjb_completed_time = now() WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'hold_container'")
	->execute(array($host_node->key));
check($shut->invoke($watch, $ok, $trial) === 0, 'a failed hold is not taken for a held site');
$trial->load();
check((string)$trial->get('htr_state') === HostedTrial::STATE_GRACE && $sl_holds() === array('failed'),
	'the site stays in grace, and its host is not asked again within the hour', json_encode($sl_holds()));
$sl_age_holds();
check($shut->invoke($watch, $ok, $trial) === 0 && $sl_holds() === array('failed', 'pending'),
	'an hour on, its host is asked again', json_encode($sl_holds()));

// The host answered, but the container did not stop: its script ends cleanly
// either way, so the job is completed. That is not a held site.
$sl_answer(false);
check($shut->invoke($watch, $ok, $trial) === 0, 'a hold the host answered without stopping the container is not taken for a held site');
$trial->load();
$site_node->load();
check((string)$trial->get('htr_state') === HostedTrial::STATE_GRACE && $site_node->get('mgn_uptime_enabled'),
	'the site stays in grace and its uptime checks stay on: it may still be serving');
check($sl_holds() === array('failed', 'completed'), 'and its host is not asked again within the hour', json_encode($sl_holds()));
$sl_age_holds();
check($shut->invoke($watch, $ok, $trial) === 0 && $sl_holds() === array('failed', 'completed', 'pending'),
	'an hour on, its host is asked again', json_encode($sl_holds()));

// The host held it: now it is shut down, quietened, and a person is told.
$sl_answer(true);
check($shut->invoke($watch, $ok, $trial) === 1, 'once the host says the container is stopped and held');
$trial->load();
check((string)$trial->get('htr_state') === HostedTrial::STATE_SHUTDOWN, 'the site is recorded as shut down');
$site_node->load();
check(!$site_node->get('mgn_uptime_enabled'), 'the site\'s uptime checks are switched off while it is held');

// A container site whose container is not on record cannot be stopped, and must
// not be recorded as if it had been: it is still serving.
$orphan_node = sl_node('sl-orphan-' . $suffix, array('mgn_mgh_managed_host_id' => $starter_host->key));
$orphan = sl_provision('sl-orphan-' . $suffix, array('cvp_landing' => 'container',
	'cvp_mgh_managed_host_id' => $starter_host->key, 'cvp_mgn_managed_node_id' => $orphan_node->key));
$otrial = sl_grace_ended($orphan);
check($shut->invoke($watch, $orphan, $otrial) === 0, 'a container site with no container on record is not acted on');
$otrial->load();
check((string)$otrial->get('htr_state') === HostedTrial::STATE_GRACE,
	'and stays in grace, so the next tick tries again, instead of reading as already off');

// ---------------------------------------------------------------------------
section('A site copy never lands on a starter host');

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SiteCopyRunner.php'));
$copy_source = sl_node('sl-copysrc-' . $suffix);
$why = SiteCopyRunner::host_refusals($copy_source, $starter_host);
check((bool)array_filter($why, function ($w) { return strpos($w, 'starter host') !== false; }),
	'the copy form names a starter host as one a copy cannot go on', implode(' | ', $why));

$db->rollBack();
harness_finish();

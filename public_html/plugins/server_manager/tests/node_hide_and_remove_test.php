<?php
/** @joinery-test
 * name: node_hide_and_remove
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Hide a site, or remove it for good (spec node_hide_and_remove).
 *
 *  - Where a node is hosted: our two accounts are ours; the customer's and
 *    elsewhere are not; a site on a server is where its server is.
 *  - Hide then Restore: the node is listed again, its drained backup space
 *    takes backups again, a cancelled job stays cancelled.
 *  - Remove Permanently takes every record the node owns: jobs, incidents and
 *    their events, its provisioning record with its trial and transfer, its
 *    backup space with its runs and ledger rows. A copy, and a copy's
 *    provision, stop naming it. No column the deletion rules know names it.
 *  - The guards: backups that cannot be checked, a container not verified
 *    gone, a machine the provider still has (or cannot be asked about), an
 *    attestation typed wrong. A matching attestation passes and is logged;
 *    a node hosted elsewhere has no machine guard.
 *  - The Jobs listing leaves out a hidden node's jobs on request.
 *  - sm_018 deletes jobs of no node or a missing one (but not a nodeless
 *    Publish Upgrade run) and ledger
 *    rows of spaces that are gone, and a second run changes nothing.
 *
 * Run: php plugins/server_manager/tests/node_hide_and_remove_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$suffix = getmypid() . '-' . random_int(1000, 9999);
$db = DbConnector::get_instance()->get_db_link();
$buyer = make_user('NodeHideRemove');
$ours = CloudAccounts::plane_account();
$other_ours = $ours === CloudAccounts::MAIN ? CloudAccounts::TEST : CloudAccounts::MAIN;

function nhr_node(string $label, array $set = array()) {
	global $suffix;
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest NHR ' . $label);
	$node->set('mgn_slug', 'nhr-' . strtolower($label) . '-' . $suffix);
	$node->set('mgn_host', '198.51.100.71');
	$node->set('mgn_enabled', true);
	foreach ($set as $k => $v) {
		$node->set($k, $v);
	}
	$node->prepare();
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	return $node;
}

function nhr_provision($buyer, $node, array $set = array()) {
	global $suffix;
	static $n = 0;
	$n++;
	$prov = new CustomerCloudProvision(NULL);
	$prov->set('cvp_origin', 'admin');
	$prov->set('cvp_usr_user_id', $buyer->key);
	$prov->set('cvp_domain', 'nhr-' . $suffix . '-' . $n . '.example.com');
	$prov->set('cvp_slug', 'nhr-' . $n . '-' . $suffix);
	$prov->set('cvp_status', 'done');
	$prov->set('cvp_hosting_mode', 'operator');
	$prov->set('cvp_mgn_managed_node_id', $node->key);
	foreach ($set as $k => $v) {
		$prov->set($k, $v);
	}
	$prov->save();
	$prov->load();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $prov->key);
	return $prov;
}

function nhr_insert(string $table, string $pkey, array $row): int {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($row)) . ') VALUES ('
		. implode(', ', array_fill(0, count($row), '?')) . ') RETURNING ' . $pkey);
	$q->execute(array_values($row));
	$id = (int)$q->fetchColumn();
	harness_register_row($table, $pkey, $id);
	return $id;
}

function nhr_exists(string $table, string $pkey, int $id): bool {
	$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT 1 FROM $table WHERE $pkey = ?");
	$q->execute(array($id));
	return (bool)$q->fetchColumn();
}

function nhr_job(int $node_id = null, string $status = 'completed', string $type = 'check_status'): int {
	return nhr_insert('mjb_management_jobs', 'mjb_management_job_id', array(
		'mjb_mgn_managed_node_id' => $node_id, 'mjb_job_type' => $type, 'mjb_status' => $status,
		'mjb_commands' => '[]'));
}

$target_id = nhr_insert('bkt_backup_targets', 'bkt_backup_target_id', array(
	'bkt_name' => 'HarnessTest NHR target ' . $suffix, 'bkt_provider' => 's3'));

function nhr_space(int $node_id, string $state = 'active'): int {
	global $target_id, $suffix;
	return nhr_insert('sps_storage_spaces', 'sps_storage_space_id', array(
		'sps_bkt_backup_target_id' => $target_id, 'sps_base_key' => 'harness/nhr-' . $suffix . '-' . $node_id . '/',
		'sps_mgn_managed_node_id' => $node_id, 'sps_state' => $state));
}

/** A provider that answers as told. */
function nhr_driver($instance_answer, $listing = array()) {
	return new class($instance_answer, $listing) {
		private $answer; private $listing;
		public $asked = array();
		function __construct($answer, $listing) { $this->answer = $answer; $this->listing = $listing; }
		function getInstance(string $id): array {
			$this->asked[] = $id;
			if ($this->answer instanceof Throwable) { throw $this->answer; }
			return $this->answer;
		}
		function listInstances(): array {
			if ($this->listing instanceof Throwable) { throw $this->listing; }
			return $this->listing;
		}
	};
}
$no_dns = function ($name) { return array(); };

// ---------------------------------------------------------------------------
section('Where a node is hosted');

$n_main = nhr_node('main', array('mgn_cloud_account' => CloudAccounts::MAIN));
$n_cust = nhr_node('cust', array('mgn_cloud_account' => CloudAccounts::CUSTOMER));
$n_ext = nhr_node('ext', array('mgn_cloud_account' => CloudAccounts::EXTERNAL));
$n_blank = nhr_node('blank');
check(CloudAccounts::is_ours($n_main) && !CloudAccounts::is_ours($n_cust) && !CloudAccounts::is_ours($n_ext),
	'our account is ours; the customer\'s and elsewhere are not');
check((string)$n_blank->get('mgn_cloud_account') === $ours, 'a node added with no account is stamped with this plane\'s');
check(CloudAccounts::of_node($n_ext) === CloudAccounts::MAIN && CloudAccounts::of_node($n_cust) === CloudAccounts::MAIN,
	'a node not hosted by us is listed on the Main tab');

$server = nhr_node('server', array('mgn_cloud_account' => CloudAccounts::EXTERNAL));
$host_id = nhr_insert('mgh_managed_hosts', 'mgh_managed_host_id', array(
	'mgh_name' => 'HarnessTest NHR host ' . $suffix, 'mgh_slug' => 'nhr-host-' . $suffix, 'mgh_host' => '198.51.100.72',
	'mgh_mgn_managed_node_id' => $server->key, 'mgh_cloud_account' => CloudAccounts::MAIN));
$on_server = nhr_node('onserver', array('mgn_mgh_managed_host_id' => $host_id, 'mgn_cloud_account' => CloudAccounts::MAIN,
	'mgn_container_name' => 'nhronsrv'));
check(CloudAccounts::hosted_at($on_server) === CloudAccounts::EXTERNAL,
	'a site on a server is hosted where its server\'s node says, not where its own field says');
check(NodeRemoval::machine_kind($on_server, 'tok') === NodeRemoval::MACHINE_NONE,
	'so a container on a server hosted elsewhere has no machine guard');

$p_op = new CustomerCloudProvision(NULL);
$p_op->set('cvp_hosting_mode', 'operator');
$p_cu = new CustomerCloudProvision(NULL);
$p_cu->set('cvp_hosting_mode', 'transferred');
check(CloudAccounts::for_provision($p_op) === $ours && CloudAccounts::for_provision($p_cu) === CloudAccounts::CUSTOMER,
	'a machine bought on our token is in this plane\'s account; one handed to the customer is theirs');

// ---------------------------------------------------------------------------
section('Hide, then Restore');

$hr = nhr_node('hide', array('mgn_agent_public_key' => 'nhr-agent-key-' . $suffix));
$hr_space = nhr_space((int)$hr->key);
$hr_job = nhr_job((int)$hr->key, 'pending');
$hr->soft_delete();
check((string)(new StorageSpace($hr_space, TRUE))->get('sps_state') === StorageSpace::STATE_DRAINING, 'hiding drains its backup space');
$listed = function ($id) {
	foreach (new MultiManagedNode(array('deleted' => false, 'mgn_managed_node_id' => $id)) as $n) { return true; }
	return false;
};
check(!$listed($hr->key), 'a hidden node is not listed');

$hr = new ManagedNode($hr->key, TRUE);
$hr->undelete();
$hr = new ManagedNode($hr->key, TRUE);
check($listed($hr->key) && !$hr->get('mgn_delete_time'), 'restored, it is listed again');
check((string)$hr->get('mgn_agent_public_key') === 'nhr-agent-key-' . $suffix,
	'its agent key is kept, so its agent is accepted with no re-pairing');
check((string)(new StorageSpace($hr_space, TRUE))->get('sps_state') === StorageSpace::STATE_ACTIVE,
	'the space its hiding drained takes its backups again');
check((string)(new ManagementJob($hr_job, TRUE))->get('mjb_status') === 'cancelled', 'a job cancelled when it was hidden stays cancelled');

$hr2 = nhr_node('hide2');
$hr2_old = nhr_space((int)$hr2->key, 'draining');
$hr2_new = nhr_space((int)$hr2->key . '9');
$db->prepare('UPDATE sps_storage_spaces SET sps_mgn_managed_node_id = ? WHERE sps_storage_space_id = ?')->execute(array($hr2->key, $hr2_new));
$hr2->soft_delete();
$hr2 = new ManagedNode($hr2->key, TRUE);
$hr2->undelete();
$states = array((string)(new StorageSpace($hr2_old, TRUE))->get('sps_state'), (string)(new StorageSpace($hr2_new, TRUE))->get('sps_state'));
check($states === array('draining', 'active'), 'only one space is given back: the one hiding drained', json_encode($states));

// ---------------------------------------------------------------------------
section('Remove Permanently takes every record the node owns');

$victim = nhr_node('victim');
$v_jobs = array(nhr_job((int)$victim->key), nhr_job((int)$victim->key, 'pending'));
$v_inc = nhr_insert('inc_incident_records', 'inc_incident_record_id', array(
	'inc_mgn_managed_node_id' => $victim->key, 'inc_source' => 'harness', 'inc_node_case_id' => 1));
$v_ine = nhr_insert('ine_incident_events', 'ine_incident_event_id', array(
	'ine_inc_incident_record_id' => $v_inc, 'ine_time' => gmdate('Y-m-d H:i:s'), 'ine_kind' => 'opened'));
$v_prov = nhr_provision($buyer, $victim, array('cvp_instance_id' => 'nhr-' . $suffix));
$v_htr = nhr_insert('htr_hosted_trials', 'htr_hosted_trial_id', array('htr_cvp_customer_cloud_provision_id' => $v_prov->key));
$v_itx = nhr_insert('itx_instance_transfers', 'itx_instance_transfer_id', array(
	'itx_cvp_customer_cloud_provision_id' => $v_prov->key, 'itx_instance_id' => 'nhr-' . $suffix));
$v_space = nhr_space((int)$victim->key);
$v_run = nhr_insert('svr_shelf_runs', 'svr_shelf_run_id', array(
	'svr_mgn_managed_node_id' => $victim->key, 'svr_sps_storage_space_id' => $v_space, 'svr_base_key' => 'harness/nhr/',
	'svr_profile' => 'site', 'svr_state' => 'finished'));
$v_svo = nhr_insert('svo_shelf_objects', 'svo_shelf_object_id', array(
	'svo_sps_storage_space_id' => $v_space, 'svo_svr_shelf_run_id' => $v_run, 'svo_key' => 'harness/nhr/one'));
$v_run_job = nhr_job((int)$victim->key);
$db->prepare('UPDATE mjb_management_jobs SET mjb_svr_shelf_run_id = ? WHERE mjb_management_job_id = ?')->execute(array($v_run, $v_run_job));

$copy = nhr_node('copy', array('mgn_copy_of_node_id' => $victim->key));
$copy_prov = nhr_provision($buyer, $copy, array('cvp_source_node_id' => $victim->key));

$victim_id = (int)$victim->key;
$notes = (new ManagedNode($victim_id, TRUE))->remove_permanently();
check(!nhr_exists('mgn_managed_nodes', 'mgn_managed_node_id', $victim_id), 'the node is gone');
check(!nhr_exists('mjb_management_jobs', 'mjb_management_job_id', $v_jobs[0]) && !nhr_exists('mjb_management_jobs', 'mjb_management_job_id', $v_jobs[1])
	&& !nhr_exists('mjb_management_jobs', 'mjb_management_job_id', $v_run_job), 'its jobs are gone');
check(!nhr_exists('inc_incident_records', 'inc_incident_record_id', $v_inc) && !nhr_exists('ine_incident_events', 'ine_incident_event_id', $v_ine),
	'its incidents and their events are gone');
check(!nhr_exists('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', (int)$v_prov->key)
	&& !nhr_exists('htr_hosted_trials', 'htr_hosted_trial_id', $v_htr) && !nhr_exists('itx_instance_transfers', 'itx_instance_transfer_id', $v_itx),
	'its provisioning record is gone, with its trial and its transfer');
check(!nhr_exists('sps_storage_spaces', 'sps_storage_space_id', $v_space) && !nhr_exists('svr_shelf_runs', 'svr_shelf_run_id', $v_run)
	&& !nhr_exists('svo_shelf_objects', 'svo_shelf_object_id', $v_svo), 'its backup space is gone, with its runs and ledger rows');
check((int)(new ManagedNode($copy->key, TRUE))->get('mgn_copy_of_node_id') === 0, 'a copy of it stays, and stops naming it');
check((int)(new CustomerCloudProvision($copy_prov->key, TRUE))->get('cvp_source_node_id') === 0, 'so does a copy\'s provision');
check(!empty($notes), 'a live node\'s site records were released on the way (its provisioning record named)', json_encode($notes));

$named = array();
foreach ($db->query("SELECT del_target_table, del_target_column FROM del_deletion_rules WHERE del_source_table = 'mgn_managed_nodes'")->fetchAll(PDO::FETCH_ASSOC) as $rule) {
	$q = $db->prepare('SELECT count(*) FROM ' . $rule['del_target_table'] . ' WHERE ' . $rule['del_target_column'] . ' = ?');
	$q->execute(array($victim_id));
	if ((int)$q->fetchColumn() > 0) { $named[] = $rule['del_target_table'] . '.' . $rule['del_target_column']; }
}
check(!$named, 'no column the deletion rules know still names it', implode(', ', $named));

// ---------------------------------------------------------------------------
section('The guards');

$g_space = nhr_node('gspace', array('mgn_cloud_account' => CloudAccounts::EXTERNAL));
nhr_space((int)$g_space->key);
check(strpos(NodeRemoval::refusal($g_space, '', null, $no_dns, 'tok'), 'could not be checked') !== false,
	'a backup space that cannot be listed refuses: none is taken for empty on no answer');

$c_live = nhr_node('cont', array('mgn_cloud_account' => $ours, 'mgn_container_name' => 'nhrc', 'mgn_joinery_version' => '0.8.1'));
check(NodeRemoval::machine_kind($c_live, 'tok') === NodeRemoval::MACHINE_CONTAINER, 'a container site of ours waits for its container');
check(strpos(NodeRemoval::refusal($c_live, '', null, $no_dns, 'tok'), 'Permanently Delete Site first') !== false,
	'one its host has not verified gone is refused');
$c_live->set('mgn_site_removed_time', gmdate('Y-m-d H:i:s'));
$c_live->save();
check(NodeRemoval::refusal($c_live, '', null, $no_dns, 'tok') === '', 'once its host verified it gone, it may go');
$c_never = nhr_node('contnever', array('mgn_cloud_account' => $ours, 'mgn_container_name' => 'nhrc2'));
check(NodeRemoval::refusal($c_never, '', null, $no_dns, 'tok') === '', 'a container where no site was ever seen has nothing to tear down');

$m = nhr_node('machine', array('mgn_cloud_account' => $ours));
nhr_provision($buyer, $m, array('cvp_instance_id' => '424242'));
check(NodeRemoval::machine_kind($m, 'tok') === NodeRemoval::MACHINE_PROVIDER, 'a machine in this plane\'s account is asked about');
$present = nhr_driver(array('id' => '424242', 'label' => 'nhr-box'));
check(strpos(NodeRemoval::refusal($m, '', $present, $no_dns, 'tok'), 'still exists') !== false, 'a machine the provider still has is refused');
check($present->asked === array('424242'), 'by the instance ID on record');
check(NodeRemoval::refusal($m, '', nhr_driver(new CloudComputeException('not found', 404)), $no_dns, 'tok') === '',
	'a machine the provider no longer has may go');
check(strpos(NodeRemoval::refusal($m, '', nhr_driver(new CloudComputeException('server error', 500)), $no_dns, 'tok'), 'Could not ask') !== false,
	'a provider error refuses');

$m_ip = nhr_node('machineip', array('mgn_cloud_account' => $ours, 'mgn_host' => '198.51.100.73'));
check(strpos(NodeRemoval::refusal($m_ip, '', nhr_driver(array(), array(array('id' => '7', 'label' => 'x', 'ipv4_public' => array('198.51.100.73'), 'ipv6' => ''))), $no_dns, 'tok'),
	'still has this machine\'s address') !== false, 'with no instance on record, a server holding its address refuses');
check(strpos(NodeRemoval::refusal($m_ip, '', nhr_driver(array(), array(array('id' => '8', 'label' => 'y', 'ipv4_public' => array('203.0.113.5'), 'ipv6' => '2001:db8::73'))), $no_dns, 'tok'),
	'still has') === false, 'one holding none of its addresses does not');
$m_ip6 = nhr_node('machineip6', array('mgn_cloud_account' => $ours, 'mgn_host' => '2001:db8:0:0::74'));
check(strpos(NodeRemoval::refusal($m_ip6, '', nhr_driver(array(), array(array('id' => '9', 'label' => 'z', 'ipv4_public' => array(), 'ipv6' => '2001:db8::74'))), $no_dns, 'tok'),
	'still has') !== false, 'an IPv6 address is compared as an address');
check(strpos(NodeRemoval::refusal($m_ip, '', nhr_driver(array(), new CloudComputeException('down', 0)), $no_dns, 'tok'), 'Could not list') !== false,
	'a listing that fails refuses');

$a = nhr_node('attest', array('mgn_cloud_account' => $other_ours));
nhr_provision($buyer, $a, array('cvp_instance_id' => '515151'));
check(NodeRemoval::machine_kind($a, 'tok') === NodeRemoval::MACHINE_ATTEST, 'a machine in the account this plane holds no token for is attested');
check(NodeRemoval::machine_kind(nhr_node('notoken', array('mgn_cloud_account' => $ours)), '') === NodeRemoval::MACHINE_ATTEST,
	'so is one in its own account when it holds no token at all');
check(NodeRemoval::attest_text($a) === '515151', 'the person types its instance ID');
check(strpos(NodeRemoval::refusal($a, '515150', null, $no_dns, 'tok'), 'Type the machine\'s instance ID') !== false, 'a wrong one is refused');
check(NodeRemoval::refusal($a, '515151', null, $no_dns, 'tok') === '', 'the right one passes');
$a_ip = nhr_node('attestip', array('mgn_cloud_account' => $other_ours, 'mgn_host' => '198.51.100.75'));
check(NodeRemoval::attest_text($a_ip, $no_dns) === '198.51.100.75', 'with no instance on record, its address');
check(NodeRemoval::attest_matches($a_ip, ' 198.51.100.75 ', $no_dns) && !NodeRemoval::attest_matches($a_ip, '198.51.100.76', $no_dns),
	'compared as an address');

$log = tempnam(sys_get_temp_dir(), 'nhr');
$was_log = ini_set('error_log', $log);
try {
	NodeRemoval::remove($a, '515151', (int)$buyer->key, null, $no_dns, 'tok');
} finally {
	ini_set('error_log', $was_log);
}
$logged = (string)file_get_contents($log);
@unlink($log);
check(strpos($logged, '[NODE_REMOVE_ATTEST]') !== false && strpos($logged, (string)$a->get('mgn_slug')) !== false
	&& strpos($logged, '515151') !== false && strpos($logged, 'user ' . (int)$buyer->key) !== false,
	'the attestation is logged with who, which node, and what was typed', $logged);
check(!nhr_exists('mgn_managed_nodes', 'mgn_managed_node_id', (int)$a->key), 'and the node is removed');

$refused = '';
try {
	NodeRemoval::remove($m, '', (int)$buyer->key, $present, $no_dns, 'tok');
} catch (DisplayableUserException $e) {
	$refused = $e->getMessage();
}
check($refused !== '' && nhr_exists('mgn_managed_nodes', 'mgn_managed_node_id', (int)$m->key), 'a refused removal removes nothing');

check(NodeRemoval::refusal($n_ext, '', null, $no_dns, 'tok') === '', 'a node hosted elsewhere has no machine guard');
check(NodeRemoval::refusal($n_cust, '', null, $no_dns, 'tok') === '', 'nor one on the customer\'s account');

// ---------------------------------------------------------------------------
section('The Jobs listing leaves out a hidden node\'s jobs');

$jl_live = nhr_node('jlive');
$jl_hidden = nhr_node('jhidden');
$jl_live_job = nhr_job((int)$jl_live->key);
$jl_hidden_job = nhr_job((int)$jl_hidden->key);
$jl_hidden->soft_delete();
$ids = function (array $opts) use ($jl_live, $jl_hidden) {
	$out = array();
	foreach (array((int)$jl_live->key, (int)$jl_hidden->key) as $node_id) {
		foreach (new MultiManagementJob($opts + array('deleted' => false, 'node_id' => $node_id)) as $j) {
			$out[] = (int)$j->key;
		}
	}
	sort($out);
	return $out;
};
check($ids(array('node_listed' => true)) === array($jl_live_job), 'listed nodes only: the hidden node\'s job is left out');
$both = array($jl_live_job, $jl_hidden_job);
sort($both);
check($ids(array()) === $both, 'shown all, it is there');

// ---------------------------------------------------------------------------
section('sm_018 clears what earlier permanent deletes left');

$migration = null;
foreach (require(PathHelper::getIncludePath('plugins/server_manager/migrations/migrations.php')) as $mg) {
	if ($mg['id'] === 'sm_018_node_records_cleanup') { $migration = $mg; }
}
check($migration !== null, 'the migration is declared');
$db->beginTransaction();
try {
	$orphan_job = nhr_job(null);
	$gone_node = (int)$db->query('SELECT COALESCE(MAX(mgn_managed_node_id), 0) + 1000 FROM mgn_managed_nodes')->fetchColumn();
	$stray_job = nhr_job($gone_node);
	$local_publish = nhr_job(null, 'completed', 'publish_upgrade');
	$gone = (int)$db->query('SELECT COALESCE(MAX(sps_storage_space_id), 0) + 1000 FROM sps_storage_spaces')->fetchColumn();
	$gone_run = (int)$db->query('SELECT COALESCE(MAX(svr_shelf_run_id), 0) + 1000 FROM svr_shelf_runs')->fetchColumn();
	$dangling = nhr_insert('svo_shelf_objects', 'svo_shelf_object_id', array('svo_sps_storage_space_id' => $gone, 'svo_key' => 'harness/nhr/gone'));
	$no_owner = nhr_insert('svo_shelf_objects', 'svo_shelf_object_id', array('svo_svr_shelf_run_id' => $gone_run, 'svo_key' => 'harness/nhr/norun'));
	$keep_space = nhr_space((int)$jl_live->key);
	$kept = nhr_insert('svo_shelf_objects', 'svo_shelf_object_id', array('svo_sps_storage_space_id' => $keep_space,
		'svo_svr_shelf_run_id' => $gone_run, 'svo_key' => 'harness/nhr/kept'));
	$sold = nhr_node('sold');
	nhr_provision($buyer, $sold, array('cvp_hosting_mode' => 'transferred'));
	$decom = nhr_node('decom', array('mgn_container_name' => 'nhrdecom'));
	$dj = nhr_job((int)$jl_live->key, 'completed', 'decommission_node');
	$db->prepare('UPDATE mjb_management_jobs SET mjb_parameters = ?, mjb_result = ?, mjb_completed_time = now() WHERE mjb_management_job_id = ?')
		->execute(array(json_encode(array('victim_node_id' => (int)$decom->key)), json_encode(array('decommissioned' => true)), $dj));

	check($migration['up'](DbConnector::get_instance()) !== 'defer', 'it runs');
	check(!nhr_exists('mjb_management_jobs', 'mjb_management_job_id', $orphan_job), 'a job that names no node is deleted');
	check(!nhr_exists('mjb_management_jobs', 'mjb_management_job_id', $stray_job), 'so is one naming a node that no longer exists');
	check(nhr_exists('mjb_management_jobs', 'mjb_management_job_id', $local_publish), 'a Publish Upgrade run that never had one is kept');
	check(!nhr_exists('svo_shelf_objects', 'svo_shelf_object_id', $dangling) && !nhr_exists('svo_shelf_objects', 'svo_shelf_object_id', $no_owner),
		'a ledger row of a space that is gone is deleted, and one with no space whose run is gone');
	check(nhr_exists('svo_shelf_objects', 'svo_shelf_object_id', $kept)
		&& (int)(new ShelfObject($kept, TRUE))->get('svo_svr_shelf_run_id') === 0, 'one in a live space stays, and stops naming the run');
	check((string)(new ManagedNode($sold->key, TRUE))->get('mgn_cloud_account') === CloudAccounts::CUSTOMER,
		'a node whose machine was handed to the customer is hosted on the customer\'s account');
	check((string)(new ManagedNode($decom->key, TRUE))->get('mgn_site_removed_time') !== '', 'a site its host verified gone is marked so');

	$count = function () use ($db) {
		return $db->query('SELECT (SELECT count(*) FROM mjb_management_jobs) || \'/\' || (SELECT count(*) FROM svo_shelf_objects)')->fetchColumn();
	};
	$before = $count();
	$migration['up'](DbConnector::get_instance());
	check($count() === $before, 'a second run changes nothing');
} finally {
	$db->rollBack();
}

harness_finish();

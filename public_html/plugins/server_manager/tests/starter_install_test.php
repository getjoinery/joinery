<?php
/** @joinery-test
 * name: starter_install
 * tier: db
 * env: any
 * needs: []
 */
/**
 * A paid Starter site becomes a running site in a container on a starter host
 * (specs/starter_tier.md, Launch scope step 3): ProvisionStarterSites places
 * it and queues site_create on the host's own agent; the result handler
 * finishes it.
 *
 *  - **The job goes to the HOST's agent**, naming the site's container, port,
 *    release and the starter limits, and only to an agent that has the word.
 *  - **The admin password is the one sealed on the provision**, minted before
 *    the job exists, and it is blanked from the job once the host answers.
 *  - **No starter host with room leaves the paid row waiting**, said once on
 *    the board and alerted once, never failed.
 *  - **An installed site is done**: its node leaves the installing state and
 *    awaits its certificate, and the site agent's key is kept for its join.
 *    A failed install fails the provision and alerts.
 *
 * Everything runs inside one transaction that is rolled back.
 *
 * Run: php plugins/server_manager/tests/starter_install_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_hosts_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionStarterSites.php'));

class SiStarterSites extends ProvisionStarterSites {
	public static $alerts = array();
	protected function alert(CustomerCloudProvision $provision, string $reason): void {
		self::$alerts[] = $reason;
	}
}

$db = DbConnector::get_instance()->get_db_link();
$db->beginTransaction();
$suffix = substr(bin2hex(random_bytes(4)), 0, 8);

harness_set_setting_mem('server_manager_starter_memory', '384m');
harness_set_setting_mem('server_manager_starter_cpus', '1');
harness_set_setting_mem('server_manager_starter_disk', '5G');
$db->exec("UPDATE mgh_managed_hosts SET mgh_provisioning_enabled = false");

function si_node($slug, array $extra = array()) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest starter install ' . $slug);
	$node->set('mgn_slug', $slug);
	$node->set('mgn_host', '203.0.113.' . random_int(2, 250));
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_uptime_enabled', false);
	$node->set('mgn_agent_public_key', 'si-agent-' . $slug);
	$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
	foreach ($extra as $k => $v) { $node->set($k, $v); }
	$node->save();
	$node->load();
	return $node;
}

function si_provision($slug) {
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_origin', 'admin');
	$p->set('cvp_usr_user_id', 990000 + random_int(0, 9999));
	$p->set('cvp_domain', $slug . '.example.com');
	$p->set('cvp_slug', $slug);
	$p->set('cvp_sitename', str_replace('-', '_', $slug));
	$p->set('cvp_hosting_mode', 'operator');
	$p->set('cvp_landing', 'container');
	$p->set('cvp_buyer_email', 'owner@example.com');
	$p->set('cvp_buyer_name', 'Owner Person');
	$p->set('cvp_status', 'ready');
	$p->save();
	$p->load();
	return $p;
}

function si_job($id) {
	$j = new ManagementJob($id, TRUE);
	return $j;
}

// ---------------------------------------------------------------------------
section('The builder: a live site, a password slot, only to a host agent that has the word');

$good = array('name' => 'st1', 'domain' => 'a.example.com', 'port' => 9101, 'release' => '0.8.599',
	'admin_email' => 'o@example.com', 'provision_id' => 77, 'memory' => '256m', 'cpus' => '1', 'disk' => '5G');
$built = JobCommandBuilder::build_site_create_primitive($good);
check($built['primitive'] === 'site_create' && $built['params']['disk'] === '5G' && !isset($built['params']['copy_of']),
	'a site_create envelope with the limits and no copy', json_encode($built['params']));
check($built['params']['admin_password'] === '__SM_ADMIN_PASSWORD_77__',
	'the job carries a slot naming the provision, never a password');
foreach (array(
	array('provision_id' => 0), array('admin_email' => 'o@example.com --wipe-data'), array('cpus' => 'all'),
	array('disk' => 'none'), array('memory' => ''), array('cpus' => ''), array('disk' => ''),
	array('release' => 'latest'), array('port' => 80), array('name' => 'Bad Name'),
) as $bad) {
	$threw = null;
	try { JobCommandBuilder::build_site_create_primitive(array_merge($good, $bad)); } catch (Exception $e) { $threw = $e->getMessage(); }
	check($threw !== null, 'refused before it reaches the wire: ' . json_encode($bad), (string)$threw);
}
$old_host_agent = si_node('si-oldhost-' . $suffix, array('mgn_agent_primitives' => 'hold_container,site_install'));
$threw = null;
try { JobCommandBuilder::build_site_create($old_host_agent, array()); } catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== null && strpos($threw, 'cannot install a new site') !== false,
	'a host agent without the word is refused with the reason', (string)$threw);

// ---------------------------------------------------------------------------
section('No starter host with room: the paid row waits, said once');

$waiting = si_provision('si-wait-' . $suffix);
$worker = new SiStarterSites();
SiStarterSites::$alerts = array();
$worker->run(array());
$worker->run(array());
$waiting->load();
check((string)$waiting->get('cvp_status') === 'ready', 'it stays ready, not failed');
check((string)$waiting->get('cvp_error') === ProvisionStarterSites::NO_ROOM, 'the board says why');
check(count(SiStarterSites::$alerts) === 1, 'and a person is alerted once, not every tick', json_encode(SiStarterSites::$alerts));
$waiting->soft_delete();

// ---------------------------------------------------------------------------
section('A host agent too old for the word: the paid row waits, nothing is taken');

$old_host = new ManagedHost(NULL);
$old_host->set('mgh_slug', 'si-oldhost-' . $suffix);
$old_host->set('mgh_name', 'HarnessTest old starter host ' . $suffix);
$old_host->set('mgh_host', '198.51.100.' . random_int(2, 250));
$old_host->set('mgh_tier', 'starter');
$old_host->set('mgh_max_sites', 8);
$old_host->set('mgh_provisioning_enabled', true);
$old_host->set('mgh_mgn_managed_node_id', $old_host_agent->key);
$old_host->save();
$old_host->load();
$old_wait = si_provision('si-oldwait-' . $suffix);
SiStarterSites::$alerts = array();
$worker->run(array());
$worker->run(array());
$old_wait->load();
check((string)$old_wait->get('cvp_status') === 'ready' && !(int)$old_wait->get('cvp_mgn_managed_node_id'),
	'it stays ready, and no node or port is taken on the host', (string)$old_wait->get('cvp_error'));
check(count(SiStarterSites::$alerts) === 1 && strpos((string)$old_wait->get('cvp_error'), 'cannot install a new site') !== false,
	'the board and one alert say the host\'s agent needs upgrading');
$old_wait->soft_delete();
$old_host->set('mgh_provisioning_enabled', false);
$old_host->save();

// ---------------------------------------------------------------------------
section('Places: the host\'s own agent is not a site, and a paid site reserved on it is one');

$host_agent = si_node('si-hostagent-' . $suffix, array('mgn_agent_primitives' => 'hold_container,site_create'));
$host = new ManagedHost(NULL);
$host->set('mgh_slug', 'si-host-' . $suffix);
$host->set('mgh_name', 'HarnessTest starter host ' . $suffix);
$host->set('mgh_host', '198.51.100.' . random_int(2, 250));
$host->set('mgh_tier', 'starter');
$host->set('mgh_max_sites', 1);
$host->set('mgh_provisioning_enabled', true);
$host->set('mgh_mgn_managed_node_id', $host_agent->key);
$host->save();
$host->load();
$host_agent->set('mgn_mgh_managed_host_id', $host->key);
$host_agent->save();
check(ManagedHost::pick_for_provisioning('starter') !== null,
	'a host with Max Sites 1 and only its own agent placed on it still has its one place');
$reserved = si_provision('si-reserved-' . $suffix);
$reserved->set('cvp_mgh_managed_host_id', $host->key);
$reserved->save();
check(ManagedHost::pick_for_provisioning('starter') === null,
	'a paid site reserved on it, with no node yet, takes that place');
$threw = null;
try {
	$h = new ManagedHost(NULL);
	$h->set('mgh_slug', 'si-hostname-' . $suffix); $h->set('mgh_name', 'x'); $h->set('mgh_host', 'box.example.com');
	$h->set('mgh_tier', 'starter'); $h->prepare(); $h->save();
} catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== null && stripos($threw, 'IPv4') !== false, 'a starter host is refused a hostname for its address', (string)$threw);
$host->set('mgh_max_sites', 8);
$host->save();

// ---------------------------------------------------------------------------
section('Placed on its reserved host, named by the plane, installed by the host\'s agent');

$p = $reserved;
$p->set('cvp_sitename', 'www');   // what a buyer could have posted: never the container's name
$p->save();
SiStarterSites::$alerts = array();
$worker->run(array());
$p->load();
check((string)$p->get('cvp_status') === 'installing', 'the row is installing', (string)$p->get('cvp_error'));
check((int)$p->get('cvp_mgh_managed_host_id') === (int)$host->key, 'on the host it was reserved on');
check($p->is_container() && trim((string)$p->get('cvp_instance_id')) === '', 'still a container site with no instance id');
$node = new ManagedNode((int)$p->get('cvp_mgn_managed_node_id'), TRUE);
$name = ProvisionStarterSites::container_name($p);
check($name === 'st' . (int)$p->key && (string)$node->get('mgn_container_name') === $name
		&& (string)$p->get('cvp_sitename') === $name,
	'the container and site name are the plane\'s (st<provision id>), not the buyer\'s', (string)$node->get('mgn_container_name'));
check($node->key && (int)$node->get('mgn_mgh_managed_host_id') === (int)$host->key
		&& (string)$node->get('mgn_host') === (string)$host->get('mgh_host')
		&& (int)$node->get('mgn_port') >= 1024 && (string)$node->get('mgn_install_state') === 'installing',
	'its node is a container on that host, at the host\'s address, with a port of its own');
check($p->admin_password_state() === 'sealed', 'the admin password was minted and sealed on the provision', $p->admin_password_state());
$sealed_pw = (string)((new SecretBox())->open((string)$p->get('cvp_admin_pass_sealed'))['value'] ?? '');

$job = si_job((int)$p->get('cvp_install_job_id'));
check($job->key && (string)$job->get('mjb_job_type') === 'site_create'
		&& (int)$job->get('mjb_mgn_managed_node_id') === (int)$host_agent->key,
	'one site_create job, addressed to the HOST\'s agent');
$cmd = json_decode((string)$job->get('mjb_commands'), true);
check(strpos((string)$job->get('mjb_commands'), $sealed_pw) === false && strpos((string)$job->get('mjb_parameters'), $sealed_pw) === false
		&& ($cmd['params']['admin_password'] ?? '') === '__SM_ADMIN_PASSWORD_' . (int)$p->key . '__',
	'the job row never holds the password, only the slot');
check(($cmd['params']['memory'] ?? '') === '384m' && ($cmd['params']['cpus'] ?? '') === '1' && ($cmd['params']['disk'] ?? '') === '5G',
	'with the starter limits: 8 sites to a Linode 4 GB', json_encode(array_diff_key($cmd['params'] ?? array(), array('admin_password' => 1))));

// The hand-out opens the slot for this job alone.
$resolve = (new ReflectionClass('AgentChannelEndpoint'))->getMethod('resolve_credential_slots');
$handed = $resolve->invoke(null, $cmd['params'], $job);
check(($handed['admin_password'] ?? '') === $sealed_pw && $sealed_pw !== '', 'the hand-out opens the slot into the sealed password');
$other = ManagementJob::createFromBuild($host_agent->key, 'site_create', $built, array('provision_id' => (int)$p->key), null);
$threw = null;
try { $resolve->invoke(null, $cmd['params'], $other); } catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== null, 'and refuses it to any other job, even one naming the same provision', (string)$threw);
$other->set('mjb_status', 'cancelled');
$other->save();

$worker->run(array());
$p->load();
check((string)$p->get('cvp_status') === 'installing', 'while the host has not answered, nothing moves');

// ---------------------------------------------------------------------------
section('The host answers: done, and the agent key kept');

$job->set('mjb_status', 'completed');
$job->set('mjb_output', json_encode(array('api_version' => 1, 'data' => array('output' => "building...\nSITE_AGENT_KEY=0123456789abcdef\ndone\n"))));
$job->save();
$worker->run(array());
$p->load();
$node->load();
check((string)$p->get('cvp_status') === 'done', 'the provision is done', (string)$p->get('cvp_error'));
check($node->get('mgn_install_state') === null && (string)$node->get('mgn_ssl_state') === 'pending',
	'the node is installed and awaits its certificate');
check((string)$p->get('cvp_expected_site_key') === '0123456789abcdef', 'the site agent\'s key is kept for its join');
check($p->admin_password_state() === 'sealed', 'the buyer\'s copy is still on the provision, to be shown once');
check(ManagedHost::pick_for_provisioning('starter') !== null, 'the host still has room for its other seven');

// ---------------------------------------------------------------------------
section('A cancelled install fails the provision; Retry runs it again on the same node');

$f = si_provision('si-fail-' . $suffix);
$worker->run(array());
$f->load();
$fnode_id = (int)$f->get('cvp_mgn_managed_node_id');
$fjob = si_job((int)$f->get('cvp_install_job_id'));
$fjob->set('mjb_status', 'cancelled');
$fjob->save();
SiStarterSites::$alerts = array();
$worker->run(array());
$f->load();
$fnode = new ManagedNode($fnode_id, TRUE);
check((string)$f->get('cvp_status') === 'failed', 'a cancelled install job fails the provision, never leaves it installing');
check((string)$fnode->get('mgn_install_state') === 'install_failed', 'its node says the install failed');
check(count(SiStarterSites::$alerts) === 1, 'a person is alerted', json_encode(SiStarterSites::$alerts));

ProvisionStarterSites::retry($f);
$worker->run(array());
$f->load();
$rjob = si_job((int)$f->get('cvp_install_job_id'));
check((string)$f->get('cvp_status') === 'installing' && (int)$f->get('cvp_mgn_managed_node_id') === $fnode_id
		&& $rjob->key && (int)$rjob->key !== (int)$fjob->key,
	'Retry installs it again on the same node, with a new job');
$threw = null;
try { ProvisionStarterSites::retry($p); } catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== null, 'a site that is not failed is never retried');

// ---------------------------------------------------------------------------
section('A reserved host that stopped taking sites gives the place up');

// $old_host has Provisioning off (an operator draining it). A paid site
// reserved there, with nothing made yet, goes where there is room now.
$moved = si_provision('si-moved-' . $suffix);
$moved->set('cvp_mgh_managed_host_id', $old_host->key);
$moved->save();
$worker->run(array());
$moved->load();
check((int)$moved->get('cvp_mgh_managed_host_id') === (int)$host->key && (string)$moved->get('cvp_status') === 'installing',
	'a site reserved on a host whose Provisioning was switched off is installed on a host that takes sites',
	$moved->get('cvp_mgh_managed_host_id') . ' ' . $moved->get('cvp_status') . ' ' . $moved->get('cvp_error'));
$moved_node = new ManagedNode((int)$moved->get('cvp_mgn_managed_node_id'), TRUE);
check((int)$moved_node->get('mgn_mgh_managed_host_id') === (int)$host->key, 'and its node is on that host');

// ---------------------------------------------------------------------------
section('A host\'s tier does not change under its sites');

$threw = null;
try { $host->set('mgh_tier', 'general'); $host->prepare(); } catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== null && strpos($threw, 'tier cannot change') !== false,
	'a starter host holding sites is refused a change to general', (string)$threw);
$host->set('mgh_tier', 'starter');
$threw = null;
try { $old_host->set('mgh_tier', 'general'); $old_host->prepare(); } catch (Exception $e) { $threw = $e->getMessage(); }
check($threw === null, 'an empty host\'s tier can change', (string)$threw);
$old_host->set('mgh_tier', 'starter');
$threw = null;
try {
	$h6 = new ManagedHost(NULL);
	$h6->set('mgh_slug', 'si-v6-' . $suffix); $h6->set('mgh_name', 'x'); $h6->set('mgh_host', '2001:db8::5');
	$h6->set('mgh_tier', 'starter'); $h6->prepare();
} catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== null && strpos($threw, 'IPv6') !== false, 'a starter host is refused an IPv6 address, and told so', (string)$threw);

// ---------------------------------------------------------------------------
section('A starter host made from Install New Node becomes a starter host record');

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionCustomerCloud.php'));
$sh_node = si_node('si-shnode-' . $suffix, array('mgn_host' => '192.0.2.' . random_int(2, 250), 'mgn_cloud_account' => 'test'));
$sh_prov = new CustomerCloudProvision(NULL);
$sh_prov->set('cvp_origin', 'admin');
$sh_prov->set('cvp_usr_user_id', 990000 + random_int(0, 9999));
$sh_prov->set('cvp_domain', 'si-sh-' . $suffix . '.example.com');
$sh_prov->set('cvp_slug', 'si-sh-' . $suffix);
$sh_prov->set('cvp_hosting_mode', 'operator');
$sh_prov->set('cvp_install_mode', 'bare');
$sh_prov->set('cvp_starter_host', true);
$sh_prov->save();
$sh = ProvisionCustomerCloud::make_starter_host($sh_prov, $sh_node);
$sh_node->load();
check($sh && (string)$sh->get('mgh_tier') === 'starter' && (int)$sh->get('mgh_max_sites') === 8
		&& (string)$sh->get('mgh_host') === (string)$sh_node->get('mgn_host'),
	'its host record is a starter host at the machine\'s IPv4, with eight places');
check($sh && (int)$sh->get('mgh_mgn_managed_node_id') === (int)$sh_node->key && !$sh->get('mgh_provisioning_enabled'),
	'its own node is the host\'s agent, and it takes no sites until an operator turns provisioning on');
$threw = null;
try {
	$bad = new CustomerCloudProvision(NULL);
	$bad->set('cvp_origin', 'admin'); $bad->set('cvp_usr_user_id', 990001);
	$bad->set('cvp_domain', 'si-shbad-' . $suffix . '.example.com'); $bad->set('cvp_slug', 'si-shbad-' . $suffix);
	$bad->set('cvp_starter_host', true); $bad->save();
} catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== null, 'a starter host with a site of its own is refused', (string)$threw);

$db->rollBack();
harness_finish();

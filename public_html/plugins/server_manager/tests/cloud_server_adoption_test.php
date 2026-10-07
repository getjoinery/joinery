<?php
/** @joinery-test
 * name: cloud_server_adoption
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Adopt cloud server (specs/test_cloud_account_and_prod_management.md WP9).
 *
 * CloudServerAdoption against fake accounts and a fake resolver: a node's one
 * server is found by its own host (IPv4 or IPv6), else by its join's report;
 * a host match beats a join match; two servers at the strongest match, a
 * container site, a node that already has a record, and a server another
 * node's record holds are each refused; a different answer than the one the
 * operator was shown adopts nothing. An adoption writes an admin 'adopted'
 * provision that reverse DNS and the IP swap then find, on the operator
 * account or a connected one.
 *
 * Run: php plugins/server_manager/tests/cloud_server_adoption_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

/** A provider account with fixed servers. */
class FakeAdoptionAccount implements CloudMachineTransfer {
	public $instances;
	function __construct(array $instances) { $this->instances = $instances; }
	public function listInstances(): array { return $this->instances; }
	public function getInstanceTransfer(string $instance_id): array { return array(); }
}

$suffix = bin2hex(random_bytes(3));
$make_node = function (string $name, string $host, array $extra = array()) use ($suffix) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest ' . $name);
	$n->set('mgn_slug', 'harnesstest-adopt-' . strtolower($name) . '-' . $suffix);
	$n->set('mgn_host', $host);
	$n->set('mgn_site_url', 'https://' . strtolower($name) . '-' . $suffix . '.example.invalid');
	$n->set('mgn_ssh_user', 'root');
	$n->set('mgn_enabled', true);
	foreach ($extra as $k => $v) {
		$n->set($k, $v);
	}
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$server = function (string $id, string $label, string $v4, string $v6 = '') {
	return array('id' => $id, 'label' => $label, 'region' => 'us-east', 'status' => 'running',
		'ipv4_public' => array($v4), 'ipv6' => $v6, 'created' => '2026-01-01 00:00:00', 'tags' => array());
};
$no_dns = function ($name) { return array(); };
$registered = array();
$register_provisions = function () use (&$registered) {
	foreach (new MultiCustomerCloudProvision(array('deleted' => false)) as $p) {
		if (strpos((string)$p->get('cvp_slug'), 'harnesstest-adopt-') === 0 && !isset($registered[(int)$p->key])) {
			$registered[(int)$p->key] = true;
			harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $p->key);
		}
	}
};
$user = make_user('adopt' . $suffix, 10);
$uid = (int)$user->key;

$solo   = $make_node('Solo', '192.0.2.31');
$v6node = $make_node('Vsix', '2001:db8::32');
$joined = $make_node('Joined', 'joined.example.invalid');
$twice  = $make_node('Twice', '192.0.2.34');
$boxed  = $make_node('Boxed', '192.0.2.35', array('mgn_container_name' => 'boxed'));

// The joined node's address is known only from its agent's join.
$join = new AgentJoinRequest(NULL);
$join->set('ajr_claimed_name', 'harness-adopt-' . $suffix);
$join->set('ajr_public_key', substr(hash('sha256', 'adopt' . $suffix), 0, 64));
$join->set('ajr_fingerprint', substr($suffix . '0000000000', 0, 16));
$join->set('ajr_source_ip', '192.0.2.33');
$join->set('ajr_status', AgentJoinRequest::STATUS_APPROVED);
$join->set('ajr_mgn_managed_node_id', (int)$joined->key);
$join->save();
$join->load();
harness_register_row('ajr_agent_join_requests', 'ajr_agent_join_request_id', $join->key);

$operator = new FakeAdoptionAccount(array(
	$server('a' . $suffix, 'solo-server', '192.0.2.31'),
	$server('b' . $suffix, 'v6-server', '192.0.2.132', '2001:db8::32'),
	$server('c' . $suffix, 'joined-server', '192.0.2.33'),
	$server('d' . $suffix, 'twice-one', '192.0.2.34'),
));
$connected = new FakeAdoptionAccount(array(
	$server('e' . $suffix, 'twice-two', '192.0.2.34'),
	$server('f' . $suffix, 'boxed-host', '192.0.2.35'),
));
$accounts = array('operator' => $operator, 'cca:424242' => $connected);

// ---------------------------------------------------------------------------
section('Finding the one server');

$m = CloudServerAdoption::find($solo, $accounts, $no_dns);
check($m['ok'] && $m['instance']['id'] === 'a' . $suffix && $m['account'] === 'operator', 'a node is matched by its host\'s IPv4');
$m = CloudServerAdoption::find($v6node, $accounts, $no_dns);
check($m['ok'] && $m['instance']['id'] === 'b' . $suffix, 'a node is matched by its host\'s IPv6');
$m = CloudServerAdoption::find($joined, $accounts, $no_dns);
check($m['ok'] && $m['instance']['id'] === 'c' . $suffix, 'a node whose host does not resolve is matched by its join\'s address');
$m = CloudServerAdoption::find($twice, $accounts, $no_dns);
check(!$m['ok'] && strpos($m['reason'], 'More than one server') !== false && strpos($m['reason'], 'twice-two') !== false,
	'two servers at the address are refused, each named: ' . $m['reason']);
$m = CloudServerAdoption::find($boxed, $accounts, $no_dns);
check(!$m['ok'] && strpos($m['reason'], 'container') !== false, 'a container site is refused');
$m = CloudServerAdoption::find($solo, array(), $no_dns);
check(!$m['ok'] && strpos($m['reason'], 'holds none') !== false, 'no accounts: says so');

// A host match beats a join match: the joined node's join also names the solo server's address.
$join->set('ajr_addresses', '192.0.2.31');
$join->save();
$joined->set('mgn_host', '192.0.2.33');
$joined->save();
$m = CloudServerAdoption::find($joined, $accounts, $no_dns);
check($m['ok'] && $m['instance']['id'] === 'c' . $suffix, 'the host\'s own match wins over an address the join also reported');

// ---------------------------------------------------------------------------
section('Adopting');

$threw = '';
try {
	CloudServerAdoption::adopt($solo, $uid, 'not-this-one', $accounts, $no_dns);
} catch (CloudServerAdoptionException $e) {
	$threw = $e->getMessage();
}
check(strpos($threw, 'is not the one shown') !== false, 'a different server than the one shown adopts nothing');
check(CloudServerAdoption::existing($solo) === null, 'and writes no record');

$p = CloudServerAdoption::adopt($solo, $uid, 'a' . $suffix, $accounts, $no_dns);
$register_provisions();
check($p->key && $p->get('cvp_install_mode') === 'adopted' && $p->get('cvp_origin') === 'admin'
	&& $p->get('cvp_status') === 'done' && $p->get('cvp_hosting_mode') === 'operator'
	&& $p->get('cvp_instance_id') === 'a' . $suffix && $p->get('cvp_instance_ip') === '192.0.2.31'
	&& (int)$p->get('cvp_mgn_managed_node_id') === (int)$solo->key && $p->get('cvp_docker_mode') === 'bare-metal',
	'the record: admin, adopted, done, operator-hosted, the instance and its address, linked to the node');
check(!$p->is_sold(), 'an adopted server is never one somebody bought');
check(NodeReverseDns::provisionForNode($solo) !== null && (int)NodeReverseDns::provisionForNode($solo)->key === (int)$p->key,
	'reverse DNS finds it');
check(IpSwapMove::provision_of($solo) !== null, 'the IP swap finds it');
check(CloudServerAdoption::refusals($solo) !== array(), 'a node with a record cannot adopt again');

$p6 = CloudServerAdoption::adopt($v6node, $uid, '', $accounts, $no_dns);
$register_provisions();
check($p6->get('cvp_instance_ipv6') === '2001:db8::32' && $p6->get('cvp_instance_ip') === '192.0.2.132',
	'a server found by IPv6 records both its addresses');

// A connected account's server.
$other = $make_node('Other', '192.0.2.36');
$connected->instances[] = $server('g' . $suffix, 'other-server', '192.0.2.36');
$pc = CloudServerAdoption::adopt($other, $uid, '', $accounts, $no_dns);
$register_provisions();
check($pc->get('cvp_hosting_mode') === 'customer' && (int)$pc->get('cvp_cca_customer_cloud_account_id') === 424242,
	'a connected account\'s server is recorded against that account');

// A server another node's record already holds.
$dupe = $make_node('Dupe', '192.0.2.31');
$solo->set('mgn_host', '198.51.100.1');
$solo->save();
$threw = '';
try {
	CloudServerAdoption::adopt($dupe, $uid, '', $accounts, $no_dns);
} catch (CloudServerAdoptionException $e) {
	$threw = $e->getMessage();
}
check(strpos($threw, 'already records the server') !== false, 'a server another node\'s record holds is refused: ' . $threw);

// ---------------------------------------------------------------------------
section('The record rules');

$bad = new CustomerCloudProvision(NULL);
$bad->set('cvp_origin', 'order');
$bad->set('cvp_external_order_item_id', 1);
$bad->set('cvp_usr_user_id', $uid);
$bad->set('cvp_domain', 'x.example.invalid');
$bad->set('cvp_slug', 'harnesstest-adopt-bad');
$bad->set('cvp_docker_mode', 'bare-metal');
$bad->set('cvp_install_mode', 'adopted');
$threw = '';
try {
	$bad->save();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $bad->key);
} catch (CustomerCloudProvisionException $e) {
	$threw = $e->getMessage();
}
check(strpos($threw, 'admin-origin') !== false, 'an adopted record made by anything but an admin is refused');

harness_finish();

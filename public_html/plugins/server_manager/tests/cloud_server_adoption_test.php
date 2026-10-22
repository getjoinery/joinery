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
 * provision that reverse DNS and the IP swap then find, naming the account of
 * ours it is in (oca:<id>, a second one of ours included) or a connected one.
 *
 * Run: php plugins/server_manager/tests/cloud_server_adoption_test.php
 *
 * @version 1.2 - the accounts are real rows; a server in a Hetzner account of ours is recorded as Hetzner's (B40)
 * @version 1.1 - accounts of ours are keyed oca:<id>; a server in a second account of ours names it
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
// A second account of ours (the cloud_accounts_list spec), at Hetzner: a server
// there names it, and its provider.
$second = new FakeAdoptionAccount(array());
// The accounts the servers are in: the record names each one's provider.
$make_oca = function (string $provider) use ($suffix) {
	$oca = new OperatorCloudAccount(NULL);
	$oca->set('oca_provider', $provider);
	$oca->set('oca_method', OperatorCloudAccount::METHOD_KEY);
	$oca->set('oca_account_name', 'HarnessTest adopt ' . $provider . ' ' . $suffix);
	$oca->set('oca_is_test', true);
	$oca->store_credential('harness-adopt-token');
	$oca->save();
	$oca->load();
	harness_register_row('oca_operator_cloud_accounts', 'oca_operator_cloud_account_id', $oca->key);
	return $oca;
};
$oca_linode = $make_oca('linode');
$oca_hetzner = $make_oca('hetzner');
$cca = new CustomerCloudAccount(NULL);
$cca->set('cca_usr_user_id', $uid);
$cca->set('cca_provider', 'linode');
$cca->set('cca_status', 'active');
$cca->set('cca_account_name', 'HarnessTest adopt connected ' . $suffix);
$cca->storeToken(new OAuth2Token('harness-adopt-cust', null, gmdate('Y-m-d H:i:s', time() + 7200), 'linodes:read_write'));
$cca->save();
$cca->load();
harness_register_row('cca_customer_cloud_accounts', 'cca_customer_cloud_account_id', $cca->key);
$k_operator = 'oca:' . (int)$oca_linode->key;
$k_second = 'oca:' . (int)$oca_hetzner->key;
$k_connected = 'cca:' . (int)$cca->key;
$accounts = array($k_operator => $operator, $k_second => $second, $k_connected => $connected);

// ---------------------------------------------------------------------------
section('Finding the one server');

$m = CloudServerAdoption::find($solo, $accounts, $no_dns);
check($m['ok'] && $m['instance']['id'] === 'a' . $suffix && $m['account'] === $k_operator, 'a node is matched by its host\'s IPv4');
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
	&& (int)$p->get('cvp_oca_operator_cloud_account_id') === (int)$oca_linode->key && $p->get('cvp_provider') === 'linode'
	&& $p->get('cvp_instance_id') === 'a' . $suffix && $p->get('cvp_instance_ip') === '192.0.2.31'
	&& (int)$p->get('cvp_mgn_managed_node_id') === (int)$solo->key && $p->get('cvp_docker_mode') === 'bare-metal',
	'the record: admin, adopted, done, in our Linode account, the instance and its address, linked to the node');
check(!$p->is_sold(), 'an adopted server is never one somebody bought');
check(NodeReverseDns::provisionForNode($solo) !== null && (int)NodeReverseDns::provisionForNode($solo)->key === (int)$p->key,
	'reverse DNS finds it');
check(IpSwapMove::provision_of($solo) !== null, 'the IP swap finds it');
check(CloudServerAdoption::refusals($solo) !== array(), 'a node with a record cannot adopt again');

$p6 = CloudServerAdoption::adopt($v6node, $uid, '', $accounts, $no_dns);
$register_provisions();
check($p6->get('cvp_instance_ipv6') === '2001:db8::32' && $p6->get('cvp_instance_ip') === '192.0.2.132',
	'a server found by IPv6 records both its addresses');

// A server in the second account of ours.
$in_second = $make_node('Second', '192.0.2.37');
$second->instances[] = $server('h' . $suffix, 'second-server', '192.0.2.37');
$ps = CloudServerAdoption::adopt($in_second, $uid, '', $accounts, $no_dns);
$register_provisions();
check($ps->get('cvp_hosting_mode') === 'operator' && (int)$ps->get('cvp_oca_operator_cloud_account_id') === (int)$oca_hetzner->key
	&& !(int)$ps->get('cvp_cca_customer_cloud_account_id'),
	'a server in the second account of ours names that account, and no connected one');
check($ps->get('cvp_provider') === 'hetzner' && IpSwapMove::provision_of($in_second) !== null,
	'a server in a Hetzner account of ours is recorded as Hetzner\'s, so its IP swap and reverse DNS reach Hetzner (B40)', (string)$ps->get('cvp_provider'));

// A connected account's server.
$other = $make_node('Other', '192.0.2.36');
$connected->instances[] = $server('g' . $suffix, 'other-server', '192.0.2.36');
$pc = CloudServerAdoption::adopt($other, $uid, '', $accounts, $no_dns);
$register_provisions();
check($pc->get('cvp_hosting_mode') === 'customer' && (int)$pc->get('cvp_cca_customer_cloud_account_id') === (int)$cca->key
	&& $pc->get('cvp_provider') === 'linode'
	&& !(int)$pc->get('cvp_oca_operator_cloud_account_id'),
	'a connected account\'s server is recorded against that account');

// An account that names no provider this plane has a driver for.
$unknown = $make_node('Unknown', '192.0.2.38');
$threw = '';
try {
	CloudServerAdoption::adopt($unknown, $uid, '', array('oca:0' => new FakeAdoptionAccount(array(
		$server('u' . $suffix, 'unknown-server', '192.0.2.38')))), $no_dns);
} catch (CloudServerAdoptionException $e) {
	$threw = $e->getMessage();
}
check(strpos($threw, 'no driver for') !== false && CloudServerAdoption::existing($unknown) === null,
	'a server in an account whose provider is not known here adopts nothing, never a guessed provider: ' . $threw);

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

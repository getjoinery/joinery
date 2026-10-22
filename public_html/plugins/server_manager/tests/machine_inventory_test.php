<?php
/** @joinery-test
 * name: machine_inventory
 * tier: db
 * env: dev-only
 * needs: []
 * covers: [plugins/server_manager/includes/MachineInventory.php, plugins/server_manager/data/fleet_hand_machines_class.php]
 */
/**
 * Every machine we pay for, on one list (specs/fleet_security_patching.md
 * WP8). rows() from arrays: a managed machine, a listed server no node runs
 * on, a server a node runs on, and machines written down by hand, each with
 * its verdict. Then one hand row saved and read back.
 *
 * Run: php plugins/server_manager/tests/machine_inventory_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$NOW = strtotime('2026-10-22T14:00:00Z');

section('Ubuntu releases and their support');

check(MachineInventory::release_of('Ubuntu 24.04.4') === '24.04' && MachineInventory::release_of('linode/ubuntu20.04') === '20.04'
		&& MachineInventory::release_of('ubuntu-26.04') === '26.04' && MachineInventory::release_of('debian-12') === null,
	'a release from a report, a Linode image and a Hetzner image; none from another system');
check(MachineInventory::past_support('20.04', $NOW) === true && MachineInventory::past_support('24.04', $NOW) === false
		&& MachineInventory::past_support('25.04', $NOW) === true && MachineInventory::past_support('19.10', $NOW) === null,
	'20.04 and 25.04 are past standard support on 2026-10-22, 24.04 is not, and a release not in the table is unknown');

section('The rows');

$hand = function (array $over) {
	return array_merge(array('id' => 1, 'name' => 'h', 'address' => '', 'where' => '', 'instance_ref' => '', 'purpose' => '', 'owner' => '',
		'os' => '', 'serves' => '', 'retire_by' => '', 'confirmed' => '2026-10-01 00:00:00'), $over);
};
$addr = function ($ip) { return array('host' => array(IpAddress::binary($ip)), 'join' => array(), 'is_host' => false); };
$snap = array(
	'nodes' => array(
		7 => array('id' => 7, 'name' => 'getjoinery', 'address' => '173.255.229.58', 'os' => 'Ubuntu 26.04', 'hosted_at' => 'One of our cloud accounts (main)'),
		8 => array('id' => 8, 'name' => 'quiet one', 'address' => '192.0.2.8', 'os' => '', 'hosted_at' => 'Elsewhere (not hosted by us)'),
		9 => array('id' => 9, 'name' => 'old source', 'address' => '192.0.2.55', 'os' => 'Ubuntu 24.04', 'hosted_at' => 'One of our cloud accounts (main)', 'state' => 'retired'),
	),
	'addresses' => array(7 => $addr('173.255.229.58'), 8 => $addr('192.0.2.8'), 9 => $addr('192.0.2.55')),
	'accounts' => array(
		'oca:1' => array('name' => 'Linode — Joinery', 'error' => '', 'instances' => array(
			array('id' => '100', 'label' => 'getjoinery', 'region' => 'us-east', 'ipv4_public' => array('173.255.229.58'), 'ipv6' => '', 'image' => 'linode/ubuntu26.04', 'created' => '2026-01-01 00:00:00', 'status' => 'running'),
			array('id' => '200', 'label' => 'devonnearhill', 'region' => 'us-east', 'ipv4_public' => array('192.0.2.74'), 'ipv6' => '', 'image' => 'linode/ubuntu20.04', 'created' => '2021-03-01 00:00:00', 'status' => 'running'),
			array('id' => '300', 'label' => 'relay1', 'region' => 'us-east', 'ipv4_public' => array('192.0.2.30'), 'ipv6' => '', 'image' => 'linode/ubuntu24.04', 'created' => '2026-06-01 00:00:00', 'status' => 'running'),
			array('id' => '400', 'label' => 'mystery', 'region' => 'us-east', 'ipv4_public' => array('192.0.2.40'), 'ipv6' => '', 'image' => 'linode/ubuntu24.04', 'created' => '2026-09-01 00:00:00', 'status' => 'running'),
			array('id' => '500', 'label' => 'old-source', 'region' => 'us-east', 'ipv4_public' => array('192.0.2.55'), 'ipv6' => '', 'image' => 'linode/ubuntu24.04', 'created' => '2026-02-01 00:00:00', 'status' => 'offline'),
			array('id' => '600', 'label' => 'upgraded', 'region' => 'us-east', 'ipv4_public' => array('192.0.2.60'), 'ipv6' => '', 'image' => 'linode/ubuntu20.04', 'created' => '2022-01-01 00:00:00', 'status' => 'running'),
			array('id' => '700', 'label' => 'v6only', 'region' => 'us-east', 'ipv4_public' => array(), 'ipv6' => '2600:3c03::f03c:91ff:fe00:7/128', 'image' => 'linode/ubuntu24.04', 'created' => '2026-09-01 00:00:00', 'status' => 'running'),
		)),
		'oca:2' => array('name' => 'Hetzner — Joinery', 'error' => 'the key is no longer accepted', 'instances' => array()),
	),
	'hand' => array(
		$hand(array('id' => 11, 'name' => 'relay1 note', 'instance_ref' => 'oca:1:300', 'purpose' => 'mail relay', 'serves' => 'relay1.getjoinery.com')),
		$hand(array('id' => 12, 'name' => 'integralzen', 'address' => '66.175.208.96', 'where' => 'Linode, the owner\'s own account', 'os' => 'Ubuntu 20.04', 'serves' => 'integralzen.org')),
		$hand(array('id' => 13, 'name' => 'old trial', 'where' => 'Linode, the owner\'s own account', 'os' => 'Ubuntu 24.04', 'confirmed' => '2026-05-01 00:00:00')),
		$hand(array('id' => 14, 'name' => 'gone soon', 'os' => 'Ubuntu 24.04', 'serves' => 'x.example', 'retire_by' => '2026-10-01')),
		$hand(array('id' => 15, 'name' => 'note on a server gone', 'instance_ref' => 'oca:1:999', 'os' => 'Ubuntu 24.04', 'serves' => 'y.example')),
		$hand(array('id' => 16, 'name' => 'upgraded note', 'instance_ref' => 'oca:1:600', 'os' => 'Ubuntu 24.04', 'purpose' => 'build box')),
	),
);
$rows = MachineInventory::rows($snap, $NOW);
$by = array();
foreach ($rows as $r) { $by[$r['name']] = $r; }

check(count($rows) === 12, 'twelve rows: three managed, five servers no node runs on, four written down (a note on a listed server is its row)', json_encode(array_column($rows, 'name')));
check($by['getjoinery']['kind'] === 'managed' && $by['getjoinery']['verdict'] === 'green' && $by['getjoinery']['where'] === 'Linode — Joinery'
		&& $by['getjoinery']['instance'] === '100' && $by['getjoinery']['os'] === 'Ubuntu 26.04',
	'a managed machine matched to its server by address: green, in that account, its report\'s release', json_encode($by['getjoinery']));
check(!isset($by['getjoinery']) || count(array_filter($rows, function ($r) { return $r['instance'] === '100'; })) === 1,
	'  and its server is not listed again as not managed');
check($by['devonnearhill']['kind'] === 'unmanaged' && $by['devonnearhill']['verdict'] === 'red' && $by['devonnearhill']['os'] === 'Ubuntu 20.04 (as made)'
		&& strpos($by['devonnearhill']['reasons'][0], 'Ubuntu 20.04 is past standard support') === 0,
	'a server no node runs on, made from 20.04: red, its release read from its image', json_encode($by['devonnearhill']));
check($by['mystery']['verdict'] === 'amber' && strpos($by['mystery']['reasons'][0], 'no node manages it') === 0 && $by['mystery']['ref'] === 'oca:1:400',
	'a server no node runs on and nothing explains: amber, asking to convert, retire or write it down', json_encode($by['mystery']));
check($by['relay1']['verdict'] === 'green' && $by['relay1']['hand']['purpose'] === 'mail relay',
	'a server no node runs on, written down (a mail relay): green, with what is written', json_encode($by['relay1']));
check($by['integralzen']['kind'] === 'hand' && $by['integralzen']['verdict'] === 'red', 'a written-down machine on 20.04: red', json_encode($by['integralzen']));
check($by['old trial']['verdict'] === 'amber'
		&& in_array('what is written about it was last confirmed over 90 days ago', $by['old trial']['reasons'], true)
		&& in_array('nothing is written as served from it: retire it, or say what it serves', $by['old trial']['reasons'], true),
	'confirmed in May and serving nothing written: amber, saying both', json_encode($by['old trial']));
check($by['gone soon']['verdict'] === 'red' && strpos($by['gone soon']['reasons'][0], 'its retire-by date (2026-10-01) has passed') === 0,
	'a retire-by date passed: red', json_encode($by['gone soon']));
check($by['note on a server gone']['kind'] === 'hand', 'a note on a server no longer listed shows as written down');
check($by['quiet one']['verdict'] === 'grey' && $by['quiet one']['where'] === 'Elsewhere (not hosted by us)',
	'a managed machine with no report of its release and no server found: grey, where its node says it is', json_encode($by['quiet one']));
check($by['old source']['kind'] === 'managed' && $by['old source']['state'] === 'retired' && $by['old source']['instance'] === '500'
		&& $by['old source']['verdict'] === 'amber' && strpos(implode('; ', $by['old source']['reasons']), 'a retired source, kept for the way back') === 0
		&& !isset($by['old-source']),
	'a retired source is listed as its node, amber, and its server is not listed again as not managed', json_encode($by['old source']));
check($by['upgraded']['os'] === 'Ubuntu 24.04' && $by['upgraded']['verdict'] === 'green',
	'a server made from 20.04 and upgraded in place: what its note says it runs, green', json_encode($by['upgraded']));
check($by['v6only']['address'] === '2600:3c03::f03c:91ff:fe00:7', 'a server with no IPv4 shows its IPv6', json_encode($by['v6only']));
$order = array_column($rows, 'verdict');
check($order === array('red', 'red', 'red', 'amber', 'amber', 'amber', 'amber', 'grey', 'green', 'green', 'green', 'green'), 'red first, then amber, grey, green', json_encode($order));

section('Which accounts are ours to list');

$acct = function (string $method, bool $test) { $a = new OperatorCloudAccount(NULL); $a->set('oca_method', $method); $a->set('oca_is_test', $test); return $a; };
check(MachineInventory::lists_account($acct(OperatorCloudAccount::METHOD_KEY, false))
		&& !MachineInventory::lists_account($acct(OperatorCloudAccount::METHOD_KEY, true))
		&& !MachineInventory::lists_account($acct(OperatorCloudAccount::METHOD_CONNECT, false)),
	'our own account is listed; the test account and a customer\'s Connect grant are not');

section('A machine written down');

$m = new FleetHandMachine(NULL);
$m->set('fhm_name', 'HarnessTest old box');
$m->set('fhm_os', 'Ubuntu 20.04');
$m->set('fhm_retire_by', '2026-12-31');
$m->confirm(null);
$m->prepare();
$m->save();
$m->load();
harness_register_row('fhm_fleet_hand_machines', 'fhm_fleet_hand_machine_id', $m->key);
check((int)$m->key > 0 && (string)$m->get('fhm_retire_by') === '2026-12-31' && (string)$m->get('fhm_confirmed_time') !== '',
	'saved with its retire-by date, and confirmed as it is written');
$found = false;
foreach (new MultiFleetHandMachine(array('deleted' => false)) as $x) { if ((int)$x->key === (int)$m->key) { $found = true; } }
check($found, 'listed among the machines written down');

harness_finish();

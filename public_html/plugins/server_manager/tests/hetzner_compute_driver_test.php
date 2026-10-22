<?php
/** @joinery-test
 * name: hetzner_compute_driver
 * tier: db
 * env: any
 * needs: []
 */

/**
 * hetzner_compute_driver (specs/site_copy_anything_to_anything.md WP5, D3): the
 * Hetzner Cloud driver against a fake Cloud API held in this file, each
 * interface it implements, and the Hetzner account of ours.
 *
 *  - a server is created with no key, its root password set by first-boot
 *    user data, a name Hetzner takes, and no disk encryption to turn on;
 *  - a server reads in the platform's shape ('off' is 'offline', its IPv6 the
 *    ::1 of its /64, no provider encryption);
 *  - plans (x86, still sold, smallest first), the default plan the cheapest at
 *    the default location, locations as regions;
 *  - the address swap: primary IPv4s unassigned, then assigned across, kept
 *    when a server is deleted; reverse DNS; firewalls by name;
 *  - the activity log by Hetzner's command names, which Tier 2 refuses;
 *  - transfer, per server and summed; a refused token says unauthorized;
 *  - the account: added under the name typed, checked without losing it, its
 *    key replaced only by a token that reaches the same servers.
 *
 * @version 1.4 - servers carry their location at the top, datacenter null, as the API answers now (B43)
 * @version 1.3 - root's password by runcmd from its hash, the plaintext nowhere in the user data (B32)
 * @version 1.2 - plans by location: one withdrawn from a location is not sold there, though its price list names it (B31)
 * @version 1.1 - the swap refuses a running server and waits on each address action
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

// status: each server's state where a test sets one; actions: each address
// action's polls left before it succeeds (or 'error').
$api = array('seen' => array(), 'unauthorized' => false, 'status' => array(), 'actions' => array(), 'next_action' => 100, 'ips' => array(
	'198.51.100.10' => array('id' => 10, 'ip' => '198.51.100.10', 'assignee_id' => 1001, 'dns_ptr' => array(array('ip' => '198.51.100.10', 'dns_ptr' => 'site.example.org'))),
	'198.51.100.20' => array('id' => 20, 'ip' => '198.51.100.20', 'assignee_id' => 1002, 'dns_ptr' => array()),
));
$server = function ($id, $status, $ip, $loc = 'fsn1', $out = 0) {
	return array('id' => $id, 'name' => 'srv-' . $id, 'status' => $status, 'created' => '2026-10-01T10:00:00+00:00',
		'public_net' => array('ipv4' => array('id' => $id * 10, 'ip' => $ip), 'ipv6' => array('ip' => '2a01:4f8:c17:' . $id . '::/64'),
			'firewalls' => array(array('id' => 77))),
		// As Hetzner answers since its datacenter field went: the location at the top, datacenter null.
		'location' => array('name' => $loc), 'datacenter' => null,
		'outgoing_traffic' => $out, 'included_traffic' => 20e12, 'labels' => array('joinery' => ''));
};
$handler = function (RequestInterface $req, array $opts) use (&$api, $server) {
	$path = $req->getUri()->getPath();
	$query = $req->getUri()->getQuery();
	$body = json_decode((string)$req->getBody(), true);
	$api['seen'][] = array($req->getMethod(), $path . ($query !== '' ? '?' . $query : ''), $body, $req->getHeaderLine('Authorization'));
	$json = function ($b, int $status = 200) {
		return Create::promiseFor(new Response($status, array('Content-Type' => 'application/json'), json_encode($b)));
	};
	if ($api['unauthorized']) {
		return Create::rejectionFor(new GuzzleHttp\Exception\ClientException('401', $req,
			new Response(401, array(), json_encode(array('error' => array('code' => 'unauthorized', 'message' => 'unable to authenticate'))))));
	}
	$m = $req->getMethod();
	if ($m === 'POST' && $path === '/v1/servers') {
		return $json(array('server' => $server(1003, 'initializing', '203.0.113.30'), 'root_password' => 'not-ours'), 201);
	}
	if ($m === 'GET' && preg_match('#^/v1/servers/(\d+)$#', $path, $mm)) {
		$status = $api['status'][(int)$mm[1]] ?? ((int)$mm[1] === 1002 ? 'off' : 'running');
		return $json(array('server' => $server((int)$mm[1], $status, (int)$mm[1] === 1001 ? '198.51.100.10' : '198.51.100.20')));
	}
	if ($m === 'GET' && $path === '/v1/servers') {
		return $json(array('servers' => array($server(1001, 'running', '198.51.100.10', 'fsn1', 30e12), $server(1002, 'off', '198.51.100.20', 'fsn1', 5e12)),
			'meta' => array('pagination' => array('next_page' => null))));
	}
	if ($m === 'GET' && $path === '/v1/server_types') {
		$price = function ($loc, $p) { return array('location' => $loc, 'price_monthly' => array('gross' => $p)); };
		$sold = function ($loc) { return array('name' => $loc, 'available' => true, 'recommended' => false, 'deprecation' => null); };
		// Withdrawn from a location, the plan's price list still names it.
		$gone = function ($loc) { return array('name' => $loc, 'available' => false, 'recommended' => false,
			'deprecation' => array('announced' => '2025-10-16T06:00:00Z', 'unavailable_after' => '2025-12-31T23:59:59Z')); };
		return $json(array('server_types' => array(
			array('name' => 'cx32', 'memory' => 8, 'disk' => 80, 'architecture' => 'x86', 'deprecation' => null, 'prices' => array($price('fsn1', '7.50')),
				'locations' => array($sold('fsn1'))),
			array('name' => 'cx22', 'memory' => 4, 'disk' => 40, 'architecture' => 'x86', 'deprecation' => null, 'prices' => array($price('fsn1', '4.50')),
				'locations' => array($sold('fsn1'))),
			array('name' => 'cpx11', 'memory' => 2, 'disk' => 40, 'architecture' => 'x86', 'deprecation' => null,
				'prices' => array($price('fsn1', '3.50'), $price('ash', '9.00')), 'locations' => array($gone('fsn1'), $sold('ash'))),
			array('name' => 'cax11', 'memory' => 4, 'disk' => 40, 'architecture' => 'arm', 'deprecation' => null, 'prices' => array($price('fsn1', '3.90')),
				'locations' => array($sold('fsn1'))),
			array('name' => 'cx11', 'memory' => 2, 'disk' => 20, 'architecture' => 'x86', 'deprecation' => array('announced' => '2024-06-06'),
				'prices' => array($price('fsn1', '3.00')), 'locations' => array($sold('fsn1'))),
		)));
	}
	if ($m === 'GET' && $path === '/v1/locations') {
		return $json(array('locations' => array(array('name' => 'fsn1', 'description' => 'Falkenstein DC Park 1'),
			array('name' => 'ash', 'description' => 'Ashburn, VA'))));
	}
	if ($m === 'GET' && $path === '/v1/primary_ips') {
		parse_str($query, $q);
		$ip = $api['ips'][$q['ip'] ?? ''] ?? null;
		return $json(array('primary_ips' => $ip ? array($ip) : array()));
	}
	if ($m === 'POST' && preg_match('#^/v1/primary_ips/(\d+)/actions/(unassign|assign)$#', $path, $mm)) {
		// An address under a running action is locked, as at Hetzner.
		foreach ($api['actions'] as $a) {
			if ($a['ip'] === (int)$mm[1] && $a['left'] !== 'error' && $a['left'] > 0) {
				return Create::rejectionFor(new GuzzleHttp\Exception\ClientException('423', $req,
					new Response(423, array(), json_encode(array('error' => array('code' => 'locked', 'message' => 'primary ip is locked'))))));
			}
		}
		foreach ($api['ips'] as $addr => $ip) {
			if ($ip['id'] === (int)$mm[1]) {
				$api['ips'][$addr]['assignee_id'] = $mm[2] === 'unassign' ? null : (int)$body['assignee_id'];
			}
		}
		$id = $api['next_action']++;
		$api['actions'][$id] = array('ip' => (int)$mm[1], 'command' => $mm[2] . '_primary_ip', 'left' => $api['polls'] ?? 2);
		return $json(array('action' => array('id' => $id, 'command' => $mm[2] . '_primary_ip', 'status' => 'running')), 201);
	}
	if ($m === 'GET' && preg_match('#^/v1/primary_ips/actions/(\d+)$#', $path, $mm)) {
		$a = &$api['actions'][(int)$mm[1]];
		if ($a['left'] !== 'error') {
			$a['left'] = max(0, $a['left'] - 1);
		}
		$status = $a['left'] === 'error' ? 'error' : ($a['left'] > 0 ? 'running' : 'success');
		return $json(array('action' => array('id' => (int)$mm[1], 'command' => $a['command'], 'status' => $status,
			'error' => $status === 'error' ? array('message' => 'server is running') : null)));
	}
	if ($m === 'PUT' && preg_match('#^/v1/primary_ips/(\d+)$#', $path)) {
		return $json(array('primary_ip' => array()));
	}
	if ($m === 'GET' && $path === '/v1/firewalls/77') {
		return $json(array('firewall' => array('id' => 77, 'name' => 'web-only')));
	}
	if ($m === 'GET' && preg_match('#^/v1/servers/(\d+)/actions$#', $path)) {
		return $json(array('actions' => array(
			array('command' => 'start_server', 'status' => 'success', 'started' => '2026-10-10T09:00:00+00:00'),
			array('command' => 'enable_rescue', 'status' => 'success', 'started' => '2026-10-11T09:00:00+00:00'),
			array('command' => 'reset_password', 'status' => 'error', 'started' => '2026-10-11T10:00:00+00:00', 'error' => array('message' => 'nope')),
		), 'meta' => array('pagination' => array('next_page' => null))));
	}
	if ($m === 'POST' && preg_match('#^/v1/servers/\d+/actions/\w+$#', $path)) {
		return $json(array('action' => array('id' => 2, 'status' => 'running')), 201);
	}
	return $json(array('error' => array('code' => 'not_found', 'message' => 'no ' . $path)), 404);
};
$driver = new HetznerComputeDriver('hz-token', new Client(array('handler' => HandlerStack::create($handler), 'base_uri' => HetznerComputeDriver::API_BASE)));
$last = function (string $method, string $prefix) use (&$api) {
	foreach (array_reverse($api['seen']) as $s) {
		if ($s[0] === $method && strpos($s[1], $prefix) === 0) {
			return $s;
		}
	}
	return null;
};

// ---------------------------------------------------------------------------
section('The registry knows it');

check(ComputeDriverRegistry::driver('hetzner', 't') instanceof HetznerComputeDriver, 'hetzner builds the Hetzner driver');
foreach (array('CloudAddressSwap', 'CloudMachineTransfer', 'CloudRegionFacts', 'CloudProviderEvents', 'CloudInstanceTypes', 'CloudKeyExpiry') as $cap) {
	check(ComputeDriverRegistry::supports('hetzner', $cap), 'it has ' . $cap);
}
foreach (array('CloudTokenScopes', 'CloudAccountIdentity', 'CloudInstanceTransfers', 'CloudVolumeFacts', 'CloudAccountCleanup') as $cap) {
	check(!ComputeDriverRegistry::supports('hetzner', $cap), 'it has no ' . $cap . ' (Hetzner offers nothing of the kind)');
}

// ---------------------------------------------------------------------------
section('A server');

$made = $driver->createInstance(array('label' => 'Copy_of site.example.org-12', 'region' => 'fsn1', 'type' => 'cx22',
	'image' => 'ubuntu-26.04', 'root_pass' => 'Aa1!secret"quote', 'disk_encryption' => 'disabled', 'swap_size' => 0));
$post = $last('POST', '/v1/servers');
check($post[2]['name'] === 'copy-of-site.example.org-12' && $post[2]['server_type'] === 'cx22' && $post[2]['location'] === 'fsn1'
	&& $post[2]['image'] === 'ubuntu-26.04' && !isset($post[2]['ssh_keys']) && $post[2]['public_net']['enable_ipv6'] === true,
	'created with a name Hetzner takes, no key, both families', json_encode($post[2]));
$ud = (string)$post[2]['user_data'];
preg_match('/^runcmd:\n  - \[usermod, -p, ("[^"\n]+"), root\]\n  - \[systemctl, restart, ssh\]\n$/m', $ud, $ud_m);
$ud_hash = isset($ud_m[1]) ? (string)json_decode($ud_m[1]) : '';
check(strpos($ud, '#cloud-config') === 0 && strpos($ud_hash, '$6$') === 0 && hash_equals($ud_hash, crypt('Aa1!secret"quote', $ud_hash))
	&& strpos($ud, 'PermitRootLogin yes') !== false && $post[3] === 'Bearer hz-token',
	'its root password is the one sealed before the create, set by runcmd from its hash, after cloud-init\'s password module (B32)', $ud);
check(strpos($ud, 'Aa1!secret') === false && strpos($ud, "chpasswd:\n  expire: false\n") !== false && strpos($ud, 'users:') === false,
	'the user data, which Hetzner serves to any process on the server, holds no plaintext; the vendor\'s expiry is turned off', $ud);
check($made['id'] === '1003' && $made['ip'] === '203.0.113.30' && $made['ipv6'] === '2a01:4f8:c17:1003::1' && $made['disk_encryption'] === 'disabled',
	'the server in the platform\'s shape: its address, its IPv6 the ::1 of its /64, no provider layer', json_encode($made));
$threw = '';
try { $driver->createInstance(array('label' => 'x', 'region' => 'fsn1', 'type' => 'cx22', 'image' => 'ubuntu-26.04', 'disk_encryption' => 'enabled')); }
catch (CloudComputeException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'no disk encryption') !== false, 'asked for the provider\'s encryption: refused, there is none', $threw);
check($driver->getInstance('1002')['status'] === 'offline', 'a stopped server reads offline, the platform\'s word');
$driver->shutdownInstance('1001');
$driver->bootInstance('1002');
$driver->rebootInstance('1001');
check($last('POST', '/v1/servers/1001/actions/shutdown') && $last('POST', '/v1/servers/1002/actions/poweron') && $last('POST', '/v1/servers/1001/actions/reboot'),
	'power: shutdown, poweron, reboot');
$driver->setReverseDns('1001', '198.51.100.10', 'site.example.org');
$ptr = $last('POST', '/v1/servers/1001/actions/change_dns_ptr');
check($ptr[2] === array('ip' => '198.51.100.10', 'dns_ptr' => 'site.example.org'), 'reverse DNS by change_dns_ptr');

// ---------------------------------------------------------------------------
section('Plans and locations');

$types = $driver->instanceTypes();
check(array_column($types, 'id') === array('cpx11', 'cx22', 'cx32') && $types[1]['memory_mb'] === 4096 && $types[1]['disk_mb'] === 40960,
	'x86 plans still sold somewhere, smallest first', json_encode($types));
check(array_column($driver->instanceTypes('fsn1'), 'id') === array('cx22', 'cx32'),
	'at a location, a plan withdrawn from it is not offered, though its price list still names it (B31)', json_encode($driver->instanceTypes('fsn1')));
check(array_column($driver->instanceTypes('ash'), 'id') === array('cpx11') && $driver->instanceTypes('nbg1') === array(),
	'a location offers only the plans its own entry says it sells');
check($driver->defaultInstanceType() === 'cx22' && $driver->defaultRegion() === 'fsn1' && $driver->defaultImage() === 'ubuntu-26.04',
	'the default plan is the cheapest x86 one sold at the default location, not a cheaper one withdrawn from it');
check($driver->defaultInstanceType('ash') === 'cpx11', 'at another location, the cheapest it sells');
$regions = $driver->regions();
check(isset($regions['fsn1'], $regions['ash']) && $regions['fsn1']['site_type'] === 'core' && $regions['fsn1']['disk_encryption'] === false,
	'locations are its regions, none with a provider layer to turn off');

// ---------------------------------------------------------------------------
section('The address swap');

$r = $driver->addressReport('1001');
check($r['ipv4_public'] === array('198.51.100.10') && $r['network_helper'] === true, 'one primary IPv4, taken up at boot by DHCP');
check($driver->ipAddress('198.51.100.10') === array('address' => '198.51.100.10', 'instance_id' => '1001', 'rdns' => 'site.example.org'),
	'an address names its server and its reverse DNS');
HetznerComputeDriver::$sleeper = function ($s) {};
harness_defer(function () { HetznerComputeDriver::$sleeper = null; });
$refused = '';
try {
	$driver->assignIpv4('fsn1', array('198.51.100.10' => '1002', '198.51.100.20' => '1001'));
} catch (CloudComputeException $e) {
	$refused = $e->getMessage();
}
check(strpos($refused, 'server 1001 is running') !== false && $api['ips']['198.51.100.10']['assignee_id'] === 1001,
	'a swap with a server still running is refused, nothing moved', $refused);
check($driver instanceof CloudSwapWhileStopped, 'and the driver says a swap needs both servers off');
$api['status'][1001] = 'off';
$api['seen'] = array();
$driver->assignIpv4('fsn1', array('198.51.100.10' => '1002', '198.51.100.20' => '1001'));
$polls = count(array_filter($api['seen'], function ($s) { return $s[0] === 'GET' && strpos($s[1], '/v1/primary_ips/actions/') === 0; }));
check($polls === 8, 'each of the four address actions is waited on until it has succeeded (two polls each)', (string)$polls);
$calls = array_map(function ($s) { return $s[0] . ' ' . $s[1]; }, array_filter($api['seen'], function ($s) { return $s[0] !== 'GET'; }));
check(array_values($calls) === array('POST /v1/primary_ips/10/actions/unassign', 'POST /v1/primary_ips/20/actions/unassign',
	'PUT /v1/primary_ips/10', 'POST /v1/primary_ips/10/actions/assign', 'PUT /v1/primary_ips/20', 'POST /v1/primary_ips/20/actions/assign'),
	'both unassigned first, then each kept and assigned across', json_encode(array_values($calls)));
check($api['ips']['198.51.100.10']['assignee_id'] === 1002 && $api['ips']['198.51.100.20']['assignee_id'] === 1001
	&& $driver->instanceForAddress('198.51.100.10') === '1002', 'swapped');
check($driver->instanceForAddress('192.0.2.1') === '', 'an address no server here holds names none');
$api['actions'] = array();
$api['polls'] = 'error';
$failed = '';
try {
	$driver->assignIpv4('fsn1', array('198.51.100.10' => '1001', '198.51.100.20' => '1002'));
} catch (CloudComputeException $e) {
	$failed = $e->getMessage();
}
check(strpos($failed, 'unassign_primary_ip failed: server is running') !== false, 'an action that fails stops the swap, saying why', $failed);
unset($api['polls']);
$api['actions'] = array();
foreach (array('198.51.100.10' => 1002, '198.51.100.20' => 1001) as $addr => $owner) {
	$api['ips'][$addr]['assignee_id'] = $owner;
}
check($driver->instanceFirewalls('1001') === array('77' => 'web-only'), 'its firewalls by name');

// ---------------------------------------------------------------------------
section('The activity log, read before an encrypted data root is opened');

$events = $driver->instanceEvents('1001', '2026-10-10 12:00:00');
check(count($events) === 2 && $events[0]['action'] === 'enable_rescue' && $events[0]['status'] === 'finished' && $events[1]['status'] === 'failed',
	'actions since the time asked, by Hetzner\'s own command names', json_encode($events));
$refused = EscrowTier2::first_refusing($events);
check($refused !== null && $refused['action'] === 'enable_rescue' && strpos($refused['words'], 'rescue') !== false,
	'a rescue refuses the boot; a failed password reset does not', json_encode($refused));

// ---------------------------------------------------------------------------
section('Transfer, and a refused token');

$t = $driver->getTransfer();
check(abs($t['used_gb'] - 35000) < 0.001 && abs($t['quota_gb'] - 40000) < 0.001 && abs($t['billable_gb'] - 10000) < 0.001,
	'the pool is each server\'s traffic summed, and each server\'s overage', json_encode($t));
$list = $driver->listInstances();
check(count($list) === 2 && $list[0]['ipv4_public'] === array('198.51.100.10') && $list[0]['region'] === 'fsn1' && $list[0]['created'] === '2026-10-01 10:00:00',
	'every server, for the transfer watch and for matching nodes');
check($driver->keyExpiry() === null, 'a Hetzner token never expires');
$api['unauthorized'] = true;
$threw = null;
try { $driver->getTransfer(); } catch (CloudComputeException $e) { $threw = $e; }
check($threw && $threw->getCode() === 401 && strpos($threw->getMessage(), 'unauthorized') === 0, 'a refused token says unauthorized, with 401');
$api['unauthorized'] = false;

// ---------------------------------------------------------------------------
section('A Hetzner account of ours');

$tag = bin2hex(random_bytes(3));
$threw = '';
try { OperatorCloudAccount::add_key('hz-token', 1, $driver, 'hetzner', ''); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'name it') !== false, 'added without a name: refused, Hetzner\'s API names no project', $threw);
$oca = OperatorCloudAccount::add_key('hz-token', 1, $driver, 'hetzner', 'Joinery ' . $tag);
harness_register_row('oca_operator_cloud_accounts', 'oca_operator_cloud_account_id', $oca->key);
$oca->load();
check($oca->provider() === 'hetzner' && (string)$oca->get('oca_account_name') === 'Joinery ' . $tag && $oca->missing_scopes() === array()
	&& $oca->can_create() && strpos($oca->label(), 'Hetzner — Joinery ' . $tag) === 0,
	'added under its name, holding every right a token has, labelled by its provider', $oca->label());
check($oca->check($driver) === '' && (string)(new OperatorCloudAccount($oca->key, TRUE))->get('oca_account_name') === 'Joinery ' . $tag,
	'a check keeps the name it was added under');
$p = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'hz-' . $tag . '.example.org', 'cvp_slug' => 'hz-' . $tag,
		'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal', 'cvp_provider' => 'hetzner', 'cvp_hosting_mode' => 'operator',
		'cvp_oca_operator_cloud_account_id' => (int)$oca->key, 'cvp_instance_id' => '1001') as $k => $v) {
	$p->set($k, $v);
}
$p->save();
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $p->key);
check($oca->replacement_refusal('hz-token-2', $driver) === '', 'a new token that reaches the same servers replaces the key');
$p->set('cvp_instance_id', '999999');
$p->save();
$why = $oca->replacement_refusal('hz-token-2', $driver);
check(strpos($why, 'does not reach server 999999') !== false, 'one that does not reach a server on record in it is another project: refused', $why);

harness_finish();

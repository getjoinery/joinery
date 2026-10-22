#!/usr/bin/env php
<?php
/**
 * Throwaway servers on the TEST cloud account, for a session that needs a real
 * machine (a trial install, a migration rehearsal, a live check).
 *
 * Usage:
 *   php test_server.php create <label> [--region=us-east] [--type=g6-nanode-1] [--image=linode/ubuntu26.04] [--keep]
 *   php test_server.php list
 *   php test_server.php delete <label|id>
 *   php test_server.php dns <name> <ipv4> [<ipv6>]
 *   php test_server.php dns-list
 *   php test_server.php dns-delete <name> | --stale
 *
 * Every command works on the one operator cloud account this management node
 * flags as test, and only after the provider confirms that account's company
 * is TestCloudCleanup::COMPANY ("Joinery Test disposable") — the same safety
 * catch the nightly Delete Old Test Servers task reads. Any other account, or
 * a company that cannot be read, is refused before anything is created or
 * deleted.
 *
 * `create` generates the root password and leaves it in a 0600 file,
 * /dev/shm/test_server_<label>; it is never printed. Pass `ssh` that file with
 * `sshpass -f`. `--keep` tags the server `keep`, so the nightly cleanup leaves
 * it past its usual day; delete it yourself when done.
 *
 * `dns` points <name>.joinerytest.com at an address (A, and AAAA when given),
 * replacing any record of that name and type. The zone lives on the test
 * account. `dns-list` shows the zone's A and AAAA records and marks each one
 * STALE whose address no test server holds; `dns-delete` removes a name's
 * records, or with --stale every stale one. `delete` removes the records
 * pointing at the server it deletes: a record left on a released address
 * names whoever the provider gives that address to next.
 *
 * @version 1.1 - dns-list, dns-delete; delete takes the server's DNS records with it
 * @version 1.0
 */

$bootstrap_path = __DIR__ . '/../../public_html/includes/PathHelper.php';
if (!file_exists($bootstrap_path)) {
	fwrite(STDERR, "ERROR: Cannot find PathHelper.php at: $bootstrap_path\n");
	exit(2);
}
require_once($bootstrap_path);
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));

const TEST_SERVER_DNS_DOMAIN = 'joinerytest.com';
const TEST_SERVER_PASSWORD_DIR = '/dev/shm';

function ts_fail(string $message): void {
	fwrite(STDERR, 'test_server: ' . $message . "\n");
	exit(1);
}

function ts_options(array $args): array {
	$opts = array('positional' => array());
	foreach ($args as $arg) {
		if (preg_match('/^--([a-z_]+)(?:=(.*))?$/', $arg, $m)) {
			$opts[$m[1]] = isset($m[2]) ? $m[2] : true;
		} else {
			$opts['positional'][] = $arg;
		}
	}
	return $opts;
}

/** The test account, after the provider confirms its company. */
function ts_account(): OperatorCloudAccount {
	$found = array();
	foreach (new MultiOperatorCloudAccount(array('oca_is_test' => true, 'deleted' => false)) as $account) {
		if ($account->usable()) {
			$found[] = $account;
		}
	}
	if (count($found) !== 1) {
		ts_fail('expected exactly one usable test cloud account on this management node, found ' . count($found) . '.');
	}
	$account = $found[0];
	$company = '';
	try {
		$company = (string)$account->driver()->accountCompany();
	} catch (Throwable $e) {
		ts_fail('could not read the test account\'s company from the provider: ' . $e->getMessage());
	}
	if ($company !== TestCloudCleanup::COMPANY) {
		ts_fail("the account flagged test reports company '{$company}', not '" . TestCloudCleanup::COMPANY . "'; refusing.");
	}
	return $account;
}

/** A raw provider client for the calls the driver does not carry (tags, DNS). */
function ts_http(OperatorCloudAccount $account): GuzzleHttp\Client {
	return new GuzzleHttp\Client(array(
		'base_uri' => LinodeComputeDriver::API_BASE,
		'timeout'  => 30,
		'headers'  => array('Authorization' => 'Bearer ' . $account->token()),
	));
}

function ts_json($response): array {
	$decoded = json_decode((string)$response->getBody(), true);
	return is_array($decoded) ? $decoded : array();
}

function ts_find(CloudComputeProvider $driver, string $which): array {
	foreach ($driver->listInstances() as $row) {
		if ((string)$row['id'] === $which || (string)($row['label'] ?? '') === $which) {
			return $row;
		}
	}
	ts_fail("no server '{$which}' on the test account.");
}

function ts_create(array $opts): void {
	$label = (string)($opts['positional'][0] ?? '');
	if (!preg_match('/^[a-z0-9][a-z0-9-]{2,39}$/', $label)) {
		ts_fail('create needs a label: 3-40 lowercase letters, digits and dashes.');
	}
	$account = ts_account();
	$driver = $account->driver();
	foreach ($driver->listInstances() as $row) {
		if ((string)($row['label'] ?? '') === $label) {
			ts_fail("a server labelled '{$label}' already exists (id {$row['id']}).");
		}
	}
	$password = 'Aa1!' . bin2hex(random_bytes(20));
	$file = TEST_SERVER_PASSWORD_DIR . '/test_server_' . $label;
	if (file_put_contents($file, $password) === false || !chmod($file, 0600)) {
		ts_fail("could not write the password file {$file}.");
	}
	$instance = $driver->createInstance(array(
		'label'     => $label,
		'region'    => (string)($opts['region'] ?? LinodeComputeDriver::DEFAULT_REGION),
		'type'      => (string)($opts['type'] ?? LinodeComputeDriver::DEFAULT_TYPE),
		'image'     => (string)($opts['image'] ?? LinodeComputeDriver::DEFAULT_IMAGE),
		'root_pass' => $password,
	));
	if (!empty($opts['keep'])) {
		ts_http($account)->request('PUT', 'linode/instances/' . rawurlencode((string)$instance['id']),
			array('json' => array('tags' => array('keep'))));
	}
	echo json_encode(array(
		'id'            => $instance['id'] ?? null,
		'label'         => $label,
		'ipv4'          => $instance['ip'] ?? null,
		'ipv6'          => $instance['ipv6'] ?? null,
		'status'        => $instance['status'] ?? null,
		'keep'          => !empty($opts['keep']),
		'password_file' => $file,
	)), "\n";
}

function ts_list(): void {
	$driver = ts_account()->driver();
	foreach ($driver->listInstances() as $row) {
		printf("%-12s %-24s %-16s %-10s %s %s\n", $row['id'], $row['label'] ?? '', implode(',', $row['ipv4_public'] ?? array()),
			$row['status'] ?? '', $row['created'] ?? '', implode(',', $row['tags'] ?? array()));
	}
}

function ts_delete(array $opts): void {
	$which = (string)($opts['positional'][0] ?? '');
	if ($which === '') {
		ts_fail('delete needs a label or id.');
	}
	$account = ts_account();
	$driver = $account->driver();
	$row = ts_find($driver, $which);
	$driver->deleteInstance((string)$row['id']);
	$file = TEST_SERVER_PASSWORD_DIR . '/test_server_' . ($row['label'] ?? '');
	if (($row['label'] ?? '') !== '' && file_exists($file)) {
		unlink($file);
	}
	echo "deleted {$row['id']} ({$row['label']})\n";
	// A record left pointing at the address names whoever is given it next.
	$addresses = array_filter(array_merge((array)($row['ipv4_public'] ?? array()), array((string)($row['ipv6'] ?? ''))));
	$http = ts_http($account);
	$zone_id = ts_zone($http);
	foreach (ts_address_records($http, $zone_id) as $record) {
		if (in_array(strtolower((string)$record['target']), array_map('strtolower', $addresses), true)) {
			ts_drop_record($http, $zone_id, $record);
		}
	}
}

/** The test account's DNS zone id, or a refusal. */
function ts_zone(GuzzleHttp\Client $http): int {
	foreach ((array)(ts_json($http->request('GET', 'domains?page_size=500'))['data'] ?? array()) as $zone) {
		if (($zone['domain'] ?? '') === TEST_SERVER_DNS_DOMAIN) {
			return (int)$zone['id'];
		}
	}
	ts_fail('the test account holds no ' . TEST_SERVER_DNS_DOMAIN . ' zone.');
}

/** The zone's A and AAAA records. */
function ts_address_records(GuzzleHttp\Client $http, int $zone_id): array {
	$out = array();
	foreach ((array)(ts_json($http->request('GET', "domains/{$zone_id}/records?page_size=500"))['data'] ?? array()) as $record) {
		if (in_array((string)($record['type'] ?? ''), array('A', 'AAAA'), true)) {
			$out[] = $record;
		}
	}
	return $out;
}

/** Every address a server on the test account holds now. */
function ts_live_addresses(CloudComputeProvider $driver): array {
	$live = array();
	foreach ($driver->listInstances() as $row) {
		foreach ((array)($row['ipv4_public'] ?? array()) as $ip) { $live[strtolower($ip)] = $row['label'] ?? ''; }
		if (!empty($row['ipv6'])) { $live[strtolower((string)$row['ipv6'])] = $row['label'] ?? ''; }
	}
	return $live;
}

function ts_drop_record(GuzzleHttp\Client $http, int $zone_id, array $record): void {
	$http->request('DELETE', "domains/{$zone_id}/records/{$record['id']}");
	echo "removed {$record['type']} {$record['name']}." . TEST_SERVER_DNS_DOMAIN . " -> {$record['target']}\n";
}

function ts_dns_list(): void {
	$account = ts_account();
	$live = ts_live_addresses($account->driver());
	$http = ts_http($account);
	foreach (ts_address_records($http, ts_zone($http)) as $r) {
		$target = strtolower((string)$r['target']);
		printf("%-5s %-40s %-40s %s\n", $r['type'], $r['name'] === '' ? '@' : $r['name'], $r['target'],
			isset($live[$target]) ? $live[$target] : 'STALE: no test server holds this address');
	}
}

function ts_dns_delete(array $opts): void {
	$name = (string)($opts['positional'][0] ?? '');
	if ($name === '' && empty($opts['stale'])) {
		ts_fail('dns-delete needs a record name, or --stale for every record no test server holds.');
	}
	$account = ts_account();
	$live = empty($opts['stale']) ? array() : ts_live_addresses($account->driver());
	$http = ts_http($account);
	$zone_id = ts_zone($http);
	$removed = 0;
	foreach (ts_address_records($http, $zone_id) as $r) {
		$stale = !empty($opts['stale']) && !isset($live[strtolower((string)$r['target'])]);
		if (($name !== '' && $r['name'] === $name) || ($name === '' && $stale)) {
			ts_drop_record($http, $zone_id, $r);
			$removed++;
		}
	}
	echo "{$removed} record(s) removed\n";
}

function ts_dns(array $opts): void {
	list($name, $ipv4, $ipv6) = array_pad($opts['positional'], 3, '');
	if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $name)) {
		ts_fail('dns needs a record name under ' . TEST_SERVER_DNS_DOMAIN . ', e.g. mytrial.');
	}
	$want = array();
	if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		$want['A'] = $ipv4;
	} else {
		ts_fail('dns needs an IPv4 address.');
	}
	if ($ipv6 !== '') {
		if (!filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			ts_fail("'{$ipv6}' is not an IPv6 address.");
		}
		$want['AAAA'] = $ipv6;
	}
	$account = ts_account();
	$account->driver();
	$http = ts_http($account);
	$zone_id = ts_zone($http);
	foreach (ts_address_records($http, $zone_id) as $record) {
		$type = (string)($record['type'] ?? '');
		if (($record['name'] ?? '') === $name && isset($want[$type])) {
			$http->request('PUT', "domains/{$zone_id}/records/{$record['id']}", array('json' => array('target' => $want[$type], 'ttl_sec' => 300)));
			echo "updated {$type} {$name}." . TEST_SERVER_DNS_DOMAIN . " -> {$want[$type]}\n";
			unset($want[$type]);
		}
	}
	foreach ($want as $type => $target) {
		$http->request('POST', "domains/{$zone_id}/records", array('json' => array('type' => $type, 'name' => $name, 'target' => $target, 'ttl_sec' => 300)));
		echo "created {$type} {$name}." . TEST_SERVER_DNS_DOMAIN . " -> {$target}\n";
	}
}

$args = $argv ?? array();
$opts = ts_options(array_slice($args, 2));
switch ($args[1] ?? '') {
	case 'create': ts_create($opts); break;
	case 'list':   ts_list(); break;
	case 'delete': ts_delete($opts); break;
	case 'dns':    ts_dns($opts); break;
	case 'dns-list':   ts_dns_list(); break;
	case 'dns-delete': ts_dns_delete($opts); break;
	default:
		fwrite(STDERR, "Usage: php test_server.php create <label> [--region=] [--type=] [--image=] [--keep] | list | delete <label|id> | dns <name> <ipv4> [<ipv6>] | dns-list | dns-delete <name>|--stale\n");
		exit(2);
}

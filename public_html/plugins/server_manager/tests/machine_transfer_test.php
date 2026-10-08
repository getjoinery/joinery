<?php
/** @joinery-test
 * name: machine_transfer
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Each cloud machine's transfer (specs/node_outbound_and_transfer.md WP1).
 *
 * MachineTransferWatch against fake accounts and a fake resolver: a machine
 * is matched to the nodes on it by address (IPv4 or IPv6, the node's own or
 * its server's), read, and recorded on one row the nodes point at; the
 * server's own node speaks for it; a failed listing unlinks nothing. The
 * rate window, the month's turn, and the three conditions (allowance passed,
 * a day above three times its share, a month on pace past one and a half
 * times) against fixed clocks. The incident source raises on the speaking
 * node only.
 *
 * Run: php plugins/server_manager/tests/machine_transfer_test.php
 *
 * @version 1.2 - a connected account the provider answers 401 is marked revoked; an outage and the operator token mark nothing
 * @version 1.1 - review R2, R5: one machine per node (host over join, newest of equals); a new month's
 *                first figure no lower than last month's is held for the first two days
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/MachineTransferWatch.php'));

/** A provider account with fixed machines and figures. */
class FakeMachineAccount implements CloudMachineTransfer {
	public $instances;
	public $transfers;
	public $fail_listing = false;
	function __construct(array $instances, array $transfers) {
		$this->instances = $instances;
		$this->transfers = $transfers;
	}
	public function listInstances(): array {
		if ($this->fail_listing) {
			throw new CloudComputeException('listing refused');
		}
		return $this->instances;
	}
	public function getInstanceTransfer(string $instance_id): array {
		return $this->transfers[$instance_id];
	}
}

$suffix = bin2hex(random_bytes(3));
$make_node = function (string $name, string $host, int $mgh = 0) use ($suffix) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest ' . $name);
	$n->set('mgn_slug', 'harnesstest-mtr-' . strtolower(preg_replace('/[^a-z0-9]/i', '', $name)) . '-' . $suffix);
	$n->set('mgn_host', $host);
	$n->set('mgn_ssh_user', 'root');
	$n->set('mgn_enabled', true);
	if ($mgh) {
		$n->set('mgn_mgh_managed_host_id', $mgh);
		$n->set('mgn_container_name', strtolower($name));
	}
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$reload = function ($n) { return new ManagedNode((int)$n->key, TRUE); };
$no_dns = function ($name) { return array(); };

// Machine A: a server with two sites and its own node, matched by IPv6 only.
$host = new ManagedHost(NULL);
$host->set('mgh_host', '192.0.2.10');
$host->set('mgh_name', 'Harness mtr host ' . $suffix);
$host->set('mgh_slug', 'harnesstest-mtr-host-' . $suffix);
$host->save();
$host->load();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $host->key);
$server = $make_node('Server', '2001:db8::10');
$site1 = $make_node('SiteOne', 'site1.example.invalid', (int)$host->key);
$site2 = $make_node('SiteTwo', 'site2.example.invalid', (int)$host->key);
$host->set('mgh_mgn_managed_node_id', (int)$server->key);
$host->save();
// Machine B: one node, matched by IPv4.
$solo = $make_node('Solo', '192.0.2.20');

$iid_a = 'h' . $suffix . 'a';
$iid_b = 'h' . $suffix . 'b';
$now = strtotime('2026-10-20 06:00:00 UTC');
$account = new FakeMachineAccount(array(
	array('id' => $iid_a, 'label' => 'server-a', 'region' => 'us-east', 'status' => 'running',
		'ipv4_public' => array('192.0.2.10'), 'ipv6' => '2001:db8::10', 'created' => '2025-01-01 00:00:00'),
	array('id' => $iid_b, 'label' => 'solo-b', 'region' => 'us-east', 'status' => 'running',
		'ipv4_public' => array('192.0.2.20'), 'ipv6' => '2001:db8::20', 'created' => '2025-01-01 00:00:00'),
	array('id' => 'h' . $suffix . 'x', 'label' => 'no-node', 'region' => 'us-east', 'status' => 'running',
		'ipv4_public' => array('192.0.2.99'), 'ipv6' => '', 'created' => '2025-01-01 00:00:00'),
), array(
	$iid_a => array('used_bytes' => 40 * 1000000000, 'quota_gb' => 4000, 'billable_gb' => 0),
	$iid_b => array('used_bytes' => 300 * 1000000000, 'quota_gb' => 1000, 'billable_gb' => 0),
));

// ---------------------------------------------------------------------------
section('A read matches machines to nodes and records them');

$result = MachineTransferWatch::run(array('harness' => $account), $no_dns, $now);
check($result['status'] === 'success', 'the read succeeds: ' . $result['message']);
$row_a = MachineTransfer::for_instance('linode', $iid_a);
$row_b = MachineTransfer::for_instance('linode', $iid_b);
check($row_a !== null && $row_b !== null, 'a row for each machine a node runs on');
check(MachineTransfer::for_instance('linode', 'h' . $suffix . 'x') === null, 'no row for a machine no node runs on');
if ($row_a) { harness_register_row('mtr_machine_transfers', 'mtr_machine_transfer_id', $row_a->key); }
if ($row_b) { harness_register_row('mtr_machine_transfers', 'mtr_machine_transfer_id', $row_b->key); }
foreach (array($server, $site1, $site2) as $n) {
	check((int)$reload($n)->get('mgn_mtr_machine_transfer_id') === (int)$row_a->key,
		$n->get('mgn_name') . ' points at the server\'s machine (its host matched by IPv6, the sites by their server)');
}
check((int)$reload($solo)->get('mgn_mtr_machine_transfer_id') === (int)$row_b->key, 'the single node points at its own machine');
check((int)$row_a->get('mtr_mgn_managed_node_id') === (int)$server->key, 'the server\'s own node speaks for the shared machine');
check((int)$row_b->get('mtr_used_bytes') === 300000000000 && (float)$row_b->get('mtr_quota_gb') === 1000.0
	&& $row_b->get('mtr_period') === '2026-10' && $row_b->get('mtr_account') === 'harness', 'the figures, month and account are recorded');
check($row_b->get('mtr_prev_read_time') === '2026-10-01 00:00:00' && (int)$row_b->get('mtr_prev_used_bytes') === 0,
	'a first read measures from the start of the month');

// ---------------------------------------------------------------------------
section('A failed listing unlinks nothing');

$account->fail_listing = true;
$result = MachineTransferWatch::run(array('harness' => $account), $no_dns, $now + 86400);
check($result['status'] === 'error', 'the failure is reported: ' . $result['message']);
check((int)$reload($solo)->get('mgn_mtr_machine_transfer_id') === (int)$row_b->key, 'the node keeps its machine');
$account->fail_listing = false;

// ---------------------------------------------------------------------------
section('A connected account the provider rejects is marked revoked');

$make_cca = function () {
	$cca = new CustomerCloudAccount(NULL);
	$cca->set('cca_usr_user_id', 990000 + random_int(0, 9999));
	$cca->set('cca_provider', 'linode');
	$cca->set('cca_status', 'active');
	$cca->save();
	$cca->load();
	harness_register_row('cca_customer_cloud_accounts', 'cca_customer_cloud_account_id', $cca->key);
	return $cca;
};
$rejecting = new class(array(), array()) extends FakeMachineAccount {
	public $code = 401;
	public function listInstances(): array {
		throw new CloudComputeException('unauthorized: Invalid Token', $this->code);
	}
};
$status_of = function ($cca) { return (string)(new CustomerCloudAccount((int)$cca->key, TRUE))->get('cca_status'); };

$cca = $make_cca();
$result = MachineTransferWatch::run(array('cca:' . $cca->key => $rejecting), $no_dns, $now);
check($status_of($cca) === 'revoked', 'a 401 marks the connected account revoked');
check($result['status'] === 'error' && strpos($result['message'], 'marked revoked') !== false,
	'the run says so once: ' . $result['message']);

$outage = $make_cca();
$rejecting->code = 503;
MachineTransferWatch::run(array('cca:' . $outage->key => $rejecting), $no_dns, $now);
check($status_of($outage) === 'active', 'a provider outage leaves the account as it was');

$rejecting->code = 401;
$result = MachineTransferWatch::run(array('operator' => $rejecting), $no_dns, $now);
check($result['status'] === 'error' && strpos($result['message'], 'could not be listed') !== false,
	'the operator token\'s 401 stays a problem: there is no account to mark');

// ---------------------------------------------------------------------------
section('The rate window');

$row = new MachineTransfer(NULL);
$row->set('mtr_instance_id', 'unsaved');
$row->set('mtr_instance_created_time', '2025-01-01 00:00:00');
$t0 = strtotime('2026-10-10 06:00:00 UTC');
MachineTransferWatch::record($row, array('used_bytes' => 100e9, 'quota_gb' => 1000), $t0);
MachineTransferWatch::record($row, array('used_bytes' => 110e9, 'quota_gb' => 1000), $t0 + 3600);
check($row->get('mtr_prev_read_time') === '2026-10-01 00:00:00', 'a read an hour later keeps the older start, so a rerun cannot shrink the window');
MachineTransferWatch::record($row, array('used_bytes' => 150e9, 'quota_gb' => 1000), $t0 + 86400);
check((int)$row->get('mtr_prev_used_bytes') === 110000000000 && $row->get('mtr_prev_read_time') === '2026-10-10 07:00:00',
	'a read a day later starts the window at the read before');
MachineTransferWatch::record($row, array('used_bytes' => 2e9, 'quota_gb' => 1000), strtotime('2026-11-01 06:00:00 UTC'));
check($row->get('mtr_period') === '2026-11' && (int)$row->get('mtr_prev_used_bytes') === 0
	&& $row->get('mtr_prev_read_time') === '2026-11-01 00:00:00', 'the month\'s turn starts again from the 1st');

MachineTransferWatch::record($row, array('used_bytes' => 900e9, 'quota_gb' => 1000), strtotime('2026-11-30 06:00:00 UTC'));
MachineTransferWatch::record($row, array('used_bytes' => 905e9, 'quota_gb' => 1000), strtotime('2026-12-01 06:00:00 UTC'));
check($row->get('mtr_period') === '2026-11' && (int)$row->get('mtr_used_bytes') === 900000000000,
	'on the 1st, a figure no lower than last month\'s last is last month\'s not yet started again: held, nothing recorded');
MachineTransferWatch::record($row, array('used_bytes' => 3e9, 'quota_gb' => 1000), strtotime('2026-12-02 06:00:00 UTC'));
check($row->get('mtr_period') === '2026-12' && (int)$row->get('mtr_used_bytes') === 3000000000, 'the next day\'s started figure is recorded');
$late = new MachineTransfer(NULL);
$late->set('mtr_instance_id', 'unsaved');
MachineTransferWatch::record($late, array('used_bytes' => 10e9, 'quota_gb' => 1000), strtotime('2026-11-30 06:00:00 UTC'));
MachineTransferWatch::record($late, array('used_bytes' => 12e9, 'quota_gb' => 1000), strtotime('2026-12-03 06:00:00 UTC'));
check($late->get('mtr_period') === '2026-12', 'past the second day a figure is recorded whatever it is');

// ---------------------------------------------------------------------------
section('A node is on one machine');

$nodes_on = new ReflectionMethod('MachineTransferWatch', 'nodes_on');
$choose = new ReflectionMethod('MachineTransferWatch', 'choose_machines');
$packed = array(
	7 => array('host' => array(IpAddress::binary('192.0.2.30')), 'join' => array(IpAddress::binary('192.0.2.31')), 'is_host' => false),
	8 => array('host' => array(), 'join' => array(IpAddress::binary('2001:db8::32')), 'is_host' => false),
);
$new = array('id' => 'new', 'ipv4_public' => array('192.0.2.30'), 'ipv6' => '', 'created' => '2026-09-01 00:00:00');
$old = array('id' => 'old', 'ipv4_public' => array('192.0.2.31'), 'ipv6' => '2001:db8::32', 'created' => '2025-01-01 00:00:00');
check($nodes_on->invoke(null, $new, $packed) === array(7 => MachineTransferWatch::MATCH_HOST)
	&& $nodes_on->invoke(null, $old, $packed) === array(7 => MachineTransferWatch::MATCH_JOIN, 8 => MachineTransferWatch::MATCH_JOIN),
	'a match by host is told from a match by a join\'s report, over IPv4 and IPv6');
$picked = $choose->invoke(null, array(
	'new' => array('instance' => $new, 'nodes' => $nodes_on->invoke(null, $new, $packed)),
	'old' => array('instance' => $old, 'nodes' => $nodes_on->invoke(null, $old, $packed)),
));
check($picked[7] === 'new', 'a node matching its own host on one machine and an old join on another is on the host\'s machine');
check($picked[8] === 'old', 'a node matched only by its join is on that machine');
$picked = $choose->invoke(null, array(
	'a' => array('instance' => array('created' => '2025-01-01 00:00:00'), 'nodes' => array(9 => MachineTransferWatch::MATCH_JOIN)),
	'b' => array('instance' => array('created' => '2026-09-01 00:00:00'), 'nodes' => array(9 => MachineTransferWatch::MATCH_JOIN)),
));
check($picked[9] === 'b', 'of two equal matches the newer machine wins');

// ---------------------------------------------------------------------------
section('The conditions');

$kinds = function (MachineTransfer $r, int $at) {
	return array_column(MachineTransferWatch::conditions($r, $at), 'kind');
};
$fresh = function (int $prev_used, string $prev_time, int $used, string $read_time, float $quota = 1000, string $created = '2025-01-01 00:00:00') {
	$r = new MachineTransfer(NULL);
	$r->set('mtr_instance_id', 'unsaved');
	$r->set('mtr_instance_created_time', $created);
	$r->set('mtr_period', substr($read_time, 0, 7));
	$r->set('mtr_quota_gb', $quota);
	$r->set('mtr_used_bytes', $used);
	$r->set('mtr_read_time', $read_time);
	$r->set('mtr_prev_used_bytes', $prev_used);
	$r->set('mtr_prev_read_time', $prev_time);
	return $r;
};
$at = strtotime('2026-10-20 07:00:00 UTC');
// 1000 GB over 31 days is 32.3 GB a day; three times is 96.8.
check($kinds($fresh(400e9, '2026-10-19 06:00:00', 450e9, '2026-10-20 06:00:00'), $at) === array(),
	'an ordinary day (50 GB) on an ordinary month (450 GB by the 20th) raises nothing');
check($kinds($fresh(400e9, '2026-10-19 06:00:00', 500e9, '2026-10-20 06:00:00'), $at) === array('spike'),
	'a day of 100 GB on a 1 TB machine is a spike');
check($kinds($fresh(100e9, '2026-10-09 06:00:00', 110e9, '2026-10-10 06:00:00'), $at) === array(),
	'a reading from ten days ago is stale and says nothing');
check($kinds($fresh(0, '2026-10-01 00:00:00', 950e9, '2026-10-20 06:00:00'), $at) === array('pace'),
	'950 GB by the 20th is on pace for about 1.5 TB');
check($kinds($fresh(1000e9, '2026-10-19 06:00:00', 1010e9, '2026-10-20 06:00:00'), $at) === array('full'),
	'past the allowance is the allowance passed, not also on pace');
$both = MachineTransferWatch::conditions($fresh(900e9, '2026-10-19 06:00:00', 1100e9, '2026-10-20 06:00:00'), $at);
check(array_column($both, 'kind') === array('full', 'spike') && $both[0]['severity'] === IncidentRecord::SEVERITY_CRITICAL,
	'the allowance passed comes first, as critical, with the spike after it');
check($kinds($fresh(0, '2026-10-01 00:00:00', 60e9, '2026-10-02 06:00:00'), strtotime('2026-10-02 07:00:00 UTC')) === array(),
	'the second day of the month makes no pace projection');
// A machine created on the 25th with 230 GB for its seven days has 32.9 GB a day.
check($kinds($fresh(0, '2026-10-25 00:00:00', 40e9, '2026-10-26 06:00:00', 230, '2026-10-25 00:00:00'),
	strtotime('2026-10-26 07:00:00 UTC')) === array(), 'a new machine\'s day is measured against its own days');
check($kinds($fresh(0, '2026-09-01 00:00:00', 2000e9, '2026-09-30 06:00:00'), $at) === array(),
	'last month\'s figure raises nothing this month');

// ---------------------------------------------------------------------------
section('The incident goes on the node that speaks for the machine');

$row_a = MachineTransfer::for_instance('linode', $iid_a);
$row_a->set('mtr_used_bytes', 5000e9);
$row_a->set('mtr_period', gmdate('Y-m'));
$row_a->set('mtr_read_time', gmdate('Y-m-d H:i:s'));
$row_a->set('mtr_prev_read_time', gmdate('Y-m-d H:i:s', time() - 86400));
$row_a->set('mtr_prev_used_bytes', 4990e9);
$row_a->save();
$source = new IncidentSourceMachineTransfer();
$found = $source->evaluate($reload($server));
check(is_array($found) && $found['severity'] === IncidentRecord::SEVERITY_CRITICAL, 'the server\'s node has the incident, critical');
check(is_array($found) && strpos((string)($found['detail']['Also on it'] ?? ''), 'SiteOne') !== false
	&& strpos((string)$found['detail']['Also on it'], 'SiteTwo') !== false, 'it names the sites on the machine');
check($source->evaluate($reload($site1)) === null, 'a site on the machine does not raise its own');
check($source->evaluate($reload($solo)) === null, 'a machine within its share raises nothing');

harness_finish();

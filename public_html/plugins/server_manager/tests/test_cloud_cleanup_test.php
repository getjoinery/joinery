<?php
/** @joinery-test
 * name: test_cloud_cleanup
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The test-account cleanup (specs/test_cloud_account_and_prod_management.md WP3).
 *
 * TestCloudCleanup against a fake account: the safety catch (a company name
 * other than the disposable one, or one that cannot be read, deletes nothing
 * and records the refusal), the age boundary, the keep tag on servers and
 * volumes, a server at a managed node's IPv4 or IPv6 held rather than
 * deleted, an attached volume spared, a failed delete reported, a failed
 * volume listing not stopping the servers' cleanup, and the incident raised
 * only while the task is on.
 *
 * Run: php plugins/server_manager/tests/test_cloud_cleanup_test.php
 *
 * @version 1.1 - the company name Linode accepts: Joinery Test disposable (it refuses parentheses)
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

/** A provider account with fixed servers and volumes that records what it was told to delete. */
class FakeTestCloudAccount implements CloudComputeProvider, CloudMachineTransfer, CloudAccountCleanup {
	public $company;
	public $company_fails = false;
	public $instances = array();
	public $volumes = array();
	public $volumes_fail = false;
	public $refuse_delete = array();
	public $deleted_instances = array();
	public $deleted_volumes = array();

	public function accountCompany(): string {
		if ($this->company_fails) {
			throw new CloudComputeException('Linode API GET account failed (401): Unauthorized');
		}
		return $this->company;
	}
	public function listInstances(): array { return $this->instances; }
	public function listVolumes(): array {
		if ($this->volumes_fail) {
			throw new CloudComputeException('Linode API GET volumes failed (401): Unauthorized');
		}
		return $this->volumes;
	}
	public function deleteInstance(string $instance_id): void {
		if (in_array($instance_id, $this->refuse_delete, true)) {
			throw new CloudComputeException('refused by the provider');
		}
		$this->deleted_instances[] = $instance_id;
	}
	public function deleteVolume(string $volume_id): void { $this->deleted_volumes[] = $volume_id; }
	public function getInstanceTransfer(string $instance_id): array { return array(); }
	public function createInstance(array $opts): array { throw new CloudComputeException('not in this test'); }
	public function getInstance(string $instance_id): array { throw new CloudComputeException('not in this test'); }
	public function rebuildInstance(string $instance_id, array $opts): array { throw new CloudComputeException('not in this test'); }
	public function shutdownInstance(string $instance_id): void {}
	public function bootInstance(string $instance_id): void {}
	public function getTransfer(): array { return array(); }
	public function setReverseDns(string $instance_id, string $ip, string $hostname): array { return array(); }
}

$state_before = get_setting_raw(TestCloudCleanup::STATE_SETTING);
$age_before = get_setting_raw(TestCloudCleanup::MAX_AGE_SETTING);
harness_defer(function () use ($state_before, $age_before) {
	set_setting_raw(TestCloudCleanup::STATE_SETTING, $state_before);
	set_setting_raw(TestCloudCleanup::MAX_AGE_SETTING, $age_before);
});
Setting::put(TestCloudCleanup::MAX_AGE_SETTING, '24');

$suffix = bin2hex(random_bytes(3));
$node = new ManagedNode(NULL);
$node->set('mgn_name', 'HarnessTest cleanup ' . $suffix);
$node->set('mgn_slug', 'harnesstest-cleanup-' . $suffix);
$node->set('mgn_host', '192.0.2.41');
$node->set('mgn_ssh_user', 'root');
$node->set('mgn_enabled', true);
$node->save();
$node->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
$node6 = new ManagedNode(NULL);
$node6->set('mgn_name', 'HarnessTest cleanup6 ' . $suffix);
$node6->set('mgn_slug', 'harnesstest-cleanup6-' . $suffix);
$node6->set('mgn_host', '2001:db8::42');
$node6->set('mgn_ssh_user', 'root');
$node6->set('mgn_enabled', true);
$node6->save();
$node6->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node6->key);

$now = strtotime('2026-10-07 12:00:00 UTC');
$ago = function (int $hours) use ($now) { return gmdate('Y-m-d H:i:s', $now - $hours * 3600); };
$server = function (string $id, int $hours, string $v4, string $v6 = '', array $tags = array()) use ($ago) {
	return array('id' => $id, 'label' => 'srv-' . $id, 'region' => 'us-east', 'status' => 'running',
		'ipv4_public' => array($v4), 'ipv6' => $v6, 'created' => $ago($hours), 'tags' => $tags);
};
$volume = function (string $id, int $hours, string $attached = '', array $tags = array()) use ($ago) {
	return array('id' => $id, 'label' => 'vol-' . $id, 'created' => $ago($hours), 'attached_to' => $attached, 'tags' => $tags);
};
$fresh_account = function () use ($server, $volume) {
	$a = new FakeTestCloudAccount();
	$a->company = TestCloudCleanup::COMPANY;
	$a->instances = array(
		$server('old', 25, '192.0.2.50'),
		$server('young', 23, '192.0.2.51'),
		$server('edge', 24, '192.0.2.52'),
		$server('kept', 100, '192.0.2.53', '', array('Keep')),
		$server('node4', 100, '192.0.2.41'),
		$server('node6', 100, '192.0.2.54', '2001:db8::42'),
		$server('nodate', 100, '192.0.2.55'),
	);
	$a->instances[6]['created'] = '';
	$a->volumes = array(
		$volume('vold', 25),
		$volume('vattached', 100, 'old'),
		$volume('vyoung', 2),
		$volume('vkept', 100, '', array('keep')),
	);
	return $a;
};
$no_dns = function ($name) { return array(); };
$nodes = MachineTransferWatch::node_addresses($no_dns);

// ---------------------------------------------------------------------------
section('The safety catch');

$live = $fresh_account();
$live->company = 'Joinery';
$plan = TestCloudCleanup::plan($live, $nodes, $now, 24);
check(!$plan['safe'] && !$plan['instances'] && strpos($plan['reason'], '"Joinery"') !== false,
	'another company name is not safe, and the plan names it: ' . $plan['reason']);
$result = TestCloudCleanup::run($live, $no_dns, $now);
check($result['status'] === 'error' && !$live->deleted_instances && !$live->deleted_volumes, 'a run on it deletes nothing');
$state = TestCloudCleanup::state();
check(($state['outcome'] ?? '') === 'refused' && ($state['account'] ?? '') === 'Joinery', 'the refusal is recorded with the account found');
check(TestCloudCleanup::condition($state, array('active' => true)) !== null, 'with the task on, it is an incident');
check(TestCloudCleanup::condition($state, array('active' => false)) === null && TestCloudCleanup::condition($state, null) === null,
	'with the task off or absent, it is not');

$blank = $fresh_account();
$blank->company = '';
check(!TestCloudCleanup::plan($blank, $nodes, $now, 24)['safe'], 'an empty company name is not safe');
$near = $fresh_account();
$near->company = 'joinery test disposable';
check(!TestCloudCleanup::plan($near, $nodes, $now, 24)['safe'], 'the name must match exactly, case included');
$blind = $fresh_account();
$blind->company_fails = true;
$plan = TestCloudCleanup::plan($blind, $nodes, $now, 24);
check(!$plan['safe'] && strpos($plan['reason'], 'account:read_only') !== false, 'an unreadable company name is not safe');
check(TestCloudCleanup::run($blind, $no_dns, $now)['status'] === 'error' && !$blind->deleted_instances, 'and deletes nothing');

// ---------------------------------------------------------------------------
section('What a run deletes');

$test = $fresh_account();
$plan = TestCloudCleanup::plan($test, $nodes, $now, 24);
$ids = function (array $rows) { return array_map(function ($r) { return $r['id']; }, $rows); };
check($plan['safe'] && $ids($plan['instances']) === array('old'),
	'only the server past the age is to go (not the young, not one exactly at the limit, not one with no date): '
	. implode(',', $ids($plan['instances'])));
check($ids($plan['held']) === array('node4', 'node6'), 'servers at a managed node\'s IPv4 and IPv6 are held');
check(in_array((int)$node->key, $plan['held'][0]['node_ids'], true) && in_array((int)$node6->key, $plan['held'][1]['node_ids'], true),
	'each held server names its node');
check($ids($plan['volumes']) === array('vold'), 'only the unattached volume past the age is to go');
check($plan['kept'] === 2, 'a server and a volume tagged keep (any case) are kept');

$result = TestCloudCleanup::run($test, $no_dns, $now);
check($result['status'] === 'success' && $test->deleted_instances === array('old') && $test->deleted_volumes === array('vold'),
	'the run deletes exactly those: ' . $result['message']);
check(strpos($result['message'], 'Held') !== false, 'the run\'s report lists the held servers');
$state = TestCloudCleanup::state();
check(($state['outcome'] ?? '') === 'ran' && count($state['deleted_instances']) === 1 && count($state['held']) === 2,
	'the outcome is recorded for the setup page');
check(TestCloudCleanup::condition($state, array('active' => true)) === null, 'a run that passes raises nothing');

Setting::put(TestCloudCleanup::MAX_AGE_SETTING, '22');
$test = $fresh_account();
TestCloudCleanup::run($test, $no_dns, $now);
check($test->deleted_instances === array('old', 'young', 'edge'), 'the age limit comes from the setting');
Setting::put(TestCloudCleanup::MAX_AGE_SETTING, '24');

// ---------------------------------------------------------------------------
section('Failures');

$test = $fresh_account();
$test->refuse_delete = array('old');
$result = TestCloudCleanup::run($test, $no_dns, $now);
check($result['status'] === 'error' && strpos($result['message'], 'refused by the provider') !== false
	&& $test->deleted_volumes === array('vold'), 'a refused delete is reported and the rest goes on');

$test = $fresh_account();
$test->volumes_fail = true;
$result = TestCloudCleanup::run($test, $no_dns, $now);
check($test->deleted_instances === array('old') && $result['status'] === 'error'
	&& strpos($result['message'], 'volumes could not be listed') !== false,
	'a failed volume listing still lets the servers go, and is reported');

harness_finish();

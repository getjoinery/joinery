<?php
/** @joinery-test
 * name: compute_driver_registry
 * tier: db
 * env: any
 * needs: []
 */

/**
 * compute_driver_registry (specs/site_copy_anything_to_anything.md WP1, D2):
 * the driver acting on a server or an account is its provider's, chosen
 * through ComputeDriverRegistry, never Linode's by name.
 *
 *  - a fake second provider is chosen by its account: the account's driver,
 *    a server's credential, the copy's plan and the create's defaults all
 *    come from it;
 *  - a provider whose tokens carry no scopes lacks none; Linode's are checked;
 *  - a plan id is carried to a copy only at the same provider;
 *  - nothing outside the registry, the Linode driver and the relay (born on
 *    Linode by design) builds the Linode driver by name.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

/** A second provider: plans, defaults, and every power call recorded. */
class RegistryFakeCloud implements CloudComputeProvider, CloudInstanceTypes {
	public $token;
	public $calls = array();
	function __construct(string $token) { $this->token = $token; }
	public function createInstance(array $opts): array { $this->calls[] = array('create', $opts); return array('id' => 'f1', 'status' => 'running', 'ip' => '192.0.2.5', 'label' => '', 'disk_encryption' => null); }
	public function getInstance(string $instance_id): array { return array('id' => $instance_id, 'status' => 'running', 'ip' => '192.0.2.5', 'label' => '', 'disk_encryption' => null); }
	public function rebuildInstance(string $instance_id, array $opts): array { throw new CloudComputeException('no'); }
	public function deleteInstance(string $instance_id): void {}
	public function shutdownInstance(string $instance_id): void { $this->calls[] = array('shutdown', $instance_id); }
	public function bootInstance(string $instance_id): void {}
	public function getTransfer(): array { return array('used_gb' => 0.0, 'quota_gb' => 0.0, 'billable_gb' => 0.0); }
	public function setReverseDns(string $instance_id, string $ip, string $hostname): array { return array('ip' => $ip, 'rdns' => $hostname); }
	public function instanceTypes(): array {
		return array(
			array('id' => 'fc-small', 'memory_mb' => 2048, 'disk_mb' => 40960),
			array('id' => 'fc-large', 'memory_mb' => 8192, 'disk_mb' => 163840),
		);
	}
	public function defaultRegion(): string { return 'fc-1'; }
	public function defaultInstanceType(): string { return 'fc-small'; }
	public function defaultImage(): string { return 'fc-ubuntu-26.04'; }
}

$made = array();
ComputeDriverRegistry::$factories['fakecloud'] = function (string $token) use (&$made) {
	$d = new RegistryFakeCloud($token);
	$made[] = $d;
	return $d;
};
harness_defer(function () { unset(ComputeDriverRegistry::$factories['fakecloud']); });

$tag = bin2hex(random_bytes(3));

// ---------------------------------------------------------------------------
section('The registry');

check(ComputeDriverRegistry::known('linode') && ComputeDriverRegistry::known('hetzner') && ComputeDriverRegistry::known('fakecloud'),
	'Linode, Hetzner and a registered provider are known');
check(!ComputeDriverRegistry::known('nowhere'), 'a provider with no driver is not');
$threw = '';
try { ComputeDriverRegistry::driver('nowhere', 't'); } catch (CloudComputeException $e) { $threw = $e->getMessage(); }
check(strpos($threw, "'nowhere'") !== false, 'asking for a provider with no driver says which', $threw);
check(ComputeDriverRegistry::driver('linode', 't') instanceof LinodeComputeDriver, 'Linode\'s driver is built for linode');
check(ComputeDriverRegistry::label('linode') === 'Linode' && ComputeDriverRegistry::label('hetzner') === 'Hetzner',
	'each provider has its name');
check(ComputeDriverRegistry::supports('linode', 'CloudAddressSwap') && ComputeDriverRegistry::supports('linode', 'CloudInstanceTypes'),
	'a capability is read from the driver before there is a credential');
check(!ComputeDriverRegistry::supports('fakecloud', 'CloudAddressSwap') && ComputeDriverRegistry::supports('fakecloud', 'CloudInstanceTypes'),
	'and the fake has only what it implements');
check(ComputeDriverRegistry::missing_scopes('linode', array('linodes:read_write'), array('linodes:read_write', 'ips:read_write'))
	=== array('ips:read_write'), 'Linode\'s scopes are checked');
check(ComputeDriverRegistry::missing_scopes('fakecloud', array(), array('linodes:read_write')) === array(),
	'a provider whose tokens carry no scopes lacks none');

// ---------------------------------------------------------------------------
section('A fake second provider is chosen by its account');

$oca = new OperatorCloudAccount(NULL);
$oca->set('oca_provider', 'fakecloud');
$oca->set('oca_method', OperatorCloudAccount::METHOD_KEY);
$oca->set('oca_account_name', 'Fake project ' . $tag);
$oca->store_credential('fake-token-' . $tag);
$oca->save();
$oca->load();
harness_register_row('oca_operator_cloud_accounts', 'oca_operator_cloud_account_id', $oca->key);

$driver = $oca->driver();
check($driver instanceof RegistryFakeCloud && $driver->token === 'fake-token-' . $tag, 'the account\'s driver is its provider\'s, with its token');
check(strpos($oca->label(), 'Fakecloud — Fake project ' . $tag) === 0, 'the account is labelled by its provider', $oca->label());
check($oca->missing_scopes() === array(), 'and its credential lacks no scope');
$why = $oca->check();
$oca->load();
check($why === '' && (string)$oca->get('oca_account_name') === 'Fake project ' . $tag && (string)$oca->get('oca_scopes') === '*',
	'a check of a provider that names no account keeps the name it was added under', $why);

$p = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'reg-' . $tag . '.example.com',
		'cvp_slug' => 'reg-' . $tag, 'cvp_status' => 'ready', 'cvp_docker_mode' => 'bare-metal', 'cvp_provider' => 'fakecloud',
		'cvp_hosting_mode' => 'operator', 'cvp_oca_operator_cloud_account_id' => (int)$oca->key) as $k => $v) {
	$p->set($k, $v);
}
$p->save();
$p->load();
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $p->key);

$held = HeldCloudAccounts::provision_token($p);
check($held['token'] === 'fake-token-' . $tag && $held['provider'] === 'fakecloud', 'a server\'s credential names its provider');
$pd = HeldCloudAccounts::provision_driver($p);
check($pd['driver'] instanceof RegistryFakeCloud, 'and its driver is that provider\'s');
check(HeldCloudAccounts::provider_of_key($oca->choice_key()) === 'fakecloud', 'an account key names its provider');

list($region, $type, $image) = ProvisionCustomerCloud::create_choices($p, $pd['driver']);
check($region === 'fc-1' && $type === 'fc-small' && $image === 'fc-ubuntu-26.04',
	'a new server at it starts from its driver\'s defaults, not the Hosting tab\'s Linode settings', "{$region} {$type} {$image}");
$p->set('cvp_region', 'fc-2');
$p->set('cvp_instance_type', 'fc-large');
list($region, $type, $image) = ProvisionCustomerCloud::create_choices($p, $pd['driver']);
check($region === 'fc-2' && $type === 'fc-large', 'what the provision chose wins');

$reverse = NodeReverseDns::driverForProvision($p);
check($reverse instanceof RegistryFakeCloud, 'reverse DNS is set with the provider\'s driver');

// ---------------------------------------------------------------------------
section('The copy\'s plan is the provider\'s');

$src = new ManagedNode(NULL);
$src->set('mgn_name', 'HarnessTest registry src ' . $tag);
$src->set('mgn_slug', 'reg-src-' . $tag);
$src->set('mgn_host', '192.0.2.9');
$src->set('mgn_uptime_enabled', false);
$src->set('mgn_last_host_report', json_encode(array('memory' => array('total_bytes' => 4000000000))));
$src->save();
$src->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $src->key);
$own = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'reg-src-' . $tag . '.example.com',
		'cvp_slug' => 'reg-src-' . $tag, 'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal', 'cvp_provider' => 'linode',
		'cvp_instance_type' => 'g6-standard-2', 'cvp_mgn_managed_node_id' => (int)$src->key) as $k => $v) {
	$own->set($k, $v);
}
$own->save();
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $own->key);

$types = function () use ($driver) { return $driver->instanceTypes(); };
SiteCopyRunner::$chain_lister = function ($node) {
	return array('chains' => array(array('chain_id' => 'chain-x', 'profile' => BackupProfile::MANAGER, 'bytes' => 1000,
		'runs' => array(array('seq' => 0, 'time' => gmdate('Y-m-d\TH:i:s\Z'), 'artifacts' => array('files' => 1000, 'db' => 1000))))),
		'objects' => array(), 'error' => null);
};
harness_defer(function () { SiteCopyRunner::$chain_lister = null; });
check(SiteCopyRunner::same_size_type($src, SiteCopy::FROM_SOURCE, $types, 'linode') === 'g6-standard-2',
	'at the source\'s own provider, its own plan');
check(SiteCopyRunner::same_size_type($src, SiteCopy::FROM_SOURCE, $types, 'fakecloud') === 'fc-large',
	'at another, the provider\'s smallest plan with the source\'s memory');

// ---------------------------------------------------------------------------
section('Nothing builds the Linode driver by name');

$root = PathHelper::getIncludePath('');
$allowed = array(
	'includes/cloud_compute/ComputeDriverRegistry.php',
	'includes/cloud_compute/LinodeComputeDriver.php',
	// The relay is born on Linode by design: its first boot uses Linode's StackScripts.
	'plugins/mailbox/includes/RelayCloudProvisioner.php',
	'plugins/mailbox/includes/relay_admin.php',
);
$offenders = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
	$path = str_replace('\\', '/', substr((string)$f, strlen($root)));
	if (substr($path, -4) !== '.php' || strpos($path, '/tests/') !== false || strpos($path, 'tests/') === 0
			|| strpos($path, 'vendor/') !== false || strpos($path, 'specs/') === 0 || in_array($path, $allowed, true)) {
		continue;
	}
	$src_text = (string)@file_get_contents((string)$f);
	if (preg_match('/new\s+LinodeComputeDriver\s*\(/', $src_text)) {
		$offenders[] = $path;
	}
}
check(!$offenders, 'no file outside the registry builds LinodeComputeDriver by name', implode(', ', $offenders));

harness_finish();

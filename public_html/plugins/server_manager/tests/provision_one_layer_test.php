<?php
/** @joinery-test
 * name: provision_one_layer
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * One layer of encryption, never two (specs/hosted_data_promise_disk_encryption.md
 * D1a, D10, WP3): a server whose data root we encrypt is born with the
 * provider's own disk encryption off and no provider swap disk, and is checked.
 *
 * Against Linode's API through a Guzzle mock (no real provider is ever asked):
 *
 *  - the driver sends disk_encryption only where the region offers the
 *    capability, refuses 'disabled' in a distributed region before any create,
 *    sends swap_size when given, and reports each instance's disk_encryption;
 *    regions() carries site_type and the capability;
 *  - the pipeline: an encrypted provision is created with disk_encryption
 *    disabled and swap_size 0, an unencrypted one with neither; an instance
 *    reporting the provider's layer on fails the provision before install, at
 *    create and at booting, with its instance id already saved; an absent
 *    answer is unknown and proceeds; a distributed region is refused before
 *    anything is created; the install passes install.sh --encrypted-data-root
 *    and this management node's recovery public key;
 *  - a relay birth sends neither field; an adopted server records the
 *    provider's answer and never claims ours;
 *  - while data_root_encryption_offered is off, nothing is marked encrypted.
 *
 * Every provision here is in a customer's account that does not exist, so the
 * real Provision Customer Cloud task can never create a server for one.
 *
 * Run: php plugins/server_manager/tests/provision_one_layer_test.php
 *
 * @version 1.3 - each adoption case's address is one no provision holds and the other case did not draw
 * @version 1.2 - the adopted servers are in a real account of ours, whose provider adoption records (B40)
 * @version 1.1 - a copy's layer is decided by SiteCopyRunner::copy_encryption(), never ticked, and a copy into its
 *               owner's account with a proven site key is born sealed to it (specs/site_copy_final_test.md E1)
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionCustomerCloud.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/** Runs the pipeline's two create-side steps against the driver given, no OAuth, no mail. */
class OneLayerProbe extends ProvisionCustomerCloud {
	public $driver;
	public $failures = array();
	/** What the row on disk named as its instance at the moment a failure was decided. */
	public $stored_instance_at_fail = null;
	protected function get_driver($provision) { return $this->driver; }
	protected function resolve_driver($provision): array { return array('driver' => $this->driver, 'reason' => '', 'park' => false); }
	protected function alert_and_fail($provision, $reason) {
		$this->failures[] = $reason;
		$on_disk = new CustomerCloudProvision((int)$provision->key, TRUE);
		$this->stored_instance_at_fail = (string)$on_disk->get('cvp_instance_id');
		$provision->fail($reason);
	}
	protected function notify_ops(string $subject, string $body): void {}
	public function ready($p) { return $this->handle_ready($p); }
	public function booting($p) { return $this->handle_booting($p); }
}

/** A Linode driver over a scripted HTTP queue; every request lands in $history. */
function pol_driver(array $responses, &$history): LinodeComputeDriver {
	$history = array();
	$stack = HandlerStack::create(new MockHandler($responses));
	$stack->push(Middleware::history($history));
	return new LinodeComputeDriver('test-token', new Client(array('handler' => $stack, 'base_uri' => LinodeComputeDriver::API_BASE)));
}

function pol_json(array $body, int $status = 200): Response {
	return new Response($status, array('Content-Type' => 'application/json'), json_encode($body));
}

/** A region as Linode's API describes it. */
function pol_region(string $id, string $site_type, bool $disk_encryption): Response {
	$caps = array('Linodes', 'Block Storage', 'Metadata');
	if ($disk_encryption) {
		$caps[] = 'Disk Encryption';
	}
	return pol_json(array('id' => $id, 'label' => 'Region ' . $id, 'site_type' => $site_type, 'capabilities' => $caps, 'status' => 'ok'));
}

/** An instance as Linode's API returns it; $layer null leaves the field out. */
function pol_instance(string $id, ?string $layer, string $status = 'provisioning', string $ip = ''): Response {
	$body = array('id' => (int)$id, 'status' => $status, 'label' => 'pol-' . $id, 'region' => 'us-east',
		'ipv4' => $ip !== '' ? array($ip) : array(), 'ipv6' => $ip !== '' ? '2001:db8::' . substr($id, -3) . '/128' : null);
	if ($layer !== null) {
		$body['disk_encryption'] = $layer;
	}
	return pol_json($body);
}

/** [method path, decoded json body] of each request made. */
function pol_requests(array $history): array {
	$out = array();
	foreach ($history as $h) {
		$req = $h['request'];
		$out[] = array($req->getMethod() . ' ' . $req->getUri()->getPath(), json_decode((string)$req->getBody(), true) ?: array());
	}
	return $out;
}

/** The body of the create call, or null when none was made. */
function pol_create_body(array $history): ?array {
	foreach (pol_requests($history) as $r) {
		if ($r[0] === 'POST /v4/linode/instances') {
			return $r[1];
		}
	}
	return null;
}

$suffix = bin2hex(random_bytes(3));
$db = DbConnector::get_instance()->get_db_link();

/**
 * A provision in a customer's account that does not exist: the real task can
 * only park it, never create a server for it.
 */
$make_provision = function (string $tag, bool $encrypted, array $extra = array()) use ($suffix) {
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_origin', 'admin');
	$p->set('cvp_usr_user_id', 990000 + random_int(0, 9999));
	$p->set('cvp_domain', 'harnesstest-ol-' . $tag . '-' . $suffix . '.example.com');
	$p->set('cvp_slug', 'harnesstest-ol-' . $tag . '-' . $suffix);
	$p->set('cvp_sitename', 'ol' . $tag . $suffix);
	$p->set('cvp_hosting_mode', 'customer');
	$p->set('cvp_region', 'us-east');
	$p->set('cvp_instance_type', 'g6-nanode-1');
	$p->set('cvp_docker_mode', 'bare-metal');
	$p->set('cvp_install_mode', 'fresh');
	$p->set('cvp_status', 'ready');
	if ($encrypted) {
		$p->set('cvp_data_root_encrypted', true);
	}
	foreach ($extra as $k => $v) {
		$p->set($k, $v);
	}
	$p->save();
	$p->load();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $p->key);
	return $p;
};

$key_ready = BackupRecoveryKey::is_ready();
$recovery_key = $key_ready ? base64_encode(BackupRecoveryKey::public_key()) : '';

// ---------------------------------------------------------------------------
section('The driver: disk_encryption and swap_size reach Linode only where they mean something');

$create = array('label' => 'pol-a', 'region' => 'us-east', 'type' => 'g6-nanode-1', 'image' => 'linode/ubuntu24.04',
	'root_pass' => 'Aa1!deadbeef');

$d = pol_driver(array(pol_region('us-east', 'core', true), pol_instance('880001', 'disabled')), $h);
$i = $d->createInstance($create + array('disk_encryption' => 'disabled', 'swap_size' => 0));
$body = pol_create_body($h);
check(($body['disk_encryption'] ?? null) === 'disabled', 'a core region with the capability: disk_encryption disabled is sent', json_encode($body));
check(array_key_exists('swap_size', (array)$body) && $body['swap_size'] === 0, 'swap_size 0 is sent, so Linode makes no swap disk');
check(pol_requests($h)[0][0] === 'GET /v4/regions/us-east', 'the region is asked first');
check(($i['disk_encryption'] ?? 'x') === 'disabled', 'the created instance reports its disk_encryption');

$d = pol_driver(array(pol_region('us-west', 'core', false), pol_instance('880002', null)), $h);
$i = $d->createInstance(array_replace($create, array('region' => 'us-west')) + array('disk_encryption' => 'disabled', 'swap_size' => 0));
$body = pol_create_body($h);
check(is_array($body) && !array_key_exists('disk_encryption', $body),
	'a core region without the capability: no field is sent (there is no layer to turn off)', json_encode($body));
check(array_key_exists('disk_encryption', $i) && $i['disk_encryption'] === null, 'an instance that does not say reports null (unknown)');

$d = pol_driver(array(pol_region('us-edge-1', 'distributed', true), pol_instance('880003', 'enabled')), $h);
$threw = null;
try {
	$d->createInstance(array_replace($create, array('region' => 'us-edge-1')) + array('disk_encryption' => 'disabled'));
} catch (CloudComputeException $e) {
	$threw = $e;
}
check($threw !== null && (int)$threw->getCode() === 400 && stripos($threw->getMessage(), 'distributed') !== false,
	'a distributed region refuses disabled, as a terminal error', $threw ? $threw->getMessage() : 'no exception');
check(pol_create_body($h) === null && count($h) === 1, 'and nothing is created');

$d = pol_driver(array(pol_instance('880004', 'enabled')), $h);
$d->createInstance($create);
$body = pol_create_body($h);
check(count($h) === 1 && is_array($body) && !array_key_exists('disk_encryption', $body) && !array_key_exists('swap_size', $body),
	'with neither option, one call and neither field: the provider\'s defaults', json_encode($body));

$d = pol_driver(array(pol_instance('880005', 'enabled', 'running', '198.51.100.5'), pol_instance('880005', 'garbage')), $h);
check($d->getInstance('880005')['disk_encryption'] === 'enabled', 'getInstance reports the provider\'s layer on');
check($d->getInstance('880005')['disk_encryption'] === null, 'a value that is neither enabled nor disabled is unknown');

$d = pol_driver(array(pol_json(array('data' => array(
	array('id' => 'us-east', 'label' => 'Newark, NJ', 'site_type' => 'core', 'capabilities' => array('Linodes', 'Disk Encryption')),
	array('id' => 'us-edge-1', 'label' => 'Edge', 'site_type' => 'distributed', 'capabilities' => array('Linodes')),
), 'page' => 1, 'pages' => 1))), $h);
$regions = $d->regions();
check(($regions['us-east']['site_type'] ?? '') === 'core' && ($regions['us-east']['disk_encryption'] ?? null) === true
	&& ($regions['us-east']['label'] ?? '') === 'Newark, NJ',
	'regions() carries each region\'s site_type, label and the disk-encryption capability', json_encode($regions));
check(($regions['us-edge-1']['site_type'] ?? '') === 'distributed' && ($regions['us-edge-1']['disk_encryption'] ?? null) === false,
	'including a distributed region without it');
$threw = false;
try { $d->regionInfo(' '); } catch (CloudComputeException $e) { $threw = true; }
check($threw, 'regionInfo refuses a blank region rather than reading the whole list');

// B28: a data root on a Block Storage volume sits under the volume's encryption.
$vols = pol_json(array('data' => array(
	array('id' => 18488612, 'label' => 'mgr-b-vol', 'encryption' => 'enabled'),
	array('id' => 18488613, 'label' => 'plain-vol', 'encryption' => 'disabled'),
), 'page' => 1, 'pages' => 1));
$d = pol_driver(array($vols, $vols), $h);
check($d->volumeEncryption('880006', '/dev/disk/by-id/scsi-0Linode_Volume_mgr-b-vol') === 'enabled'
	&& $d->volumeEncryption('880006', '/dev/disk/by-id/scsi-0Linode_Volume_plain-vol') === 'disabled'
	&& pol_requests($h)[0][0] === 'GET /v4/linode/instances/880006/volumes',
	'volumeEncryption reads the attached volume by the label in its device path', json_encode(pol_requests($h)));
$d = pol_driver(array(), $h);
check($d->volumeEncryption('880006', '/dev/sdb') === '' && count($h) === 0,
	'a device that is not a volume is the server\'s own disk: no answer of the volume\'s, and nothing asked');
$d = pol_driver(array($vols), $h);
$threw = false;
try { $d->volumeEncryption('880006', '/dev/disk/by-id/scsi-0Linode_Volume_gone'); } catch (CloudComputeException $e) { $threw = true; }
check($threw, 'a volume this instance does not have is an error, never a guess');

// ---------------------------------------------------------------------------
section('The pipeline: an encrypted provision is born with the provider\'s layer off and no swap disk');

if (!$key_ready) {
	// Without a proven recovery key nothing encrypted may be made: the refusal
	// is the whole behaviour to prove on such a box.
	$p = $make_provision('nokey', true);
	$probe = new OneLayerProbe();
	$probe->driver = pol_driver(array(pol_region('us-east', 'core', true), pol_instance('880100', 'disabled')), $h);
	$probe->ready($p);
	$p->load();
	check($p->get('cvp_status') === 'failed' && count($h) === 0 && stripos((string)$p->get('cvp_error'), 'recovery key') !== false,
		'without a proven recovery key, an encrypted provision is refused before anything is asked or created', (string)$p->get('cvp_error'));
	harness_skip('the encrypted-birth checks', 'this management node has no proven recovery key');
} else {
	$p = $make_provision('enc', true);
	$probe = new OneLayerProbe();
	$probe->driver = pol_driver(array(pol_region('us-east', 'core', true), pol_instance('880101', 'disabled')), $h);
	$probe->ready($p);
	$p->load();
	$body = pol_create_body($h);
	check(($body['disk_encryption'] ?? null) === 'disabled' && array_key_exists('swap_size', (array)$body) && $body['swap_size'] === 0,
		'the create carries disk_encryption disabled and swap_size 0', json_encode($body));
	check($p->get('cvp_status') === 'booting' && (string)$p->get('cvp_instance_id') === '880101', 'and the provision boots');
	check($p->get('cvp_provider_disk_encryption') === 'disabled', 'the provider\'s answer is recorded: disabled');

	$p = $make_provision('plain', false);
	$probe = new OneLayerProbe();
	$probe->driver = pol_driver(array(pol_instance('880102', 'enabled')), $h);
	$probe->ready($p);
	$p->load();
	$body = pol_create_body($h);
	check(count($h) === 1 && is_array($body) && !array_key_exists('disk_encryption', $body) && !array_key_exists('swap_size', $body),
		'an unencrypted provision sends neither, and asks nothing of the region', json_encode(pol_requests($h)));
	check($p->get('cvp_status') === 'booting' && $p->get('cvp_provider_disk_encryption') === 'enabled',
		'and records the provider\'s layer as it answered, for the node page\'s count of layers (E4)');

	// -----------------------------------------------------------------------
	section('The provider\'s layer reported on fails the provision before install');

	$p = $make_provision('oncreate', true);
	$probe = new OneLayerProbe();
	$probe->driver = pol_driver(array(pol_region('us-east', 'core', true), pol_instance('880103', 'enabled')), $h);
	$probe->ready($p);
	$p->load();
	check($p->get('cvp_status') === 'failed' && stripos((string)$p->get('cvp_error'), 'disk encryption is on') !== false
		&& stripos((string)$p->get('cvp_error'), 'only layer') !== false,
		'at create: failed, saying the provider\'s layer is on and ours must be the only one', (string)$p->get('cvp_error'));
	check($probe->stored_instance_at_fail === '880103', 'its instance id was saved before the check failed it',
		var_export($probe->stored_instance_at_fail, true));
	check((string)$p->get('cvp_instance_id') === '880103' && $p->get('cvp_provider_disk_encryption') === 'enabled',
		'the row names the machine left for its owner to delete, and what the provider said');
	check(!(int)$p->get('cvp_mgn_managed_node_id'), 'no node is made, so nothing is installed');

	$p = $make_provision('onboot', true, array('cvp_status' => 'booting', 'cvp_instance_id' => '880104',
		'cvp_provider_disk_encryption' => 'unknown'));
	$probe = new OneLayerProbe();
	$probe->driver = pol_driver(array(pol_instance('880104', 'enabled', 'running', '198.51.100.104')), $h);
	$probe->booting($p);
	$p->load();
	check($p->get('cvp_status') === 'failed' && stripos((string)$p->get('cvp_error'), 'disk encryption is on') !== false,
		'at booting: failed the same way', (string)$p->get('cvp_error'));
	check(!(int)$p->get('cvp_mgn_managed_node_id') && $p->get('cvp_provider_disk_encryption') === 'enabled',
		'before any node or install job exists, with the answer recorded');

	// -----------------------------------------------------------------------
	section('An absent answer is unknown, and the provision proceeds');

	$p = $make_provision('absent', true);
	$probe = new OneLayerProbe();
	$probe->driver = pol_driver(array(pol_region('us-east', 'core', true), pol_instance('880105', null)), $h);
	$probe->ready($p);
	$p->load();
	check($p->get('cvp_status') === 'booting' && $p->get('cvp_provider_disk_encryption') === 'unknown',
		'at create: recorded unknown, and it boots', $p->get('cvp_status') . ' / ' . var_export($p->get('cvp_provider_disk_encryption'), true));

	// At booting, all the way to the install job: the bootstrap names the
	// encrypted data root and the recovery key it is sealed to.
	$ip = '198.51.100.' . random_int(110, 240);
	$p = $make_provision('install', true, array('cvp_status' => 'booting', 'cvp_instance_id' => '880106',
		'cvp_provider_disk_encryption' => 'disabled'));
	$probe = new OneLayerProbe();
	$probe->driver = pol_driver(array(pol_instance('880106', null, 'running', $ip)), $h);
	$probe->booting($p);
	$p->load();
	$node_id = (int)$p->get('cvp_mgn_managed_node_id');
	$job = $node_id ? ManagementJob::latestForNode($node_id, 'install_node') : null;
	// The install job is queued for the plane-side executor: removed at once,
	// with the node and its storage space, so nothing ever runs it.
	$cmd = '';
	$params = array();
	if ($job) {
		foreach ((array)(json_decode((string)$job->get('mjb_commands'), true)['steps'] ?? array()) as $s) {
			if (($s['type'] ?? '') === 'ssh') { $cmd .= $s['cmd']; }
		}
		$params = $job->get('mjb_parameters');
		$params = is_string($params) ? (json_decode($params, true) ?: array()) : (array)$params;
	}
	if ($node_id) {
		$db->prepare('DELETE FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ?')->execute(array($node_id));
		$db->prepare('DELETE FROM sps_storage_spaces WHERE sps_mgn_managed_node_id = ?')->execute(array($node_id));
		$db->prepare('UPDATE cvp_customer_cloud_provisions SET cvp_mgn_managed_node_id = NULL WHERE cvp_customer_cloud_provision_id = ?')
			->execute(array($p->key));
		$db->prepare('DELETE FROM mgn_managed_nodes WHERE mgn_managed_node_id = ?')->execute(array($node_id));
	}
	check($p->get('cvp_status') === 'installing' && $job !== null, 'an absent answer at booting does not stop the install',
		(string)$p->get('cvp_status') . ' ' . (string)$p->get('cvp_error'));
	check($p->get('cvp_provider_disk_encryption') === 'disabled', 'and does not overwrite the answer the create gave');
	$want = "--encrypted-data-root --recovery-public-key='" . $recovery_key . "'";
	check(preg_match('/install\.sh -y -q server[^;]*' . preg_quote($want, '/') . '/', $cmd) === 1,
		'the bootstrap runs install.sh server --encrypted-data-root --recovery-public-key=<this management node\'s key>', $cmd);
	check(strlen($recovery_key) === 44, 'the key passed is the 44-character base64 public key');
	check(!empty($params['encrypted_data_root']) && ($params['recovery_public_key'] ?? '') === $recovery_key,
		'the job\'s parameters carry it too, so a retried install is encrypted the same way');

	// -----------------------------------------------------------------------
	section('A distributed region is refused before anything is created');

	$p = $make_provision('edge', true, array('cvp_region' => 'us-edge-1'));
	$probe = new OneLayerProbe();
	$probe->driver = pol_driver(array(pol_region('us-edge-1', 'distributed', true), pol_instance('880107', 'enabled')), $h);
	$probe->ready($p);
	$p->load();
	check($p->get('cvp_status') === 'failed' && stripos((string)$p->get('cvp_error'), 'distributed') !== false,
		'refused, saying why', (string)$p->get('cvp_error'));
	check(count($h) === 1 && pol_create_body($h) === null && trim((string)$p->get('cvp_instance_id')) === '',
		'only the region was asked: no instance exists');
	check(trim((string)$p->get('cvp_root_pass_sealed')) === '', 'and no install password was ever sealed for it');
}

// ---------------------------------------------------------------------------
section('The install command: the flags go on the line that makes the data root');

$node = new ManagedNode(NULL);
$node->set('mgn_name', 'HarnessTest one layer ' . $suffix);
$node->set('mgn_slug', 'harnesstest-ol-node-' . $suffix);
$node->set('mgn_host', '203.0.113.' . random_int(2, 250));
$node->set('mgn_ssh_user', 'root');
$node->set('mgn_port', 8080);
$node->save();
$node->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
$sample_key = base64_encode(str_repeat("\x07", 32));
$flags = "--encrypted-data-root --recovery-public-key='" . $sample_key . "'";
$base = array('mode' => 'fresh', 'sitename' => 'olsite' . $suffix, 'domain' => 'ol-' . $suffix . '.example.com');
$ssh = function (array $steps) {
	$out = '';
	foreach ($steps as $s) { if (($s['type'] ?? '') === 'ssh') { $out .= $s['cmd']; } }
	return $out;
};

$metal = $ssh(JobCommandBuilder::build_install_node($node, $base + array('docker_mode' => 'bare-metal',
	'encrypted_data_root' => true, 'recovery_public_key' => $sample_key)));
check(preg_match('/install\.sh -y -q server[^;]*' . preg_quote($flags, '/') . ';/', $metal) === 1, 'bare metal: on install.sh server', $metal);
check(preg_match('/install\.sh -y -q site[^\n]*--encrypted-data-root/', $metal) === 0, 'and not on the site install');

$docker = $ssh(JobCommandBuilder::build_install_node($node, $base + array('docker_mode' => 'docker',
	'encrypted_data_root' => true, 'recovery_public_key' => $sample_key)));
check(preg_match('/install\.sh -y -q docker [^\n]*' . preg_quote($flags, '/') . '\n/', $docker) === 1, 'Docker: on install.sh docker', $docker);

$plain = $ssh(JobCommandBuilder::build_install_node($node, $base + array('docker_mode' => 'bare-metal')));
check(strpos($plain, '--encrypted-data-root') === false && strpos($plain, '--recovery-public-key') === false,
	'an unencrypted install passes neither');

foreach (array('' => 'no key', 'not-a-key' => 'a malformed key', base64_encode(str_repeat('a', 24)) => 'a key of the wrong size') as $bad => $what) {
	$threw = false;
	try {
		JobCommandBuilder::build_install_node($node, $base + array('docker_mode' => 'bare-metal',
			'encrypted_data_root' => true, 'recovery_public_key' => $bad));
	} catch (Exception $e) {
		$threw = stripos($e->getMessage(), 'recovery public key') !== false;
	}
	check($threw, 'an encrypted install with ' . $what . ' is refused, never sent without its recipient');
}

// ---------------------------------------------------------------------------
section('A relay birth sends no disk_encryption');

if (!class_exists('RelayCloudProvisioner') || !class_exists('RelayCloudProvision')) {
	harness_skip('relay births', 'the mailbox plugin is not active here');
} elseif (!is_file(PathHelper::getIncludePath('agent_dist/support_bundle.tar.gz'))) {
	harness_skip('relay births', 'no agent_dist/support_bundle.tar.gz on this deployment');
} else {
	$relay_driver = pol_driver(array(
		pol_json(array('id' => 'us-test', 'site_type' => 'core', 'capabilities' => array('Linodes', 'Metadata', 'Disk Encryption'))),
		pol_instance('880200', 'enabled'),
	), $rh);
	RelayCloudProvisioner::$driver_factory = function ($run) use ($relay_driver) { return $relay_driver; };
	harness_defer(function () { RelayCloudProvisioner::$driver_factory = null; });
	// The birth's user-data names the relay keys: a run's own, so the test
	// never mints the deployment's real ones as a side effect.
	RelayClientIdentity::useThrowawayForTests(RelayClientIdentity::KIND_CLIENT);
	RelayClientIdentity::useThrowawayForTests(RelayClientIdentity::KIND_OPERATOR);
	harness_defer(function () { RelayClientIdentity::resetForTests(); });
	$run = new RelayCloudProvision(NULL);
	$run->set('rcl_kind', 'provision');
	$run->set('rcl_status', 'ready');
	$run->set('rcl_provider', 'linode');
	$run->set('rcl_mail_hostname', 'mx.pol-' . $suffix . '.example');
	$run->set('rcl_region', 'us-test');
	$run->set('rcl_instance_type', 'g6-test-1');
	$run->sealToken('fake-access-token');
	$run->save();
	harness_register_model('RelayCloudProvision', $run->key);
	harness_defer(function () use ($run) { $run->eraseBundle(); });
	$said = (new RelayCloudProvisioner())->advance($run);
	$body = pol_create_body($rh);
	check(is_array($body) && !array_key_exists('disk_encryption', $body) && !array_key_exists('swap_size', $body),
		'the relay is created with the provider\'s defaults: neither field', $said . ' ' . json_encode(pol_requests($rh)));
}

// ---------------------------------------------------------------------------
section('An adopted server records the provider\'s answer and never claims ours');

/** One account of ours holding fixed servers. */
class PolAdoptionAccount implements CloudMachineTransfer {
	public $instances;
	function __construct(array $instances) { $this->instances = $instances; }
	public function listInstances(): array { return $this->instances; }
	public function getInstanceTransfer(string $instance_id): array { return array(); }
}
$adopter = make_user('pol' . $suffix, 10);
$no_dns = function ($name) { return array(); };
// The account the server is in: adoption records its provider.
$pol_oca = new OperatorCloudAccount(NULL);
$pol_oca->set('oca_provider', 'linode');
$pol_oca->set('oca_method', OperatorCloudAccount::METHOD_KEY);
$pol_oca->set('oca_account_name', 'HarnessTest pol ' . $suffix);
$pol_oca->set('oca_is_test', true);
$pol_oca->store_credential('harness-pol-token');
$pol_oca->save();
$pol_oca->load();
harness_register_row('oca_operator_cloud_accounts', 'oca_operator_cloud_account_id', $pol_oca->key);
// Each case's server needs an address no provision holds: adoption refuses one
// that is held, and a random draw could repeat the other case's or meet a row
// another suite made (a db --changed gate, 2026-10-30).
$pol_used = array();
foreach (array('enabled' => 'enabled', 'absent' => 'unknown') as $case => $expect) {
	do {
		$v4 = '192.0.2.' . random_int(100, 250);
	} while (in_array($v4, $pol_used, true) || CustomerCloudProvision::for_machine_address($v4));
	$pol_used[] = $v4;
	$an = new ManagedNode(NULL);
	$an->set('mgn_name', 'HarnessTest adopt ' . $case . ' ' . $suffix);
	$an->set('mgn_slug', 'harnesstest-ol-adopt-' . $case . '-' . $suffix);
	$an->set('mgn_host', $v4);
	$an->set('mgn_site_url', 'https://ol-adopt-' . $case . '-' . $suffix . '.example.invalid');
	$an->set('mgn_ssh_user', 'root');
	$an->set('mgn_enabled', true);
	$an->save();
	$an->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $an->key);
	$server = array('id' => '8803' . random_int(10, 99), 'label' => 'pol-adopt', 'region' => 'us-east', 'status' => 'running',
		'ipv4_public' => array($v4), 'ipv6' => '', 'created' => '2026-01-01 00:00:00', 'tags' => array());
	if ($case === 'enabled') {
		$server['disk_encryption'] = 'enabled';
	}
	try {
		$adopted = CloudServerAdoption::adopt($an, (int)$adopter->key, '', array('oca:' . (int)$pol_oca->key => new PolAdoptionAccount(array($server))), $no_dns);
		harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $adopted->key);
		check($adopted->get('cvp_provider_disk_encryption') === $expect && !$adopted->get('cvp_data_root_encrypted'),
			'listed ' . ($case === 'enabled' ? 'with the layer on: recorded enabled' : 'with no answer: recorded unknown')
			. ', and not encrypted by us', var_export($adopted->get('cvp_provider_disk_encryption'), true));
	} catch (Exception $e) {
		check(false, 'adoption (' . $case . ')', $e->getMessage());
	}
}

// ---------------------------------------------------------------------------
section('Nothing is marked encrypted while data_root_encryption_offered is off');

harness_set_setting_mem('data_root_encryption_offered', '0');
check(!CustomerCloudProvision::data_root_encryption_offered(), 'off: not offered');
check(!CustomerCloudProvision::born_encrypted_by_default('operator'), 'off: a server in our account is not born encrypted');
// A site whose recovery key is proven, with its public half on record, and one without.
$e1_keyed = new ManagedNode(NULL);
$e1_keyed->set('mgn_backup_recovery_public_key', base64_encode(str_repeat("\x07", 32)));
$e1_bare = new ManagedNode(NULL);
check(!SiteCopyRunner::copy_encryption($e1_keyed, 'operator')['ours'] && !SiteCopyRunner::copy_encryption($e1_keyed, 'customer')['ours'],
	'off: a copy is not born encrypted by us, in any account');

harness_set_setting_mem('data_root_encryption_offered', '1');
check(CustomerCloudProvision::born_encrypted_by_default('operator'), 'on: a server in our account is born encrypted');
check(!CustomerCloudProvision::born_encrypted_by_default('customer'), 'on: a server in a customer\'s account is born unencrypted (self-hosted)');
$e1 = SiteCopyRunner::copy_encryption($e1_bare, 'operator');
check($e1['ours'] && $e1['recipient'] === '' && strpos($e1['says'], 'one layer') !== false,
	'on: a copy into one of our accounts is born with ours, sealed to this management node\'s key: decided, never ticked');
$e1 = SiteCopyRunner::copy_encryption($e1_keyed, 'customer');
check($e1['ours'] && $e1['recipient'] === base64_encode(str_repeat("\x07", 32)),
	'on: into its owner\'s account, a site with a proven key is born with ours, sealed to that key (E1, D2)');
$e1 = SiteCopyRunner::copy_encryption($e1_bare, 'customer');
check(!$e1['ours'] && strpos($e1['says'], 'Prove') !== false,
	'on: into its owner\'s account without a proven key: the provider\'s default, and the tab says how to get ours');
harness_set_setting_mem('data_root_encryption_offered', '0');

// Each creating path takes its answer from the one rule above. The order path
// through a paid activation is proven in managed_site_purchase; the others
// need a real account or the getjoinery API, so this guards that each still
// asks the rule rather than deciding for itself.
foreach (array(
	'plugins/server_manager/includes/provisioning/PollHostingOrders.php' => 'born_encrypted_by_default(',
	'plugins/server_manager/includes/fulfillment_providers/CustomerCloudFulfillment.php' => 'born_encrypted_by_default(',
	'plugins/server_manager/views/admin/install_node_form.php' => 'born_encrypted_by_default(',
	'plugins/server_manager/includes/SiteCopyRunner.php' => 'self::copy_encryption(',
) as $file => $call) {
	$src = (string)file_get_contents(PathHelper::getIncludePath($file));
	check(strpos($src, $call) !== false && strpos($src, "'cvp_data_root_encrypted', true") !== false,
		basename($file) . ' marks a provision encrypted only through the rule');
}

// ---------------------------------------------------------------------------
section('The node page says it in one line');

$line = function (bool $encrypted, ?string $layer) {
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_data_root_encrypted', $encrypted);
	$p->set('cvp_provider_disk_encryption', $layer);
	return $p->data_root_line();
};
check($line(true, 'disabled') === "Data root: encrypted by us · provider's disk encryption: off", 'encrypted, provider off', $line(true, 'disabled'));
check($line(true, 'unknown') === "Data root: encrypted by us · provider's disk encryption: unknown", 'encrypted, provider unknown');
check($line(false, 'enabled') === "Data root: not encrypted by us · provider's disk encryption: on", 'an adopted server, provider on');
check($line(false, null) === '', 'a provision that was never asked says nothing');
$now = function (bool $born_encrypted, $reported, ?string $provider) {
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_data_root_encrypted', $born_encrypted);
	$p->set('cvp_provider_disk_encryption', 'enabled');
	return $p->data_root_line($reported, $provider);
};
check($now(false, array('backing' => 'luks-device'), 'enabled') === "Data root: encrypted by us · provider's disk encryption: on",
	'a server born plain and encrypted in place since: as it reports now, the line agreeing with its Layers (B44)', $now(false, array('backing' => 'luks-device'), 'enabled'));
check($now(true, 'none', 'disabled') === "Data root: not encrypted by us · provider's disk encryption: off",
	'one born encrypted and decrypted since: not encrypted, and the provider\'s layer as the page reads it');
check($now(true, null, null) === "Data root: encrypted by us · provider's disk encryption: on",
	'no report yet: as it was born');

harness_finish();

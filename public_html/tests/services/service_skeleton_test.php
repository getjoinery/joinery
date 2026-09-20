<?php
/** @joinery-test
 * name: service_skeleton
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The enrolment skeleton every rented service sits on
 * (specs/services_phase2_platform.md §4, §10 item 1):
 *
 *   - ServiceClient: the tenant's one way to reach an operator's service —
 *     the URL it builds, the key pair in dash-spelled headers, the JSON body,
 *     and the error contract (unreachable, unreadable, refused, not configured).
 *     The network leg is stood in for, so nothing here leaves the process.
 *   - ServiceTenantLadder: active → lapsed → in grace → suspended, and back to
 *     active in place when entitlement returns; the closures fire exactly on
 *     the two rungs that touch the provider; rows on other rungs are left alone.
 *     Walked on an in-memory row, so no table is needed.
 *
 * Run: php tests/run.php --only=tests/services/service_skeleton_test.php
 *
 * @version 1.1 - a deployment with no secret_box_key
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

// ── Stand-ins ──────────────────────────────────────────────────────────────

/** A client whose network leg records the request and answers a canned reply. */
class SkeletonTestClient extends ServiceClient {
	public $requests = array();
	public $reply = array('body' => '{"data":{}}', 'http' => 200, 'error' => '');
	public function __construct() {
		parent::__construct('harness_svc_url', 'harness_svc_public', 'harness_svc_secret', 'harness');
	}
	protected function label(): string { return 'Harness service'; }
	protected function transport(string $url, array $headers, string $body): array {
		$this->requests[] = array('url' => $url, 'headers' => $headers, 'body' => $body);
		return $this->reply;
	}
}

/** A tenant row that lives in memory: set()/get() as any model, save() only counts. */
class SkeletonTestRow extends SystemBase {
	public static $prefix = 'hst';
	public static $tablename = 'hst_harness_skeleton_rows';
	public static $pkey_column = 'hst_id';
	public static $field_specifications = array(
		'hst_id'         => array('type' => 'int8'),
		'hst_state'      => array('type' => 'varchar(20)'),
		'hst_lapse_time' => array('type' => 'timestamp(6)'),
		'hst_check_time' => array('type' => 'timestamp(6)'),
	);
	public $saves = 0;
	function save($debug = false) { $this->saves++; return true; }
}

$columns = array('state' => 'hst_state', 'lapse_time' => 'hst_lapse_time', 'check_time' => 'hst_check_time');

// ── ServiceClient ──────────────────────────────────────────────────────────

section('ServiceClient: not configured');
$client = new SkeletonTestClient();
check(!$client->configured(), 'three empty settings mean not configured');
try {
	$client->call('anything', array());
	check(false, 'an unconfigured client refuses to call');
} catch (ServiceClientException $e) {
	check(strpos($e->getMessage(), 'Harness service is not configured') === 0,
		'the refusal names the service and says to configure it', $e->getMessage());
}
check(count($client->requests) === 0, 'nothing went on the wire');

section('ServiceClient: the call shape');
harness_set_setting_mem('harness_svc_url', 'https://operator.example/');
harness_set_setting_mem('harness_svc_public', 'public_abc123');
harness_set_setting_mem('harness_svc_secret', 'secret_xyz789');
$client = new SkeletonTestClient();
check($client->configured(), 'three filled settings mean configured');
check($client->serviceUrl() === 'https://operator.example', 'the trailing slash is dropped from the URL');
$client->reply = array('body' => json_encode(array('data' => array('slug' => 't7', 'status' => 'active'))), 'http' => 200, 'error' => '');
$data = $client->call('fleet_enroll', array('public_key' => 'pk'));
check($data === array('slug' => 't7', 'status' => 'active'), 'a 200 answers its data array');
$req = $client->requests[0];
check($req['url'] === 'https://operator.example/api/v1/action/harness/fleet_enroll',
	'the URL is {url}/api/v1/action/{segment}/{action}', $req['url']);
check(in_array('public-key: public_abc123', $req['headers'], true), 'the public key rides a dash-spelled header');
check(in_array('secret-key: secret_xyz789', $req['headers'], true), 'the secret key rides a dash-spelled header');
check(in_array('Content-Type: application/json', $req['headers'], true), 'the body is declared JSON');
check(json_decode($req['body'], true) === array('public_key' => 'pk'), 'the payload is the JSON body');

section('ServiceClient: the error contract');
$client->reply = array('body' => json_encode(array('error' => 'Your subscription does not include a slot.')), 'http' => 403, 'error' => '');
try {
	$client->call('fleet_enroll', array());
	check(false, 'a non-200 throws');
} catch (ServiceClientException $e) {
	check($e->getMessage() === 'Harness service: Your subscription does not include a slot.',
		'a refusal carries the remote error text', $e->getMessage());
}
$client->reply = array('body' => '<html>gateway</html>', 'http' => 502, 'error' => '');
try {
	$client->call('fleet_enroll', array());
	check(false, 'an unreadable body throws');
} catch (ServiceClientException $e) {
	check(strpos($e->getMessage(), 'unreadable response (HTTP 502)') !== false,
		'an unreadable body names the HTTP status', $e->getMessage());
}
$client->reply = array('body' => false, 'http' => 0, 'error' => 'Could not resolve host');
try {
	$client->call('fleet_enroll', array());
	check(false, 'a transport failure throws');
} catch (ServiceClientException $e) {
	check($e->getMessage() === 'Could not reach harness service: Could not resolve host',
		'a transport failure says the service could not be reached', $e->getMessage());
}
$client->reply = array('body' => json_encode(array('status' => 'ok')), 'http' => 200, 'error' => '');
check($client->call('fleet_status', array()) === array(), 'a 200 with no data array answers an empty array');

section('ServiceClient: a sealed secret opens');
try {
	$box = new SecretBox();
} catch (\Throwable $e) {
	$box = null;
}
if ($box === null) {
	harness_skip('no secret_box_key on this deployment');
} else {
	// The seal needs a declared locator; the mailbox fleet's secret is one, and
	// this is the same read every service client makes.
	if (!class_exists('FleetClient')) {
		harness_skip('mailbox plugin not active — no declared secret locator to seal under');
	} else {
		harness_set_setting_mem('mailbox_fleet_service_url', 'https://operator.example');
		harness_set_setting_mem('mailbox_fleet_api_public_key', 'public_abc123');
		harness_set_setting_mem('mailbox_fleet_api_secret_key',
			ServiceClient::storedSecret('mailbox_fleet_api_secret_key', 'secret_sealed1'));
		check(SecretBox::looksEncrypted(Globalvars::get_instance()->get_setting('mailbox_fleet_api_secret_key')),
			'storedSecret seals the value at rest');
		$fleet = new FleetClient();
		check($fleet->configured(), 'a sealed secret counts as configured');
		$probe = new ReflectionMethod('ServiceClient', 'secretKey');
		check($probe->invoke($fleet) === 'secret_sealed1', 'the sealed secret opens to its plaintext for the wire');
	}
}

section('ServiceClient: a deployment with no secret_box_key');
// Blanking the key in memory is what a zero-config site looks like to
// SecretBox (the key lives in the config file, never in stg_settings).
$sealed_blob = ($box !== null && class_exists('FleetClient'))
	? ServiceClient::storedSecret('mailbox_fleet_api_secret_key', 'secret_sealed2') : '';
$saved_key = Globalvars::get_instance()->get_setting('secret_box_key', false, true);
harness_set_setting_mem('secret_box_key', '');
try {
	$plain = new SkeletonTestClient();
	harness_set_setting_mem('harness_svc_secret', 'secret_plain');
	check($plain->configured(), 'a plaintext secret counts as configured with no box');
	$probe = new ReflectionMethod('ServiceClient', 'secretKey');
	check($probe->invoke($plain) === 'secret_plain', 'the plaintext secret is used as it is');
	if ($sealed_blob === '') {
		harness_skip('no sealed blob to test with');
	} else {
		harness_set_setting_mem('harness_svc_secret', $sealed_blob);
		check(!$plain->configured(), 'a sealed secret that cannot be opened here means not configured, not a throw');
	}
} catch (\Throwable $e) {
	check(false, 'configured() answers on a deployment with no secret_box_key', get_class($e) . ': ' . $e->getMessage());
} finally {
	harness_set_setting_mem('secret_box_key', $saved_key);
	harness_set_setting_mem('harness_svc_secret', 'secret_xyz789');
}

// ── ServiceTenantLadder ────────────────────────────────────────────────────

section('ServiceTenantLadder: rungs');
$fired = array();
$suspend = function ($row) use (&$fired) { $fired[] = 'suspend'; };
$reactivate = function ($row) use (&$fired) { $fired[] = 'reactivate'; };
$t0 = '2026-09-01 12:00:00';

$row = new SkeletonTestRow(NULL);
$row->set('hst_state', ServiceTenantLadder::STATE_ACTIVE);
$step = ServiceTenantLadder::advance($row, $columns, true, 14, $suspend, $reactivate, $t0);
check($step === ServiceTenantLadder::STEP_CHECKED, 'active + entitled → checked');
check($row->get('hst_check_time') === $t0, 'the check time is stamped');
check($row->get('hst_lapse_time') === null, 'no lapse is running');
check($row->saves === 1, 'one save');

$step = ServiceTenantLadder::advance($row, $columns, false, 14, $suspend, $reactivate, $t0);
check($step === ServiceTenantLadder::STEP_LAPSED, 'active + not entitled → lapsed');
check($row->get('hst_lapse_time') === $t0, 'the lapse time is stamped on the first pass');
check($row->get('hst_state') === ServiceTenantLadder::STATE_ACTIVE, 'the row stays active through the window');
check(ServiceTenantLadder::graceEnds($row, 'hst_lapse_time', 14) === '2026-09-15 12:00:00', 'graceEnds is lapse + grace days');

$step = ServiceTenantLadder::advance($row, $columns, false, 14, $suspend, $reactivate, '2026-09-10 12:00:00');
check($step === ServiceTenantLadder::STEP_IN_GRACE, 'inside the window → in_grace');
check($row->saves === 2, 'in_grace writes nothing');
check($fired === array(), 'no act inside the window');

$step = ServiceTenantLadder::advance($row, $columns, true, 14, $suspend, $reactivate, '2026-09-10 12:00:00');
check($step === ServiceTenantLadder::STEP_CHECKED && $row->get('hst_lapse_time') === null,
	're-entitled inside the window clears the lapse in place');
check($fired === array(), 'a lapse cleared inside the window fires no act');

ServiceTenantLadder::advance($row, $columns, false, 14, $suspend, $reactivate, $t0);
$step = ServiceTenantLadder::advance($row, $columns, false, 14, $suspend, $reactivate, '2026-09-15 12:00:01');
check($step === ServiceTenantLadder::STEP_SUSPENDED, 'past the window → suspended');
check($row->get('hst_state') === ServiceTenantLadder::STATE_SUSPENDED, 'the row is suspended');
check($fired === array('suspend'), 'the suspend act fires once, after the save');
check($row->get('hst_lapse_time') === $t0, 'the lapse time is kept on a suspended row');

$step = ServiceTenantLadder::advance($row, $columns, false, 14, $suspend, $reactivate, '2026-10-01 00:00:00');
check($step === ServiceTenantLadder::STEP_NONE, 'suspended + still not entitled → none');
check($fired === array('suspend'), 'nothing fires again');

$step = ServiceTenantLadder::advance($row, $columns, true, 14, $suspend, $reactivate, '2026-10-02 00:00:00');
check($step === ServiceTenantLadder::STEP_REACTIVATED, 'suspended + entitled → reactivated');
check($row->get('hst_state') === ServiceTenantLadder::STATE_ACTIVE, 'the row is active again in place');
check($row->get('hst_lapse_time') === null, 'the lapse is cleared');
check($row->get('hst_check_time') === '2026-10-02 00:00:00', 'the check time is stamped on reactivation');
check($fired === array('suspend', 'reactivate'), 'the reactivate act fires once');

section('ServiceTenantLadder: rungs it does not move');
foreach (array(ServiceTenantLadder::STATE_PROVISIONING, ServiceTenantLadder::STATE_RELEASED, 'evicted') as $state) {
	$other = new SkeletonTestRow(NULL);
	$other->set('hst_state', $state);
	$fired = array();
	$a = ServiceTenantLadder::advance($other, $columns, true, 14, $suspend, $reactivate, $t0);
	$b = ServiceTenantLadder::advance($other, $columns, false, 14, $suspend, $reactivate, $t0);
	check($a === ServiceTenantLadder::STEP_NONE && $b === ServiceTenantLadder::STEP_NONE && $other->saves === 0
		&& $fired === array() && $other->get('hst_state') === $state,
		$state . ' is left alone whether entitled or not');
}

section('ServiceTenantLadder: a zero-day grace suspends on the next pass');
$row = new SkeletonTestRow(NULL);
$row->set('hst_state', ServiceTenantLadder::STATE_ACTIVE);
$fired = array();
ServiceTenantLadder::advance($row, $columns, false, 0, $suspend, $reactivate, $t0);
$step = ServiceTenantLadder::advance($row, $columns, false, 0, $suspend, $reactivate, '2026-09-01 12:00:01');
check($step === ServiceTenantLadder::STEP_SUSPENDED && $fired === array('suspend'),
	'grace 0: the lapse is recorded, then the very next pass suspends');

section('ServiceTenantLadder: a row without a check column');
$row = new SkeletonTestRow(NULL);
$row->set('hst_state', ServiceTenantLadder::STATE_ACTIVE);
$step = ServiceTenantLadder::advance($row, array('state' => 'hst_state', 'lapse_time' => 'hst_lapse_time'),
	true, 14, $suspend, $reactivate, $t0);
check($step === ServiceTenantLadder::STEP_CHECKED && $row->get('hst_check_time') === null,
	'check_time is optional');

check(ServiceTenantLadder::STATES === array('provisioning', 'active', 'suspended', 'released'),
	'the shared vocabulary is the four states');

harness_finish();

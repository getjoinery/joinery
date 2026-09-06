<?php
/** @joinery-test
 * name: relay_birth
 * tier: db
 * env: any
 * needs: []
 * timeout: 180
 *
 * The birth channel (specs/relay_without_a_shell.md § Birth): a relay born
 * from user-data fetches its bundle and posts a signed birth report, and the
 * plane believes neither on the token alone.
 *
 *   bundle  only a live run's live token gets the run's own copy, and only by
 *           naming the hash the plane put in the user-data
 *   born    refused when the report names a different address than the
 *           provider gave, when it arrives from elsewhere, when its signature
 *           does not verify, or when the token is spent; accepted only after a
 *           pinned ping to the provider's address answers - then the row
 *           carries the pin, the map is on the relay, the run is done and its
 *           credentials are erased
 *
 * The report is signed by the real relay binary's own birth-report verb, so
 * the test never holds a relay key; the run row is a fixture removed at
 * teardown, and so is the relay row the birth creates.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/lib/relay_ping_probe.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayBirthEndpoint.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_client_identity_class.php'));

$binary = RelayPingProbe::binary();
if ($binary === null) {
	harness_skip('the birth channel against the real relay', 'no prebuilt relay-sealer and no go toolchain');
	harness_finish();
}

/** A provider that records the reverse-DNS request and does nothing else. */
class BirthTestDriver implements CloudComputeProvider {
	// Power and the account's transfer pool (CloudComputeProvider 1.2). Recorded
	// rather than refused: a test that shuts an instance down should be able to
	// see that it did.
	public $shutdowns = array();
	public $boots = array();
	public $transfer = array('used_gb' => 0.0, 'quota_gb' => 1000.0, 'billable_gb' => 0.0);

	public function shutdownInstance(string $instance_id): void { $this->shutdowns[] = $instance_id; }
	public function bootInstance(string $instance_id): void { $this->boots[] = $instance_id; }
	public function getTransfer(): array { return $this->transfer; }

	public $rdns = array();
	public function createInstance(array $opts): array { return array(); }
	public function getInstance(string $id): array { return array('id' => $id, 'status' => 'running', 'ip' => '127.0.0.1', 'label' => ''); }
	public function rebuildInstance(string $id, array $opts): array { return array(); }
	public function deleteInstance(string $id): void {}
	public function setReverseDns(string $id, string $ip, string $hostname): array { $this->rdns[] = array($id, $ip, $hostname); return array('ip' => $ip, 'rdns' => $hostname); }
}
$driver = new BirthTestDriver();
RelayCloudProvisioner::$driver_factory = function ($run) use ($driver) { return $driver; };
harness_defer(function () { RelayCloudProvisioner::$driver_factory = null; });

// ---------------------------------------------------------------------------
section('A run in booting, with a token and its bundle copy');

$run = new RelayCloudProvision(NULL);
$run->set('rcp_kind', 'provision');
$run->set('rcp_status', 'booting');
$run->set('rcp_provider', 'linode');
$run->set('rcp_mail_hostname', 'mx.birth-' . substr(md5(uniqid()), 0, 6) . '.test');
$run->set('rcp_region', 'us-east');
$run->set('rcp_instance_type', 'g6-nanode-1');
$run->set('rcp_instance_id', 'birth-test-' . mt_rand(1000, 9999));
$run->set('rcp_instance_ip', '127.0.0.1');
$token = $run->issueRunToken(1800);
$run->save();
harness_register_model('RelayCloudProvision', $run->key);
$run_id = (string)$run->key;
check($run->runTokenMatches($token), 'the issued token matches its run');
check(!$run->runTokenMatches('not-the-token'), 'another string does not');

$bundle_ok = false;
try {
	$sha = $run->copyBundle();
	$run->save();
	$bundle_ok = is_file($run->bundlePath()) && $sha === hash_file('sha256', $run->bundlePath());
	check($bundle_ok, 'the bundle is copied onto the run and its hash recorded', $run->bundlePath());
	harness_defer(function () use ($run) { $run->eraseBundle(); });
} catch (\Throwable $e) {
	harness_skip('the bundle is copied onto the run', $e->getMessage());
}

// ---------------------------------------------------------------------------
section('GET /relay/bundle');

if ($bundle_ok) {
	$r = RelayBirthEndpoint::processBundle($run_id, $sha, $token);
	check($r['status'] === 200 && ($r['path'] ?? '') === $run->bundlePath(), 'a live token naming the right hash is served the run\'s copy', json_encode($r));
	$r = RelayBirthEndpoint::processBundle($run_id, str_repeat('0', 64), $token);
	check($r['status'] === 409, 'the wrong hash is refused', json_encode($r));
	$r = RelayBirthEndpoint::processBundle($run_id, $sha, 'wrong');
	check($r['status'] === 403, 'the wrong token is refused', json_encode($r));
	$r = RelayBirthEndpoint::processBundle('999999999', $sha, $token);
	check($r['status'] === 403, 'an unknown run is refused the same way', json_encode($r));
}

// ---------------------------------------------------------------------------
section('POST /relay/born');

$probe = new RelayPingProbe($binary);
$probe->addTenantWithKey('main', RelayClientIdentity::publicKey(RelayClientIdentity::KIND_CLIENT));
if (!$probe->start()) {
	check(false, 'the relay listener starts', (string)@file_get_contents($probe->home . '/serve.log'));
	harness_finish();
}
RelayClient::$base_url_override['127.0.0.1'] = $probe->baseUrl();

$signed = $probe->birthReport($run_id, '127.0.0.1');
check(is_array($signed) && isset($signed['report'], $signed['signature']), 'the relay binary signs a birth report with its identity');
check(($signed['report']['identity_fingerprint'] ?? '') === $probe->fingerprint(), 'the report carries the fingerprint the listener presents');
check(RelayBirthEndpoint::spkiFingerprint(base64_decode($signed['report']['identity_public_key'])) === $probe->fingerprint(),
	'the plane derives the same fingerprint from the raw key the report carries');
$body = json_encode($signed);

// Refusals first: none of them may spend the token or write a row.
$r = RelayBirthEndpoint::processBorn($body, 'wrong-token', '127.0.0.1');
check($r['status'] === 403, 'a wrong token is refused', json_encode($r));
$r = RelayBirthEndpoint::processBorn($body, $token, '203.0.113.5');
check($r['status'] === 403, 'a report arriving from another address is refused', json_encode($r));
$lying = $signed; $lying['report']['public_ip'] = '203.0.113.5';
$r = RelayBirthEndpoint::processBorn(json_encode($lying), $token, '127.0.0.1');
check($r['status'] === 403, 'a report naming another address is refused (and its signature no longer verifies anyway)', json_encode($r));
$forged = $signed; $forged['signature'] = base64_encode(str_repeat("\x00", 64));
$r = RelayBirthEndpoint::processBorn(json_encode($forged), $token, '127.0.0.1');
check($r['status'] === 403, 'a bad signature is refused', json_encode($r));
$swapped = $signed; $swapped['report']['identity_fingerprint'] = base64_encode(str_repeat("\x22", 32));
$r = RelayBirthEndpoint::processBorn(json_encode($swapped), $token, '127.0.0.1');
check($r['status'] === 403, 'a fingerprint that is not the key\'s fingerprint is refused', json_encode($r));
$r = RelayBirthEndpoint::processBorn('{"report":', $token, '127.0.0.1');
check($r['status'] === 400, 'garbage is a 400', json_encode($r));
$check_run = new RelayCloudProvision(intval($run_id), TRUE);
check((string)$check_run->get('rcp_status') === 'booting' && !(bool)$check_run->get('rcp_run_token_spent'), 'no refusal moved the run or spent the token');

// The pinned ping must answer before the pin is trusted: with the listener
// stopped nothing does, and the run stays where it is.
$probe->stop();
$r = RelayBirthEndpoint::processBorn($body, $token, '127.0.0.1');
check($r['status'] === 409 && strpos($r['error'], 'pinned ping') !== false, 'a relay that does not answer its pinned ping is not believed', json_encode($r));
$rows = new MultiMailboxRelay(array('deleted' => false));
$stray = 0;
foreach ($rows as $row) { if ((string)$row->get('mrl_cloud_instance_id') === (string)$run->get('rcp_instance_id')) { $stray++; } }
check($stray === 0, 'a refused birth leaves no relay row behind', $stray . ' row(s)');

// Now the relay answers.
$probe2 = new RelayPingProbe($binary);
$probe2->addTenantWithKey('main', RelayClientIdentity::publicKey(RelayClientIdentity::KIND_CLIENT));
check($probe2->start(), 'a relay listener answers');
RelayClient::$base_url_override['127.0.0.1'] = $probe2->baseUrl();
$signed2 = $probe2->birthReport($run_id, '127.0.0.1');
$r = RelayBirthEndpoint::processBorn(json_encode($signed2), $token, '127.0.0.1');
check($r['status'] === 200, 'the birth report is accepted', json_encode($r));
$relay_id = intval($r['data']['relay_id'] ?? 0);
if ($relay_id > 0) {
	harness_register_model('MailboxRelay', $relay_id);
	$relay = new MailboxRelay($relay_id, TRUE);
	// Disabled at once: this fixture must not stay this deployment's active relay.
	$relay->set('mrl_is_enabled', false);
	$relay->save();
	check((string)$relay->get('mrl_identity_fingerprint') === $probe2->fingerprint(), 'the row carries the pin');
	check((string)$relay->get('mrl_public_ip') === '127.0.0.1', 'the row carries the address');
	check(intval($relay->get('mrl_map_version')) >= 1 && is_array($probe2->acceptedFragment('main')), 'the map push was the gate: the relay holds the fragment');
	check($relay->lastHealth() !== null, 'the born relay\'s health is stored from the ping in hand');
	check((string)$relay->get('mrl_transport_public_key') !== '', 'the transport keypair exists');
	if (intval($relay->get('mrl_mgn_managed_node_id')) > 0) {
		harness_register_model('ManagedNode', intval($relay->get('mrl_mgn_managed_node_id')));
		$node = new ManagedNode(intval($relay->get('mrl_mgn_managed_node_id')), TRUE);
		check((bool)$node->get('mgn_is_relay') && (string)$node->get('mgn_ssh_key_path') === ''
			&& (string)$node->get('mgn_uptime_check_type') === 'tcp_port',
			'with server_manager active the born relay is a ManagedNode in the disposable posture');
	}
}
$done = new RelayCloudProvision(intval($run_id), TRUE);
check((string)$done->get('rcp_status') === 'done', 'the run is done');
check((bool)$done->get('rcp_run_token_spent') && (string)$done->get('rcp_sealed_run_token') === '', 'the token is spent and erased');
check(!is_file($done->bundlePath()), 'the run\'s bundle copy is erased');
check(intval($done->get('rcp_mrl_mailbox_relay_id')) === $relay_id, 'the run names its relay');
check(count($driver->rdns) === 1 && $driver->rdns[0][1] === '127.0.0.1', 'reverse DNS was requested from the provider');
$r = RelayBirthEndpoint::processBorn(json_encode($signed2), $token, '127.0.0.1');
check($r['status'] === 403, 'a second report with the spent token is refused', json_encode($r));

$probe2->stop();
harness_finish();

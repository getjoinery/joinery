<?php
/** @joinery-test
 * name: relay_cloud_provision
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 *
 * The relay cloud run, born configured (specs/relay_without_a_shell.md WP3):
 * the state machine RelayCloudProvisioner drives against a fake provider, and
 * a real relay binary where the run has to be answered.
 *
 *   create   ready -> booting carries first-boot user-data naming this run, its
 *            token and the bundle's hash; installs no SSH key; records no
 *            password. A 4xx is terminal with the token erased; a 5xx stays put.
 *   boot     booting -> provisioning once the instance runs with an address; a
 *            boot that never completes is failed and its instance destroyed.
 *   birth    provisioning WAITS. A relay silent past the birth timeout is
 *            failed and destroyed; a birth report completes the run (covered in
 *            relay_birth) and, with server_manager active, attaches a
 *            ManagedNode in the disposable posture.
 *   update   drains the spool over the relay API (refusing a shared relay), then
 *            re-images the SAME instance with fresh user-data and a fresh token.
 *
 * Fixture rows (runs, relays, nodes) are removed at teardown.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/lib/relay_ping_probe.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayCloudProvisioner.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayBirthEndpoint.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_client_identity_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayFirstBoot.php'));

/** A provider that records every call and answers what the test scripted. */
class RcpFakeDriver implements CloudComputeProvider {
	public $instances = array();
	public $rebuilds = array();
	public $deleted = array();
	public $rdns = array();
	public $boot_sequence = array('running');
	public $create_error = null; // CloudComputeException to throw on create
	public $metadata_regions = array('us-test' => true);
	public $stackscripts = array();
	private $polls = 0;

	public function createInstance(array $opts): array {
		if ($this->create_error !== null) { $e = $this->create_error; $this->create_error = null; throw $e; }
		$id = 'fake-' . (count($this->instances) + 1);
		$this->instances[$id] = $opts;
		return array('id' => $id, 'status' => 'provisioning', 'ip' => '198.51.100.99', 'label' => $opts['label']);
	}
	public function getInstance(string $id): array {
		$status = $this->boot_sequence[min($this->polls, count($this->boot_sequence) - 1)];
		$this->polls++;
		return array('id' => $id, 'status' => $status, 'ip' => $status === 'running' ? '198.51.100.99' : '', 'label' => 'r');
	}
	public function rebuildInstance(string $id, array $opts): array {
		$this->rebuilds[] = array($id, $opts);
		$this->polls = 0;
		return array('id' => $id, 'status' => 'rebuilding', 'ip' => '198.51.100.99', 'label' => 'r');
	}
	public function deleteInstance(string $id): void { $this->deleted[] = $id; }
	public function setReverseDns(string $id, string $ip, string $hostname): array { $this->rdns[] = array($id, $ip, $hostname); return array('ip' => $ip, 'rdns' => $hostname); }
}

class RelayCloudProvisionTest {
	/** @var RcpFakeDriver */
	public $driver;

	function run() {
		$test = $this;
		RelayCloudProvisioner::$driver_factory = function ($run) use ($test) { return $test->driver; };
		harness_defer(function () { RelayCloudProvisioner::$driver_factory = null; });
		try {
			$this->testCreateCarriesFirstBoot();
			$this->testCreateFails();
			$this->testTransientRetries();
			$this->testBootTimeout();
			$this->testBirthTimeout();
			$this->testUpdateDrainsThenReimages();
		} catch (\Throwable $e) {
			check(false, 'uncaught ' . get_class($e), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		}
	}

	private function makeRun(array $over = array()): RelayCloudProvision {
		$run = new RelayCloudProvision(NULL);
		$run->set('rcp_kind', 'provision');
		$run->set('rcp_status', 'ready');
		$run->set('rcp_provider', 'linode');
		$run->set('rcp_mail_hostname', 'mx.rcp-test.example');
		$run->set('rcp_region', 'us-test');
		$run->set('rcp_instance_type', 'g6-test-1');
		foreach ($over as $k => $v) { $run->set($k, $v); }
		$run->sealToken('fake-access-token');
		$run->save();
		harness_register_model('RelayCloudProvision', $run->key);
		harness_defer(function () use ($run) { $run->eraseBundle(); });
		return $run;
	}

	private function bundleAvailable(): bool {
		return is_file(PathHelper::getIncludePath('agent_dist/support_bundle.tar.gz'));
	}

	private function testCreateCarriesFirstBoot() {
		section('create: the instance is born from user-data, with no key and no password kept');
		if (!$this->bundleAvailable()) {
			harness_skip('create carries first-boot user-data', 'no agent_dist/support_bundle.tar.gz on this deployment');
			return;
		}
		$this->driver = new RcpFakeDriver();
		$this->driver->boot_sequence = array('provisioning', 'running');
		$run = $this->makeRun();
		$p = new RelayCloudProvisioner();

		check(strpos($p->advance($run), 'user-data') !== false, 'ready -> booting names the user-data');
		check((string)$run->get('rcp_status') === 'booting', 'ready -> booting');
		$opts = $this->driver->instances['fake-1'] ?? array();
		check(!isset($opts['authorized_keys']), 'no SSH key is installed on the relay');
		check(!isset($opts['root_pass']), 'the provisioner hands the driver no root password to record');
		check(!empty($opts['user_data']), 'the create carries first-boot user-data');
		$ud = (string)($opts['user_data'] ?? '');
		check(strpos($ud, 'RUN_ID="${RUN_ID:-' . $run->key . '}"') !== false, 'the user-data names this run');
		check(strpos($ud, 'BUNDLE_SHA256="${BUNDLE_SHA256:-' . (string)$run->get('rcp_bundle_sha256') . '}"') !== false
			&& (string)$run->get('rcp_bundle_sha256') !== '', 'the user-data names the hash of the run\'s bundle copy');
		check(is_file($run->bundlePath()), 'the run holds its own copy of the bundle');
		check(strpos($ud, 'CLIENT_PUBLIC_KEY="${CLIENT_PUBLIC_KEY:-' . RelayClientIdentity::publicKey(RelayClientIdentity::KIND_CLIENT) . '}"') !== false,
			'the user-data carries this deployment\'s relay client public key');
		check(strpos($ud, '--keep-sshd') === false || strpos($ud, 'KEEP_SSHD=0') !== false, 'the user-data never keeps sshd');
		preg_match('/RUN_TOKEN="\${RUN_TOKEN:-([0-9a-f]+)}"/', $ud, $m);
		check(!empty($m[1]) && $run->runTokenMatches($m[1]), 'the user-data carries the run\'s live token');

		check($p->advance($run) === 'still booting' && (string)$run->get('rcp_status') === 'booting', 'not running yet stays booting');
		check(strpos($p->advance($run), 'report in') !== false && (string)$run->get('rcp_status') === 'provisioning',
			'running with an address -> provisioning, which is a wait');
		check((string)$run->get('rcp_instance_ip') === '198.51.100.99', 'the provider\'s address is recorded for the birth report to match');
		check(strpos($p->advance($run), 'waiting') !== false && (string)$run->get('rcp_status') === 'provisioning',
			'provisioning waits for the birth report rather than building anything from here');
		check(count($this->driver->deleted) === 0, 'nothing destroyed while waiting');
	}

	private function testCreateFails() {
		section('create fails (4xx terminal)');
		if (!$this->bundleAvailable()) { harness_skip('create fails', 'no bundle'); return; }
		$this->driver = new RcpFakeDriver();
		$this->driver->create_error = new CloudComputeException('Linode API POST failed (400): region invalid', 400);
		$run = $this->makeRun();
		(new RelayCloudProvisioner())->advance($run);
		check((string)$run->get('rcp_status') === 'failed', '4xx create error is terminal');
		check((string)$run->get('rcp_sealed_token') === '' && (string)$run->get('rcp_sealed_run_token') === '', 'provider token and run token erased on failure');
		check(!is_file($run->bundlePath()), 'the bundle copy is erased on failure');
		check(count($this->driver->deleted) === 0, 'no instance existed, none destroyed');
	}

	private function testTransientRetries() {
		section('transient error retries');
		if (!$this->bundleAvailable()) { harness_skip('transient retries', 'no bundle'); return; }
		$this->driver = new RcpFakeDriver();
		$this->driver->create_error = new CloudComputeException('Linode API POST failed (503): try later', 503);
		$run = $this->makeRun();
		(new RelayCloudProvisioner())->advance($run);
		check((string)$run->get('rcp_status') === 'ready', '5xx stays put for the next tick');
		check((string)$run->get('rcp_sealed_token') !== '', 'provider token kept while the run is live');
		check(strpos((string)$run->get('rcp_error'), 'Transient') === 0, 'the transient reason is recorded');
	}

	private function testBootTimeout() {
		section('boot timeout');
		if (!$this->bundleAvailable()) { harness_skip('boot timeout', 'no bundle'); return; }
		$this->driver = new RcpFakeDriver();
		$this->driver->boot_sequence = array('provisioning');
		$run = $this->makeRun();
		$p = new RelayCloudProvisioner();
		$p->advance($run); // -> booting
		// Age the run past the boot window.
		$this->age($run, RelayCloudProvisioner::BOOT_TIMEOUT_SECONDS + 60);
		$p->advance($run);
		check((string)$run->get('rcp_status') === 'failed', 'boot timeout is terminal');
		check(in_array('fake-1', $this->driver->deleted, true), 'timed-out instance destroyed within the grant');
		check((string)$run->get('rcp_sealed_token') === '', 'token erased after timeout');
	}

	private function testBirthTimeout() {
		section('birth timeout: a relay that never reports in');
		if (!$this->bundleAvailable()) { harness_skip('birth timeout', 'no bundle'); return; }
		$this->driver = new RcpFakeDriver();
		$this->driver->boot_sequence = array('running');
		$run = $this->makeRun();
		$p = new RelayCloudProvisioner();
		$p->advance($run); // -> booting
		$p->advance($run); // -> provisioning
		check((string)$run->get('rcp_status') === 'provisioning', 'waiting');
		$this->age($run, RelayCloudProvisioner::BIRTH_TIMEOUT_SECONDS + 60);
		check(strpos($p->advance($run), 'birth timeout') !== false, 'the wait ends');
		check((string)$run->get('rcp_status') === 'failed', 'a silent relay is a failed run');
		check(in_array('fake-1', $this->driver->deleted, true), 'its instance is destroyed within the grant');
		check(!is_file($run->bundlePath()) && (string)$run->get('rcp_sealed_run_token') === '', 'bundle copy and run token erased');
	}

	private function testUpdateDrainsThenReimages() {
		section('update: drain over the relay API, then re-image the same instance');
		if (!$this->bundleAvailable()) { harness_skip('update', 'no bundle'); return; }
		$binary = RelayPingProbe::binary();
		if ($binary === null) { harness_skip('update against a real relay', 'no relay binary'); return; }

		// A born relay, reachable at 127.0.0.1 through the probe, one tenant.
		$probe = new RelayPingProbe($binary);
		$probe->addTenantWithKey('main', RelayClientIdentity::publicKey(RelayClientIdentity::KIND_CLIENT));
		if (!$probe->start()) { check(false, 'the relay listener starts'); return; }
		RelayClient::$base_url_override['127.0.0.1'] = $probe->baseUrl();
		try {
			$relay = new MailboxRelay(NULL);
			$relay->set('mrl_name', 'mx.rcp-update.example');
			$relay->set('mrl_mx_hostname', 'mx.rcp-update.example');
			$relay->set('mrl_is_enabled', false);
			$relay->set('mrl_public_ip', '127.0.0.1');
			$relay->set('mrl_identity_fingerprint', $probe->fingerprint());
			$relay->set('mrl_tenant_slug', 'main');
			$relay->set('mrl_cloud_provider', 'linode');
			$relay->set('mrl_cloud_instance_id', 'fake-existing');
			$relay->save();
			harness_register_model('MailboxRelay', $relay->key);
			$relay->ensureTransportKeypair();

			$this->driver = new RcpFakeDriver();
			$run = $this->makeRun(array(
				'rcp_kind' => 'upgrade', 'rcp_mrl_mailbox_relay_id' => intval($relay->key),
				'rcp_instance_id' => 'fake-existing', 'rcp_instance_ip' => '127.0.0.1',
				'rcp_mail_hostname' => 'mx.rcp-update.example',
			));
			$p = new RelayCloudProvisioner();

			// An undrained spool refuses: a held entry is mail a wipe would destroy.
			// (An empty spool drains in one pass, below.)
			$out = $p->advance($run);
			check((string)$run->get('rcp_status') === 'rebuilding', 'an empty spool drains over the API and the run moves to rebuilding', $out . ' / ' . (string)$run->get('rcp_error'));

			$out = $p->advance($run);
			check((string)$run->get('rcp_status') === 'booting', 'rebuilding -> booting', $out);
			check(count($this->driver->rebuilds) === 1 && $this->driver->rebuilds[0][0] === 'fake-existing',
				'the SAME instance is re-imaged; nothing is created');
			$opts = $this->driver->rebuilds[0][1] ?? array();
			check(!empty($opts['user_data']) && !isset($opts['authorized_keys']), 'the re-image carries fresh user-data and no SSH key');
			check(strpos((string)$opts['user_data'], 'RUN_ID="${RUN_ID:-' . $run->key . '}"') !== false, 'the user-data names the update run');
			check($run->runTokenMatches($this->tokenIn((string)$opts['user_data'])), 'a fresh run token rides in it');
			check(count($this->driver->deleted) === 0, 'an update never destroys the customer\'s instance');

			// The update's birth writes the new pin on the SAME row.
			$this->driver->boot_sequence = array('running');
			$p->advance($run); // booting -> provisioning (the fake reports 198.51.100.99, so re-point the run at the probe)
			$run->set('rcp_instance_ip', '127.0.0.1');
			$run->save();
			$probe2 = new RelayPingProbe($binary);
			$probe2->addTenantWithKey('main', RelayClientIdentity::publicKey(RelayClientIdentity::KIND_CLIENT));
			check($probe2->start(), 'the re-imaged relay listens');
			RelayClient::$base_url_override['127.0.0.1'] = $probe2->baseUrl();
			$report = $probe2->birthReport((string)$run->key, '127.0.0.1');
			$r = RelayBirthEndpoint::processBorn(json_encode($report), $this->tokenIn((string)$opts['user_data']), '127.0.0.1');
			check($r['status'] === 200, 'the re-imaged relay\'s birth report is accepted', json_encode($r));
			check(intval($r['data']['relay_id'] ?? 0) === intval($relay->key), 'the birth lands on the SAME relay row, not a second one');
			$fresh = new MailboxRelay(intval($relay->key), TRUE);
			check((string)$fresh->get('mrl_identity_fingerprint') === $probe2->fingerprint(), 'the row carries the new identity pin');
			check((string)$fresh->get('mrl_public_ip') === '127.0.0.1', 'the address is kept');
			$done = new RelayCloudProvision(intval($run->key), TRUE);
			check((string)$done->get('rcp_status') === 'done', 'the update run is done');
			if (intval($fresh->get('mrl_mgn_managed_node_id')) > 0) {
				harness_register_model('ManagedNode', intval($fresh->get('mrl_mgn_managed_node_id')));
				$node = new ManagedNode(intval($fresh->get('mrl_mgn_managed_node_id')), TRUE);
				check((bool)$node->get('mgn_is_relay') && (string)$node->get('mgn_ssh_key_path') === ''
					&& (string)$node->get('mgn_uptime_check_type') === 'tcp_port' && intval($node->get('mgn_uptime_tcp_port')) === 25
					&& (string)$node->get('mgn_agent_public_key') === '',
					'with server_manager active the relay is a ManagedNode in the disposable posture: relay, no key, no agent, tcp/25 probe');
			} else {
				check(!PluginHelper::isPluginActive('server_manager'), 'no ManagedNode only when server_manager is inactive');
			}
			$fresh->set('mrl_is_enabled', false);
			$fresh->save();
			$probe2->stop();
		} finally {
			$probe->stop();
		}
	}

	/** Back-date a run's last transition, as a long wait would; save() would stamp now. */
	private function age(RelayCloudProvision $run, int $seconds): void {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('UPDATE rcp_relay_cloud_provisions SET rcp_update_time = ? WHERE rcp_id = ?');
		$stmt->execute(array(gmdate('Y-m-d H:i:s', time() - $seconds), intval($run->key)));
		$run->set('rcp_update_time', gmdate('Y-m-d H:i:s', time() - $seconds));
	}

	private function tokenIn(string $user_data): string {
		return preg_match('/RUN_TOKEN="\${RUN_TOKEN:-([0-9a-f]+)}"/', $user_data, $m) ? $m[1] : '';
	}
}

$test = new RelayCloudProvisionTest();
$test->run();
harness_finish();

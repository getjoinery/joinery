<?php
/** @joinery-test
 * name: relay_cloud_provision
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The relay cloud-provisioning state machine
 * (specs/mailbox_relay_cloud_provisioning.md) driven end to end with a fake
 * CloudComputeProvider and a scripted SSH runner: happy path (relay row
 * registered with cloud coordinates, credentials erased), create-fails,
 * boot-timeout, script-fails (instance destroyed + credentials erased at every
 * terminal state), and transient-error retry. The platform offers no destroy-a-running-server
 * act — the only instance deletion is a failed run cleaning up its own
 * half-built instance.
 *
 * Run: php tests/run.php db --filter=relay_cloud_provision
 *
 * @version 1.3
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayCloudProvisioner.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySsh.php'));

class RcpFakeDriver implements CloudComputeProvider {
	public $instances = array();
	public $create_exception = null;
	public $boot_sequence = array(); // statuses returned by successive getInstance calls
	public $deleted = array();
	public $rebuilt = array();
	public $rdns = array();
	private $boot_i = 0;

	public function createInstance(array $opts): array {
		if ($this->create_exception !== null) {
			throw $this->create_exception;
		}
		$id = 'fake-' . (count($this->instances) + 1);
		$this->instances[$id] = $opts;
		return array('id' => $id, 'status' => 'provisioning', 'ip' => '', 'label' => $opts['label']);
	}
	public function getInstance(string $instance_id): array {
		$status = $this->boot_sequence[$this->boot_i] ?? 'running';
		$this->boot_i++;
		$ip = ($status === 'running') ? '198.51.100.99' : '';
		return array('id' => $instance_id, 'status' => $status, 'ip' => $ip, 'label' => 'x');
	}
	public function rebuildInstance(string $instance_id, array $opts): array {
		$this->rebuilt[] = array('id' => $instance_id, 'opts' => $opts);
		// A real rebuild keeps the instance and its address; the fake must too,
		// or a test could pass while the address silently moved.
		return array('id' => $instance_id, 'status' => 'rebuilding',
			'ip' => '198.51.100.99', 'label' => 'x');
	}
	public function deleteInstance(string $instance_id): void {
		$this->deleted[] = $instance_id;
	}
	public function setReverseDns(string $instance_id, string $ip, string $hostname): array {
		$this->rdns[] = array($instance_id, $ip, $hostname);
		return array('ip' => $ip, 'rdns' => $hostname);
	}
	public function regions(): array { return array(); }
	public function plans(): array { return array(); }
}

class RelayCloudProvisionTest {

	private $cleanup_runs = array();
	private $cleanup_relays = array();
	/** @var RcpFakeDriver */
	private $driver;
	private $ssh_script_ok = true;
	private $commands = array();

	private $wg_setting_was = null;

	/** Path of the throwaway pull key this run wrote, '' when the box had its own. */
	public $pull_pub_created = '';

	function run() {
		try {
			// The engine requires the main box's WireGuard identity; give the
			// run a fake one when the deployment has none, restored after.
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->query("SELECT stg_value FROM stg_settings WHERE stg_name = 'mailbox_relay_wg_public_key'");
			$this->wg_setting_was = $q ? $q->fetchColumn() : false;
			if ($this->wg_setting_was === false || trim((string)$this->wg_setting_was) === '') {
				// Blank cached settings re-query the DB on every get_setting
				// call, so a direct row write is visible immediately.
				$db->exec("INSERT INTO stg_settings (stg_name, stg_value) VALUES ('mailbox_relay_wg_public_key', 'FAKE-MAIN-WG')"
					. " ON CONFLICT (stg_name) DO UPDATE SET stg_value = 'FAKE-MAIN-WG'");
			}
			// The engine also requires the main box's relay pull key, which
			// lives on the filesystem rather than in the database — so a clone
			// of this deployment has the setting above but not the file, and
			// every provisioning run fails at 'lost its relay identity'. Same
			// treatment as the WireGuard key: supply a fake, remove it after.
			$pull_pub = RelaySsh::pullKeyPath() . '.pub';
			if (!is_file($pull_pub) || trim((string)@file_get_contents($pull_pub)) === '') {
				@file_put_contents($pull_pub, "ssh-ed25519 AAAAFAKEPULLKEYFORTESTS relay-pull-test\n");
				$this->pull_pub_created = is_file($pull_pub) ? $pull_pub : '';
			}

			$this->installSeams();
			$this->testHappyPath();
			$this->testCreateFails();
			$this->testTransientRetries();
			$this->testBootTimeout();
			$this->testScriptFails();
		} catch (\Throwable $e) {
			check(false, 'uncaught ' . get_class($e), $e->getMessage());
		} finally {
			RelayCloudProvisioner::$driver_factory = null;
			RelayCloudProvisioner::$runner = null;
			if ($this->pull_pub_created !== '') {
				@unlink($this->pull_pub_created);
			}
			$this->cleanupRows();
		}
	}

	private function installSeams() {
		$test = $this;
		RelayCloudProvisioner::$driver_factory = function ($run) use ($test) {
			return $test->driver;
		};
		// Scripted runner: ssh-keygen produces a real throwaway keypair (the
		// engine reads the files); tar/scp succeed silently; the big ssh build
		// step succeeds (with markers) or fails per the test's flag; the
		// WireGuard peer helper succeeds.
		RelayCloudProvisioner::$runner = function ($cmd) use ($test) {
			$test->commands[] = $cmd;
			if (strpos($cmd, 'ssh-keygen') === 0) {
				if (preg_match('/-f (\S+)/', $cmd, $m)) {
					$f = trim($m[1], "'");
					file_put_contents($f, "FAKE-PRIVATE-KEY\n");
					file_put_contents($f . '.pub', "ssh-ed25519 AAAAfake joinery-relay-provision\n");
				}
				return array(0, 'ok');
			}
			if (strpos($cmd, 'timeout 1800 ssh') === 0) {
				if (!$test->ssh_script_ok) {
					return array(1, 'boom: apt exploded');
				}
				return array(0, "stuff\nRELAY_WG_PUBKEY=FAKEWGKEY123\nRELAY_PUBLIC_IP=198.51.100.99\nPROVISION_RELAY_SUCCESS");
			}
			return array(0, 'ok');
		};
	}

	private function makeRun(array $over = array()): RelayCloudProvision {
		$run = new RelayCloudProvision(NULL);
		$run->set('rcp_kind', 'provision');
		$run->set('rcp_status', 'ready');
		$run->set('rcp_provider', 'linode');
		$run->set('rcp_mail_hostname', 'mx.rcp-test.example');
		$run->set('rcp_region', 'us-test');
		$run->set('rcp_instance_type', 'g6-test-1');
		foreach ($over as $k => $v) {
			$run->set($k, $v);
		}
		$run->sealToken('fake-access-token');
		$run->save();
		$this->cleanup_runs[] = intval($run->key);
		return $run;
	}

	private function testHappyPath() {
		section('happy path');
		$this->driver = new RcpFakeDriver();
		$this->driver->boot_sequence = array('provisioning', 'running');
		$this->ssh_script_ok = true;

		$run = $this->makeRun();
		$p = new RelayCloudProvisioner();

		$p->advance($run);
		check((string)$run->get('rcp_status') === 'booting', 'ready -> booting');
		check((string)$run->get('rcp_instance_id') === 'fake-1', 'instance id recorded at create');
		check(!empty($this->driver->instances['fake-1']['authorized_keys'][0]), 'per-run public key injected at create');
		// The naming RULES belong to relay_instance_label_test; what matters here is
		// that create() is fed this run's own hostname and id, so each rebuild gets a
		// distinct label instead of colliding with its predecessor.
		$expect_label = RelayCloudProvisioner::instanceLabel(
			(string)$run->get('rcp_mail_hostname'), intval($run->key));
		check((string)$this->driver->instances['fake-1']['label'] === $expect_label,
			'instance label is built from this run\'s hostname and id',
			'got ' . (string)$this->driver->instances['fake-1']['label'] . ', want ' . $expect_label);

		$p->advance($run);
		check((string)$run->get('rcp_status') === 'booting', 'not running yet stays booting');

		// Cheap page-driven advance moves boot completion; the heavy build
		// waits for the next full advance (the scheduled task's job).
		$p->advanceCheap($run);
		check((string)$run->get('rcp_status') === 'provisioning', 'boot complete marks provisioning without building');

		$p->advance($run);
		check((string)$run->get('rcp_status') === 'done', 'the next pass runs the build to done');
		check((string)$run->get('rcp_sealed_token') === '', 'token erased at terminal state');
		check((string)$run->get('rcp_sealed_ssh_key') === '', 'ssh key erased at terminal state');
		check(count($this->driver->rdns) === 1, 'reverse DNS attempted through the provider');

		$relay = null;
		$multi = new MultiMailboxRelay(array('deleted' => false));
		$multi->load();
		foreach ($multi as $row) {
			if ((string)$row->get('mrl_cloud_instance_id') === 'fake-1') {
				$relay = $row;
			}
		}
		check($relay !== null, 'relay row registered');
		if ($relay !== null) {
			$this->cleanup_relays[] = intval($relay->key);
			check((bool)$relay->get('mrl_is_enabled') === true, 'relay born ENABLED (doctrine keys off cutover state)');
			check((string)$relay->get('mrl_cloud_provider') === 'linode', 'relay carries the cloud provider');
			check((string)$relay->get('mrl_mx_hostname') === 'mx.rcp-test.example', 'relay carries the MX hostname');
			check((string)$relay->get('mrl_public_ip') === '198.51.100.99', 'relay carries the instance IP');
			check((string)$relay->get('mrl_wg_public_key') === 'FAKEWGKEY123', 'relay carries the WG key from the markers');
			check((string)$relay->get('mrl_tenant_slug') === 'main', 'self-provisioned relay is a fleet of one');
		}
	}

	private function testCreateFails() {
		section('create fails (4xx terminal)');
		$this->driver = new RcpFakeDriver();
		$this->driver->create_exception = new CloudComputeException('Bad region', 400);

		$run = $this->makeRun();
		(new RelayCloudProvisioner())->advance($run);
		check((string)$run->get('rcp_status') === 'failed', '4xx create error is terminal');
		check((string)$run->get('rcp_sealed_token') === '', 'token erased on failure');
		check(count($this->driver->deleted) === 0, 'no instance existed, none destroyed');
	}

	private function testTransientRetries() {
		section('transient error retries');
		$this->driver = new RcpFakeDriver();
		$this->driver->create_exception = new CloudComputeException('gateway timeout', 502);

		$run = $this->makeRun();
		(new RelayCloudProvisioner())->advance($run);
		check((string)$run->get('rcp_status') === 'ready', '5xx stays put for the next tick');
		check((string)$run->get('rcp_sealed_token') !== '', 'token kept while the run is live');
		$run->fail('test cleanup');
	}

	private function testBootTimeout() {
		section('boot timeout');
		$this->driver = new RcpFakeDriver();
		$this->driver->boot_sequence = array_fill(0, 10, 'provisioning');

		$run = $this->makeRun();
		(new RelayCloudProvisioner())->advance($run); // -> booting
		// Age the row past the timeout window.
		$db = DbConnector::get_instance()->get_db_link();
		$db->exec("UPDATE rcp_relay_cloud_provisions SET rcp_update_time = now() - interval '1 hour' WHERE rcp_id = " . intval($run->key));
		$run->load();
		(new RelayCloudProvisioner())->advance($run);
		check((string)$run->get('rcp_status') === 'failed', 'boot timeout is terminal');
		check(in_array('fake-1', $this->driver->deleted, true), 'timed-out instance destroyed within the grant');
		check((string)$run->get('rcp_sealed_token') === '', 'token erased after timeout');
	}

	private function testScriptFails() {
		section('provision script fails');
		$this->driver = new RcpFakeDriver();
		$this->driver->boot_sequence = array('running');
		$this->ssh_script_ok = false;

		$run = $this->makeRun();
		$p = new RelayCloudProvisioner();
		$p->advance($run); // -> booting
		$p->advance($run); // running -> marks provisioning
		$p->advance($run); // the build pass -> fails
		check((string)$run->get('rcp_status') === 'failed', 'script failure is terminal');
		check(strpos((string)$run->get('rcp_error'), 'boom') !== false, 'failure carries the script output tail');
		check(in_array('fake-1', $this->driver->deleted, true), 'half-built instance destroyed within the grant');
		check((string)$run->get('rcp_sealed_token') === '', 'token erased after script failure');
		$this->ssh_script_ok = true;
	}

	private function cleanupRows() {
		$db = DbConnector::get_instance()->get_db_link();
		if ($this->wg_setting_was === false || trim((string)$this->wg_setting_was) === '') {
			$was = ($this->wg_setting_was === false) ? '' : (string)$this->wg_setting_was;
			$stmt = $db->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = 'mailbox_relay_wg_public_key'");
			$stmt->execute(array($was));
		}
		foreach ($this->cleanup_runs as $id) {
			try { $db->exec("DELETE FROM rcp_relay_cloud_provisions WHERE rcp_id = " . intval($id)); } catch (\Throwable $e) {}
		}
		foreach ($this->cleanup_relays as $id) {
			try { $db->exec("DELETE FROM mrl_mailbox_relays WHERE mrl_mailbox_relay_id = " . intval($id)); } catch (\Throwable $e) {}
		}
	}
}

$test = new RelayCloudProvisionTest();
$test->run();
harness_finish();

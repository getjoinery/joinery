<?php
/** @joinery-test
 * name: join_auto_approval
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Automatic approval of joins from machines this plane provisioned
 * (the auto_approve_provisioned_joins spec).
 *
 * Every case builds a provision, a recorded key and a pending join request, runs
 * JoinAutoApproval with a fake provider, and checks what was (not) approved:
 *
 *  - a site agent and a host agent with the right address, name and key are approved
 *    and their key is consumed;
 *  - everything else stays pending and creates nothing: wrong key, wrong name,
 *    an address no provision has, no recorded key, a failed provision, a provider that
 *    says not running or throws, a second agent on a node that has one;
 *  - the provider is only asked about a request that already matched a recorded key;
 *  - the kill switch stops it all;
 *  - what the install printed is read into keys for the right agent only.
 *
 * Run: php plugins/server_manager/tests/join_auto_approval_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

class JaaFakeDriver implements CloudComputeProvider {
	public $shutdowns = array();
	public $boots = array();
	public $transfer = array('used_gb' => 0.0, 'quota_gb' => 1000.0, 'billable_gb' => 0.0);
	public function shutdownInstance(string $instance_id): void { $this->shutdowns[] = $instance_id; }
	public function bootInstance(string $instance_id): void { $this->boots[] = $instance_id; }
	public function getTransfer(): array { return $this->transfer; }

	public $calls = 0;
	public $result = ['id' => '1', 'ip' => '', 'status' => 'running'];
	public $throw = false;
	public function createInstance(array $opts): array { throw new CloudComputeException('not used'); }
	public function getInstance(string $instance_id): array {
		$this->calls++;
		if ($this->throw) { throw new CloudComputeException('provider down'); }
		return $this->result;
	}
	public function rebuildInstance(string $instance_id, array $opts): array { throw new CloudComputeException('not used'); }
	public function deleteInstance(string $instance_id): void {}
	public function setReverseDns(string $instance_id, string $ip, string $hostname): array { return ['ip' => $ip, 'rdns' => $hostname]; }
}

class JaaFakeProvisioner extends ProvisionCustomerCloud {
	public $fakeDriver;
	protected function get_driver($provision) { return $this->fakeDriver; }
	protected function resolve_driver($provision): array { return ['driver' => $this->fakeDriver, 'reason' => '', 'park' => false]; }
}

class JoinAutoApprovalTest {
	private $driver;
	private $user_id;

	function __construct() {
		$this->driver = new JaaFakeDriver();
		$this->user_id = 980000 + random_int(0, 9999);
		$prov = new JaaFakeProvisioner();
		$prov->fakeDriver = $this->driver;
		AgentChannelEndpoint::$provisioner = $prov;
	}

	function run() {
		$was = Globalvars::get_instance()->get_setting(JoinAutoApproval::SETTING);
		try {
			$this->test_install_output();
			$this->test_site_agent();
			$this->test_host_agent_over_ipv6();
			$this->test_held_cases();
			$this->test_kill_switch();
		} catch (Throwable $e) {
			check(false, 'uncaught exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			AgentChannelEndpoint::$provisioner = null;
			Setting::put(JoinAutoApproval::SETTING, $was === null || $was === '' ? '1' : (string)$was);
		}
	}

	/** A provision, its site node, and the addresses it answers on. */
	private function provision(string $status = 'done', array $keys = []): array {
		$sfx = substr(bin2hex(random_bytes(4)), 0, 8);
		$ip = '198.51.100.' . random_int(2, 250);
		$ip6 = '2600:3c02::' . dechex(random_int(0x1000, 0xffff)) . ':e6ff:fea7:' . dechex(random_int(0x1000, 0xffff));

		$site = new ManagedNode(NULL);
		$site->set('mgn_name', 'HarnessTest jaa site ' . $sfx);
		$site->set('mgn_slug', 'jaa-' . $sfx);
		$site->set('mgn_host', $ip);
		$site->set('mgn_uptime_enabled', false);
		$site->save();
		$site->load();
		harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $site->key);

		$prov = new CustomerCloudProvision(NULL);
		$prov->set('cvp_origin', 'admin');
		$prov->set('cvp_usr_user_id', $this->user_id);
		$prov->set('cvp_domain', 'jaa-' . $sfx . '.example.com');
		$prov->set('cvp_slug', 'jaa-' . $sfx);
		$prov->set('cvp_sitename', 'jaa' . $sfx);
		$prov->set('cvp_docker_mode', 'docker');
		$prov->set('cvp_install_mode', 'fresh');
		$prov->set('cvp_port', random_int(8100, 8999));
		$prov->set('cvp_status', $status);
		$prov->set('cvp_instance_id', 'jaa' . $sfx);
		$prov->set('cvp_instance_ip', $ip);
		$prov->set('cvp_instance_ipv6', $ip6);
		$prov->set('cvp_mgn_managed_node_id', (int)$site->key);
		foreach ($keys as $col => $val) { $prov->set($col, $val); }
		$prov->save();
		harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $prov->key);

		return ['prov' => $prov, 'site' => $site, 'ip' => $ip, 'ip6' => $ip6, 'sfx' => $sfx];
	}

	/** A pending join request from $ip, claiming $name, with a fresh key. Returns [request, fingerprint]. */
	private function join(string $name, string $ip): array {
		$pub = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
		$jr = new AgentJoinRequest();
		$jr->set('ajr_claimed_name', $name);
		$jr->set('ajr_public_key', base64_encode($pub));
		$jr->set('ajr_fingerprint', AgentJoinRequest::fingerprint($pub));
		$jr->set('ajr_source_ip', $ip);
		$jr->set('ajr_agent_version', '1.66.0');
		$jr->set('ajr_status', AgentJoinRequest::STATUS_PENDING);
		$jr->save();
		harness_register_row('ajr_agent_join_requests', 'ajr_agent_join_request_id', $jr->key);
		return [$jr, AgentJoinRequest::fingerprint($pub)];
	}

	private function running(array $p) {
		$this->driver->throw = false;
		$this->driver->calls = 0;
		$this->driver->result = ['id' => (string)$p['prov']->get('cvp_instance_id'), 'ip' => $p['ip'], 'ipv6' => $p['ip6'], 'status' => 'running'];
	}

	private function status(AgentJoinRequest $jr): string {
		$j = new AgentJoinRequest((int)$jr->key, TRUE);
		return (string)$j->get('ajr_status');
	}

	private function test_install_output() {
		section('What the install printed becomes the expected keys');

		$p = $this->provision('installing');
		$prov = $p['prov'];
		$host_name = $prov->host_agent_name();
		$out = "agent installer: joinery-agent v1.66.0 running\n"
			. "\x1b[1;33m[WARN]\x1b[0m something\n"
			. "Asked https://dev.example.test to adopt this machine as \"{$host_name}\".\r\n\r\n    Key fingerprint:  f2716c8b2d4c52b5\r\n\r\n"
			. "Approve the request on the management node.\n"
			. "CONTAINER_PORT=8080\n"
			. "SITE_AGENT_KEY=07EEAE82B00CE63E\n";
		$keys = JoinAutoApproval::keys_from_install_output($out, $prov);
		check($keys['host'] === 'f2716c8b2d4c52b5', 'the host agent key is read when the name is the provision\'s host-agent name', (string)$keys['host']);
		check($keys['site'] === '07eeae82b00ce63e', 'the site agent key is read from SITE_AGENT_KEY=, lower-cased', (string)$keys['site']);

		$other = "Asked https://x to adopt this machine as \"someone-else-host\".\n\n    Key fingerprint:  0123456789abcdef\n";
		$keys = JoinAutoApproval::keys_from_install_output($other, $prov);
		check($keys['host'] === null, 'a key printed for another name is not taken');
		$keys = JoinAutoApproval::keys_from_install_output("SITE_AGENT_KEY=xyz\nKey fingerprint: 12\nnothing here\n", $prov);
		check($keys['host'] === null && $keys['site'] === null, 'garbage records nothing');

		// A retry's second host key replaces the first.
		$two = "Asked https://x to adopt this machine as \"{$host_name}\".\n\n    Key fingerprint:  1111111111111111\n"
			. "Asked https://x to adopt this machine as \"{$host_name}\".\n\n    Key fingerprint:  2222222222222222\n";
		$keys = JoinAutoApproval::keys_from_install_output($two, $prov);
		check($keys['host'] === '2222222222222222', 'the last key printed for the host agent wins (a retry mints a new agent)');

		JoinAutoApproval::record_from_install($p['site'], $out);
		$prov->load();
		check($prov->get('cvp_expected_host_key') === 'f2716c8b2d4c52b5' && $prov->get('cvp_expected_site_key') === '07eeae82b00ce63e'
			&& $prov->get('cvp_keys_captured_time') !== null, 'recording stores both keys on the provision of that site node');
		$stranger = new ManagedNode(NULL);
		$stranger->set('mgn_name', 'HarnessTest jaa stranger');
		$stranger->set('mgn_slug', 'jaa-stranger-' . $p['sfx']);
		$stranger->set('mgn_host', '203.0.113.9');
		$stranger->set('mgn_uptime_enabled', false);
		$stranger->save();
		harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $stranger->key);
		JoinAutoApproval::record_from_install($stranger, $out); // no provision: nothing to record, nothing thrown
		check(true, 'an install of a node that is no provision\'s records nothing and does not throw');
	}

	private function test_site_agent() {
		section('A site agent whose address, name and key match is approved');

		$p = $this->provision('done');
		[$jr, $fp] = $this->join($p['prov']->site_agent_name(), $p['ip']);
		$p['prov']->set('cvp_expected_site_key', $fp);
		$p['prov']->save();
		$this->running($p);

		$v = JoinAutoApproval::verdict($jr);
		check($v['eligible'] && $v['agent'] === 'site', 'the verdict is eligible, for the site agent', $v['reason']);
		check($this->driver->calls === 0, 'the verdict alone never calls the provider');

		check(JoinAutoApproval::run() >= 1, 'a run approves it');
		$jr = new AgentJoinRequest((int)$jr->key, TRUE);
		$site = new ManagedNode((int)$p['site']->key, TRUE);
		check($jr->get('ajr_status') === AgentJoinRequest::STATUS_APPROVED && $jr->get('ajr_decided_by') === 'auto',
			'the request is approved and marked as decided automatically');
		check(trim((string)$site->get('mgn_agent_public_key')) === (string)$jr->get('ajr_public_key')
			&& (int)$jr->get('ajr_mgn_managed_node_id') === (int)$site->key, 'its key is bound to the provision\'s site node');
		$prov = new CustomerCloudProvision((int)$p['prov']->key, TRUE);
		check(trim((string)$prov->get('cvp_expected_site_key')) === '', 'the recorded key is consumed');
		check($this->driver->calls >= 1, 'the provider was asked');

		// A later request for the same machine finds no key to match.
		[$again] = $this->join($p['prov']->site_agent_name(), $p['ip']);
		$v = JoinAutoApproval::verdict($again);
		check(!$v['eligible'], 'after approval nothing is left for a second request to match', $v['reason']);
		$this->reject($again);
	}

	private function test_host_agent_over_ipv6() {
		section('A host agent over IPv6 is approved and becomes the host node');

		$p = $this->provision('done');
		[$jr, $fp] = $this->join($p['prov']->host_agent_name(), $p['ip6']);
		$p['prov']->set('cvp_expected_host_key', $fp);
		$p['prov']->save();
		$this->running($p);

		$v = JoinAutoApproval::verdict($jr);
		check($v['eligible'] && $v['agent'] === 'host', 'eligible, for the host agent, from the IPv6 address', $v['reason']);
		JoinAutoApproval::run();
		$jr = new AgentJoinRequest((int)$jr->key, TRUE);
		check($jr->get('ajr_status') === AgentJoinRequest::STATUS_APPROVED && $jr->get('ajr_decided_by') === 'auto', 'approved automatically');
		$node_id = (int)$jr->get('ajr_mgn_managed_node_id');
		check($node_id > 0, 'a host node was made');
		if ($node_id > 0) {
			harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node_id);
			$node = new ManagedNode($node_id, TRUE);
			$node->set('mgn_name', 'HarnessTest ' . $node->get('mgn_name'));
			$node->save();
			check($node->get('mgn_host') === $p['ip'], 'at the instance\'s IPv4, not the IPv6 the join came from', (string)$node->get('mgn_host'));
		}
		$prov = new CustomerCloudProvision((int)$p['prov']->key, TRUE);
		check(trim((string)$prov->get('cvp_expected_host_key')) === '', 'the host key is consumed');
	}

	private function test_held_cases() {
		section('Everything else stays pending and creates nothing');

		// wrong key
		$p = $this->provision('done', ['cvp_expected_site_key' => 'aaaaaaaaaaaaaaaa']);
		[$jr] = $this->join($p['prov']->site_agent_name(), $p['ip']);
		$this->running($p);
		$v = JoinAutoApproval::verdict($jr);
		check(!$v['eligible'] && $v['key_mismatch'], 'a key that is not the recorded one is held and flagged as a mismatch', $v['reason']);
		JoinAutoApproval::run();
		check($this->status($jr) === AgentJoinRequest::STATUS_PENDING, 'and stays pending after a run');
		check($this->driver->calls === 0, 'the provider is not asked about a request whose key does not match');
		$this->reject($jr);

		// wrong name
		$p = $this->provision('done');
		[$jr, $fp] = $this->join('not-the-name', $p['ip']);
		$p['prov']->set('cvp_expected_site_key', $fp); $p['prov']->save();
		$this->running($p);
		check(!JoinAutoApproval::verdict($jr)['eligible'], 'a name the provision\'s agents do not join as is held');
		JoinAutoApproval::run();
		check($this->status($jr) === AgentJoinRequest::STATUS_PENDING && $this->driver->calls === 0, 'and the provider is not asked');
		$this->reject($jr);

		// address of no provision
		[$jr] = $this->join('whatever', '203.0.113.' . random_int(2, 250));
		check(!JoinAutoApproval::verdict($jr)['eligible'], 'an address no provision has is held');
		$this->reject($jr);

		// no recorded key
		$p = $this->provision('done');
		[$jr] = $this->join($p['prov']->site_agent_name(), $p['ip']);
		$this->running($p);
		$v = JoinAutoApproval::verdict($jr);
		check(!$v['eligible'] && !$v['key_mismatch'], 'no recorded key means no automatic approval', $v['reason']);
		$this->reject($jr);

		// failed provision
		$p = $this->provision('failed');
		[$jr, $fp] = $this->join($p['prov']->site_agent_name(), $p['ip']);
		$p['prov']->set('cvp_expected_site_key', $fp); $p['prov']->save();
		check(!JoinAutoApproval::verdict($jr)['eligible'], 'a failed provision approves nothing');
		$this->reject($jr);

		// provider says not running
		$p = $this->provision('done');
		[$jr, $fp] = $this->join($p['prov']->site_agent_name(), $p['ip']);
		$p['prov']->set('cvp_expected_site_key', $fp); $p['prov']->save();
		$this->running($p);
		$this->driver->result['status'] = 'offline';
		JoinAutoApproval::run();
		check($this->status($jr) === AgentJoinRequest::STATUS_PENDING && $this->driver->calls >= 1, 'an instance the provider says is not running is held (the provider was asked)');
		$prov = new CustomerCloudProvision((int)$p['prov']->key, TRUE);
		check($prov->get('cvp_expected_site_key') === $fp, 'and the recorded key is kept for when it is');

		// provider throws
		$this->driver->throw = true;
		JoinAutoApproval::run();
		check($this->status($jr) === AgentJoinRequest::STATUS_PENDING, 'a provider that throws leaves the request pending');
		$this->driver->throw = false;
		$this->reject($jr);

		// the site node already has an agent
		$p = $this->provision('done');
		$p['site']->set('mgn_agent_public_key', base64_encode(random_bytes(32)));
		$p['site']->save();
		[$jr, $fp] = $this->join($p['prov']->site_agent_name(), $p['ip']);
		$p['prov']->set('cvp_expected_site_key', $fp); $p['prov']->save();
		$this->running($p);
		check(!JoinAutoApproval::verdict($jr)['eligible'], 'a second agent on a site node that already has one is held');
		JoinAutoApproval::run();
		check($this->status($jr) === AgentJoinRequest::STATUS_PENDING, 'and stays pending');
		$this->reject($jr);
	}

	private function test_kill_switch() {
		section('The kill switch');

		$p = $this->provision('done');
		[$jr, $fp] = $this->join($p['prov']->site_agent_name(), $p['ip']);
		$p['prov']->set('cvp_expected_site_key', $fp); $p['prov']->save();
		$this->running($p);
		Setting::put(JoinAutoApproval::SETTING, '0');
		check(!JoinAutoApproval::enabled(), 'switched off reads as off');
		check(JoinAutoApproval::run() === 0 && $this->status($jr) === AgentJoinRequest::STATUS_PENDING && $this->driver->calls === 0,
			'nothing is approved and the provider is not asked');
		Setting::put(JoinAutoApproval::SETTING, '1');
		check(JoinAutoApproval::enabled(), 'switched on reads as on');
		JoinAutoApproval::run();
		check($this->status($jr) === AgentJoinRequest::STATUS_APPROVED, 'and the same request is then approved');
	}

	/** A held request is left behind by a test; take it off the pending list so it cannot approve in a later case. */
	private function reject(AgentJoinRequest $jr) {
		$j = new AgentJoinRequest((int)$jr->key, TRUE);
		$j->set('ajr_status', AgentJoinRequest::STATUS_REJECTED);
		$j->save();
	}
}

(new JoinAutoApprovalTest())->run();
harness_finish();
?>

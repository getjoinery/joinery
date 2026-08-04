<?php
/** @joinery-test
 * name: customer_cloud_provisioning
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Customer-cloud provisioning tests — the BYO-Linode fulfillment mode's parts
 * that run without a real provider or Go agent:
 *
 *  - LinodeComputeDriver against Guzzle MockHandler: normalized instance
 *    shape, private-IP filtering, error envelope extraction, 401 vs 4xx vs
 *    missing-option failures.
 *  - CustomerCloudAccount: token round-trip is SecretBox-encrypted at rest
 *    and decrypts back to the granted values; get_for_user scoping.
 *  - CustomerCloudProvision: CRUD, fail(), Multi status/order-item filters,
 *    duplicate order-item rejection.
 *  - Origin rules: order-origin requires an order item, admin-origin does
 *    not; install-parameter validation (docker mode, install mode,
 *    from-backup source); defaults reproduce the order-flow behavior.
 *  - CustomerCloudConsumer: a grant upserts the account link and flips the
 *    user's pending_connect provisions (same provider only) to ready.
 *
 * Run: php plugins/server_manager/tests/customer_cloud_provisioning_test.php
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/oauth_consumers/CustomerCloudConsumer.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Token.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

class CustomerCloudProvisioningTest {
	private $db;
	private $user_id;

	function __construct() {
		$this->db = DbConnector::get_instance()->get_db_link();
		// Fake buyer id far above real users; cvp/cca rows carry no user FK.
		$this->user_id = 990000 + random_int(0, 9999);
	}

	private function driverWith(array $responses): LinodeComputeDriver {
		$mock = new MockHandler($responses);
		$stack = HandlerStack::create($mock);
		return new LinodeComputeDriver('test-token', new Client(['handler' => $stack, 'base_uri' => LinodeComputeDriver::API_BASE]));
	}

	private function jsonResponse($status, array $body): Response {
		return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
	}

	function run() {
		try {
			$this->test_driver();
			$this->test_account_tokens();
			$this->test_provision_model();
			$this->test_origin_rules();
			$this->test_consumer();
			$this->test_reverse_dns();
		} catch (Exception $e) {
			check(false, 'uncaught exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->cleanup();
		}
	}

	private function test_driver() {
		section('LinodeComputeDriver (mocked API)');

		// Happy-path create: normalized shape, public IP chosen over private.
		$driver = $this->driverWith([$this->jsonResponse(200, [
			'id' => 12345, 'status' => 'provisioning', 'label' => 'mysite-1',
			'ipv4' => ['192.168.128.10', '203.0.113.7'],
		])]);
		$instance = $driver->createInstance([
			'label' => 'mysite-1', 'region' => 'us-southeast', 'type' => 'g6-nanode-1',
			'image' => 'linode/ubuntu24.04', 'root_pass' => 'Aa1!deadbeef',
			'authorized_keys' => ['ssh-ed25519 AAAA test'],
		]);
		check($instance['id'] === '12345', 'create returns normalized string id');
		check($instance['ip'] === '203.0.113.7', 'private RFC1918 IP skipped, public IP selected');
		check($instance['status'] === 'provisioning', 'status passed through');

		// Missing required option fails before any request.
		try {
			$driver->createInstance(['label' => 'x']);
			check(false, 'missing options rejected');
		} catch (CloudComputeException $e) {
			check(strpos($e->getMessage(), 'region') !== false, 'missing options rejected');
		}

		// 401 surfaces with code 401 (grant revoked signal).
		$driver = $this->driverWith([$this->jsonResponse(401, ['errors' => [['reason' => 'Invalid Token']]])]);
		try {
			$driver->getInstance('12345');
			check(false, '401 raises CloudComputeException(401)');
		} catch (CloudComputeException $e) {
			check($e->getCode() === 401 && strpos($e->getMessage(), 'Invalid Token') !== false,
				'401 raises CloudComputeException(401) with provider reason');
		}

		// Validation 400 carries field + reason from the Linode error envelope.
		$driver = $this->driverWith([$this->jsonResponse(400, ['errors' => [
			['field' => 'region', 'reason' => 'region is not valid'],
		]])]);
		try {
			$driver->createInstance([
				'label' => 'x', 'region' => 'nope', 'type' => 'g6-nanode-1',
				'image' => 'linode/ubuntu24.04', 'root_pass' => 'Aa1!deadbeef',
			]);
			check(false, '400 raises with field context');
		} catch (CloudComputeException $e) {
			check($e->getCode() === 400 && strpos($e->getMessage(), 'region: region is not valid') !== false,
				'400 raises with field context');
		}

		// getInstance running state.
		$driver = $this->driverWith([$this->jsonResponse(200, [
			'id' => 12345, 'status' => 'running', 'label' => 'mysite-1', 'ipv4' => ['203.0.113.7'],
		])]);
		$instance = $driver->getInstance('12345');
		check($instance['status'] === 'running' && $instance['ip'] === '203.0.113.7', 'getInstance normalizes running instance');

		// setReverseDns: normalized {ip, rdns} from the provider response.
		$driver = $this->driverWith([$this->jsonResponse(200, [
			'address' => '203.0.113.7', 'rdns' => 'mail.example.com', 'type' => 'ipv4', 'public' => true,
		])]);
		$r = $driver->setReverseDns('12345', '203.0.113.7', 'mail.example.com');
		check($r['ip'] === '203.0.113.7' && $r['rdns'] === 'mail.example.com',
			'setReverseDns returns normalized ip + rdns');

		// Linode rejects rdns whose forward record is absent — reason surfaces with field.
		$driver = $this->driverWith([$this->jsonResponse(400, ['errors' => [
			['field' => 'rdns', 'reason' => 'Hostname does not resolve to this address'],
		]])]);
		try {
			$driver->setReverseDns('12345', '203.0.113.7', 'mail.example.com');
			check(false, 'setReverseDns 400 raises with field context');
		} catch (CloudComputeException $e) {
			check($e->getCode() === 400 && strpos($e->getMessage(), 'rdns: Hostname does not resolve') !== false,
				'setReverseDns 400 raises with field context');
		}
	}

	private function test_account_tokens() {
		section('CustomerCloudAccount token round-trip');

		$token = new OAuth2Token('access-secret-123', 'refresh-secret-456', gmdate('Y-m-d H:i:s', time() + 7200), 'linodes:read_write');

		$account = new CustomerCloudAccount(NULL);
		$account->set('cca_usr_user_id', $this->user_id);
		$account->set('cca_provider', 'linode');
		$account->storeToken($token);
		$account->save();
		$account->load();
		check($account->key > 0, 'account row created');

		// Encrypted at rest: raw column value is not the plaintext.
		$q = $this->db->prepare("SELECT cca_access_token, cca_refresh_token FROM cca_customer_cloud_accounts WHERE cca_id = ?");
		$q->execute([$account->key]);
		$raw = $q->fetch(PDO::FETCH_ASSOC);
		check(strpos($raw['cca_access_token'], 'access-secret-123') === false
			&& SecretBox::looksEncrypted($raw['cca_access_token']), 'access token SecretBox-encrypted at rest');
		check(strpos($raw['cca_refresh_token'], 'refresh-secret-456') === false
			&& SecretBox::looksEncrypted($raw['cca_refresh_token']), 'refresh token SecretBox-encrypted at rest');

		// Round-trip decrypts to the granted values.
		$loaded = new CustomerCloudAccount($account->key, TRUE);
		$restored = $loaded->getToken();
		check($restored !== null
			&& $restored->getAccessToken() === 'access-secret-123'
			&& $restored->getRefreshToken() === 'refresh-secret-456'
			&& $restored->getScope() === 'linodes:read_write', 'token round-trip restores all fields');

		check(CustomerCloudAccount::get_for_user($this->user_id, 'linode') !== null, 'get_for_user finds the link');
		check(CustomerCloudAccount::get_for_user($this->user_id, 'other') === null, 'get_for_user scopes by provider');
	}

	private function test_provision_model() {
		section('CustomerCloudProvision model');

		$order_id = 980000 + random_int(0, 9999);
		$provision = new CustomerCloudProvision(NULL);
		$provision->set('cvp_external_order_item_id', $order_id);
		$provision->set('cvp_usr_user_id', $this->user_id);
		$provision->set('cvp_domain', 'cloudtest.example.com');
		$provision->set('cvp_slug', 'cloudtest-example-com');
		$provision->save();
		$provision->load();
		check($provision->key > 0 && $provision->get('cvp_status') === 'pending_connect', 'provision defaults to pending_connect');

		// Duplicate order item rejected by the unique constraint.
		try {
			$dup = new CustomerCloudProvision(NULL);
			$dup->set('cvp_external_order_item_id', $order_id);
			$dup->set('cvp_usr_user_id', $this->user_id);
			$dup->set('cvp_domain', 'other.example.com');
			$dup->set('cvp_slug', 'other-example-com');
			$dup->save();
			check(false, 'duplicate order item id rejected');
		} catch (Exception $e) {
			check(true, 'duplicate order item id rejected');
		}

		// Multi filters: statuses set + order item lookup.
		$provision->set('cvp_status', 'booting');
		$provision->save();
		$multi = new MultiCustomerCloudProvision(['statuses' => ['ready', 'booting', 'installing'], 'deleted' => false]);
		$multi->load();
		$found = false;
		foreach ($multi as $row) {
			if ((int)$row->key === (int)$provision->key) $found = true;
		}
		check($found, 'statuses filter matches actionable provision');

		$multi = new MultiCustomerCloudProvision(['external_order_item_id' => $order_id, 'deleted' => false]);
		$multi->load();
		check(count($multi) === 1, 'external_order_item_id filter finds exactly one');

		// fail() is terminal and records why.
		$provision->fail('test failure reason');
		$reloaded = new CustomerCloudProvision($provision->key, TRUE);
		check($reloaded->get('cvp_status') === 'failed' && strpos($reloaded->get('cvp_error'), 'test failure reason') !== false,
			'fail() records terminal status + reason');
	}

	private function test_origin_rules() {
		section('Provision origin + install-parameter rules');

		// Admin origin needs no order item; install params ride on the row.
		$admin = new CustomerCloudProvision(NULL);
		$admin->set('cvp_origin', 'admin');
		$admin->set('cvp_usr_user_id', $this->user_id);
		$admin->set('cvp_domain', 'adminborn.example.com');
		$admin->set('cvp_slug', 'adminborn-example-com');
		$admin->set('cvp_sitename', 'adminborn');
		$admin->set('cvp_docker_mode', 'bare-metal');
		$admin->set('cvp_install_mode', 'from_backup');
		$admin->set('cvp_source_node_id', 12345);
		$admin->set('cvp_backup_source', 'new');
		$admin->set('cvp_status', 'ready');
		$admin->save();
		$admin->load();
		check($admin->key > 0 && $admin->get('cvp_external_order_item_id') === null,
			'admin-origin provision saves without an order item');
		check($admin->get('cvp_docker_mode') === 'bare-metal'
			&& $admin->get('cvp_install_mode') === 'from_backup'
			&& (int)$admin->get('cvp_source_node_id') === 12345,
			'install parameters persist on the row');

		// A second admin provision also without an order item — the unique
		// constraint must allow multiple NULLs.
		$admin2 = new CustomerCloudProvision(NULL);
		$admin2->set('cvp_origin', 'admin');
		$admin2->set('cvp_usr_user_id', $this->user_id);
		$admin2->set('cvp_domain', 'adminborn2.example.com');
		$admin2->set('cvp_slug', 'adminborn2-example-com');
		$admin2->save();
		check($admin2->key > 0, 'second order-item-less provision saves (unique allows NULLs)');

		// Order origin still demands the order item.
		try {
			$bad = new CustomerCloudProvision(NULL);
			$bad->set('cvp_usr_user_id', $this->user_id);
			$bad->set('cvp_domain', 'noorder.example.com');
			$bad->set('cvp_slug', 'noorder-example-com');
			$bad->save();
			check(false, 'order-origin without order item rejected');
		} catch (CustomerCloudProvisionException $e) {
			check(true, 'order-origin without order item rejected');
		}

		// From-backup demands a source node.
		try {
			$bad = new CustomerCloudProvision(NULL);
			$bad->set('cvp_origin', 'admin');
			$bad->set('cvp_usr_user_id', $this->user_id);
			$bad->set('cvp_domain', 'nosource.example.com');
			$bad->set('cvp_slug', 'nosource-example-com');
			$bad->set('cvp_install_mode', 'from_backup');
			$bad->save();
			check(false, 'from-backup without source node rejected');
		} catch (CustomerCloudProvisionException $e) {
			check(true, 'from-backup without source node rejected');
		}

		// Unknown docker mode rejected.
		try {
			$bad = new CustomerCloudProvision(NULL);
			$bad->set('cvp_origin', 'admin');
			$bad->set('cvp_usr_user_id', $this->user_id);
			$bad->set('cvp_domain', 'badmode.example.com');
			$bad->set('cvp_slug', 'badmode-example-com');
			$bad->set('cvp_docker_mode', 'kvm');
			$bad->save();
			check(false, 'unknown docker mode rejected');
		} catch (CustomerCloudProvisionException $e) {
			check(true, 'unknown docker mode rejected');
		}

		// Bare mode: admin-origin saves with no site parameters at all.
		$bare = new CustomerCloudProvision(NULL);
		$bare->set('cvp_origin', 'admin');
		$bare->set('cvp_usr_user_id', $this->user_id);
		$bare->set('cvp_domain', 'mx1.example.com');
		$bare->set('cvp_slug', 'mx1-example-com');
		$bare->set('cvp_install_mode', 'bare');
		$bare->save();
		check($bare->key > 0, 'bare admin-origin provision saves without site parameters');

		// Bare mode is an infrastructure decision an order can never make.
		try {
			$bad = new CustomerCloudProvision(NULL);
			$bad->set('cvp_external_order_item_id', 970000 + random_int(0, 9999));
			$bad->set('cvp_usr_user_id', $this->user_id);
			$bad->set('cvp_domain', 'orderbare.example.com');
			$bad->set('cvp_slug', 'orderbare-example-com');
			$bad->set('cvp_install_mode', 'bare');
			$bad->save();
			check(false, 'bare mode on an order-origin provision rejected');
		} catch (CustomerCloudProvisionException $e) {
			check(true, 'bare mode on an order-origin provision rejected');
		}

		// Defaults reproduce the order flow exactly: docker, fresh, port 8080.
		$order_id = 960000 + random_int(0, 9999);
		$plain = new CustomerCloudProvision(NULL);
		$plain->set('cvp_external_order_item_id', $order_id);
		$plain->set('cvp_usr_user_id', $this->user_id);
		$plain->set('cvp_domain', 'defaults.example.com');
		$plain->set('cvp_slug', 'defaults-example-com');
		$plain->save();
		$plain->load();
		check($plain->get('cvp_origin') === 'order'
			&& $plain->get('cvp_docker_mode') === 'docker'
			&& $plain->get('cvp_install_mode') === 'fresh'
			&& (int)$plain->get('cvp_port') === 8080,
			'defaults keep order-flow behavior (order/docker/fresh/8080)');
	}

	private function test_consumer() {
		section('CustomerCloudConsumer grant handling');

		// A second user with one pending_connect provision awaiting the grant.
		$user2 = $this->user_id + 20000;
		$order_id = 970000 + random_int(0, 9999);
		$provision = new CustomerCloudProvision(NULL);
		$provision->set('cvp_external_order_item_id', $order_id);
		$provision->set('cvp_usr_user_id', $user2);
		$provision->set('cvp_domain', 'granted.example.com');
		$provision->set('cvp_slug', 'granted-example-com');
		$provision->save();
		$provision->load();

		$token = new OAuth2Token('at-grant', 'rt-grant', gmdate('Y-m-d H:i:s', time() + 7200), 'linodes:read_write');
		$consumer = new CustomerCloudConsumer();
		$redirect = $consumer->onTokenGranted($token, ['user_id' => $user2, 'provider' => 'linode']);
		check(strpos($redirect, '/profile/server_manager/connect_cloud') === 0, 'consumer returns the Connect page');

		$account = CustomerCloudAccount::get_for_user($user2, 'linode');
		check($account !== null && $account->get('cca_status') === 'active', 'grant creates active account link');

		$after = new CustomerCloudProvision($provision->key, TRUE);
		check($after->get('cvp_status') === 'ready'
			&& (int)$after->get('cvp_cca_account_id') === (int)$account->key, 'pending_connect provision flipped to ready + linked');

		// A second grant for the same user updates, not duplicates.
		$consumer->onTokenGranted($token, ['user_id' => $user2, 'provider' => 'linode']);
		$multi = new MultiCustomerCloudAccount(['user_id' => $user2, 'provider' => 'linode', 'deleted' => false]);
		$multi->load();
		check(count($multi) === 1, 're-grant upserts the same account row');
	}

	private function test_reverse_dns() {
		section('NodeReverseDns (injected fake driver)');
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeReverseDns.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

		$blank_node = new ManagedNode(NULL);

		// Hostname syntax gate fires before anything else.
		try {
			NodeReverseDns::set($blank_node, 'not a hostname');
			check(false, 'invalid hostname rejected');
		} catch (NodeReverseDnsException $e) {
			check(strpos($e->getMessage(), 'fully-qualified') !== false, 'invalid hostname rejected');
		}

		// A node that was not cloud-born has no provision — actionable error.
		$manual_node = new ManagedNode(NULL);
		$manual_node->set('mgn_name', 'RDNS Test Manual');
		$manual_node->set('mgn_slug', 'rdns-test-manual-' . random_int(1000, 9999));
		$manual_node->set('mgn_host', '203.0.113.99');
		$manual_node->save();
		$this->rdns_node_ids[] = (int)$manual_node->key;
		try {
			NodeReverseDns::set($manual_node, 'mail.example.com');
			check(false, 'node without provision gets panel guidance');
		} catch (NodeReverseDnsException $e) {
			check(strpos($e->getMessage(), 'no cloud-provision record') !== false,
				'node without provision gets panel guidance');
		}

		// Cloud-born node: provision row links node → instance; injected fake
		// driver receives the provision's instance id + ip and the hostname.
		$node = new ManagedNode(NULL);
		$node->set('mgn_name', 'RDNS Test Cloud');
		$node->set('mgn_slug', 'rdns-test-cloud-' . random_int(1000, 9999));
		$node->set('mgn_host', '203.0.113.80');
		$node->save();
		$this->rdns_node_ids[] = (int)$node->key;

		$provision = new CustomerCloudProvision(NULL);
		$provision->set('cvp_origin', 'admin');
		$provision->set('cvp_usr_user_id', $this->user_id);
		$provision->set('cvp_domain', 'rdns.example.com');
		$provision->set('cvp_slug', 'rdns-example-com');
		$provision->set('cvp_status', 'done');
		$provision->set('cvp_instance_id', '424242');
		$provision->set('cvp_instance_ip', '203.0.113.80');
		$provision->set('cvp_mgn_node_id', $node->key);
		$provision->save();

		$found = NodeReverseDns::provisionForNode($node);
		check($found && (int)$found->key === (int)$provision->key, 'provisionForNode finds the birth provision');

		$fake = new RdnsFakeDriver();
		$result = NodeReverseDns::set($node, 'Mail.Example.com', $fake, true);
		check($fake->calls === 1
			&& $fake->last_instance === '424242'
			&& $fake->last_ip === '203.0.113.80'
			&& $fake->last_hostname === 'mail.example.com',
			'driver called with provision instance/ip and lowercased hostname');
		check($result['rdns'] === 'mail.example.com', 'set returns the provider result');

		// Forward-record precondition: a hostname that cannot resolve to the
		// node IP is refused with the create-the-A-record instruction, and the
		// driver is never reached.
		try {
			NodeReverseDns::set($node, 'rdns-missing.example.invalid', $fake, false);
			check(false, 'missing forward A record refused before the provider call');
		} catch (NodeReverseDnsException $e) {
			check(strpos($e->getMessage(), 'Create the A record first') !== false && $fake->calls === 1,
				'missing forward A record refused before the provider call');
		}

		// setQuietly (the SSL-issuance pipeline hook) never throws: failure is
		// a returned ok=false, success passes the provider result through.
		$quiet_fail = NodeReverseDns::setQuietly($manual_node, 'mail.example.com');
		check(is_array($quiet_fail) && $quiet_fail['ok'] === false
			&& strpos($quiet_fail['message'], 'no cloud-provision record') !== false,
			'setQuietly swallows failure into ok=false');
		$quiet_ok = NodeReverseDns::setQuietly($node, 'rdns.example.com', $fake, true);
		check($quiet_ok['ok'] === true && $fake->calls === 2 && $fake->last_hostname === 'rdns.example.com',
			'setQuietly success reaches the provider');
	}

	private $rdns_node_ids = [];

	private function cleanup() {
		$ids = [$this->user_id, $this->user_id + 20000];
		$q = $this->db->prepare("DELETE FROM cvp_customer_cloud_provisions WHERE cvp_usr_user_id IN (?, ?)");
		$q->execute($ids);
		$q = $this->db->prepare("DELETE FROM cca_customer_cloud_accounts WHERE cca_usr_user_id IN (?, ?)");
		$q->execute($ids);
		foreach ($this->rdns_node_ids as $node_id) {
			$q = $this->db->prepare("DELETE FROM mgn_managed_nodes WHERE mgn_id = ?");
			$q->execute([$node_id]);
		}
	}
}

/** Records the arguments NodeReverseDns passes through; no network. */
class RdnsFakeDriver implements CloudComputeProvider {
	public $calls = 0;
	public $last_instance = '';
	public $last_ip = '';
	public $last_hostname = '';

	public function createInstance(array $opts): array { throw new CloudComputeException('not used'); }
	public function getInstance(string $instance_id): array { throw new CloudComputeException('not used'); }
	public function rebuildInstance(string $instance_id, array $opts): array { throw new CloudComputeException('not used'); }
	public function deleteInstance(string $instance_id): void { throw new CloudComputeException('not used'); }

	public function setReverseDns(string $instance_id, string $ip, string $hostname): array {
		$this->calls++;
		$this->last_instance = $instance_id;
		$this->last_ip = $ip;
		$this->last_hostname = $hostname;
		return array('ip' => $ip, 'rdns' => $hostname);
	}
}

(new CustomerCloudProvisioningTest())->run();
harness_finish();
?>

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
 *  - CustomerCloudConsumer: a grant upserts the account link and flips the
 *    user's pending_connect provisions (same provider only) to ready.
 *
 * Run: php plugins/server_manager/tests/customer_cloud_provisioning_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/cloud_compute/LinodeComputeDriver.php'));
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
			$this->test_consumer();
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

	private function cleanup() {
		$ids = [$this->user_id, $this->user_id + 20000];
		$q = $this->db->prepare("DELETE FROM cvp_customer_cloud_provisions WHERE cvp_usr_user_id IN (?, ?)");
		$q->execute($ids);
		$q = $this->db->prepare("DELETE FROM cca_customer_cloud_accounts WHERE cca_usr_user_id IN (?, ?)");
		$q->execute($ids);
	}
}

(new CustomerCloudProvisioningTest())->run();
harness_finish();
?>

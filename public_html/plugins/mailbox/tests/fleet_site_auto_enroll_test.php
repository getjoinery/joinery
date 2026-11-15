<?php
/** @joinery-test
 * name: fleet_site_auto_enroll
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * A seeded site that can receive only through a relay sets itself up
 * (hosting_launch S5-2, FleetClient::autoEnroll): it enrolls, its hosted relay
 * row is born enabled, its receive mode is relay, its own domain is
 * registered for receiving with its ownership claim filed and proven. A step
 * left undone is taken on a later pass, a site that is done is not asked
 * again, a site with a mail server of its own is left alone, and a refusal
 * waits before the next try.
 *
 * The fleet service is a stub (no network). Runs on the test database.
 *
 * Run: php tests/run.php test-db --filter=fleet_site_auto_enroll
 *
 * @version 1.1 - each step is retried until done; the ownership claim is proven
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetClient.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));

/** The operator's fleet service, answered here. */
class HarnessStubFleetClient extends FleetClient {
	public $calls = array();
	public $refuse = false;
	/** Whether the ownership TXT is found when the fleet checks it. */
	public $txt_published = false;
	public $claims = array();

	public function call(string $action, array $payload): array {
		$this->calls[] = $action;
		if ($this->refuse) {
			throw new ServiceClientException('No fleet shard has capacity right now — enrollment is temporarily closed.');
		}
		$coordinates = array(
			'slot_id' => 4242, 'status' => 'provisioning', 'slug' => 't4242',
			'mx_hostname' => 't4242.fleet.harnesstest.example',
			'authserv_id' => 'shard9.fleet.harnesstest.example',
			'shard_public_ip' => '203.0.113.12',
			'identity_fingerprint' => base64_encode(str_repeat("\x04", 32)),
		);
		if ($action === 'fleet_enroll') {
			return $coordinates;
		}
		if ($action === 'fleet_status') {
			return array('enrolled' => true, 'coordinates' => $coordinates, 'claims' => array_values($this->claims));
		}
		if ($action === 'fleet_claim_domain') {
			$domain = (string)($payload['domain'] ?? '');
			if (!isset($this->claims[$domain])) {
				$this->claims[$domain] = array('claim_id' => count($this->claims) + 1, 'domain' => $domain, 'status' => 'pending',
					'txt_host' => '_joinery-fleet-challenge.' . $domain, 'txt_value' => 'joinery-fleet-verify-x');
			}
			return $this->claims[$domain];
		}
		if ($action === 'fleet_verify_domain') {
			foreach ($this->claims as $domain => $claim) {
				if ((int)$claim['claim_id'] === (int)($payload['claim_id'] ?? 0) && $this->txt_published) {
					$this->claims[$domain]['status'] = 'verified';
					return array('verified' => true);
				}
			}
			return array('verified' => false);
		}
		return array();
	}
}

$domain = 'harnesstest-autoenroll-' . substr(md5(uniqid('', true)), 0, 6) . '.example.com';
harness_set_setting_mem('mailbox_fleet_service_url', 'https://plane.harnesstest.example');
harness_set_setting_mem('mailbox_fleet_api_public_key', 'public_harnesstestkey');
harness_set_setting_mem('mailbox_fleet_api_secret_key', 'secret_harnesstestkey');
harness_set_setting_mem('webDir', 'https://www.' . $domain . '/');

$hosted_rows = function () {
	$n = 0;
	foreach (new MultiMailboxRelay(array('deleted' => false)) as $r) {
		if ((bool)$r->get('mrl_is_hosted')) { $n++; }
	}
	return $n;
};
if ($hosted_rows() > 0) {
	harness_skip('the test database already holds a hosted relay row', 'autoEnroll does nothing there');
	harness_finish();
}
/** Let the wait after an unfinished pass run out. */
$wait_over = function () {
	Setting::put('mailbox_fleet_auto_enroll_failed_time', gmdate('Y-m-d H:i:s', time() - FleetClient::AUTO_ENROLL_RETRY_SECONDS - 60));
};
$setting = function (string $name) {
	return (string)Globalvars::get_instance()->get_setting($name, true, true);
};
Setting::put('mailbox_fleet_auto_enroll_failed_time', '');
Setting::put('mailbox_fleet_auto_enroll_done_time', '');

section('siteDomain');
check(FleetClient::siteDomain() === $domain, 'webDir with a scheme, www. and a slash gives the bare domain', FleetClient::siteDomain());
harness_set_setting_mem('webDir', '192.0.2.5');
check(FleetClient::siteDomain() === '', 'an address is no domain');
harness_set_setting_mem('webDir', $domain . ':8443/x');
check(FleetClient::siteDomain() === $domain, 'a port and a path are dropped');

section('A site with a mail server of its own is left alone');
harness_set_setting_mem('deployment_environment', 'bare-metal');
$client = new HarnessStubFleetClient();
check(!mailbox_needs_relay() && $client->autoEnroll() === '' && !$client->calls,
	'no relay needed: no call to the fleet');

section('A site that can receive only through a relay sets itself up');
harness_set_setting_mem('deployment_environment', 'docker');
if (!mailbox_needs_relay()) {
	harness_skip('this box\'s inbound provider is a webhook', 'mailbox_needs_relay() is false in a container then');
	harness_finish();
}
// First pass with no domain of its own yet: it enrolls and says what is owed.
harness_set_setting_mem('webDir', '192.0.2.5');
$outcome = $client->autoEnroll();
check(strpos($outcome, 't4242') !== false && in_array('fleet_enroll', $client->calls, true), 'it enrolls', $outcome);
$relay = $client->hostedRelayRow();
if ($relay) { harness_register_row('mrl_mailbox_relays', 'mrl_mailbox_relay_id', $relay->key); }
check($relay !== null && (bool)$relay->get('mrl_is_enabled'), 'its hosted relay row is born enabled: there is no other way in');
check($setting('mailbox_receive_mode') === 'relay', 'its receive mode is relay');
check(strpos($outcome, 'no domain name') !== false && $setting('mailbox_fleet_auto_enroll_done_time') === '',
	'with no domain of its own it is not done', $outcome);

$client->calls = array();
check($client->autoEnroll() === '' && !$client->calls, 'the next pass waits before trying again');

// The domain appears: the next pass after the wait registers it and files its claim.
harness_set_setting_mem('webDir', 'https://www.' . $domain . '/');
$wait_over();
$client->calls = array();
$outcome = $client->autoEnroll();
$ied = InboundEmailDomain::GetByDomain($domain);
if ($ied) { harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', $ied->key); }
check(!in_array('fleet_enroll', $client->calls, true), 'an enrolled site is not enrolled again');
check($ied && strpos($outcome, $domain . ' registered') !== false, 'a step left undone is taken on a later pass', $outcome);
check(isset($client->claims[$domain]), 'and the domain\'s ownership claim is filed');
check(strpos($outcome, 'waiting for') !== false && in_array('fleet_verify_domain', $client->calls, true),
	'it asks the fleet to check the ownership record, still missing', $outcome);

// The record is published: the next pass after the wait finds it proven.
$client->txt_published = true;
$wait_over();
$outcome = $client->autoEnroll();
check(strpos($outcome, 'receiving through the hosted relay') !== false && $setting('mailbox_fleet_auto_enroll_done_time') !== ''
	&& $setting('mailbox_fleet_auto_enroll_failed_time') === '', 'once the claim is proven it is done', $outcome);
$client->calls = array();
check($client->autoEnroll() === '' && !$client->calls, 'a site that is done is not asked again');

section('A refusal waits before the next try');
if ($relay) { $relay->permanent_delete(); }
$client->refuse = true;
$threw = false;
try {
	$client->autoEnroll();
} catch (ServiceClientException $e) {
	$threw = strpos($e->getMessage(), 'capacity') !== false;
}
check($threw, 'with its relay row gone it asks again, and the fleet\'s refusal is raised to the caller');
check($setting('mailbox_fleet_auto_enroll_failed_time') !== '', 'and its time recorded');
$client->calls = array();
check($client->autoEnroll() === '' && !$client->calls, 'the next pass does not ask again inside the wait');
$wait_over();
$client->refuse = false;
$again = $client->autoEnroll();
check(strpos($again, 't4242') !== false, 'after the wait it asks again and enrolls', $again);
check($setting('mailbox_fleet_auto_enroll_failed_time') === '', 'and the failure is cleared');
$relay = $client->hostedRelayRow();
if ($relay) { harness_register_row('mrl_mailbox_relays', 'mrl_mailbox_relay_id', $relay->key); }
Setting::put('mailbox_fleet_auto_enroll_done_time', '');

harness_finish();

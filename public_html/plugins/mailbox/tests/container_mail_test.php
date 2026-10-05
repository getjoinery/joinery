<?php
/** @joinery-test
 * name: mailbox_container_mail
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A site container has no mail server of its own
 * (specs/multi_tenant_docker_hosts.md WP9): no Postfix, no rspamd, no port 25.
 * Its mail arrives through a relay. This drives the mailbox's own answers with
 * the site recorded as a container (deployment_environment = docker, set in
 * this process only) and checks that nothing asks it to be a mail server:
 *
 *  - with mail arriving by SMTP and no relay, the topology is relay_needed,
 *    never colocated;
 *  - the Setup tab's host layer says how mail reaches the site (red with no
 *    relay, red with the relay disabled, green behind an enabled one), and no
 *    Postfix, port 25, rspamd or HELO-name row is asked for; a sending setting
 *    aimed at this box's own mail server gets a red row of its own;
 *  - a domain's MX row says a relay is needed, and the DNS plan prescribes no
 *    MX, no A record for the box and no box address in SPF;
 *  - SPF follows the providers, never the hidden-origin rule: SMTP2GO, Joinery
 *    services and plain SMTP are never told to switch provider, with or without
 *    a relay, and a provider with no SPF mechanism needs nothing in SPF;
 *  - a webhook provider (Mailgun) needs no relay: not relay_needed, its own
 *    inbound verification, the provisioning check passes, receive mode is not
 *    forced to relay;
 *  - the Local mail listener actions are refused;
 *  - the provisioning check asks for an enabled relay, not port 25;
 *  - the same checker on a bare-metal record still asks for Postfix.
 *
 * DNS goes through the FakeDnsBackend fixture; the relay topology is injected
 * by reflection where a relay is needed, so no relay rows are created.
 *
 * Run: php tests/run.php db --filter=mailbox_container_mail
 *
 * @version 1.1 - webhook provider, sending providers, a disabled relay, local sending (review B1, B2, B4, N2)
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/dns_fixtures.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/listener_admin.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relays_class.php'));

const RELAY_MX = 't9.mx.operator.example';
const RELAY_IP = '198.51.100.7';
const BOX_IP   = '203.0.113.10';
const DOMAIN   = 'container.example';

DnsResolver::setBackend(new FakeDnsBackend(array(
	RELAY_MX . '|' . DNS_A                 => array(array('ip' => RELAY_IP)),
	RELAY_MX . '|' . DNS_CNAME             => array(),
	'7.100.51.198.in-addr.arpa|' . DNS_PTR => array(array('target' => RELAY_MX . '.')),
	DOMAIN . '|' . DNS_MX                  => array(),
	DOMAIN . '|' . DNS_TXT                 => array(),
)));

/** A checker with a fixed box identity, and the topology injected when given. */
function container_checker(?array $topology = null): InboundEmailSetupCheck {
	$checker = new InboundEmailSetupCheck();
	$ref = new ReflectionClass(InboundEmailSetupCheck::class);
	foreach (array('publicIp' => BOX_IP, 'publicIpIsPrivate' => false,
			'mailHostname' => 'mail.box.example', 'topology' => $topology) as $name => $value) {
		$ref->getProperty($name)->setValue($checker, $value);
	}
	return $checker;
}

function call_private($obj, string $method, ...$args) {
	return (new ReflectionMethod(get_class($obj), $method))->invoke($obj, ...$args);
}

function row_ids(array $rows): array {
	return array_map(function ($r) { return $r['id']; }, $rows);
}

$relays = new MultiMailboxRelay(array('deleted' => false));
$relay_rows = count($relays);

try {

	section('a site container has no mail server of its own');

	harness_set_setting_mem('deployment_environment', 'docker');
	check(mailbox_site_has_mail_server() === false, 'deployment_environment docker => no mail server');
	check(mailbox_receive_mode() === 'relay', 'the live receive mode is relay');

	$mode = container_checker()->topology()['mode'];
	if ($relay_rows === 0) {
		check($mode === 'relay_needed', 'no relay row => topology relay_needed', $mode);
	} else {
		check($mode !== 'colocated', 'with a relay row the topology is the relay\'s, never colocated', $mode);
	}

	section('no relay: the Setup tab asks for one, and for nothing on the box');

	$c = container_checker(array('mode' => 'relay_needed', 'relay' => null, 'mx_hostname' => '',
		'public_ip' => '', 'enabled' => false));

	// The harness sends test mail through SMTP on localhost: a container has no
	// mail server there, and says so in a row of its own.
	$host = $c->checkHostLayer();
	check(row_ids($host) === array('host.mail_server', 'host.outbound_local'),
		'sending aimed at localhost adds a second row', implode(',', row_ids($host)));
	check(($host[1]['status'] ?? '') === InboundEmailSetupCheck::FAIL, 'and it is red');
	harness_set_setting_mem('smtp_host', 'smtp.example.net');
	harness_set_setting_mem('mailbox_forwarding_smtp_host', 'localhost');
	check(row_ids($c->checkHostLayer()) === array('host.mail_server', 'host.outbound_local'),
		'so is a forwarding SMTP host on localhost');
	harness_set_setting_mem('mailbox_forwarding_smtp_host', 'smtp.example.net');

	$host = $c->checkHostLayer();
	check(row_ids($host) === array('host.mail_server'), 'with an outside SMTP server the host layer is one row',
		implode(',', row_ids($host)));
	if (mailbox_receiving_domain_exists()) {
		check($host[0]['status'] === InboundEmailSetupCheck::FAIL && $host[0]['severity'] === InboundEmailSetupCheck::REQUIRED,
			'a domain receives here, so it is red: no mail can reach the site', $host[0]['status']);
		check(strpos($host[0]['fix']['text'] ?? '', 'Relay section') !== false, 'its fix points at the Relay section');
	} else {
		check($host[0]['status'] === InboundEmailSetupCheck::INFO, 'no domain receives here, so it is only INFO');
	}
	check(strpos(json_encode($host), 'install_email.sh') === false, 'no installer run is offered');

	check($c->checkMailHostLayer() === array(), 'the mail-host layer is empty (no HELO name to set)');

	$v = call_private($c, 'checkInboundVerification');
	check($v['status'] === InboundEmailSetupCheck::INFO, 'inbound verification is INFO, not a fault', $v['status']);

	$mx = null;
	foreach (call_private($c, 'checkDomain', DOMAIN, true) as $r) {
		if ($r['id'] === 'domain.mx') { $mx = $r; }
	}
	check($mx !== null && $mx['status'] === InboundEmailSetupCheck::FAIL, 'the domain\'s MX row is red');
	check($mx !== null && strpos($mx['summary'], 'No relay') !== false, 'and says a relay is needed',
		$mx['summary'] ?? '');
	check($mx !== null && empty($mx['fix']['dns_record']), 'and prescribes no MX target');

	$records = $c->dnsPlan(DOMAIN)->toArray()['records'];
	$types = array_map(function ($r) { return strtoupper((string)$r['type']); }, $records);
	check(!in_array('MX', $types, true), 'the DNS plan has no MX record');
	check(!in_array('A', $types, true), 'the DNS plan has no A record for the box');
	check(strpos(json_encode($records), BOX_IP) === false, 'the box\'s address appears nowhere in the plan');

	$dkim = call_private($c, 'dkimPlan');
	check(empty($dkim['local']), 'DKIM is never prescribed from a key on the box');

	section('SPF follows the providers, never the hidden-origin rule');

	$relayed = array('mode' => 'self_hosted', 'relay' => null, 'mx_hostname' => RELAY_MX,
		'public_ip' => RELAY_IP, 'enabled' => true);
	foreach (array('smtp2go', 'joinery_services', 'smtp') as $service) {
		harness_set_setting_mem('email_service', $service);
		foreach (array('no relay' => null, 'behind a relay' => $relayed) as $where => $topo) {
			$plan = call_private(container_checker($topo ?? array('mode' => 'relay_needed', 'relay' => null,
				'mx_hostname' => '', 'public_ip' => '', 'enabled' => false)), 'spfPlan', DOMAIN);
			check($plan['prescribe'] !== 'switch_provider', $service . ', ' . $where . ': never told to switch provider',
				json_encode($plan));
			check(strpos((string)$plan['value'], BOX_IP) === false, $service . ', ' . $where . ': no box address',
				json_encode($plan));
		}
	}
	harness_set_setting_mem('email_service', 'smtp2go');
	$plan = call_private($c, 'spfPlan', DOMAIN);
	check($plan['prescribe'] === 'none', 'a provider with no SPF mechanism needs nothing in SPF', json_encode($plan));
	check(strpos((string)($plan['note'] ?? ''), 'return-path') !== false, 'and says the provider uses its own return path',
		(string)($plan['note'] ?? ''));
	harness_set_setting_mem('email_service', 'none-configured');
	$plan = call_private(container_checker(array('mode' => 'relay_needed', 'relay' => null, 'mx_hostname' => '',
		'public_ip' => '', 'enabled' => false)), 'spfPlan', DOMAIN);
	check($plan['prescribe'] === 'none' && strpos((string)($plan['note'] ?? ''), 'no working way to send') !== false,
		'with no provider it says there is no way to send yet, not that a provider has a return path',
		json_encode($plan));
	harness_set_setting_mem('email_service', 'smtp');

	section('a webhook provider needs no relay');

	harness_set_setting_mem('mailbox_provider', 'mailgun');
	check(mailbox_needs_relay() === false, 'Mailgun receiving: no relay needed');
	if ($relay_rows === 0) {
		check(container_checker()->topology()['mode'] === 'colocated', 'and the topology is not relay_needed');
		$message = '';
		try {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));
			InboundEmailHealth::checkInboundMailServer();
		} catch (ProvisioningCheckFailed $e) {
			$message = $e->getMessage();
		}
		check($message === '', 'the provisioning check passes', $message);
		check(mailbox_receive_mode() !== 'relay', 'receive mode is not forced to relay', mailbox_receive_mode());
	}
	$v = call_private(container_checker(), 'checkInboundVerification');
	check(strpos((string)$v['summary'], 'no relay is set up') === false, 'inbound verification is the provider\'s',
		(string)$v['summary']);
	$plan = call_private(container_checker(), 'spfPlan', DOMAIN);
	check(strpos((string)$plan['value'], BOX_IP) === false, 'its SPF never names the box', json_encode($plan));
	check(empty(call_private(container_checker(), 'dkimPlan')['local']), 'nor its DKIM a key on the box');
	harness_set_setting_mem('mailbox_provider', 'postfix');

	section('behind a relay: one green row, and the relay\'s identity');

	$f = container_checker(array('mode' => 'self_hosted', 'relay' => null, 'mx_hostname' => RELAY_MX,
		'public_ip' => RELAY_IP, 'enabled' => true));
	$host = $f->checkHostLayer();
	check(row_ids($host) === array('host.mail_server') && $host[0]['status'] === InboundEmailSetupCheck::PASS,
		'the host layer is one green row', json_encode(array_column($host, 'status')));
	$mailhost = row_ids($f->checkMailHostLayer());
	check(!in_array('mailhost.hostname_set', $mailhost, true) && !in_array('mailhost.hostname_matches', $mailhost, true),
		'no Postfix HELO-name rows', implode(',', $mailhost));
	check(in_array('mailhost.a_record', $mailhost, true), 'the relay\'s A record is checked', implode(',', $mailhost));

	$d = container_checker(array('mode' => 'self_hosted', 'relay' => null, 'mx_hostname' => RELAY_MX,
		'public_ip' => RELAY_IP, 'enabled' => false));
	$host = $d->checkHostLayer();
	check(($host[0]['status'] ?? '') === InboundEmailSetupCheck::FAIL && strpos($host[0]['summary'], 'disabled') !== false,
		'with the relay disabled the row is red and says so', json_encode($host[0] ?? array()));

	section('the Local mail listener actions are refused');

	$_SESSION['mailbox_relay_notice'] = null;
	foreach (array('listener_decommission', 'listener_restore') as $action) {
		$res = mailbox_listener_actions(array('action' => $action), null, '/admin/x');
		check($res instanceof LogicResult, $action . ' answers with a redirect');
		check(strpos((string)($_SESSION['mailbox_relay_notice']['message'] ?? ''), 'container') !== false,
			$action . ' says the container has no mail server');
	}

	section('the provisioning check asks for a relay, not port 25');

	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));
	$active = null;
	foreach ($relays as $r) { if ((bool)$r->get('mrl_is_enabled')) { $active = $r; } }
	$message = '';
	try {
		InboundEmailHealth::checkInboundMailServer();
	} catch (ProvisioningCheckFailed $e) {
		$message = $e->getMessage();
	}
	if ($active === null) {
		check(strpos($message, 'No relay is enabled') !== false, 'no enabled relay => it fails, naming the relay', $message);
		check(strpos($message, 'port 25') === false, 'and never mentions port 25', $message);
	} else {
		check($message === '', 'an enabled relay => it passes', $message);
	}

	section('compose sends: a container\'s relay hides nothing');

	// An enabled relay with the cutover recorded is a hidden-origin deployment
	// on bare metal, where an SMTP provider needs a clean origin-leak probe
	// first. A container's relay hides nothing, so the same send takes the
	// ordinary path. The relay row is a fixture, removed at teardown.
	$fixture = new MailboxRelay(NULL);
	$fixture->set('mrl_name', 'container_mail_test scratch');
	$fixture->set('mrl_tenant_slug', 'main');
	$fixture->set('mrl_is_enabled', true);
	$fixture->save();
	$fixture_id = intval($fixture->key);
	harness_defer(function () use ($fixture_id) {
		DbConnector::get_instance()->get_db_link()
			->prepare('DELETE FROM mrl_mailbox_relays WHERE mrl_mailbox_relay_id = ?')->execute(array($fixture_id));
	});
	harness_set_setting_mem('mailbox_relay_cutover_complete', '1');
	harness_set_setting_mem('email_service', 'smtp');
	if (MailboxRelay::active() !== null && intval(MailboxRelay::active()->key) === $fixture_id) {
		harness_set_setting_mem('deployment_environment', 'baremetal');
		$t = OutboundTransport::forHostedAlias('someone@' . DOMAIN);
		check((string)$t->error !== '', 'bare metal: an SMTP provider is held until the origin-leak probe passes',
			(string)$t->error);
		harness_set_setting_mem('deployment_environment', 'docker');
		$t = OutboundTransport::forHostedAlias('someone@' . DOMAIN);
		check((string)$t->error === '' && $t->transport === null,
			'container: the same send takes the ordinary path', (string)$t->error);
	} else {
		check(false, 'the fixture relay is the active one', 'another enabled relay exists on this database');
	}
	DbConnector::get_instance()->get_db_link()
		->prepare('DELETE FROM mrl_mailbox_relays WHERE mrl_mailbox_relay_id = ?')->execute(array($fixture_id));
	harness_set_setting_mem('mailbox_relay_cutover_complete', '0');

	section('a bare-metal server is unchanged');

	harness_set_setting_mem('deployment_environment', 'baremetal');
	check(mailbox_site_has_mail_server() === true, 'deployment_environment baremetal => a mail server');
	$b = container_checker();
	if ($relay_rows === 0) {
		check($b->topology()['mode'] === 'colocated', 'no relay row => colocated');
	}
	$ids = row_ids($b->checkHostLayer());
	check(in_array('host.postfix', $ids, true) && !in_array('host.mail_server', $ids, true),
		'the host layer asks for Postfix', implode(',', $ids));

} catch (\Throwable $e) {
	check(false, 'no exception', get_class($e) . ': ' . $e->getMessage());
}

harness_finish();

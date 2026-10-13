<?php
/** @joinery-test
 * name: mail_apps_relay
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * Mail apps on a relay-fronted deployment (specs/mail_apps_plugin.md § Where
 * programs connect): programs connect to the relay's name, the relay passes
 * them through to this server, and this server's address reaches the relay in
 * the fragment.
 *
 * On the test database, because a relay row here becomes the deployment's
 * active relay. Checks:
 *  - the version gate (RelayVersion::passesMailApps, 3.12);
 *  - the name programs use is the relay's mail host name;
 *  - cannotTurnOn() names what is missing, in order: a shared relay, an older
 *    relay, the relay's name or address, this server's IPv4;
 *  - the fragment carries this server's IPv4 exactly while mail apps are on
 *    through this relay, and nothing otherwise.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

$settings = Globalvars::get_instance();
$was = array();
foreach (array('mail_apps_enabled', 'mail_apps_served_name', 'mailbox_public_ip') as $name) {
	$was[$name] = (string)$settings->get_setting($name);
}
harness_defer(function () use ($was) {
	foreach ($was as $name => $value) {
		Setting::put($name, $value);
	}
});

section('The version gate');
check(RelayVersion::PASSES_MAIL_APPS === '3.12', 'Mail apps need relay 3.12');
check(RelayVersion::passesMailApps('3.12') && RelayVersion::passesMailApps('3.13') && RelayVersion::passesMailApps('4.0'),
	'3.12 and later pass mail programs through');
check(!RelayVersion::passesMailApps('3.11') && !RelayVersion::passesMailApps('3.10') && !RelayVersion::passesMailApps('')
	&& !RelayVersion::passesMailApps('garbage'),
	'3.11 (which dialed the server\'s 465 onward, blocked by cloud providers), 3.10, an unknown version and garbage do not');

if (MailboxRelay::active() !== null) {
	harness_skip('the test database already has an active relay');
}

$relay = new MailboxRelay(NULL);
$relay->set('mrl_name', 'harnesstest mail apps relay');
$relay->set('mrl_is_enabled', true);
$relay->set('mrl_mx_hostname', 'MX.Relay.Example');
$relay->set('mrl_public_ip', '198.51.100.7');
$relay->set('mrl_last_health_json', json_encode(array('state' => 'ok', 'provisioned' => '3.10')));
$relay->save();
harness_register_row('mrl_mailbox_relays', 'mrl_mailbox_relay_id', intval($relay->key));
$reload = function () use ($relay) { return new MailboxRelay(intval($relay->key), TRUE); };
$set = function (array $fields) use ($reload) {
	$r = $reload();
	foreach ($fields as $k => $v) { $r->set($k, $v); }
	$r->save();
};

section('Programs connect to the relay');
check(MailAppGateway::relay() !== null && intval(MailAppGateway::relay()->key) === intval($relay->key), 'The relay is this deployment\'s');
check(MailAppGateway::mailHostname() === 'mx.relay.example', 'The name programs use is the relay\'s mail host name',
	(string)MailAppGateway::mailHostname());

section('What is missing, said in order');
Setting::put('mailbox_public_ip', '');
$why = (string)MailAppGateway::cannotTurnOn();
check(strpos($why, 'runs version 3.10') !== false && strpos($why, '3.12') !== false && strpos($why, 'Upgrade the relay') !== false,
	'A 3.10 relay: names its version, the one needed and the way forward', $why);
$set(array('mrl_last_health_json' => json_encode(array('state' => 'ok', 'provisioned' => '3.11'))));
$why = (string)MailAppGateway::cannotTurnOn();
check(strpos($why, 'runs version 3.11') !== false && strpos($why, 'Upgrade the relay') !== false,
	'A 3.11 relay is asked to upgrade too (its sending path is blocked)', $why);
$set(array('mrl_last_health_json' => json_encode(array('state' => 'ok', 'provisioned' => '3.12'))));
$why = (string)MailAppGateway::cannotTurnOn();
check(strpos($why, 'mailbox_public_ip') !== false, 'A 3.12 relay and no server IPv4: names the setting', $why);
Setting::put('mailbox_public_ip', '10.0.0.5');
check(strpos((string)MailAppGateway::cannotTurnOn(), 'mailbox_public_ip') !== false, 'A private address is not one the relay can reach');
Setting::put('mailbox_public_ip', '203.0.113.10');
check(MailAppGateway::cannotTurnOn() === null, 'A 3.12 relay with a name and address, and the server\'s IPv4: allowed',
	(string)MailAppGateway::cannotTurnOn());
$set(array('mrl_is_hosted' => true));
check(strpos((string)MailAppGateway::cannotTurnOn(), 'shared relay') !== false, 'A shared relay passes none');
$set(array('mrl_is_hosted' => false, 'mrl_public_ip' => ''));
check(strpos((string)MailAppGateway::cannotTurnOn(), 'no mail host name or public address') !== false, 'A relay with no address yet');
$set(array('mrl_public_ip' => '198.51.100.7'));

section('The fragment carries this server\'s address while mail apps are on through the relay');
$origins = function () use ($reload) {
	$built = (new RelayMapExporter($reload()))->build();
	$fragment = json_decode($built['fragment'], true);
	return $fragment['mail_apps_origins'] ?? 'absent';
};
Setting::put('mail_apps_enabled', '0');
Setting::put('mail_apps_served_name', '');
check($origins() === array(), 'Mail apps off: the key is present and empty, so the relay turns the pass-through off',
	json_encode($origins()));
Setting::put('mail_apps_enabled', '1');
Setting::put('mail_apps_served_name', 'mx.relay.example');
check($origins() === array('203.0.113.10'), 'On through this relay: the server\'s IPv4', json_encode($origins()));
Setting::put('mail_apps_served_name', 'mail.elsewhere.example');
check($origins() === array(), 'On, but serving another name (not through this relay): nothing', json_encode($origins()));
Setting::put('mail_apps_served_name', 'mx.relay.example');
Setting::put('mailbox_public_ip', '192.168.1.5');
check($origins() === array(), 'Never a private address: the relay would refuse the whole fragment', json_encode($origins()));
Setting::put('mailbox_public_ip', '203.0.113.10');

section('The relay\'s own view of the pass-through, from the report the site keeps');
Setting::put('mail_apps_route', 'relay');
$set(array('mrl_last_health_json' => json_encode(array('state' => 'ok', 'provisioned' => '3.12'))));
check(MailAppGateway::relayReport() === null, 'No report yet: nothing to show');
$ping = array('mail_apps' => array('origins' => 1, 'spliced_1h' => 4, 'refused_1h' => 2,
	'last_refusal' => 'the server did not answer on 10465', 'last_refusal_utc' => '2026-10-13T16:22:00Z',
	'reaches_server' => array('993' => array('port' => 993, 'answers' => true), '465' => array('port' => 10465, 'answers' => false)),
	'reaches_server_utc' => '2026-10-13T16:23:00Z'));
$set(array('mrl_last_health_json' => json_encode(array('state' => 'ok', 'provisioned' => '3.12', 'ping' => $ping)),
	'mrl_last_health_time' => '2026-10-13 16:23:30'));
$report = MailAppGateway::relayReport();
check($report !== null && $report['reaches']['993'] === array('port' => 993, 'answers' => true)
	&& $report['reaches']['465'] === array('port' => 10465, 'answers' => false),
	'Each program port: the server port the relay dials and whether the server answered', json_encode($report));
check($report['passed_1h'] === 4 && $report['refused_1h'] === 2 && $report['last_refusal'] === 'the server did not answer on 10465'
	&& $report['last_refusal_time'] === strtotime('2026-10-13T16:22:00Z') && $report['report_time'] === strtotime('2026-10-13 16:23:30 UTC'),
	'  the hour\'s counts, the last refusal and when the relay reported', json_encode($report));
unset($ping['mail_apps']['reaches_server'], $ping['mail_apps']['reaches_server_utc']);
$set(array('mrl_last_health_json' => json_encode(array('state' => 'ok', 'provisioned' => '3.12', 'ping' => $ping))));
check(MailAppGateway::relayReport()['reaches'] === null, '  a relay that has not checked yet: reaches is null, not all-clear');
Setting::put('mail_apps_route', 'direct');
check(MailAppGateway::relayReport() === null, 'On directly: no relay report');

harness_finish();

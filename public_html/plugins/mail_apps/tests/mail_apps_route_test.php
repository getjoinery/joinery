<?php
/** @joinery-test
 * name: mail_apps_route
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * The route mail programs use is kept, not followed (specs/mail_apps_plugin.md
 * § Which route a deployment uses). With the root helper stood in for:
 *  - Allow records the route and the name; a site turned on before routes
 *    were recorded is read from its served name;
 *  - a changed mail host name, or a relay of this deployment's own added
 *    later, is an offer: nothing changes until the admin switches;
 *  - programs reaching this server directly under a relay that cannot pass
 *    them through is a warning (the address is public), with no switch;
 *  - KeepMailAppsReachable leaves a working route alone, follows a relay to a
 *    new address, re-points a gone relay route (direct, or off when nothing
 *    can carry it) with a notice, and leaves a relay that is only disabled;
 *  - a site in a container (its storage stood in for) connects programs to its
 *    web name on its host: Allow and Stop write the request, the host's answer
 *    is read back, a web name behind Cloudflare is refused, and the hourly
 *    task leaves it to the host.
 *
 * On the test database, because a relay row here becomes the deployment's
 * active relay. The fake relays have no identity pin, so the fragment push
 * refuses at once and nothing leaves this machine.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

$settings = Globalvars::get_instance();
$names = array('mail_apps_enabled', 'mail_apps_served_name', 'mail_apps_route', 'mail_apps_route_notice',
	'mailbox_public_ip', 'mailbox_mail_hostname');
$was = array();
foreach ($names as $name) {
	$was[$name] = (string)$settings->get_setting($name);
}
harness_defer(function () use ($was) {
	MailAppGateway::standInForHelper(null);
	foreach ($was as $name => $value) {
		Setting::put($name, $value);
	}
});

if (MailboxRelay::active() !== null) {
	harness_skip('the test database already has an active relay');
}

// The helper, stood in for: every call recorded; status names the relay
// addresses the gateway was last told and a certificate good for 60 days.
$calls = array();
$told = '';
MailAppGateway::standInForHelper(function (array $args, ?string $stdin) use (&$calls, &$told) {
	$calls[] = $args;
	switch ($args[0]) {
		case 'on':
			$told = isset($args[3]) ? $args[3] : '';
			return array('code' => 0, 'output' => 'GATEWAY_ON');
		case 'off':
			$told = '';
			return array('code' => 0, 'output' => 'GATEWAY_OFF');
		case 'status':
			return array('code' => 0, 'output' => ($told !== '' ? 'relays=' . $told . "\n" : '')
				. 'relay_cert_expires=' . (time() + 60 * 86400) . "\nGATEWAY_STATUS");
	}
	return array('code' => 2, 'output' => 'unexpected verb ' . $args[0]);
});
$verbs = function () use (&$calls) { return array_map(function ($c) { return implode(' ', $c); }, $calls); };

Setting::put('mail_apps_enabled', false);
Setting::put('mail_apps_served_name', '');
Setting::put('mail_apps_route', '');
Setting::put('mail_apps_route_notice', '');
Setting::put('mailbox_mail_hostname', 'mail.direct.example');
Setting::put('mailbox_public_ip', '203.0.113.9');

section('Off: nothing to keep');
check(MailAppGateway::routeState() === null, 'no route state while mail apps are off');
$kept = MailAppGateway::keepReachable();
check($kept['ok'] && $calls === array(), 'the hourly task does nothing with mail apps off');

section('Allow, direct: the route and name are recorded');
$on = MailAppGateway::allow();
check($on['ok'], 'allow succeeds: ' . $on['message']);
check((string)$settings->get_setting('mail_apps_route') === 'direct', 'route recorded: direct');
check((string)$settings->get_setting('mail_apps_served_name') === 'mail.direct.example', 'name recorded');
check(MailAppGateway::routeState() === null, 'matches the rule: no state');

section('A changed mail host name is an offer, and nothing moves');
Setting::put('mailbox_mail_hostname', 'mail2.direct.example');
$state = MailAppGateway::routeState();
check($state !== null && $state['state'] === 'offer' && $state['name'] === 'mail2.direct.example', 'offer to the new name');
$calls = array();
MailAppGateway::keepReachable();
check((string)$settings->get_setting('mail_apps_served_name') === 'mail.direct.example' && $calls === array(),
	'the hourly task keeps the old name and calls nothing');
Setting::put('mailbox_mail_hostname', 'mail.direct.example');

// A relay of this deployment's own, 3.12, with its name and address.
$relay = new MailboxRelay(NULL);
$relay->set('mrl_name', 'harnesstest route relay');
$relay->set('mrl_is_enabled', true);
$relay->set('mrl_mx_hostname', 'MX.Route.Example');
$relay->set('mrl_public_ip', '198.51.100.7');
$relay->set('mrl_last_health_json', json_encode(array('state' => 'ok', 'provisioned' => '3.12')));
$relay->save();
harness_register_row('mrl_mailbox_relays', 'mrl_mailbox_relay_id', intval($relay->key));
$set = function (array $fields) use ($relay) {
	$r = new MailboxRelay(intval($relay->key), TRUE);
	foreach ($fields as $k => $v) { $r->set($k, $v); }
	$r->save();
};

section('A relay of its own added later is an offer to switch');
$state = MailAppGateway::routeState();
check($state !== null && $state['state'] === 'offer' && $state['preferred'] === 'relay'
	&& $state['name'] === 'mx.route.example', 'offer the relay route, under the relay\'s name');
$calls = array();
MailAppGateway::keepReachable();
check((string)$settings->get_setting('mail_apps_route') === 'direct' && $calls === array(),
	'the hourly task leaves the direct route alone');

section('A relay that cannot pass programs through: the address is public');
$set(array('mrl_last_health_json' => json_encode(array('state' => 'ok', 'provisioned' => '3.10'))));
$state = MailAppGateway::routeState();
check($state !== null && $state['state'] === 'exposed' && strpos((string)$state['reason'], '3.12') !== false,
	'exposed, saying why the relay cannot carry them');
$set(array('mrl_last_health_json' => json_encode(array('state' => 'ok', 'provisioned' => '3.12'))));

section('Switch: on through the relay');
$calls = array();
$sw = MailAppGateway::allow();
check($sw['ok'], 'switch succeeds: ' . $sw['message']);
check(in_array('on ' . MailAppGateway::siteName() . ' mx.route.example 198.51.100.7', $verbs(), true),
	'the helper serves the relay\'s name, reachable from the relay\'s address');
check((string)$settings->get_setting('mail_apps_route') === 'relay'
	&& (string)$settings->get_setting('mail_apps_served_name') === 'mx.route.example', 'relay route and name recorded');
check(MailAppGateway::routeState() === null, 'matches the rule: no state');
check(strpos($sw['message'], 'has not taken the change') !== false, 'the push to the fake relay is refused, and said');

section('A site turned on before routes were recorded is read from its name');
Setting::put('mail_apps_route', '');
check(MailAppGateway::recordedRoute() === 'relay', 'served name is the relay\'s: relay');
Setting::put('mail_apps_route', 'relay');

section('The relay moves to a new address: followed');
$calls = array();
$kept = MailAppGateway::keepReachable();
check(!in_array('on ' . MailAppGateway::siteName() . ' mx.route.example 198.51.100.7', $verbs(), true),
	'unmoved: on is not run again');
$set(array('mrl_public_ip' => '198.51.100.8'));
check(MailAppGateway::relayAddressMoved(), 'the moved address is seen');
$calls = array();
$kept = MailAppGateway::keepReachable();
check($kept['ok'] && in_array('on ' . MailAppGateway::siteName() . ' mx.route.example 198.51.100.8', $verbs(), true),
	'the gateway is told the new address: ' . $kept['message']);
check(!MailAppGateway::relayAddressMoved(), 'and is in step afterwards');

section('A relay only disabled is not gone');
$set(array('mrl_is_enabled' => false));
check(MailAppGateway::routeState() === null, 'no state: programs wait for the relay, as mail does');
$calls = array();
MailAppGateway::keepReachable();
check((string)$settings->get_setting('mail_apps_route') === 'relay' && !in_array('off ' . MailAppGateway::siteName(), $verbs(), true),
	'the hourly task neither re-points nor turns off');
$set(array('mrl_is_enabled' => true));

section('A relay that is gone: re-pointed direct, with a notice');
$set(array('mrl_delete_time' => gmdate('Y-m-d H:i:s')));
$state = MailAppGateway::routeState();
check($state !== null && $state['state'] === 'gone', 'gone');
$kept = MailAppGateway::keepReachable();
check($kept['ok'] && (string)$settings->get_setting('mail_apps_route') === 'direct'
	&& (string)$settings->get_setting('mail_apps_served_name') === 'mail.direct.example', 're-pointed direct: ' . $kept['message']);
$notice = MailAppGateway::routeNotice();
check($notice !== null && strpos($notice['text'], 'mail.direct.example') !== false, 'the admin page is left a notice naming the new name');
MailAppGateway::dismissRouteNotice();
check(MailAppGateway::routeNotice() === null, 'dismissed');

section('A gone relay route with nothing to carry it: off, with a notice');
$set(array('mrl_delete_time' => null));
$sw = MailAppGateway::allow();
check($sw['ok'] && (string)$settings->get_setting('mail_apps_route') === 'relay', 'back on through the relay');
// The deployment now gets its mail through a shared slot under another name.
$set(array('mrl_is_hosted' => true, 'mrl_mx_hostname' => 't9.fleet.example'));
$state = MailAppGateway::routeState();
check($state !== null && $state['state'] === 'gone' && $state['preferred'] === null, 'gone, and the rule picks nothing');
$kept = MailAppGateway::keepReachable();
check(!$settings->get_setting('mail_apps_enabled') && (string)$settings->get_setting('mail_apps_route') === '',
	'mail apps turned off: ' . $kept['message']);
$notice = MailAppGateway::routeNotice();
check($notice !== null && strpos($notice['text'], 'turned off') !== false, 'the notice says they were turned off');

section('A site in a container asks its host, in its own storage');
$box = sys_get_temp_dir() . '/mail_apps_route_' . getmypid();
@mkdir($box, 0700, true);
harness_defer(function () use ($box) {
	MailAppGateway::standInForContainer(null);
	@unlink($box . '/request.json');
	@unlink($box . '/answer.json');
	@rmdir($box);
});
MailAppGateway::standInForContainer($box);
$web = strtolower(MailAppGateway::hostname());
check(MailAppGateway::mailHostname() === $web, 'programs connect to the web name, not the relay\'s (' . $web . ')');
check(MailAppGateway::behindCloudflare('dev.getjoinery.com') && !MailAppGateway::behindCloudflare('mailapps-walk.joinerytest.com'),
	'a name proxied by Cloudflare is told from one that points straight at a server');
if (MailAppGateway::behindCloudflare($web)) {
	check(strpos((string)MailAppGateway::cannotTurnOn(), 'Cloudflare') !== false, 'a web name behind Cloudflare is refused, saying why');
}
$calls = array();
$asked = MailAppGateway::turnOn();
$request = json_decode((string)@file_get_contents($box . '/request.json'), true);
check($asked['ok'] && $request === array('on' => true, 'name' => $web) && $calls === array(),
	'Allow writes the request, and runs no helper');
check(MailAppGateway::hostAnswer() === null, 'no answer before the host gives one');
file_put_contents($box . '/answer.json', json_encode(array('state' => 'failed', 'name' => $web, 'cert_expires' => null,
	'error' => 'Mail programs can only use the site\'s web name for now.', 'time' => 1760000000)));
$answer = MailAppGateway::hostAnswer();
check($answer !== null && $answer['state'] === 'failed' && strpos((string)$answer['error'], 'web name') !== false
	&& $answer['time'] === 1760000000, 'the host\'s answer is read back');
file_put_contents($box . '/answer.json', '{"state": "maybe"}');
check(MailAppGateway::hostAnswer() === null, 'an answer in no known state is no answer');
$off = MailAppGateway::turnOff();
check($off['ok'] && json_decode((string)file_get_contents($box . '/request.json'), true) === array('on' => false),
	'Stop writes the off request');
Setting::put('mail_apps_enabled', true);
Setting::put('mail_apps_served_name', $web);
Setting::put('mail_apps_route', 'direct');
check(MailAppGateway::routeState() === null, 'no public-address warning in a container, relay or not: its host\'s address is public anyway');
check(MailAppGateway::checkAddress() === $web, 'the checks dial the served name, the host, not the container\'s own loopback');
$kept = MailAppGateway::keepReachable();
check($kept['ok'] && $calls === array(), 'the hourly task leaves a container to its host');
file_put_contents($box . '/answer.json', '{"state": "off"}');
$gone = MailAppGateway::withdrawFromHost();
check($gone['ok'] && !file_exists($box . '/request.json') && !file_exists($box . '/answer.json'),
	'uninstall takes the request and the answer away, so the host forgets the site (not only stops serving it)');
check(MailAppGateway::withdrawFromHost()['ok'], '  and a second time is still fine');
MailAppGateway::standInForContainer(null);

harness_finish();

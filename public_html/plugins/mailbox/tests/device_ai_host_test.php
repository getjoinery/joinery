<?php
/** @joinery-test
 * name: mailbox_device_ai_host
 * tier: db
 * env: dev-only
 * needs: []
 *
 * Where a member's own AI model answers (specs/fortress_mail_device_ai.md
 * § R2). The mailbox page names this origin in its CSP, so it decides where
 * the member's end-to-end encrypted mail may be sent from their browser.
 * This suite pins:
 *   - what may be stored: an origin, never a path, a query or credentials;
 *     https, or plain http only to this computer or a private or tailnet
 *     address;
 *   - the mailbox/device_ai_host action: setting or changing it needs a fresh
 *     second-factor confirmation (and answers requires_stepup without one),
 *     the same origin again and a removal need none, and one member's row
 *     never touches another's.
 *
 * @version 1.1 - the site's own model is offered only where a browser could call it
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

// ---------------------------------------------------------------------------
section('What may be stored');

$norm = function ($in) {
	try { return MailboxDeviceAiHost::normalizeOrigin($in); } catch (MailboxDeviceAiHostException $e) { return null; }
};
check($norm('https://api.fireworks.ai') === 'https://api.fireworks.ai', 'an https origin is stored as it is');
check($norm('https://API.Fireworks.AI/') === 'https://api.fireworks.ai', 'lower-cased, a trailing slash dropped');
check($norm('https://models.example.test:8443') === 'https://models.example.test:8443', 'a port is kept');
check($norm('https://api.fireworks.ai/inference/v1') === null, 'a path is refused: the rest stays in the browser');
check($norm('https://api.fireworks.ai/?x=1') === null && $norm('https://api.fireworks.ai/#x') === null, 'a query or a fragment is refused');
check($norm('https://me:pw@api.fireworks.ai') === null, 'credentials in the address are refused');
check($norm('ftp://api.fireworks.ai') === null && $norm('api.fireworks.ai') === null && $norm('') === null,
	'another scheme, no scheme, or nothing is refused');
foreach (array('http://localhost:11434', 'http://127.0.0.1:11434', 'http://[::1]:11434', 'http://192.168.1.20:11434',
	'http://10.0.0.5', 'http://172.16.4.4', 'http://100.69.133.69:11434', 'http://[fd7a:115c:a1e0::1]:11434') as $local) {
	check($norm($local) === strtolower($local), 'plain http to this computer or the person\'s own network: ' . $local);
}
foreach (array('http://api.fireworks.ai', 'http://8.8.8.8', 'http://172.32.0.1', 'http://100.128.0.1', 'http://studio.tail1.ts.net') as $public) {
	check($norm($public) === null, 'plain http to anything else is refused: ' . $public);
}

// ---------------------------------------------------------------------------
section('Setting it needs a fresh confirmation');

if (session_id() === '') @session_start();
if (session_id() === '') {
	harness_skip('the action', 'no session could be started on the CLI');
	harness_finish();
}
$sid = session_id();
$clear_markers = function () use ($sid) {
	$q = DbConnector::get_instance()->get_db_link()->prepare("DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?");
	$q->execute(array($sid));
};
harness_defer($clear_markers);
$session = SessionControl::get_instance();

$member = make_user('DeviceAiHost');
$member->enable_totp('JBSWY3DPEHPK3PXP');
$member->save();
$other = make_user('DeviceAiHostOther');
$saved_session = array('usr_user_id' => $_SESSION['usr_user_id'] ?? null, 'loggedin' => $_SESSION['loggedin'] ?? null);
harness_defer(function () use ($saved_session) {
	foreach ($saved_session as $k => $v) {
		if ($v === null) { unset($_SESSION[$k]); } else { $_SESSION[$k] = $v; }
	}
});
$register = function ($user_id) {
	$row = MailboxDeviceAiHost::loadForUser($user_id);
	if ($row) harness_register_model('MailboxDeviceAiHost', (int)$row->key);
};

$call = function ($origin) {
	return harness_call_logic('plugins/mailbox/logic/device_ai_host_logic.php', 'device_ai_host_logic', array('origin' => $origin));
};

MailboxDeviceAiHost::setForUser((int)$other->key, 'https://other.example.test');
$register((int)$other->key);

$_SESSION['usr_user_id'] = (int)$member->key;
$_SESSION['loggedin'] = true;
$clear_markers();

$r = $call('https://api.fireworks.ai/inference/v1');
check($r->error !== null && stripos((string)$r->error, 'start of the address') !== false,
	'a path is refused with the reason', (string)$r->error);

$r = $call('https://api.fireworks.ai');
check($r->error !== null && !empty($r->data['requires_stepup']), 'without a recent confirmation: refused, requires_stepup', json_encode($r->data));
check(MailboxDeviceAiHost::originForUser((int)$member->key) === null, 'and nothing was stored');

$session->stamp_second_factor();
$r = $call('https://API.fireworks.ai/');
$register((int)$member->key);
check($r->error === null && ($r->data['origin'] ?? null) === 'https://api.fireworks.ai', 'confirmed: stored, as the origin', (string)$r->error);
check(MailboxDeviceAiHost::originForUser((int)$member->key) === 'https://api.fireworks.ai', 'the row holds the origin');

$clear_markers();
$r = $call('https://api.fireworks.ai');
check($r->error === null, 'the same origin again asks for nothing', (string)$r->error);
$r = $call('https://models.example.test');
check(!empty($r->data['requires_stepup']) && MailboxDeviceAiHost::originForUser((int)$member->key) === 'https://api.fireworks.ai',
	'a change without a fresh confirmation is refused and leaves the old origin');
$r = $call('http://api.fireworks.ai');
check($r->error !== null && empty($r->data['requires_stepup']), 'plain http to a public host is refused before any confirmation is asked for');

$r = $call('');
check($r->error === null && array_key_exists('origin', $r->data) && $r->data['origin'] === null
	&& MailboxDeviceAiHost::originForUser((int)$member->key) === null,
	'removing it needs no confirmation: it can only narrow where mail goes');
check(MailboxDeviceAiHost::originForUser((int)$other->key) === 'https://other.example.test', 'another member\'s row is untouched');

unset($_SESSION['usr_user_id'], $_SESSION['loggedin']);
$r = $call('https://api.fireworks.ai');
check($r->error !== null && MailboxDeviceAiHost::originForUser(0) === null, 'signed out: refused');

section('The site\'s own model is offered only where a browser could call it');
$offer = MailboxDeviceAi::siteModelFrom('http://100.69.133.69:11434/v1', 'qwen3.6:35b, qwen3.5:9b', false, true);
check(is_array($offer) && $offer['origin'] === 'http://100.69.133.69:11434' && $offer['path'] === '/v1'
	&& $offer['model'] === 'qwen3.6:35b' && $offer['host'] === '100.69.133.69:11434' && $offer['operator'] === true,
	'a tailnet address, no key: offered with its origin, path and the first model', json_encode($offer));
check(MailboxDeviceAi::siteModelFrom('http://192.168.1.20:8080/v1/', 'm', false, false)['path'] === '/v1',
	'a trailing slash on the path is dropped');
check(MailboxDeviceAi::siteModelFrom('http://localhost:11434/v1', 'm', false, true) === null
	&& MailboxDeviceAi::siteModelFrom('http://127.0.0.1:11434/v1', 'm', false, true) === null,
	'loopback is the server\'s own machine, not the person\'s: not offered');
check(MailboxDeviceAi::siteModelFrom('http://studio.tail1.ts.net:11434/v1', 'm', false, true) === null
	&& MailboxDeviceAi::siteModelFrom('https://llm.example.com/v1', 'm', false, true) === null,
	'a name, or a public host, is not the site\'s own hardware: not offered');
check(MailboxDeviceAi::siteModelFrom('http://100.69.133.69:11434/v1', 'm', true, true) === null,
	'a provider with a key is not offered: the platform\'s keys never reach a browser');
check(MailboxDeviceAi::siteModelFrom('http://100.69.133.69:11434/v1', ' ', false, true) === null
	&& MailboxDeviceAi::siteModelFrom('', 'm', false, true) === null,
	'no model, or no address, is nothing to offer');

harness_finish();

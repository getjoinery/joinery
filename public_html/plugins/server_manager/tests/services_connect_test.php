<?php
/** @joinery-test
 * name: services_connect
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * The Connect flow on the plane and the Connected sites page
 * (specs/services_phase2_platform.md §4, E6, D2 — build item 4):
 *
 *   - The authorise page: a signed-out visitor is sent to sign in and back;
 *     a link that names no site, a return off the site's host or not https,
 *     or a missing state is refused; approval is a POST that mints the key
 *     and redirects to the return with the pair and the state; Cancel
 *     redirects with an error and mints nothing.
 *   - One active key per site per account: a second connect deactivates the
 *     first key and moves the site's rows to the new one; both services get
 *     an unpaid row on first contact.
 *   - Disconnect releases every service the site holds and deactivates the key.
 *   - The Connected sites page lists the account's sites with their services.
 *
 * Rows go to the test database and are deleted.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/services_connect_test.php
 *
 * @version 1.1 - Disconnect cuts the key first
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
// A live session before any \$_SESSION write: FormWriter and OAuth2State start
// one when none is active, which would replace what the test put there.
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
require_once(PathHelper::getIncludePath('tests/lib/logic.php'));

$db = DbConnector::get_instance()->get_db_link();
if (!$db->query("SELECT to_regclass('public.svt_service_tenants') IS NOT NULL")->fetchColumn()) {
	section('schema');
	harness_skip('svt_service_tenants is not in the test database yet — sync the server_manager plugin, then copy live to test');
	harness_finish();
	exit;
}
$cleanup = array();
harness_defer(function () use (&$cleanup, $db) {
	foreach (array_reverse($cleanup) as $row) {
		$db->exec("DELETE FROM {$row[0]} WHERE {$row[1]} = " . (int)$row[2]);
	}
});
$track_rows = function (int $user_id) use (&$cleanup) {
	$rows = new MultiServiceTenant(array('user_id' => $user_id, 'deleted' => false));
	foreach ($rows as $row) {
		$id = (int)$row->key;
		$found = false;
		foreach ($cleanup as $c) { if ($c[0] === 'svt_service_tenants' && $c[2] === $id) { $found = true; } }
		if (!$found) { $cleanup[] = array('svt_service_tenants', 'svt_service_tenant_id', $id); }
	}
};
$keys_of = function (int $user_id): array {
	$out = array();
	$keys = new MultiApiKey(array('user_id' => $user_id));
	foreach ($keys as $k) {
		if ((string)$k->get('apk_name') === ServicesConnect::KEY_NAME) {
			$out[(int)$k->key] = (bool)$k->get('apk_is_active');
		}
	}
	return $out;
};
harness_set_setting_mem('webDir', 'plane.example.com');

$owner = make_user('ConnOwner');
$host = 'conn-' . substr(md5(uniqid('', true)), 0, 6) . '.example.com';

// ── cleanReturn ─────────────────────────────────────────────────────────────
section('the return address must be the site\'s own, over https');
check(ServicesConnect::cleanReturn('https://' . $host . '/services_connected', $host) === 'https://' . $host . '/services_connected', 'the site\'s own https return is accepted');
check(ServicesConnect::cleanReturn('https://evil.example/services_connected', $host) === '', 'another host is refused');
check(ServicesConnect::cleanReturn('http://' . $host . '/services_connected', $host) === '', 'plain http on a public host is refused');
check(ServicesConnect::cleanReturn('http://localhost/services_connected', 'localhost') === 'http://localhost/services_connected', 'http is fine on a developer\'s own box');
check(ServicesConnect::cleanReturn('https://user:pw@' . $host . '/x', $host) === '', 'credentials in the URL are refused');
check(ServicesConnect::cleanReturn('https://' . $host . '/x#frag', $host) === '', 'a fragment is refused');
check(ServicesConnect::returnUrl('https://' . $host . '/services_connected?step=mail', 'public_a', 'secret_b', 'st')
	=== 'https://' . $host . '/services_connected?step=mail&public_key=public_a&secret_key=secret_b&state=st', 'the key rides the redirect once');

// ── mintKey ─────────────────────────────────────────────────────────────────
section('mintKey: one active key per site per account, rows on first contact');
$first = ServicesConnect::mintKey($owner->key, $host);
harness_register_key_id($first['key_id']);
$track_rows($owner->key);
check(preg_match('/^public_[a-z0-9]{16}$/i', $first['public_key']) && preg_match('/^secret_[a-z0-9]{16}$/i', $first['secret_key']), 'a key pair in the platform\'s shape');
$k = new ApiKey($first['key_id'], TRUE);
check((int)$k->get('apk_permission') === 3 && (string)$k->get('apk_type') === ApiKey::TYPE_MACHINE && (bool)$k->get('apk_is_active')
	&& (string)$k->get('apk_name') === 'Joinery services', 'read + write, no delete; machine; active; named for the service');
$rows = ServiceTenant::forHost($owner->key, $host);
check(count($rows) === 2, 'both services get a row on first contact');
foreach ($rows as $row) {
	check((string)$row->get('svt_state') === 'unpaid' && (int)$row->get('svt_apk_api_key_id') === $first['key_id'],
		$row->get('svt_service') . ' row is unpaid and keyed by the new key');
}

$second = ServicesConnect::mintKey($owner->key, $host);
harness_register_key_id($second['key_id']);
$keys = $keys_of($owner->key);
check($keys[$first['key_id']] === false && $keys[$second['key_id']] === true, 'a re-connect deactivates the earlier key');
$rows = ServiceTenant::forHost($owner->key, $host);
check(count($rows) === 2 && (int)$rows[0]->get('svt_apk_api_key_id') === $second['key_id'] && (int)$rows[1]->get('svt_apk_api_key_id') === $second['key_id'],
	'the site\'s rows move to the new key; no third row');
check(ServiceTenant::forKey($first['key_id'], 'mail') === null, 'the old key names no row');

try {
	ServicesConnect::mintKey($owner->key, 'not a host');
	check(false, 'a bad host is refused');
} catch (ServicesConnectException $e) {
	check(true, 'a bad host is refused');
}

// ── sitesFor / disconnect ───────────────────────────────────────────────────
section('the account\'s connected sites, and Disconnect');
$sites = ServicesConnect::sitesFor($owner->key);
check(count($sites) === 1 && $sites[0]['host'] === $host && $sites[0]['active'] === true && $sites[0]['key_id'] === $second['key_id']
	&& isset($sites[0]['services']['mail'], $sites[0]['services']['shelf']), 'one site, active, with both services', json_encode($sites));
JoineryServices::grant(ServiceTenant::forKey($second['key_id'], 'shelf'), '2030-01-01');
$released = ServicesConnect::disconnect($owner->key, $host);
check($released === 2, 'disconnect releases both rows');
$keys = $keys_of($owner->key);
check($keys[$second['key_id']] === false, 'and deactivates the key');
$rows = ServiceTenant::forHost($owner->key, $host);
check((string)$rows[0]->get('svt_state') === 'released' && (string)$rows[1]->get('svt_state') === 'released', 'both rows are released');
$sites = ServicesConnect::sitesFor($owner->key);
check($sites[0]['active'] === false, 'the site shows as inactive');
try {
	ServicesConnect::disconnect($owner->key, 'nobody-' . $host);
	check(false, 'disconnecting an unknown host refuses');
} catch (ServicesConnectException $e) {
	check(true, 'disconnecting an unknown host refuses');
}

section('Disconnect cuts the key before it releases; a provider refusal never leaves it live');
$host_b = 'cut-' . substr(md5(uniqid('', true)), 0, 6) . '.example.com';
$third = ServicesConnect::mintKey($owner->key, $host_b);
harness_register_key_id($third['key_id']);
$track_rows($owner->key);
$mail_b = ServiceTenant::forKey($third['key_id'], 'mail');
$mail_b->set('svt_state', 'active');
$mail_b->set('svt_paid_until', '2030-01-01 23:59:59');
$mail_b->set('svt_provider_subaccount_id', 'sub-harness-refuses');
$mail_b->save();
JoineryServices::grant(ServiceTenant::forKey($third['key_id'], 'shelf'), '2030-01-01');
$mock = new \GuzzleHttp\Handler\MockHandler(array(new \GuzzleHttp\Psr7\Response(500, array(), json_encode(array('data' => array('error' => 'harness: provider down'))))));
$refusing = new Smtp2GoClient('harness-master-key', new \GuzzleHttp\Client(array('handler' => \GuzzleHttp\HandlerStack::create($mock))));
try {
	ServicesConnect::disconnect($owner->key, $host_b, $refusing);
	check(false, 'the provider refusal is surfaced');
} catch (\Throwable $e) {
	check(strpos($e->getMessage(), 'provider down') !== false, 'the provider refusal is surfaced', $e->getMessage());
}
$keys = $keys_of($owner->key);
check($keys[$third['key_id']] === false, 'the key is deactivated although the mail close failed');
check((string)ServiceTenant::forKey($third['key_id'], 'shelf')->get('svt_state') === 'released', 'the shelf row is released although mail came first and failed');
check((string)ServiceTenant::forKey($third['key_id'], 'mail')->get('svt_state') === 'active', 'the mail row keeps its state for the next attempt');

// ── The authorise page ──────────────────────────────────────────────────────
section('the authorise page');
$saved_session = $_SESSION ?? array();
$_SESSION = array();
$host2 = 'auth-' . substr(md5(uniqid('', true)), 0, 6) . '.example.com';
$return = 'https://' . $host2 . '/services_connected';
$res = harness_call_logic('plugins/server_manager/logic/services_authorize_logic.php', 'services_authorize_logic',
	array('site' => $host2, 'return' => $return, 'state' => 'abc123'), 'GET');
check(strpos((string)$res->redirect, '/login?return=') === 0 && strpos(urldecode((string)$res->redirect), '/services/authorize?site=' . $host2) !== false,
	'signed out: sent to sign in with this page as the return', (string)$res->redirect);
check(strpos((string)SessionControl::get_instance()->get_return(), '/services/authorize?') === 0, 'the session return slot points back here');

$_SESSION = array('loggedin' => 1, 'usr_user_id' => (int)$owner->key, 'permission' => 0);
$res = harness_call_logic('plugins/server_manager/logic/services_authorize_logic.php', 'services_authorize_logic',
	array('site' => $host2, 'return' => $return, 'state' => 'abc123'), 'GET');
check($res->redirect === null && $res->data['problem'] === '' && $res->data['host'] === $host2 && $res->data['account'] === (string)$owner->get('usr_email'),
	'signed in: the page names the site and the account', json_encode($res->data['problem'] ?? null));
$res = harness_call_logic('plugins/server_manager/logic/services_authorize_logic.php', 'services_authorize_logic',
	array('site' => $host2, 'return' => 'https://elsewhere.example/x', 'state' => 'abc123'), 'GET');
check(strpos((string)$res->data['problem'], 'return address') !== false, 'a return off the site is refused');
$res = harness_call_logic('plugins/server_manager/logic/services_authorize_logic.php', 'services_authorize_logic',
	array('site' => '', 'return' => $return, 'state' => 'abc123'), 'GET');
check(strpos((string)$res->data['problem'], 'does not name a site') !== false, 'no site is refused');
$res = harness_call_logic('plugins/server_manager/logic/services_authorize_logic.php', 'services_authorize_logic',
	array('site' => $host2, 'return' => $return, 'state' => ''), 'GET');
check(strpos((string)$res->data['problem'], 'expired or is incomplete') !== false, 'no state is refused');

// Approve: a POST with the form's token (minted afresh by every render).
$token = function (): array {
	$fw = new FormWriterV2HTML5('services_authorize');
	ob_start(); $fw->begin_form(); $fw->end_form(); $form_html = ob_get_clean();
	preg_match('/name="([^"]*csrf[^"]*)" value="([^"]+)"/i', $form_html, $m);
	return array($m[1] ?? 'csrf_token' => $m[2] ?? '');
};
$csrf = $token();
$res = harness_call_logic('plugins/server_manager/logic/services_authorize_logic.php', 'services_authorize_logic',
	array('site' => $host2, 'return' => $return, 'state' => 'abc123', 'decision' => 'approve') + $csrf, 'POST');
check($res->redirect !== null && strpos((string)$res->redirect, $return . '?') === 0, 'approval redirects to the return', (string)$res->redirect);
parse_str((string)parse_url((string)$res->redirect, PHP_URL_QUERY), $back);
check(preg_match('/^public_/', (string)($back['public_key'] ?? '')) && preg_match('/^secret_/', (string)($back['secret_key'] ?? ''))
	&& ($back['state'] ?? '') === 'abc123', 'the redirect carries the pair and the state');
$track_rows($owner->key);
$minted = ServiceTenant::forHost($owner->key, $host2);
check(count($minted) === 2, 'the site\'s rows exist');
$new_key_id = (int)$minted[0]->get('svt_apk_api_key_id');
harness_register_key_id($new_key_id);
$kk = new ApiKey($new_key_id, TRUE);
check((string)$kk->get('apk_public_key') === (string)$back['public_key'], 'the key in the redirect is the key on the rows');

$res = harness_call_logic('plugins/server_manager/logic/services_authorize_logic.php', 'services_authorize_logic',
	array('site' => $host2, 'return' => $return, 'state' => 'zzz', 'decision' => 'decline') + $token(), 'POST');
check((string)$res->redirect === $return . '?state=zzz&error=declined', 'Cancel redirects with an error', (string)$res->redirect);
$keys_after = $keys_of($owner->key);
check(count(array_filter($keys_after)) === 1, 'Cancel minted nothing: one active key on the account');

$res = harness_call_logic('plugins/server_manager/logic/services_authorize_logic.php', 'services_authorize_logic',
	array('site' => $host2, 'return' => $return, 'state' => 'abc124', 'decision' => 'approve'), 'POST');
check(strpos((string)($res->data['problem'] ?? ''), 'token') !== false, 'a POST without the form token is refused');

// ── The profile page ────────────────────────────────────────────────────────
section('the Connected sites page');
$res = harness_call_logic('plugins/server_manager/logic/profile_services_logic.php', 'profile_services_logic', array(), 'GET');
$hosts = array_map(function ($s) { return $s['host']; }, $res->data['sites']);
sort($hosts);
$expected = array($host2, $host, $host_b);
sort($expected);
check($hosts === $expected, 'all three sites are listed', json_encode($hosts));
$res = harness_call_logic('plugins/server_manager/logic/profile_services_logic.php', 'profile_services_logic',
	array('action' => 'disconnect', 'host' => $host2), 'POST');
check($res->redirect === '/profile/server_manager/services', 'Disconnect is a POST that redirects back');
check($keys_of($owner->key)[$new_key_id] === false && (string)ServiceTenant::forKey($new_key_id, 'mail')->get('svt_state') === 'released',
	'the page\'s Disconnect cut the site off');
$_SESSION = array();
$res = harness_call_logic('plugins/server_manager/logic/profile_services_logic.php', 'profile_services_logic', array(), 'GET');
check(strpos((string)$res->redirect, '/login?return=') === 0, 'signed out, the page asks for sign-in');

$_SESSION = $saved_session;
harness_finish();

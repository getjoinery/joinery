<?php
/** @joinery-test
 * name: sandbox_webhooks
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * A sandboxed plugin's webhooks: actions anyone on the internet may call
 * (spec sandboxed_plugin_api_actions, phase 2).
 *
 * What is pinned here:
 *  - the descriptor: requires_session false, with no auth and no ai_agent;
 *  - consent: each webhook a grant of its own, by its address, described as
 *    the spec words it; not there (404) until approved, nor a new one an
 *    update adds; never offered to the AI assistant; call() refuses one;
 *  - the call: anyone's, with no user; the request's headers with every
 *    credential left out; the raw body byte for byte; the envelope's
 *    webhook mark; the log row's network; a body over 1 MB refused;
 *  - load: the anonymous pool (one call per network at a time) and the
 *    plugin's 300 a minute from every network, a refusal 429 or 503 and
 *    never run;
 *  - the relay: reads; at most 10 changes and 5 outside requests; mail to at
 *    most 3 people, never a batch, and the plugin's 10 people an hour from
 *    webhooks; no files at all;
 *  - the real runner (unfenced, and fenced where this box has the plugin's
 *    user): the plugin checks a signature over SandboxRequest::rawBody() and
 *    a header in $_SERVER, and a bad one is its 401;
 *  - over HTTP: anyone's POST reaches it, with no key.
 *
 * Run: php tests/run.php db --filter=sandbox_webhooks
 *
 * @version 1.1 - reviewer2 R1-R3: an unapproved webhook is not in the catalog; a changed file's refusal comes after
 *                the counts, writing no row past them; a refusal speaks of an address
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/logic.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
require_once(__DIR__ . '/../../lib/http.php');
harness_boot();

$inbound = <<<'PHP'
<?php
function inbound_logic_descriptor(): array {
	return array(
		'description'      => 'Receives delivery events from the mail service.',
		'requires_session' => false,
		'mutates'          => true,
		'input'            => array('event' => array('type' => 'string', 'max_length' => 40)),
	);
}

function inbound_logic($input) {
	$secret = (string)Globalvars::get_instance()->get_setting('webhook_secret');
	$sent = (string)($_SERVER['HTTP_X_SIGNATURE'] ?? '');
	if ($secret === '' || !hash_equals(hash_hmac('sha256', SandboxRequest::rawBody(), $secret), $sent)) {
		return LogicResult::error('Bad signature.', array('http_status' => 401));
	}
	return LogicResult::render(array(
		'event'  => $input['event'] ?? null,
		'length' => strlen(SandboxRequest::rawBody()),
		'user'   => SessionControl::get_instance()->get_user_id(),
		'cookie' => isset($_SERVER['HTTP_COOKIE']),
	));
}
PHP;
$manifest = array(
	'send_email' => true,
	'settings'   => array(array('name' => 'webhook_secret', 'label' => 'Secret', 'type' => 'text', 'default' => 'shh')),
);
$pm = PluginManager::getInstance();
$db = DbConnector::get_instance()->get_db_link();
$cleanup_keys = array();
harness_defer(function () use ($db, &$cleanup_keys) {
	foreach (array_unique($cleanup_keys) as $key) {
		$db->prepare('DELETE FROM rql_request_logs WHERE rql_key = ?')->execute(array($key));
	}
	$db->prepare("DELETE FROM rql_request_logs WHERE rql_ip_network IN ('198.51.100.21', '198.51.100.22') AND rql_feature IN ('sandbox_public_page', 'sandbox_public_mail', 'sandbox_webhook', 'sandbox_webhook_mail')")->execute();
});
$_SERVER['REMOTE_ADDR'] = '198.51.100.21';

try {
	section('The descriptor');
	$m = SandboxManifest::fromArray(array('name' => 'hts_hookread', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1,
		'id' => '0123456789abcdef0123456789abcdef', 'settings' => $manifest['settings']));
	$check = function (string $code) use ($m) {
		return SandboxActions::check('hts_hookread', $m, array('inbound_logic.php' => SandboxModelFile::readLogic($code)));
	};
	$ok = $check($inbound);
	check(!$ok['problems'] && SandboxActions::isWebhook($ok['actions']['inbound'] ?? array()), 'requires_session false, with no auth, is a webhook', json_encode($ok['problems']));
	$with = function (string $extra) use ($inbound) {
		return str_replace("'requires_session' => false,", "'requires_session' => false, $extra", $inbound);
	};
	check(strpos(implode(' ', $check($with("'auth' => array('min_user_permission' => 0),"))['problems']), 'has no auth') !== false, 'A webhook with an auth block is refused');
	check(strpos(implode(' ', $check($with("'ai_agent' => 'confirm',"))['problems']), 'not offered to the AI assistant') !== false, 'and one marked for the assistant');

	section('Install, and approval');
	$p = sandbox_fixture_plugin($manifest, array('files' => array('logic/inbound_logic.php' => $inbound)));
	$name = $p['name'];
	$pm->install($name, false);
	$grants = SandboxConsent::grantsOf(SandboxPlugins::manifest($name));
	check(($grants['webhooks'] ?? null) === array($name . '/inbound'), 'Each webhook is a grant, by its address', json_encode($grants['webhooks'] ?? null));
	$words = SandboxConsent::describe($grants);
	$heading = 'Webhooks: addresses anyone on the internet can call, with no sign-in (its own code checks who sent each call)';
	check(($words[$heading][0] ?? '') === 'Accepts calls from anyone on the internet at /api/v1/action/' . $name
		. '/inbound, and can change its records, call its approved websites and send mail when called', 'The approval page says what it is', json_encode($words[$heading] ?? null));
	$lines = SandboxActions::lines(SandboxActions::descriptors($name));
	check(strpos($lines[0] ?? '', 'anyone on the internet, with no sign-in') !== false, 'Its own action line says who may call it', json_encode($lines));
	sandbox_approve($name);
	$pm->activate($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	check(SandboxActions::lookup($name, 'inbound') !== null, 'Approved, it is there');

	// An update adding a webhook installs, and the new one is not there until approved.
	file_put_contents($p['dir'] . '/src/logic/second_logic.php', str_replace('inbound_logic', 'second_logic', $inbound));
	SandboxModels::refresh($name);
	SandboxModels::storePending($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	check(SandboxActions::lookup($name, 'second') === null && SandboxConsent::needsApproval($name)
		&& (SandboxConsent::pending($name)['webhooks'] ?? null) === array($name . '/second'), 'A webhook an update adds is not there until approved');
	check(SandboxActions::lookup($name, 'inbound') !== null, 'while the approved one stays');
	(new ReflectionProperty('ApiLogicEndpoint', 'catalog_cache'))->setValue(null, null);
	$catalog = ApiLogicEndpoint::actionCatalog();
	check(!isset($catalog[$name . '/second']) && isset($catalog[$name . '/inbound']),
		'Nor is it listed: GET /api/v1/actions shows the approved webhook only (reviewer2 R1)');

	if (class_exists('ActionRegistry')) {
		(new ReflectionProperty('ActionRegistry', 'actions'))->setValue(null, null);
		check(ActionRegistry::get($name . '/inbound') === null, 'Never offered to the AI assistant');
	}
	$member = make_user('SbxHookMember', 10);
	check((SandboxActions::call($name, 'inbound', array(), $member, 10)->data['http_status'] ?? null) === 400,
		'call() refuses a webhook: only its address runs it');

	section('The call');
	$host = sandbox_stand_in_runner(array('action' => array('ok' => true, 'output' => array('data' => array('fine' => true)))));
	$body = "{\"event\":\"delivered\",\"bytes\":\"\xff\xfe\"}";
	SandboxActions::$raw_body = $body;
	SandboxActions::$raw_headers = array('X-Signature' => 'abc', 'Cookie' => 'PHPSESSID=x', 'Authorization' => 'Bearer y',
		'Proxy-Authorization' => 'z', 'public-key' => 'p', 'Secret_Key' => 's', 'X-Joinery-Csrf' => 't', 'Content-Type' => 'application/json');
	harness_defer(function () { SandboxActions::$raw_body = null; SandboxActions::$raw_headers = null; });
	$call = SandboxActions::callable($name, 'inbound');
	$r = $call(array('event' => 'delivered', 'other' => 'x'));
	$sent = $host['requests']();
	$env = end($sent) ?: array();
	check(!$r->error && $r->data === array('fine' => true), 'Anyone\'s call runs it', json_encode(array($r->error, $r->data)));
	check(array_key_exists('user', $env['input'] ?? array()) && $env['input']['user'] === null && ($env['input']['values'] ?? null) === array('event' => 'delivered'), 'with no user, and only its declared fields');
	check(($env['input']['headers'] ?? null) === array('HTTP_X_SIGNATURE' => 'abc', 'HTTP_CONTENT_TYPE' => 'application/json'),
		'The headers as $_SERVER names them, every credential and X-Joinery-* left out', json_encode($env['input']['headers'] ?? null));
	check(base64_decode((string)($env['input']['body_b64'] ?? ''), true) === $body, 'The raw body byte for byte');
	check(($env['anonymous'] ?? null) === array('checked_post' => false, 'webhook' => true), 'The envelope marks it a webhook', json_encode($env['anonymous'] ?? null));
	$row = iterator_to_array(new MultiSandboxLog(array('plugin' => $name, 'kind' => SandboxLog::KIND_ACTION)), false)[0] ?? null;
	check($row && $row->get('sbl_ip_network') === '198.51.100.21' && !$row->get('sbl_usr_user_id'), 'Its log row carries the network, and no user');

	SandboxActions::$raw_body = str_repeat('x', SandboxActions::MAX_BODY_BYTES + 1);
	check(($call(array())->data['http_status'] ?? null) === 413, 'A body over 1 MB is refused');
	SandboxActions::$raw_body = $body;

	section('Load');
	$dir = rtrim(PathHelper::getSiteRoot(), '/') . '/cache/sandbox';
	$n = hexdec(substr(hash('sha256', IpAddress::network('198.51.100.21')), 0, 8)) % SandboxHost::NETWORK_LOCKS;
	$fh = fopen($dir . '/anon_net_' . $n . '.lock', 'c');
	flock($fh, LOCK_EX);
	$before = count($host['requests']());
	$r = $call(array());
	$_SERVER['REMOTE_ADDR'] = '198.51.100.21';
	flock($fh, LOCK_UN);
	fclose($fh);
	check(($r->data['http_status'] ?? null) === 503 && SandboxActions::neverRan($r) && count($host['requests']()) === $before,
		'One call per network at a time: the second is busy and never runs', json_encode($r->data));
	$key = SandboxHost::webhookKey($name);
	$cleanup_keys[] = $key;
	$db->prepare("INSERT INTO rql_request_logs (rql_feature, rql_action, rql_ip_address, rql_ip_network, rql_was_success, rql_key)
		SELECT ?, 'call', '203.0.113.9', '203.0.113.9', true, ? FROM generate_series(1, ?)")->execute(array(SandboxHost::WEBHOOK_LIMIT_FEATURE, $key, SandboxHost::WEBHOOK_CALLS_PER_MINUTE));
	$r = $call(array());
	check(($r->data['http_status'] ?? null) === 429 && SandboxActions::neverRan($r) && count($host['requests']()) === $before,
		'Past 300 calls a minute from every network together, 429 before it runs', json_encode(array($r->error, $r->data)));
	// With its file changed and not synced, a stranger past the count still writes no row (reviewer2 R2).
	$logs = function () use ($name) {
		return count(new MultiSandboxLog(array('plugin' => $name)));
	};
	$rows = $logs();
	$file = $p['dir'] . '/src/logic/inbound_logic.php';
	file_put_contents($file, $inbound . "\n// changed\n");
	$r = $call(array());
	check(($r->data['http_status'] ?? null) === 429 && $logs() === $rows, 'A changed file is refused after the counts: past them, no call-log row', json_encode($r->data));
	$db->prepare('DELETE FROM rql_request_logs WHERE rql_key = ?')->execute(array($key));
	$r = $call(array());
	check(($r->data['http_status'] ?? null) === 503 && strpos((string)$r->error, 'action file changed') !== false && $logs() === $rows + 1,
		'Within them, the refusal is one logged row, and the call never runs', json_encode(array($r->error, $r->data)));
	file_put_contents($file, $inbound);
	$db->prepare('DELETE FROM rql_request_logs WHERE rql_key = ?')->execute(array($key));
	$db->prepare('DELETE FROM rql_request_logs WHERE rql_key = ?')->execute(array($key));

	section('The relay, for a webhook');
	$relay = function () use ($name) {
		return new SandboxRelay($name, array('input' => array('user' => null), 'anonymous' => array('checked_post' => false, 'webhook' => true)));
	};
	$r = $relay();
	$a = $r->handle(array('ask' => 'record.get', 'model' => 'Note', 'id' => 0));
	check(strpos((string)($a['error'] ?? ''), 'may only read') === false, 'It reads', json_encode($a));
	foreach (array(
		'file.read' => array('ask' => 'file.read', 'id' => 1),
		'file.bytes' => array('ask' => 'file.bytes', 'part' => '', 'more' => false, 'name' => 'a.txt'),
		'a File record' => array('ask' => 'record.get', 'model' => 'File', 'id' => 1),
	) as $what => $ask) {
		$a = $r->handle($ask);
		check(strpos((string)($a['error'] ?? ''), 'reads and stores no files') !== false, 'No files: ' . $what, json_encode($a));
	}
	$refused = 0;
	for ($i = 1; $i <= 11; $i++) {
		$a = $r->handle(array('ask' => 'record.update', 'model' => 'Note', 'id' => 0, 'fields' => array('text' => 'x')));
		if (strpos((string)($a['error'] ?? ''), 'at most 10 changes') !== false) {
			$refused = $i;
			break;
		}
	}
	check($refused === 11, 'At most 10 changes', (string)$refused);
	$refused = 0;
	for ($i = 1; $i <= 6; $i++) {
		$a = $r->handle(array('ask' => 'http', 'method' => 'GET', 'url' => 'https://example.com/'));
		if (strpos((string)($a['error'] ?? ''), 'at most 5 outside requests') !== false) {
			$refused = $i;
			break;
		}
	}
	check($refused === 6, 'At most 5 outside requests', (string)$refused);
	$mail = function (array $to) {
		$message = EmailMessage::create($to[0], 'Hello', 'Body');
		foreach (array_slice($to, 1) as $address) {
			$message->cc($address);
		}
		return $message->toTransfer();
	};
	$a = $relay()->handle(array('ask' => 'email.send', 'message' => $mail(array('a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'))));
	check(strpos((string)($a['error'] ?? ''), 'at most 3 people') !== false, 'Mail to at most 3 people a call', json_encode($a));
	$a = $relay()->handle(array('ask' => 'email.batch', 'message' => $mail(array('a@example.com')), 'recipients' => array('b@example.com')));
	check(strpos((string)($a['error'] ?? ''), 'batch') !== false, 'Never a batch', json_encode($a));
	$_SERVER['REMOTE_ADDR'] = '198.51.100.22';
	$db->prepare("INSERT INTO rql_request_logs (rql_feature, rql_action, rql_ip_address, rql_ip_network, rql_was_success, rql_key)
		SELECT ?, 'recipient', '203.0.113.9', '203.0.113.9', true, ? FROM generate_series(1, ?)")->execute(array(SandboxRelay::WEBHOOK_MAIL_FEATURE, $key, SandboxRelay::WEBHOOK_MAIL_PER_HOUR));
	$a = $relay()->handle(array('ask' => 'email.send', 'message' => $mail(array('a@example.com'))));
	check(strpos((string)($a['error'] ?? ''), 'webhooks have had mail sent to 10 people') !== false,
		'From a fresh network, still at most 10 people an hour from the plugin\'s webhooks', json_encode($a));
	$db->prepare('DELETE FROM rql_request_logs WHERE rql_key = ?')->execute(array($key));
	$_SERVER['REMOTE_ADDR'] = '198.51.100.21';

	section('The real runner: the plugin checks the signature');
	$host['stop']();
	$signed = function (string $secret) use ($body) {
		SandboxActions::$raw_headers = array('X-Signature' => hash_hmac('sha256', $body, $secret), 'Cookie' => 'PHPSESSID=x');
	};
	$run_real = function (string $plugin, string $label) use ($signed, $body) {
		$call = SandboxActions::callable($plugin, 'inbound');
		$signed('shh');
		$r = $call(array('event' => 'delivered'));
		check(!$r->error && ($r->data['event'] ?? null) === 'delivered' && ($r->data['length'] ?? null) === strlen($body)
			&& array_key_exists('user', $r->data) && $r->data['user'] === null && ($r->data['cookie'] ?? true) === false,
			$label . ': a good signature over SandboxRequest::rawBody() runs it, with no user and no cookie', json_encode(array($r->error, $r->data)));
		$signed('wrong');
		$r = $call(array('event' => 'delivered'));
		check($r->error === 'Bad signature.' && ($r->data['http_status'] ?? null) === 401, $label . ': a bad one is its own 401', json_encode(array($r->error, $r->data)));
	};
	SandboxRunner::$command_override = function (array $envelope) {
		$cmd = SandboxRunner::command($envelope);
		return array_slice($cmd, array_search('--', $cmd, true) + 1);
	};
	harness_defer(function () { SandboxRunner::$command_override = null; });
	$run_real($name, 'Unfenced');
	SandboxRunner::$command_override = null;

	section('Over HTTP');
	$res = harness_request('POST', '/api/v1/action/' . $name . '/second', array('body' => array('event' => 'x')));
	if ($res['status'] === 0) {
		harness_skip('Over HTTP', 'this site does not answer');
	} else {
		check($res['status'] === 404, 'A webhook not approved is the same 404 as any action not there', $res['status'] . ' ' . $res['body']);
		$res = harness_request('POST', '/api/v1/action/' . $name . '/inbound', array('body' => array('event' => 'x')));
		check(in_array($res['status'], array(503, 401, 200), true) && $res['status'] !== 404,
			'Anyone\'s POST with no key reaches it (503 here: this fixture has no fence user)', $res['status'] . ' ' . $res['body']);
	}

	section('The real runner, fenced, and over HTTP');
	$fence = 'sbxhooks';
	$why = SandboxRunner::unavailable('hts_none');
	if ($why === SandboxRunner::NO_FENCE || $why === SandboxRunner::OLD_LAUNCHER) {
		harness_skip('The fenced webhook', 'this box has no launcher 2.1 with the allowlist');
	} else {
		$ready = function_exists('posix_getpwnam') && posix_getpwnam(SandboxRunner::userFor($fence)) !== false;
		sandbox_fixture_plugin($manifest, array('name' => $fence, 'files' => array('logic/inbound_logic.php' => $inbound), 'keep_folder' => !$ready));
		if (!$ready) {
			harness_skip('The fenced webhook', 'jpx-' . $fence . ' is not made yet; the root step makes it within a minute of this folder, which is left in place. Run again then.');
		} else {
			$pm->install($fence, false);
			sandbox_approve($fence);
			$pm->activate($fence);
			SandboxPlugins::reset();
			SandboxModels::reset();
			$run_real($fence, 'Fenced');
			$cleanup_keys[] = SandboxHost::webhookKey($fence);
			$raw = '{"event":"opened"}';
			$res = harness_request('POST', '/api/v1/action/' . $fence . '/inbound', array('body' => $raw, 'encode' => 'raw',
				'headers' => array('Content-Type: application/json', 'X-Signature: ' . hash_hmac('sha256', $raw, 'shh'))));
			check($res['status'] === 200 && ($res['json']['data']['event'] ?? null) === 'opened' && ($res['json']['data']['length'] ?? null) === strlen($raw),
				'Over HTTP, a signed POST with no key runs it in the fence', $res['status'] . ' ' . $res['body']);
			$res = harness_request('POST', '/api/v1/action/' . $fence . '/inbound', array('body' => $raw, 'encode' => 'raw',
				'headers' => array('Content-Type: application/json', 'X-Signature: nope')));
			check($res['status'] === 401, 'and a bad signature gets the plugin\'s 401', $res['status'] . ' ' . $res['body']);
		}
	}
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();

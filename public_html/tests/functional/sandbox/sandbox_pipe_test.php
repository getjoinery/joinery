<?php
/** @joinery-test
 * name: sandbox_pipe
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * The pipe between core and a fenced sandboxed PHP plugin
 * (specs/sandboxed_php_plugins.md WP2, design 3): SandboxRunner's
 * conversation, SandboxRelay's asks over this site's own loopback, and the
 * fence end to end where this box has the plugin's user.
 *
 * What is pinned here:
 *  - the envelope carries no credential, and the key's secret is never in
 *    what core writes to the plugin (its argv, its environment, its stdin:
 *    the envelope and every answer) nor in the call log;
 *  - record asks reach the API with the plugin's key: create, read, list,
 *    update, delete; a delete is a trip to the trash, and the row is still in
 *    its table and can be restored;
 *  - a filter or sort that is not a readable declared column of the model is
 *    refused before the API is asked: a credential column, a collection's own
 *    option key, an undeclared key, a value that is not one scalar;
 *  - a model or action the plugin was not approved for is refused;
 *  - its API requests are logged as the plugin's and metered on its own
 *    budget, not the loopback address's (reviewer2 E4);
 *  - an http ask goes only to an approved host, over https on 443, and a
 *    header that steers the connection or carries CR or LF is refused; the
 *    log names the host and not the address;
 *  - a call is bounded: its time, 200 asks, a 4 MB line; a plugin that
 *    throws, dies or never answers is an error, not a success; one that
 *    answers and lingers is answered and its run ended; the conversation is
 *    in strict turns, so unread answers never pile up in the worker;
 *  - on a host that refuses unsigned packages nothing is installed,
 *    activated or run, and each refusal says the server is shared;
 *  - a file under src/ is neither run nor served over HTTP;
 *  - in the real fence, as the plugin's own user, a call runs and its asks
 *    reach the API, core ends a run that lingers or is refused through the
 *    launcher's watch pipe, and proc_open, fork and sockets fail (skipped until the root step has made jpx-sbxpipe, a
 *    minute after the folder first appears).
 *
 * Run: php tests/run.php db --filter=sandbox_pipe
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
require_once(__DIR__ . '/../../lib/http.php');
harness_boot();

/** The plugin the conversation runs: one on_ui, its mode chosen by the call's values. */
$plugin_php = <<<'PHP'
<?php
// SBX_SOURCE_MARKER: this text must never be served.
echo 'SBX_EXEC' . 'UTED';

function sbx_try(string $ask, array $args): array {
	try {
		return SandboxPipe::ask($ask, $args);
	} catch (SandboxPipeException $e) {
		return array('refused' => $e->getMessage());
	}
}

function on_ui(array $input) {
	$v = $input['values'] ?? array();
	$mode = $v['mode'] ?? '';
	if ($mode === 'loop') { while (true) { } }
	if ($mode === 'throw') { throw new RuntimeException('nope'); }
	if ($mode === 'die') { exit(7); }
	if ($mode === 'flood') { for ($i = 0; $i < 300; $i++) { SandboxPipe::ask('log', array('message' => 'n' . $i)); } }
	if ($mode === 'long') { fwrite(STDOUT, str_repeat('x', 4300000)); sleep(5); }
	if ($mode === 'linger') { register_shutdown_function(function () { while (true) { } }); return 'answered'; }
	if ($mode === 'pile') {
		for ($i = 0; $i < 50; $i++) { fwrite(STDOUT, json_encode(array('ask' => 'http', 'url' => 'https://hooks.example.com/p' . $i)) . "\n"); }
		sleep(30);
	}
	if ($mode === 'escape') {
		$p = @proc_open(array('/bin/true'), array(), $pipes);
		$fork = function_exists('pcntl_fork') ? @pcntl_fork() : -1;
		if ($fork === 0) { exit(0); }
		$sock = @stream_socket_client('tcp://127.0.0.1:443', $errno, $errstr, 1);
		return array('proc_open' => is_resource($p), 'fork' => $fork, 'socket' => $sock !== false);
	}
	if ($mode === 'memory') { $s = str_repeat('y', 200 * 1048576); return strlen($s); }
	if ($mode === 'uid') {
		$made = SandboxPipe::ask('record.create', array('model' => 'Note', 'fields' => array('text' => 'fenced')));
		return array('uid' => function_exists('posix_getuid') ? posix_getuid() : null, 'record' => $made['record'],
			'config' => @file_get_contents($v['config'] ?? '/nonexistent') !== false);
	}
	$answers = array();
	$answers['create'] = sbx_try('record.create', array('model' => 'Note', 'fields' => array('text' => 'hello', 'qty' => 3)));
	$id = 0;
	foreach (($answers['create']['record'] ?? array()) as $k => $val) {
		if (substr($k, -9) === '__note_id') { $id = (int)$val; }
	}
	$answers['id'] = $id;
	$answers['get'] = sbx_try('record.get', array('model' => 'Note', 'id' => $id));
	$answers['list'] = sbx_try('record.list', array('model' => 'Note', 'filters' => array('text' => 'hello', 'deleted' => false), 'sort' => 'qty', 'direction' => 'desc', 'limit' => 10));
	$answers['update'] = sbx_try('record.update', array('model' => 'Note', 'id' => $id, 'fields' => array('qty' => 4)));
	$answers['delete'] = sbx_try('record.delete', array('model' => 'Note', 'id' => $id));
	$answers['option_key'] = sbx_try('record.list', array('model' => 'Note', 'filters' => array('product_id_is_not' => '0) OR (1=1')));
	$answers['undeclared'] = sbx_try('record.list', array('model' => 'Note', 'filters' => array('nope' => 1)));
	$answers['array_value'] = sbx_try('record.list', array('model' => 'Note', 'filters' => array('text' => array('a', 'b'))));
	$answers['bad_sort'] = sbx_try('record.list', array('model' => 'Note', 'sort' => 'text; DROP TABLE x'));
	$answers['not_approved'] = sbx_try('record.list', array('model' => 'User'));
	$answers['action'] = sbx_try('action', array('name' => 'no_such_action_here'));
	$answers['bad_ask'] = sbx_try('shell', array());
	$answers['log'] = sbx_try('log', array('message' => "a\nline", 'level' => 'warn'));
	if (!empty($v['capture'])) {
		file_put_contents($v['capture'], json_encode(array(
			'argv' => $GLOBALS['argv'], 'env' => getenv(), 'envelope' => SandboxPipe::$envelope, 'answers' => $answers,
		)));
	}
	return array('answers' => $answers);
}
PHP;

$manifest = array(
	'models' => array(array('name' => 'Note', 'fields' => array(
		array('name' => 'text', 'type' => 'string', 'max_length' => 80),
		array('name' => 'qty', 'type' => 'integer'),
		array('name' => 'secret_hash', 'type' => 'string', 'max_length' => 80),
	))),
	'outbound_hosts' => array('hooks.example.com'),
	'limits' => array('memory_mb' => 16, 'timeout_ms' => 3000),
);

/** Run each call unfenced, through the same PHP command line the fence would run. */
function sbx_pipe_unfenced(): void {
	SandboxRunner::$command_override = function (array $envelope) {
		$cmd = SandboxRunner::command($envelope);
		return array_slice($cmd, array_search('--', $cmd, true) + 1);
	};
	harness_defer(function () {
		SandboxRunner::$command_override = null;
	});
}

try {
	$p = sandbox_fixture_plugin($manifest, array('files' => array('plugin.php' => $plugin_php)));
	$name = $p['name'];
	$class = SandboxManifest::classFor($name, 'Note');
	$prefix = SandboxManifest::prefixFor($name, 'Note');
	$pm = PluginManager::getInstance();
	$pm->install($name, false);
	$admin = sandbox_approve($name);
	$pm->activate($name);
	SandboxPlugins::reset();
	$credential = SandboxInstall::credentialFor($name);
	check($credential !== null, 'The approved plugin has a key');
	$secret = (string)($credential['secret_key'] ?? '');

	section('The envelope carries no credential');
	$envelope = SandboxHost::envelope($name, 'on_ui', array());
	check(!array_key_exists('api', $envelope) && strpos(json_encode($envelope), $secret) === false,
		'No api entry and no secret in it', implode(',', array_keys($envelope)));
	$cmd = SandboxRunner::command($envelope);
	check(strpos(implode(' ', $cmd), $secret) === false && $cmd[0] === SandboxRunner::LAUNCHER
		&& in_array('--user=jpx-' . $name, $cmd, true), 'The fenced command runs as the plugin\'s user and holds no secret', implode(' ', $cmd));

	section('A conversation');
	sbx_pipe_unfenced();
	$capture = harness_scratch_dir('sandbox_pipe') . '/capture.json';
	$result = SandboxHost::ui($name, 'go', array('mode' => 'crud', 'capture' => $capture), $admin);
	check($result['status'] === SandboxLog::STATUS_OK, 'The call is answered', json_encode($result));
	$a = $result['output']['answers'] ?? array();
	$id = (int)($a['id'] ?? 0);
	check($id > 0 && ($a['create']['record'][$prefix . '_text'] ?? null) === 'hello', 'record.create made a row through the API', json_encode($a['create'] ?? null));
	check(($a['get']['record'][$prefix . '_qty'] ?? null) == 3, 'record.get read it', json_encode($a['get'] ?? null));
	check(($a['list']['total'] ?? null) === 1 && count($a['list']['records'] ?? array()) === 1, 'record.list found it by a declared column', json_encode($a['list'] ?? null));
	check(($a['update']['record'][$prefix . '_qty'] ?? null) == 4, 'record.update changed it', json_encode($a['update'] ?? null));
	check(!isset($a['delete']['refused']), 'record.delete was carried out', json_encode($a['delete'] ?? null));
	$row = new $class($id, TRUE);
	check($row->key && $row->get($prefix . '_delete_time'), 'A deleted row is still in its table, in the trash');
	$row->undelete();
	$row = new $class($id, TRUE);
	check($row->key && !$row->get($prefix . '_delete_time'), 'and an admin can restore it');

	section('Its API calls are stamped with its install, and have no budget');
	$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT COUNT(*) FROM rql_request_logs WHERE rql_feature = 'api' AND rql_key = ? AND rql_create_time > NOW() - INTERVAL '10 minutes'");
	$q->execute(array(RequestLogger::SANDBOX_KEY_PREFIX . PluginInstallIdentity::idOf($name)));
	$stamped = (int)$q->fetchColumn();
	check($stamped >= 5, 'Each of its API requests is logged with its install id (' . $stamped . ')');
	check(!method_exists('RequestLogger', 'sandbox_plugin_rate_limit_state')
		&& strpos((string)file_get_contents(PathHelper::getIncludePath('api/apiv1.php')), 'api_rate_limited($plugin_state') === false,
		'No hourly budget is kept for a plugin\'s key (spec sandboxed_plugin_public_pages D3)');
	$saved_addr = $_SERVER['REMOTE_ADDR'] ?? null;
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT COUNT(*) FROM rql_request_logs WHERE rql_feature = 'api' AND rql_ip_address = '127.0.0.1' AND rql_create_time > NOW() - INTERVAL '3600 seconds'");
	$q->execute();
	$all_loopback = (int)$q->fetchColumn();
	$address = RequestLogger::address_rate_limit_state('api', 1000000, 3600);
	check($address['count'] <= $all_loopback - $stamped, 'and not against the loopback address\'s', json_encode(array($address['count'], $all_loopback, $stamped)));
	if ($saved_addr === null) { unset($_SERVER['REMOTE_ADDR']); } else { $_SERVER['REMOTE_ADDR'] = $saved_addr; }

	section('What the relay refuses');
	check(isset($a['option_key']['refused']) && strpos($a['option_key']['refused'], 'only on a column') !== false,
		'A collection\'s own option key, with SQL in its value', json_encode($a['option_key'] ?? null));
	check(isset($a['undeclared']['refused']), 'A key that is no column', json_encode($a['undeclared'] ?? null));
	check(isset($a['array_value']['refused']), 'A filter value that is not one scalar', json_encode($a['array_value'] ?? null));
	check(isset($a['bad_sort']['refused']), 'A sort that is no column', json_encode($a['bad_sort'] ?? null));
	check(isset($a['not_approved']['refused']) && strpos($a['not_approved']['refused'], 'not approved') !== false,
		'A model it was not approved for', json_encode($a['not_approved'] ?? null));
	check(isset($a['action']['refused']) && strpos($a['action']['refused'], 'not approved') !== false,
		'An action it was not approved for', json_encode($a['action'] ?? null));
	check(isset($a['bad_ask']['refused']), 'An ask that does not exist', json_encode($a['bad_ask'] ?? null));
	check(SandboxRelay::listQuery($class, array('filters' => array('secret_hash' => 'x'))) !== null
		&& is_string(SandboxRelay::listQuery($class, array('filters' => array('secret_hash' => 'x')))),
		'A filter on a hash column it may not read');
	check(is_string(SandboxRelay::listQuery($class, array('sort' => 'secret_hash'))), 'and a sort on one');
	check(is_string(SandboxRelay::listQuery('User', array('filters' => array('password' => 'x'))))
		&& is_string(SandboxRelay::listQuery('User', array('filters' => array('usr_password' => 'x')))),
		'A core model\'s password column, by either name');
	check(is_string(SandboxRelay::listQuery($class, array('filters' => array('text' => null)))), 'A NULL filter (not carried yet)');
	check(is_string(SandboxRelay::listQuery($class, array('limit' => 201))) && is_string(SandboxRelay::listQuery($class, array('limit' => 10, 'offset' => 5))),
		'A limit past 200, an offset off the page');
	$q = SandboxRelay::listQuery($class, array('filters' => array('qty' => true, 'deleted' => true), 'sort' => 'text', 'limit' => 20, 'offset' => 40));
	check($q === array($prefix . '_qty' => '1', 'deleted' => '1', 'sort' => $prefix . '_text', 'sdirection' => 'ASC', 'numperpage' => '20', 'page' => '2'),
		'An allowed list goes by full column names, true as 1, the page from the offset', json_encode($q));

	section('The secret never travels toward the plugin');
	$seen = is_file($capture) ? json_decode((string)file_get_contents($capture), true) : null;
	check(is_array($seen), 'The plugin recorded what it was given');
	check(is_array($seen) && strpos(json_encode($seen['argv']), $secret) === false, 'not in its argv');
	check(is_array($seen) && strpos(json_encode($seen['env']), $secret) === false && count($seen['env']) <= 1,
		'not in its environment, which is one fixed variable', json_encode(array_keys($seen['env'] ?? array())));
	check(is_array($seen) && strpos(json_encode($seen['envelope']) . json_encode($seen['answers']), $secret) === false,
		'not in anything written to its stdin');
	check(strpos(json_encode($seen['answers'] ?? null), (string)$credential['public_key']) === false, 'nor is its public key');
	$log = DbConnector::get_instance()->get_db_link()->prepare('SELECT sbl_message, sbl_calls FROM sbl_sandbox_logs WHERE sbl_plugin = ? ORDER BY sbl_sandbox_log_id DESC LIMIT 1');
	$log->execute(array($name));
	$last = $log->fetch(PDO::FETCH_ASSOC);
	check($last && strpos(json_encode($last), $secret) === false, 'not in the call log');
	$calls = json_decode((string)($last['sbl_calls'] ?? ''), true) ?: array();
	$doors = array_count_values(array_column($calls, 'door'));
	check(($doors['api'] ?? 0) >= 5 && ($doors['log'] ?? 0) === 1, 'The call log lists each ask', json_encode($doors));
	$logged = array_values(array_filter($calls, function ($c) { return $c['door'] === 'log'; }));
	check(($logged[0]['target'] ?? '') === 'warn' && ($logged[0]['detail'] ?? '') === 'a line', 'a log line on one line, at its level', json_encode($logged));

	section('Outside requests');
	$sent = array();
	SandboxRelay::$outbound = function ($method, $url, $body, $headers, $seconds) use (&$sent) {
		$sent[] = array($method, $url, $headers);
		return new SafeHttpResponse(200, array('content-type' => 'text/plain'), 'pong', $url);
	};
	harness_defer(function () { SandboxRelay::$outbound = null; });
	$relay = new SandboxRelay($name);
	$ok = $relay->handle(array('ask' => 'http', 'method' => 'POST', 'url' => 'https://hooks.example.com/T0k3n/path', 'headers' => array('X-Sig' => 'abc'), 'body' => '{}'));
	check(!empty($ok['ok']) && ($ok['body'] ?? '') === 'pong' && count($sent) === 1 && $sent[0][2] === array('X-Sig' => 'abc'),
		'An approved host is reached with the plugin\'s headers', json_encode($ok));
	foreach (array(
		'an unapproved host'      => array('url' => 'https://evil.example.com/'),
		'plain http'              => array('url' => 'http://hooks.example.com/'),
		'another port'            => array('url' => 'https://hooks.example.com:8443/'),
		'a user in the address'   => array('url' => 'https://u:p@hooks.example.com/'),
		'a CR LF in a value'      => array('url' => 'https://hooks.example.com/', 'headers' => array('X-A' => "a\r\nHost: other.example")),
		'a LF in a name'          => array('url' => 'https://hooks.example.com/', 'headers' => array("X-A\nHost" => 'b')),
		'a Host header'           => array('url' => 'https://hooks.example.com/', 'headers' => array('Host' => 'other.example')),
		'a Content-Length header' => array('url' => 'https://hooks.example.com/', 'headers' => array('content-length' => '0')),
		'a method it may not use' => array('url' => 'https://hooks.example.com/', 'method' => 'CONNECT'),
	) as $label => $ask) {
		$before = count($sent);
		$answer = $relay->handle(array('ask' => 'http') + $ask);
		check(empty($answer['ok']) && count($sent) === $before, 'Refused: ' . $label, json_encode($answer));
	}
	$targets = array_column(array_filter($relay->calls(), function ($c) { return $c['door'] === 'http'; }), 'target');
	check(strpos(implode(' ', $targets), 'T0k3n') === false && in_array('POST https://hooks.example.com/…', $targets, true),
		'The log names the host, never the rest of the address', json_encode($targets));

	section('A call is bounded');
	$result = SandboxHost::ui($name, 'go', array('mode' => 'loop'), $admin);
	check($result['status'] === SandboxLog::STATUS_TIMEOUT && $result['duration_ms'] < 4500, 'A plugin that never answers is cut off at its time limit', json_encode($result));
	$result = SandboxHost::ui($name, 'go', array('mode' => 'flood'), $admin);
	check($result['status'] === SandboxLog::STATUS_ERROR && strpos($result['message'], 'more than 200') !== false, 'More than 200 asks ends the call', $result['message']);
	$result = SandboxHost::ui($name, 'go', array('mode' => 'long'), $admin);
	check($result['status'] === SandboxLog::STATUS_ERROR && strpos($result['message'], 'longer than') !== false && $result['duration_ms'] < 2500,
		'A line past 4 MB ends the call at once', json_encode($result));
	$result = SandboxHost::ui($name, 'go', array('mode' => 'throw'), $admin);
	check($result['status'] === SandboxLog::STATUS_ERROR && strpos($result['message'], 'nope') !== false, 'A plugin that throws is an error, saying what', $result['message']);
	$result = SandboxHost::ui($name, 'go', array('mode' => 'die'), $admin);
	check($result['status'] === SandboxLog::STATUS_ERROR && strpos($result['message'], 'without answering') !== false, 'One that exits without answering is an error', $result['message']);
	$result = SandboxHost::ui($name, 'go', array('mode' => 'linger'), $admin);
	check($result['status'] === SandboxLog::STATUS_OK && $result['output'] === 'answered' && $result['duration_ms'] < 1500,
		'A plugin that answers and keeps running is answered, and its run ended', json_encode($result));
	// Answers larger than a pipe holds: the first cannot be written while the
	// plugin does not read, so no further ask is taken in the meantime.
	SandboxRelay::$outbound = function ($method, $url, $body, $headers, $seconds) {
		return new SafeHttpResponse(200, array(), str_repeat('z', 300000), $url);
	};
	$result = SandboxHost::ui($name, 'go', array('mode' => 'pile'), $admin);
	$asked = count(array_filter($result['calls'], function ($c) { return $c['door'] === 'http'; }));
	check($result['status'] === SandboxLog::STATUS_TIMEOUT && $asked === 1,
		'One that asks without reading its answers gets no further ask carried out while an answer is unwritten', json_encode(array($result['status'], $asked)));
	SandboxRelay::$outbound = null;
	$result = SandboxHost::ui($name, 'go', array('mode' => 'memory'), $admin);
	check($result['status'] === SandboxLog::STATUS_ERROR && strpos($result['message'], 'memory') !== false, 'One past its memory limit is an error that says so', $result['message']);
	SandboxRunner::$command_override = null;

	section('A file under src/ is never served or run by the site');
	$r = harness_request('GET', '/plugins/' . $name . '/src/plugin.php', array('accept' => null));
	check(strpos($r['body'], 'SBX_SOURCE_MARKER') === false && strpos($r['body'], 'SBX_EXECUTED') === false && $r['status'] !== 200,
		'GET /plugins/' . $name . '/src/plugin.php gives neither the source nor its output', 'HTTP ' . $r['status']);
	$r = harness_request('GET', '/plugins/' . $name . '/src/', array('accept' => null));
	check(strpos($r['body'], 'plugin.php') === false || $r['status'] !== 200, 'nor a listing of src/', 'HTTP ' . $r['status']);

	section('A shared host');
	$restrictions = harness_scratch_dir('sandbox_pipe') . '/restrictions';
	file_put_contents($restrictions, "unsigned_packages=refuse\n");
	HostRestrictions::useFile($restrictions);
	harness_defer(function () { HostRestrictions::useFile(null); });
	check(!SandboxPlugins::enabled() && SandboxPlugins::offMessage() === SandboxPlugins::SHARED_HOST_MESSAGE,
		'Sandboxed plugins are off, whatever the setting says, and the reason is the shared host');
	check(SandboxRunner::unavailable($name) === SandboxRunner::SHARED_HOST, 'A call is refused before anything starts');
	$result = SandboxHost::ui($name, 'go', array('mode' => 'crud'), $admin);
	check($result['status'] === SandboxLog::STATUS_UNAVAILABLE && $result['message'] === SandboxRunner::SHARED_HOST, 'and logged unavailable, saying why', json_encode($result));
	$refused = function (callable $fn) {
		try {
			$fn();
			return '';
		} catch (Throwable $e) {
			return $e->getMessage();
		}
	};
	$pm->deactivate($name);
	check(strpos($refused(function () use ($pm, $name) { $pm->activate($name); }), 'shared with other sites') !== false, 'Activation is refused, saying why');
	check(strpos($refused(function () use ($name) { SandboxPluginManager::install($name); }), 'shared with other sites') !== false, 'Install is refused, saying why');
	HostRestrictions::useFile(null);
	$pm->activate($name);

	section('In the fence, as the plugin\'s own user');
	$fence_name = 'sbxpipe';
	if (SandboxRunner::unavailable('hts_none') === SandboxRunner::NO_FENCE) {
		harness_skip('The fenced call', 'this box has no launcher with the allowlist');
	} else {
		$user_ready = function_exists('posix_getpwnam') && posix_getpwnam(SandboxRunner::userFor($fence_name)) !== false;
		$f = sandbox_fixture_plugin($manifest + array(), array('name' => $fence_name, 'files' => array('plugin.php' => $plugin_php),
			'keep_folder' => !$user_ready));
		if (!$user_ready) {
			harness_skip('The fenced call', 'jpx-' . $fence_name . ' is not made yet; the root step makes it within a minute of this folder, which is left in place. Run again then.');
		} else {
			$pm->install($fence_name, false);
			$fence_admin = sandbox_approve($fence_name);
			$pm->activate($fence_name);
			SandboxPlugins::reset();
			$config = realpath(PathHelper::getSiteRoot() . '/config/Globalvars_site.php');
			$started = microtime(true);
			$result = SandboxHost::ui($fence_name, 'go', array('mode' => 'uid', 'config' => (string)$config), $fence_admin);
			$ms = (int)round((microtime(true) - $started) * 1000);
			$pw = posix_getpwnam(SandboxRunner::userFor($fence_name));
			check($result['status'] === SandboxLog::STATUS_OK && ($result['output']['uid'] ?? null) === $pw['uid'],
				'The call ran as jpx-' . $fence_name . ' (uid ' . $pw['uid'] . ')', json_encode($result));
			$fprefix = SandboxManifest::prefixFor($fence_name, 'Note');
			check(($result['output']['record'][$fprefix . '_text'] ?? null) === 'fenced', 'and its ask reached the API through the relay');
			check(($result['output']['config'] ?? true) === false, 'It cannot read the site\'s config file');
			check(true, 'One fenced call with one ask took ' . $ms . ' ms (V4)');
			$result = SandboxHost::ui($fence_name, 'go', array('mode' => 'loop'), $fence_admin);
			check($result['status'] === SandboxLog::STATUS_TIMEOUT && $result['duration_ms'] < 3600,
				'A fenced plugin that never answers is cut off at core\'s deadline, not the launcher\'s', json_encode($result));
			$result = SandboxHost::ui($fence_name, 'go', array('mode' => 'linger'), $fence_admin);
			check($result['status'] === SandboxLog::STATUS_OK && $result['duration_ms'] < 1500,
				'One that answers and keeps running is answered, and core ends its run through the watch pipe', json_encode($result));
			$result = SandboxHost::ui($fence_name, 'go', array('mode' => 'long'), $fence_admin);
			check($result['status'] === SandboxLog::STATUS_ERROR && $result['duration_ms'] < 1500,
				'A refused run ends at once, not at the launcher\'s timeout', json_encode($result));
			$result = SandboxHost::ui($fence_name, 'go', array('mode' => 'escape'), $fence_admin);
			$o = $result['output'] ?? array();
			check($result['status'] === SandboxLog::STATUS_OK && ($o['proc_open'] ?? true) === false && ($o['fork'] ?? 0) === -1 && ($o['socket'] ?? true) === false,
				'In the fence proc_open, a fork and a socket all fail', json_encode($result));
		}
	}
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();

<?php
/** @joinery-test
 * name: sandbox_actions
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * A sandboxed plugin's own API actions (spec sandboxed_plugin_api_actions,
 * phase 1).
 *
 * What is pinned here:
 *  - the reader: a logic file's top-level functions and its descriptor, a
 *    single return of a plain array, read never run; anything else refused;
 *  - the allow-list: what a descriptor may hold, what is refused by name
 *    (allow_guest, session_write, app_purpose, a _logic_form companion, a
 *    sessionless action), auth.min_user_permission required, the input
 *    schema held to DescriptorValidator's words, requires_setting stored
 *    under the setting's real name;
 *  - storage: read and kept on the plugin's row with the model files;
 *  - the catalog and the lookup: listed as {plugin}/{action} while active,
 *    gone while its setting is off or the plugin is inactive;
 *  - consent: the approval page's lines, assistant_auto as its own grant, an
 *    update to auto waiting for approval and asking first until then (D5);
 *  - the call: only declared fields cross, the caller and their level, the
 *    LogicResult rebuilt with each part held to a size, a redirect off the
 *    site refused, busy and unavailable marked as never run, a timeout not;
 *  - the real runner (unfenced through the command override, and fenced
 *    when this box has the plugin's user): the logic file runs, $_POST holds
 *    the values, the session is the caller's;
 *  - one sandboxed plugin asking for a sandboxed action, its own or
 *    another's, is refused at install and by the relay;
 *  - the AI assistant's registry: {plugin}/{action}, the approved tier;
 *  - over HTTP: a key calls it, its level gate holds, the 404 for an action
 *    not there, and an Idempotency-Key released when the plugin never ran.
 *
 * Run: php tests/run.php db --filter=sandbox_actions
 *
 * @version 1.1 - reviewer2 R1-R4: the caller held to the descriptor's level and setting by call() itself, key-only
 *                actions never offered to the assistant, a changed logic file refused until a sync, a use function line
 *                and a namespace, a call refused before it started marked as never run
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/logic.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
require_once(__DIR__ . '/../../lib/http.php');
harness_boot();

$logic_file = function (string $action, string $descriptor_body, string $body = '') {
	return "<?php\nfunction {$action}_logic_descriptor(): array {\n\treturn $descriptor_body;\n}\n\nfunction {$action}_logic(\$input) {\n"
		. ($body !== '' ? $body : "\treturn LogicResult::render(array());") . "\n}\n";
};
$tidy = <<<'PHP'
<?php
// A helper the action uses: never read by the site.
function tidy_label(string $folder): string {
	return 'folder ' . $folder;
}

function tidy_logic_descriptor(): array {
	return array(
		'description'      => 'Tidy the notes waiting in a folder.',
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'requires_setting' => 'enabled',
		'auth'             => array('capability' => 'write', 'min_user_permission' => 0),
		'input'            => array(
			'folder' => array('type' => 'string', 'required' => true, 'max_length' => 80),
			'limit'  => array('type' => 'int', 'min' => 1, 'max' => 50),
		),
	);
}

function tidy_logic($input) {
	$session = SessionControl::get_instance();
	if (($input['folder'] ?? '') === 'bad') {
		$result = LogicResult::error('Not that folder.');
		$result->addValidationError('folder', 'Pick another folder.');
		return $result;
	}
	if (($input['folder'] ?? '') === 'away') {
		return LogicResult::redirect('/profile/x');
	}
	return LogicResult::render(array(
		'said'  => tidy_label((string)$input['folder']),
		'user'  => (int)$session->get_user_id(),
		'level' => (int)$session->get_permission(),
		'post'  => isset($_POST['folder']),
		'extra' => array_key_exists('extra', $input),
	));
}
PHP;
$admin_only = "array('description' => 'Staff only.', 'auth' => array('min_user_permission' => 5))";
$manifest = array(
	'settings' => array(array('name' => 'enabled', 'label' => 'Enabled', 'type' => 'boolean', 'default' => true)),
);
$pm = PluginManager::getInstance();

try {
	section('The reader: a descriptor is read, never run');
	$read = SandboxModelFile::readLogic($tidy);
	check(!empty($read['ok']) && isset($read['functions']['tidy_logic'], $read['functions']['tidy_label'], $read['descriptors']['tidy_logic_descriptor']),
		'It names the top-level functions and reads the descriptor', json_encode($read));
	$m = SandboxManifest::fromArray(array('name' => 'hts_reader', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1,
		'id' => '0123456789abcdef0123456789abcdef', 'settings' => $manifest['settings']));
	$checked = SandboxActions::check('hts_reader', $m, array('tidy_logic.php' => $read));
	check(!$checked['problems'] && ($checked['actions']['tidy']['requires_setting'] ?? '') === SandboxManifest::settingName('hts_reader', 'enabled'),
		'requires_setting is stored under the setting\'s real name', json_encode($checked));
	check(($checked['actions']['tidy']['_plugin'] ?? '') === 'hts_reader' && ($checked['actions']['tidy']['input']['limit']['max'] ?? null) === 50,
		'The rest as written, marked with its plugin');

	$refused = function (string $code) use ($m) {
		$file = preg_match('~function ([a-z_]+)_logic_descriptor~', $code, $f) ? $f[1] . '_logic.php' : 'a_logic.php';
		$c = SandboxActions::check('hts_reader', $m, array($file => SandboxModelFile::readLogic($code)));
		return implode(' | ', $c['problems']);
	};
	$cases = array(
		'Worked out, not written out'       => array("<?php function a_logic_descriptor() { \$x = array(); return \$x; } function a_logic(\$i) {}", 'one return'),
		'A constant'                        => array("<?php function a_logic_descriptor() { return array('auth' => array('min_user_permission' => FOO)); } function a_logic(\$i) {}", 'not a plain value'),
		'A joined string'                   => array("<?php function a_logic_descriptor() { return array('description' => 'a' . 'b'); } function a_logic(\$i) {}", 'not a plain value'),
		'allow_guest'                       => array($logic_file('a', "array('auth' => array('min_user_permission' => 0, 'allow_guest' => true))"), 'allow_guest is not available'),
		'session_write'                     => array($logic_file('a', "array('auth' => array('min_user_permission' => 0, 'session_write' => true))"), 'session_write is not available'),
		'app_purpose'                       => array($logic_file('a', "array('app_purpose' => 'mail', 'auth' => array('min_user_permission' => 0))"), "'app_purpose' is not available"),
		'A sessionless action (phase 2)'    => array($logic_file('a', "array('requires_session' => false, 'auth' => array('min_user_permission' => 0))"), 'not available to a sandboxed plugin yet'),
		'An unknown key'                    => array($logic_file('a', "array('mood' => 'x', 'auth' => array('min_user_permission' => 0))"), "'mood' is not a key"),
		'No min_user_permission'            => array($logic_file('a', "array('auth' => array('capability' => 'write'))"), 'min_user_permission is required'),
		'A level out of range'              => array($logic_file('a', "array('auth' => array('min_user_permission' => 11))"), 'a level from 0 to 10'),
		'A description too long'            => array($logic_file('a', "array('description' => '" . str_repeat('x', 301) . "', 'auth' => array('min_user_permission' => 0))"), 'at most 300'),
		'An input type unknown'             => array($logic_file('a', "array('auth' => array('min_user_permission' => 0), 'input' => array('n' => array('type' => 'blob')))"), 'type is one of'),
		'An input key unknown'              => array($logic_file('a', "array('auth' => array('min_user_permission' => 0), 'input' => array('n' => array('pattern' => '~x~')))"), "'pattern' is not something"),
		'A setting not its own'             => array($logic_file('a', "array('requires_setting' => 'site_name', 'auth' => array('min_user_permission' => 0))"), "one of this plugin's own settings"),
		'A form companion'                  => array($logic_file('a', "array('auth' => array('min_user_permission' => 0))") . "function a_logic_form(\$f) {}\n", 'a_logic_form() (a form the API serves) is not available'),
		'No logic function'                 => array("<?php function a_logic_descriptor() { return array('auth' => array('min_user_permission' => 0)); }", 'no a_logic($input)'),
		'A function the site declares'      => array($logic_file('process', "array('auth' => array('min_user_permission' => 0))"), "process_logic() is a function the site's own code declares"),
		'A use function line, no function' => array("<?php\nuse function a_logic;\nfunction a_logic_descriptor() { return array('auth' => array('min_user_permission' => 0)); }", 'no a_logic($input)'),
		'A namespace'                       => array("<?php\nnamespace Foo;\n" . substr($logic_file('a', "array('auth' => array('min_user_permission' => 0))"), 6), 'has no namespace'),
		'User::USER_DELETED'                => array($logic_file('a', "array('auth' => array('min_user_permission' => 0), 'input' => array('n' => array('default' => User::USER_DELETED)))"), 'no constants'),
	);
	foreach ($cases as $label => list($code, $words)) {
		$got = $refused($code);
		check(strpos($got, $words) !== false, 'Refused: ' . $label, $got);
	}
	check($refused($logic_file('a', "array('auth' => array('min_user_permission' => 'content:write'), 'ai_agent' => 'auto')")) === '',
		'A level by class and verb, and ai_agent auto, are allowed');

	section('Install: read and kept with the model files');
	$p = sandbox_fixture_plugin($manifest, array('files' => array(
		'logic/tidy_logic.php' => $tidy,
		'logic/staff_logic.php'   => $logic_file('staff', $admin_only),
		// A page's own logic, no descriptor: not an action, never read.
		'logic/helper_logic.php'  => "<?php\nfunction helper_logic(\$x) { return \$x . (\n",
	)));
	$name = $p['name'];
	$pm->install($name, false);
	$row = Plugin::get_by_plugin_name($name);
	$metadata = json_decode((string)$row->get('plg_metadata'), true);
	check(array_keys($metadata[SandboxActions::METADATA_KEY]['actions'] ?? array()) === array('staff', 'tidy'),
		'The row keeps its two actions; a logic file with no descriptor is not one', json_encode(array_keys($metadata[SandboxActions::METADATA_KEY]['actions'] ?? array())));
	check(SandboxActions::lookup($name, 'tidy') === null, 'Not callable before it is active');

	section('The approval page');
	$grants = SandboxConsent::grantsOf(SandboxPlugins::manifest($name));
	check(($grants['assistant_auto'] ?? null) === array(), 'An action the assistant asks about first is not a grant');
	$lines = SandboxActions::lines(SandboxActions::descriptors($name));
	check(count($lines) === 2 && strpos($lines[1], 'tidy (Tidy the notes waiting in a folder): any signed-in member. The assistant may run this after asking.') === 0
		&& strpos($lines[0], 'staff (Staff only): staff at level 5 and above.') === 0, 'Each action in words: who may call it, what the assistant may do', json_encode($lines));
	$approver = sandbox_approve($name);
	$pm->activate($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	ApiLogicEndpointProbe::reset();

	section('The catalog and the lookup');
	$catalog = ApiLogicEndpoint::actionCatalog();
	check(isset($catalog[$name . '/tidy']) && ($catalog[$name . '/tidy']['sandboxed'] ?? null) === $name && $catalog[$name . '/tidy']['has_form'] === false,
		'Listed as {plugin}/{action}, a sandboxed one with no form');
	check(ApiLogicEndpoint::machineKeyCanCall($catalog[$name . '/tidy']['meta']), 'A machine key can be scoped to it');
	check(SandboxActions::lookup($name, 'nope') === null && SandboxActions::lookup('hts_none', 'tidy') === null, 'An action or plugin not there looks up as nothing');
	harness_set_setting_mem(SandboxManifest::settingName($name, 'enabled'), '0');
	ApiLogicEndpointProbe::reset();
	check(!isset(ApiLogicEndpoint::actionCatalog()[$name . '/tidy']) && isset(ApiLogicEndpoint::actionCatalog()[$name . '/staff']),
		'With its setting off it is not listed; the other action still is');
	harness_set_setting_mem(SandboxManifest::settingName($name, 'enabled'), '1');
	ApiLogicEndpointProbe::reset();

	section('The call');
	$member = make_user('SbxActMember', 0);
	$host = sandbox_stand_in_runner(array('action' => array('ok' => true, 'output' => array('data' => array('n' => 1), 'error' => null, 'validation_errors' => array(), 'redirect' => null))));
	$r = SandboxActions::call($name, 'tidy', array('folder' => 'inbox', 'limit' => 3, 'extra' => 'not declared'), $member, 0);
	$sent = $host['requests']();
	$env = end($sent) ?: array();
	check(($env['export'] ?? '') === 'action' && ($env['input']['action'] ?? '') === 'tidy', 'One fenced call, export action', json_encode($env['input'] ?? null));
	check(($env['input']['values'] ?? null) === array('folder' => 'inbox', 'limit' => 3), 'Only the fields it declares cross', json_encode($env['input']['values'] ?? null));
	check(($env['input']['user']['id'] ?? null) === (int)$member->key && ($env['input']['user']['permission'] ?? null) === 0, 'The caller and the level the request acts at');
	check(!$r->error && $r->data === array('n' => 1), 'A LogicResult rebuilt from its parts');
	$log = new MultiSandboxLog(array('plugin' => $name, 'kind' => SandboxLog::KIND_ACTION));
	check(count($log) === 1, 'Logged as an action call');

	$reply = function (array $output) use ($host) {
		$host['set_replies'](array('action' => array('ok' => true, 'output' => $output)));
	};
	$reply(array('data' => array(), 'error' => str_repeat('e', 900), 'validation_errors' => array('folder' => str_repeat('v', 400), 'Bad Key' => 'x', 'f2' => array('x'))));
	$r = SandboxActions::call($name, 'tidy', array('folder' => 'x'), $member, 0);
	check(mb_strlen($r->error) === 500 && array_keys($r->validation_errors) === array('folder') && mb_strlen($r->validation_errors['folder']) === 300,
		'An error held to 500 characters, validation errors to field names and 300 characters', json_encode(array(mb_strlen((string)$r->error), $r->validation_errors)));
	$reply(array('data' => array(), 'redirect' => '/profile/notes'));
	check(SandboxActions::call($name, 'tidy', array('folder' => 'x'), $member, 0)->redirect === '/profile/notes', 'A redirect to a path on the site');
	foreach (array('https://elsewhere.example/', '//elsewhere.example/x', '/\\elsewhere', "/a\nb") as $off) {
		$reply(array('data' => array(), 'redirect' => $off));
		$r = SandboxActions::call($name, 'tidy', array('folder' => 'x'), $member, 0);
		check($r->error !== null && $r->redirect === null, 'A redirect off the site is refused: ' . json_encode($off));
	}
	$reply(array('data' => array('big' => str_repeat('x', SandboxActions::MAX_DATA_BYTES + 10))));
	check(strpos((string)SandboxActions::call($name, 'tidy', array('folder' => 'x'), $member, 0)->error, 'larger than 1 MB') !== false, 'Data over 1 MB is refused');
	$host['set_replies'](array('action' => array('ok' => true, 'output' => 'not a result')));
	check(strpos((string)SandboxActions::call($name, 'tidy', array('folder' => 'x'), $member, 0)->error, 'LogicResult') !== false, 'An answer that is not a result\'s parts is an error');
	$r = SandboxActions::call($name, 'tidy', array('folder' => str_repeat('x', SandboxActions::MAX_INPUT_BYTES)), $member, 0);
	check(($r->data['http_status'] ?? null) === 413, 'Input over 1 MB is refused before the call');
	check((SandboxActions::call($name, 'tidy', array('folder' => 'x'), null)->data['http_status'] ?? null) === 401, 'No caller, no call');
	$before = count($host['requests']());
	$r = SandboxActions::call($name, 'staff', array(), $member, 0);
	check(($r->data['http_status'] ?? null) === 403 && count($host['requests']()) === $before,
		'call() itself holds the caller to the descriptor\'s level: a member on a level-5 action never reaches the plugin (reviewer2 R1)');
	harness_set_setting_mem(SandboxManifest::settingName($name, 'enabled'), '0');
	$r = SandboxActions::call($name, 'tidy', array('folder' => 'x'), $member, 0);
	harness_set_setting_mem(SandboxManifest::settingName($name, 'enabled'), '1');
	check(($r->data['http_status'] ?? null) === 403 && count($host['requests']()) === $before, 'and to its setting');
	check(SandboxActions::keyOnly(array('auth' => array('requires_scoped_key' => true))) && !SandboxActions::keyOnly(array('auth' => array())),
		'An action for a key alone is told apart');

	$host['set_replies'](array('action' => array('timed_out' => true)));
	$r = SandboxActions::call($name, 'tidy', array('folder' => 'x'), $member, 0);
	check(($r->data['http_status'] ?? null) === 504 && !SandboxActions::neverRan($r), 'A timeout is 504, and may have run');
	$host['set_replies'](array('action' => array('unavailable' => 'No fence here.')));
	$r = SandboxActions::call($name, 'tidy', array('folder' => 'x'), $member, 0);
	check(($r->data['http_status'] ?? null) === 503 && SandboxActions::neverRan($r) && $r->error === 'No fence here.', 'Unavailable is 503, and never ran');
	$host['set_replies'](array('action' => array('ok' => false, 'error' => 'RuntimeException: boom')));
	$r = SandboxActions::call($name, 'tidy', array('folder' => 'x'), $member, 0);
	check(($r->data['http_status'] ?? null) === 500 && !SandboxActions::neverRan($r), 'A failure is 500');
	// Both UI slots held: busy, and never ran.
	$dir = rtrim(PathHelper::getSiteRoot(), '/') . '/cache/sandbox';
	$held = array();
	for ($i = 0; $i < SandboxHost::UI_SLOTS; $i++) {
		$fh = fopen($dir . '/ui_slot_' . $i . '.lock', 'c');
		flock($fh, LOCK_EX);
		$held[] = $fh;
	}
	$r = SandboxActions::call($name, 'tidy', array('folder' => 'x'), $member, 0);
	foreach ($held as $fh) {
		flock($fh, LOCK_UN);
		fclose($fh);
	}
	check(($r->data['http_status'] ?? null) === 503 && SandboxActions::neverRan($r) && ($r->data['reason'] ?? '') === 'plugin_busy',
		'With both slots taken it is busy: 503, never ran', json_encode($r->data));

	section('A file changed since the site read it (reviewer2 R2, R4)');
	$tidy_path = $p['dir'] . '/src/logic/tidy_logic.php';
	file_put_contents($tidy_path, $tidy . "\n// changed\n");
	$host['set_replies'](array('action' => array('ok' => true, 'output' => array('data' => array()))));
	$before = count($host['requests']());
	$r = SandboxActions::call($name, 'tidy', array('folder' => 'x'), $member, 0);
	check(($r->data['http_status'] ?? null) === 503 && SandboxActions::neverRan($r) && strpos((string)$r->error, 'action file changed') !== false
		&& count($host['requests']()) === $before, 'A changed logic file is refused until a sync, and never ran', (string)$r->error);
	file_put_contents($tidy_path, $tidy);
	check(!SandboxActions::call($name, 'tidy', array('folder' => 'x'), $member, 0)->error, 'Put back as read, it runs again');
	$stray = $p['dir'] . '/src/data/stray_class.php';
	@mkdir(dirname($stray), 0755, true);
	file_put_contents($stray, "<?php\n");
	$r = SandboxActions::call($name, 'tidy', array('folder' => 'x'), $member, 0);
	unlink($stray);
	@rmdir(dirname($stray));
	check(($r->data['http_status'] ?? null) === 503 && SandboxActions::neverRan($r),
		'A call refused before it started (its model files changed) never ran, so its key is released', json_encode(array($r->error, $r->data)));

	section('The real runner, unfenced');
	$host['stop']();
	SandboxRunner::$command_override = function (array $envelope) {
		$cmd = SandboxRunner::command($envelope);
		return array_slice($cmd, array_search('--', $cmd, true) + 1);
	};
	harness_defer(function () { SandboxRunner::$command_override = null; });
	$run_real = function (string $plugin, string $label) use ($member) {
		$r = SandboxActions::call($plugin, 'tidy', array('folder' => 'inbox', 'extra' => 'x'), $member, 0);
		check(!$r->error && ($r->data['said'] ?? '') === 'folder inbox' && ($r->data['user'] ?? null) === (int)$member->key
			&& ($r->data['level'] ?? null) === 0 && ($r->data['post'] ?? null) === true && ($r->data['extra'] ?? null) === false,
			$label . ': the logic file runs with its helper, as the caller, $_POST holding the declared values only', json_encode(array($r->error, $r->data)));
		$r = SandboxActions::call($plugin, 'tidy', array('folder' => 'bad'), $member, 0);
		check($r->error === 'Not that folder.' && ($r->validation_errors['folder'] ?? '') === 'Pick another folder.', $label . ': an error and its validation errors come back', json_encode(array($r->error, $r->validation_errors)));
		$r = SandboxActions::call($plugin, 'tidy', array('folder' => 'away'), $member, 0);
		check($r->redirect === '/profile/x', $label . ': a redirect comes back');
	};
	$run_real($name, 'Unfenced');
	SandboxRunner::$command_override = null;

	section('One sandboxed plugin may not ask for a sandboxed action');
	foreach (array($name . '/tidy' => 'another\'s', 'hts_asker/own' => 'its own') as $action => $whose) {
		$asker = SandboxManifest::fromArray(array('name' => 'hts_asker', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1,
			'id' => 'fedcba9876543210fedcba9876543210', 'permissions' => array('actions' => array($action))));
		check(count(preg_grep('~a sandboxed plugin\'s own action~', SandboxCompatibility::problems($asker))) === 1, 'Refused at install: ' . $whose);
	}
	$relay = new SandboxRelay($name, array());
	(new ReflectionProperty('SandboxRelay', 'scope'))->setValue($relay, array($name . '/tidy'));
	$answer = $relay->handle(array('ask' => 'action', 'name' => $name . '/tidy', 'input' => array()));
	check(is_array($answer) && empty($answer['ok']) && strpos((string)($answer['error'] ?? ''), 'cannot call a sandboxed plugin') !== false,
		'And by the relay, even when a scope names it', json_encode($answer));

	section('The AI assistant');
	if (class_exists('ActionRegistry')) {
		$all = (new ReflectionClass('ActionRegistry'));
		$all->getProperty('actions')->setValue(null, null);
		$info = ActionRegistry::get($name . '/tidy');
		check($info !== null && ($info['sandboxed'] ?? null) === array($name, 'tidy') && ActionRegistry::agentTier($info['descriptor']) === 'confirm',
			'Its registry keys it {plugin}/{action}, at the approved tier', json_encode($info['descriptor']['ai_agent'] ?? null));
		check(ActionRegistry::get('tidy') === null || empty(ActionRegistry::get('tidy')['sandboxed']), 'It never stands in for a bare name');
		check(ActionRegistry::agentTier(ActionRegistry::get($name . '/staff')['descriptor'] ?? array()) === null, 'An action with no ai_agent is not offered');
		$active = array_keys(PluginHelper::getActivePlugins());
		$inactive_offered = array();
		foreach (ActionRegistry::all() as $key => $info) {
			if (strpos($key, '/') === false && empty($info['sandboxed'])) {
				$file = (new ReflectionFunction($info['logic_function']))->getFileName();
				if (preg_match('~/plugins/([^/]+)/logic/~', (string)$file, $mm) && !in_array($mm[1], $active, true)) {
					$inactive_offered[] = $key;
				}
			}
		}
		check(!$inactive_offered, 'No inactive plugin\'s action is offered (reviewer2 B1)', implode(', ', $inactive_offered));
	} else {
		harness_skip('The AI assistant', 'joinery_ai is not active here');
	}

	section('D5: an update to auto waits for approval, asking first until then');
	file_put_contents($p['dir'] . '/src/logic/tidy_logic.php', str_replace("'ai_agent'         => 'confirm'", "'ai_agent'         => 'auto'", $tidy));
	// What Sync with Filesystem does for a sandboxed plugin (PluginManager::sync()).
	SandboxModels::refresh($name);
	SandboxModels::storePending($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	check((SandboxActions::descriptors($name)['tidy']['ai_agent'] ?? null) === 'auto', 'A sync reads the new descriptor');
	check(SandboxConsent::needsApproval($name) && (SandboxConsent::pending($name)['assistant_auto'] ?? null) === array('tidy'), 'It waits on an approval, as assistant_auto');
	check(SandboxActions::assistantTier($name, 'tidy', SandboxActions::descriptors($name)['tidy']) === 'confirm', 'Until then the assistant asks first');
	$new = SandboxConsent::describe(SandboxConsent::pending($name));
	check(isset($new["Let the site's AI assistant run these of its actions without asking first"]), 'The approval page says so');
	sandbox_approve($name, $approver);
	SandboxModels::reset();
	check(SandboxActions::assistantTier($name, 'tidy', SandboxActions::descriptors($name)['tidy']) === 'auto', 'Approved, it runs without asking');

	section('Over HTTP');
	$web_member = make_user('SbxActWeb', 0);
	$key = make_machine_key((int)$web_member->key, 'HarnessTest sbx action key', 3);
	$headers = array('public-key: ' . $key['api_key']->get('apk_public_key'), 'secret-key: ' . $key['secret_key']);
	$res = harness_request('POST', '/api/v1/action/' . $name . '/nope', array('headers' => $headers, 'body' => array()));
	if ($res['status'] === 0) {
		harness_skip('Over HTTP', 'this site does not answer');
	} else {
		check($res['status'] === 404 && strpos((string)($res['json']['error'] ?? ''), 'Unknown action') !== false, 'An action it does not have is the same 404 as any', $res['status'] . ' ' . $res['body']);
		$res = harness_request('POST', '/api/v1/action/hts_nobody/tidy', array('headers' => $headers, 'body' => array()));
		check($res['status'] === 404, 'So is a plugin that is not here');
		$res = harness_request('POST', '/api/v1/action/' . $name . '/staff', array('headers' => $headers, 'body' => array()));
		check($res['status'] === 403, 'Its level gate holds (a member\'s key on a level-5 action)', $res['status'] . ' ' . $res['body']);
		$res = harness_request('POST', '/api/v1/action/' . $name . '/tidy', array('headers' => $headers, 'body' => array('limit' => 3)));
		check($res['status'] === 422, 'Its input schema holds before anything runs', $res['status'] . ' ' . $res['body']);
		$res = harness_request('GET', '/api/v1/actions', array('headers' => $headers));
		check(isset($res['json']['data'][$name . '/tidy']), 'GET /api/v1/actions lists it');

		// The web tidy runs the real runner: this fixture has no fence user,
		// so the call is unavailable, which means its code never ran.
		$idem = 'harness-' . bin2hex(random_bytes(6));
		$res = harness_request('POST', '/api/v1/action/' . $name . '/tidy', array('headers' => array_merge($headers, array('Idempotency-Key: ' . $idem)), 'body' => array('folder' => 'inbox')));
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare('SELECT COUNT(*) FROM aik_api_idempotency_keys WHERE aik_key_hash = ?');
		$q->execute(array(hash('sha256', $idem)));
		if ($res['status'] === 200) {
			harness_skip('The Idempotency-Key release', 'the web tidy ran the plugin (this box fences it), so the call did run');
		} else {
			check($res['status'] === 503 && in_array($res['json']['data']['reason'] ?? '', array('plugin_unavailable', 'plugin_busy'), true),
				'A call the plugin never ran is 503', $res['status'] . ' ' . $res['body']);
			check((int)$q->fetchColumn() === 0, 'and its Idempotency-Key is released, so a retry runs it');
		}
	}

	section('The real runner, fenced');
	$fence = 'sbxactions';
	$why = SandboxRunner::unavailable('hts_none');
	if ($why === SandboxRunner::NO_FENCE || $why === SandboxRunner::OLD_LAUNCHER) {
		harness_skip('The fenced action', 'this box has no launcher 2.1 with the allowlist');
	} else {
		$ready = function_exists('posix_getpwnam') && posix_getpwnam(SandboxRunner::userFor($fence)) !== false;
		sandbox_fixture_plugin($manifest, array('name' => $fence, 'files' => array('logic/tidy_logic.php' => $tidy), 'keep_folder' => !$ready));
		if (!$ready) {
			harness_skip('The fenced action', 'jpx-' . $fence . ' is not made yet; the root step makes it within a minute of this folder, which is left in place. Run again then.');
		} else {
			$pm->install($fence, false);
			sandbox_approve($fence);
			$pm->activate($fence);
			SandboxPlugins::reset();
			SandboxModels::reset();
			$run_real($fence, 'Fenced');
		}
	}
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();

/** Forgets ApiLogicEndpoint's per-request catalog. */
final class ApiLogicEndpointProbe {
	public static function reset(): void {
		(new ReflectionProperty('ApiLogicEndpoint', 'catalog_cache'))->setValue(null, null);
	}
}

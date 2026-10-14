<?php
/** @joinery-test
 * name: sandbox_pages
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * Manifest-rendered UI (plugin_tiers.md WP4): a sandboxed plugin's admin
 * and profile pages, its menus, its settings, and door 4 (a form the plugin
 * answers through the host), against a stand-in host.
 *
 * What is pinned here:
 *  - activation adds the plugin's admin menu (one entry per admin page) and a
 *    member menu entry per profile page, and seeds its declared settings;
 *  - its settings are declarations like any (named sbx_{plugin}__{name}),
 *    pass the declaration schema, save through SettingsWriter on its page,
 *    are validated as declared, and reach the module by their manifest names;
 *  - a table section shows the plugin's own rows;
 *  - a form section is sent to the host with exactly the declared fields, the
 *    user who asked, the plugin's limits, settings and declared hosts, and
 *    what the module answers is shown; a module error is shown as one;
 *  - with no host, a slow host, or both UI slots taken, the page says the
 *    plugin did not respond, and the call is logged;
 *  - a profile form is answered for the member who submitted it.
 *
 * Run: php tests/run.php db --filter=sandbox_pages
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/logic.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
require_once(__DIR__ . '/../../lib/http.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();

$sign_in = function (User $user) {
	// A session started later (FormWriter starts one) would replace what is
	// set here, so it is started first.
	if (session_status() === PHP_SESSION_NONE) {
		@session_start();
	}
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = (int)$user->get('usr_permission');
	$_SESSION['saved_messages'] = array();
};
$last_message = function () {
	$messages = $_SESSION['saved_messages'] ?? array();
	$last = end($messages);
	return $last ? array('text' => (string)$last->message, 'type' => $last->display_type) : array('text' => '', 'type' => null);
};
// Settings are memoized per process; forget the plugin's after a write.
$forget_settings = function (string $plugin) {
	foreach (SandboxPlugins::manifest($plugin)->settings() as $setting) {
		Globalvars::get_instance()->forget_setting(SandboxManifest::settingName($plugin, $setting['name']));
	}
};
$logs = function (string $plugin) use ($db) {
	$q = $db->prepare('SELECT sbl_kind, sbl_name, sbl_status, sbl_message, sbl_calls, sbl_usr_user_id FROM sbl_sandbox_logs WHERE sbl_plugin = ? ORDER BY sbl_sandbox_log_id');
	$q->execute(array($plugin));
	return $q->fetchAll(PDO::FETCH_ASSOC);
};

try {
	section('A plugin with pages, settings and a model');
	$p = sandbox_fixture_plugin(array(
		'outbound_hosts' => array('hooks.example.com'),
		'limits' => array('memory_mb' => 8, 'timeout_ms' => 400),
		'settings' => array(
			array('name' => 'channel', 'label' => 'Channel', 'type' => 'text', 'default' => 'general', 'required' => true),
			array('name' => 'webhook_url', 'label' => 'Webhook URL', 'type' => 'url', 'secret' => true),
			array('name' => 'enabled', 'label' => 'Enabled', 'type' => 'boolean', 'default' => true),
		),
		'models' => array(array('name' => 'Item', 'fields' => array(
			array('name' => 'title', 'type' => 'string', 'label' => '<img src=x onerror=alert(1)> Title'),
			array('name' => 'qty', 'type' => 'integer'),
		))),
		'admin_pages' => array(
			array('slug' => 'main', 'title' => 'Main', 'sections' => array(
				array('type' => 'text', 'text' => 'Hello admins.'),
				array('type' => 'table', 'model' => 'Item', 'columns' => array('title', 'qty', 'create_time'), 'order' => 'qty'),
				array('type' => 'form', 'handler' => 'greet', 'submit_label' => 'Greet', 'fields' => array(
					array('name' => 'who', 'label' => 'Who', 'type' => 'text', 'required' => true),
					array('name' => 'loud', 'label' => 'Loud', 'type' => 'boolean'),
					array('name' => 'mood', 'label' => 'Mood', 'type' => 'select', 'options' => array('happy' => 'Happy', 'calm' => 'Calm')),
				)),
				array('type' => 'settings'),
			)),
			array('slug' => 'second', 'title' => 'Second', 'sections' => array(array('type' => 'text', 'text' => 'Two.'))),
		),
		'profile_pages' => array(array('slug' => 'hello', 'title' => 'Hello', 'sections' => array(
			array('type' => 'text', 'text' => 'Hi member.'),
			array('type' => 'form', 'handler' => 'hello', 'fields' => array(array('name' => 'note', 'label' => 'Note', 'type' => 'textarea'))),
		))),
		'menu' => array('title' => 'HarnessTest menu'),
	));
	$name = $p['name'];
	$pm = PluginManager::getInstance();
	$pm->install($name, false);
	sandbox_approve($name);
	$pm->activate($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	SettingsDeclarations::reset();
	check(SandboxPlugins::isActive($name), 'The plugin is active');

	section('Menus');
	$slugs = $db->prepare('SELECT amu_slug, amu_defaultpage, amu_location FROM amu_admin_menus WHERE amu_slug LIKE ? ORDER BY amu_slug');
	$slugs->execute(array('sbx-' . $name . '%'));
	$menu = $slugs->fetchAll(PDO::FETCH_ASSOC);
	$menu_slugs = array_column($menu, 'amu_slug');
	check(in_array('sbx-' . $name, $menu_slugs, true) && in_array('sbx-' . $name . '-main', $menu_slugs, true)
		&& in_array('sbx-' . $name . '-second', $menu_slugs, true), 'An admin entry holding a link to each admin page', implode(', ', $menu_slugs));
	check(in_array(SandboxPluginManager::profileMenuSlug($name, 'hello'), $menu_slugs, true), 'and a member menu entry for the profile page, under its own slug');

	section('Settings');
	$channel = SandboxManifest::settingName($name, 'channel');
	$secret = SandboxManifest::settingName($name, 'webhook_url');
	check(SettingsDeclarations::get($channel) !== null && SettingsDeclarations::isSecret($secret), 'Its settings are declarations, the secret one secret');
	check(isset(SettingsDeclarations::active()[$channel]), 'and active');
	$own_errors = array_values(array_filter(SettingsDeclarations::schemaErrors(), function ($e) use ($name) { return strpos($e, $name) !== false; }));
	check($own_errors === array(), 'They pass the declaration schema', implode(' | ', $own_errors));
	$row = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
	$row->execute(array($channel));
	check($row->fetchColumn() === 'general', 'Defaults are seeded on activation');
	check(SandboxSettings::values($name) === array('channel' => 'general', 'webhook_url' => '', 'enabled' => '1'),
		'The module is handed them by their manifest names', json_encode(SandboxSettings::values($name)));

	$admin = make_user('SbxAdmin', 8);
	$sign_in($admin);
	$page_logic = 'adm/logic/admin_sandbox_page_logic.php';
	$result = harness_call_logic($page_logic, 'admin_sandbox_page_logic', array(
		'plugin' => $name, 'page' => 'main', 'action' => 'settings',
		$channel => 'random', $secret => 'https://hooks.example.com/abc', 'enabled' => '',
	), 'POST');
	check(!$result->error, 'The settings section saves', (string)$result->error);
	$forget_settings($name);
	$values = SandboxSettings::values($name);
	check($values['channel'] === 'random' && $values['webhook_url'] === 'https://hooks.example.com/abc',
		'and the module sees the new values', json_encode($values));
	$_SESSION['saved_messages'] = array();
	harness_call_logic($page_logic, 'admin_sandbox_page_logic', array(
		'plugin' => $name, 'page' => 'main', 'action' => 'settings', $channel => 'x', $secret => 'not a url',
	), 'POST');
	$forget_settings($name);
	check(SandboxSettings::values($name)['webhook_url'] === 'https://hooks.example.com/abc', 'A value failing its declared rule is refused');

	section('The admin page');
	$class = SandboxManifest::classFor($name, 'Item');
	foreach (array(array('B', 9), array('A', 2)) as list($title, $qty)) {
		$item = new $class(NULL);
		$item->set(SandboxManifest::prefixFor($name, 'Item') . '_title', $title);
		$item->set(SandboxManifest::prefixFor($name, 'Item') . '_qty', $qty);
		$item->save();
	}
	$result = harness_call_logic($page_logic, 'admin_sandbox_page_logic', array('plugin' => $name, 'page' => 'main'), 'GET');
	check(!$result->error, 'It renders', (string)$result->error);
	$vars = $result->data ?? array();
	$table = null;
	foreach ($vars['sections'] ?? array() as $s) {
		if ($s['section']['type'] === 'table') {
			$table = $s;
		}
	}
	$titles = array();
	if ($table) {
		foreach ($table['rows'] as $r) {
			$titles[] = $r->get(SandboxManifest::prefixFor($name, 'Item') . '_title');
		}
	}
	check($titles === array('A', 'B'), 'The table shows the plugin\'s rows in its declared order', json_encode($titles));
	$result = harness_call_logic($page_logic, 'admin_sandbox_page_logic', array('plugin' => $name, 'page' => 'nope'), 'GET');
	check((bool)$result->error, 'A page it does not declare is refused');

	section('A form with no host');
	SandboxHost::$socket_override = sys_get_temp_dir() . '/sbx_absent_' . getmypid() . '.sock';
	$_SESSION['saved_messages'] = array();
	harness_call_logic($page_logic, 'admin_sandbox_page_logic', array(
		'plugin' => $name, 'page' => 'main', 'action' => 'form', 'section' => 2, 'sbx_who' => 'Ann',
	), 'POST');
	$m = $last_message();
	check($m['type'] === DisplayMessage::MESSAGE_WARNING && stripos($m['text'], 'did not respond') !== false,
		'The page says the plugin did not respond', $m['text']);
	$l = $logs($name);
	check(count($l) === 1 && $l[0]['sbl_status'] === 'unavailable' && $l[0]['sbl_kind'] === 'ui' && $l[0]['sbl_name'] === 'greet',
		'and the call is logged as unavailable', json_encode($l));

	section('A form the plugin answers');
	$host = fake_sandbox_host_start(array('on_ui' => array('ok' => true, 'output' => array('message' => 'Hello, Ann!'),
		'calls' => array(array('door' => 'log', 'target' => 'info', 'status' => 'ok', 'detail' => 'greeted')), 'duration_ms' => 3)));
	$_SESSION['saved_messages'] = array();
	harness_call_logic($page_logic, 'admin_sandbox_page_logic', array(
		'plugin' => $name, 'page' => 'main', 'action' => 'form', 'section' => 2,
		'sbx_who' => '  Ann ', 'sbx_loud' => '1', 'sbx_mood' => 'calm', 'sbx_extra' => 'not declared',
	), 'POST');
	$m = $last_message();
	check($m['type'] === DisplayMessage::MESSAGE_ANNOUNCEMENT && $m['text'] === 'Hello, Ann!', 'The module\'s answer is shown', $m['text']);
	$sent = $host['requests']();
	$req = end($sent) ?: array();
	check(($req['plugin'] ?? '') === $name && ($req['export'] ?? '') === 'on_ui', 'The host is asked to run on_ui for this plugin');
	check(($req['input']['handler'] ?? '') === 'greet', 'with the handler');
	check(($req['input']['values'] ?? null) === array('who' => 'Ann', 'loud' => true, 'mood' => 'calm'),
		'only the declared fields, trimmed and typed', json_encode($req['input']['values'] ?? null));
	check(($req['input']['user']['id'] ?? 0) === (int)$admin->key, 'and who asked');
	check(($req['limits'] ?? null) === array('memory_mb' => 8, 'timeout_ms' => 400), 'its limits');
	check(($req['settings']['channel'] ?? '') === 'random' && ($req['settings']['webhook_url'] ?? '') === 'https://hooks.example.com/abc',
		'its settings, the secret opened');
	check(($req['outbound_hosts'] ?? null) === array('hooks.example.com'), 'its declared hosts');
	check(substr((string)($req['module'] ?? ''), -12) === '/module.wasm' && is_file($req['module']), 'and its module file');
	$l = $logs($name);
	$last = end($l);
	check($last['sbl_status'] === 'ok' && strpos((string)$last['sbl_calls'], 'greeted') !== false && (int)$last['sbl_usr_user_id'] === (int)$admin->key,
		'The call is logged with what the module did', json_encode($last));

	$host['set_replies'](array('on_ui' => array('ok' => true, 'output' => array('status' => 'error', 'errors' => array('who' => 'Too short')))));
	$_SESSION['saved_messages'] = array();
	harness_call_logic($page_logic, 'admin_sandbox_page_logic', array(
		'plugin' => $name, 'page' => 'main', 'action' => 'form', 'section' => 2, 'sbx_who' => 'A',
	), 'POST');
	$m = $last_message();
	check($m['type'] === DisplayMessage::MESSAGE_WARNING && strpos($m['text'], 'who: Too short') !== false, 'A module error is shown as one', $m['text']);

	// The admin renderer escapes a message, so an admin's carries plain text.
	$host['set_replies'](array('on_ui' => array('ok' => true, 'output' => array('message' => "Tom's <b>list</b>"))));
	$_SESSION['saved_messages'] = array();
	harness_call_logic($page_logic, 'admin_sandbox_page_logic', array(
		'plugin' => $name, 'page' => 'main', 'action' => 'form', 'section' => 2, 'sbx_who' => 'Tom',
	), 'POST');
	check($last_message()['text'] === "Tom's <b>list</b>", 'An admin\'s message is the module\'s plain text, escaped once by its renderer', $last_message()['text']);

	$host['set_replies'](array('on_ui' => array('ok' => true, 'output' => array('message' => 'late'), 'sleep_ms' => 2800)));
	$_SESSION['saved_messages'] = array();
	$started = microtime(true);
	harness_call_logic($page_logic, 'admin_sandbox_page_logic', array(
		'plugin' => $name, 'page' => 'main', 'action' => 'form', 'section' => 2, 'sbx_who' => 'Ann',
	), 'POST');
	$waited = microtime(true) - $started;
	$m = $last_message();
	check(stripos($m['text'], 'did not respond in time') !== false, 'A plugin slower than its limit is cut off', $m['text']);
	check($waited < 2.8, 'within its limit plus the margin, not however long it takes', sprintf('%.2fs', $waited));

	section('Two UI calls at once, and no more');
	$dir = rtrim(PathHelper::getSiteRoot(), '/') . '/cache/sandbox';
	$held = array();
	for ($i = 0; $i < SandboxHost::UI_SLOTS; $i++) {
		$fh = fopen($dir . '/ui_slot_' . $i . '.lock', 'c');
		flock($fh, LOCK_EX);
		$held[] = $fh;
	}
	$result = SandboxHost::ui($name, 'greet', array(), $admin);
	check($result['status'] === SandboxLog::STATUS_BUSY, 'With both slots in use a third call is refused at once');
	foreach ($held as $fh) {
		flock($fh, LOCK_UN);
		fclose($fh);
	}
	$mine = fopen($dir . '/ui_user_' . (int)$admin->key . '.lock', 'c');
	flock($mine, LOCK_EX);
	$result = SandboxHost::ui($name, 'greet', array(), $admin);
	check($result['status'] === SandboxLog::STATUS_BUSY && strpos($result['message'], 'your last request') !== false,
		'One person has one call at a time', $result['message']);
	flock($mine, LOCK_UN);
	fclose($mine);

	section('A profile page');
	$host['set_replies'](array('on_ui' => array('ok' => true, 'output' => array('message' => 'Noted.'))));
	$member = make_user('SbxMember', 0);
	$sign_in($member);
	$member_logic = 'logic/sandbox_page_logic.php';
	$result = harness_call_logic($member_logic, 'sandbox_page_logic', array('plugin' => $name, 'page' => 'hello'), 'GET');
	check(!$result->error && ($result->data['page']['slug'] ?? '') === 'hello', 'It renders for a member', (string)$result->error);
	$_SESSION['saved_messages'] = array();
	$result = harness_call_logic($member_logic, 'sandbox_page_logic', array('plugin' => $name, 'page' => 'hello', 'action' => 'form', 'section' => 1, 'sbx_note' => 'hi'), 'POST');
	$sent = $host['requests']();
	$req = end($sent) ?: array();
	check(($req['input']['handler'] ?? '') === 'hello' && ($req['input']['user']['id'] ?? 0) === (int)$member->key
		&& ($req['input']['values'] ?? null) === array('note' => 'hi'),
		'Its form is answered for the member who sent it', json_encode($req['input'] ?? null));
	check($last_message()['text'] === 'Noted.', 'and the answer is shown');
	check(($result->data['result']['ok'] ?? null) === true && ($result->data['result']['lines'] ?? null) === array('Noted.'),
		'and returned for an API caller', json_encode($result->data));
	harness_call_logic($member_logic, 'sandbox_page_logic', array('plugin' => $name, 'page' => 'hello', 'section' => 1, 'values' => array('note' => 'from the app')), 'POST');
	$sent = $host['requests']();
	$req = end($sent) ?: array();
	check(($req['input']['values'] ?? null) === array('note' => 'from the app'), 'An app sends the fields as values', json_encode($req['input']['values'] ?? null));
	$catalog = ApiLogicEndpoint::actionCatalog();
	check(isset($catalog['sandbox_page']), 'It is the API action sandbox_page');
	check(isset($catalog['sandbox_page']) && !ApiLogicEndpoint::machineKeyCanCall($catalog['sandbox_page']['meta']),
		'for a person\'s credential only, never a machine key');
	$m = SandboxManifest::fromArray(array('name' => 'askspage', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1,
		'permissions' => array('actions' => array('sandbox_page'))));
	check(count(preg_grep('~sandbox_page~', SandboxCompatibility::problems($m))) === 1,
		'so a sandboxed plugin cannot ask for it');
	$machine = make_machine_key((int)$member->key, 'HarnessTest sbx machine', 4);
	$res = harness_request('POST', '/api/v1/action/sandbox_page', array(
		'headers' => array('public-key: ' . $machine['api_key']->get('apk_public_key'), 'secret-key: ' . $machine['secret_key']),
		'body'    => array('plugin' => $name, 'page' => 'hello', 'section' => 1, 'values' => array('note' => 'by machine')),
	));
	check($res['status'] === 403, 'A machine key calling it is refused', 'status ' . $res['status']);
	$host['set_replies'](array('on_ui' => array('ok' => true, 'output' => array('message' => "Tom's <b>list</b>"))));
	$_SESSION['saved_messages'] = array();
	harness_call_logic($member_logic, 'sandbox_page_logic', array('plugin' => $name, 'page' => 'hello', 'section' => 1, 'sbx_note' => 'x'), 'POST');
	check($last_message()['text'] === 'Tom&#039;s &lt;b&gt;list&lt;/b&gt;', 'A member\'s message is escaped for the renderer that prints HTML', $last_message()['text']);

	section('The plugin author\'s words, drawn on the admin page');
	$sign_in($admin);
	$web_admin = make_user('SbxWebAdmin', 10);
	$jar = harness_jar_new('sbxpg');
	check(harness_web_login($jar, $web_admin->get('usr_email'), 'TestPassword_SbxWebAdmin') !== null, 'A superadmin signs in');
	$page_html = harness_request('GET', SandboxPluginManager::adminPageUrl($name, 'main'), array('jar' => $jar, 'accept' => null));
	check($page_html['status'] === 200 && strpos($page_html['body'], 'Hello admins.') !== false, 'The admin page renders', (string)$page_html['status']);
	check(strpos($page_html['body'], '<img src=x onerror') === false && strpos($page_html['body'], '&lt;img src=x onerror=alert(1)&gt; Title') !== false,
		'A column label is printed as text, never as markup');
	check(strpos($page_html['body'], 'data-label="&lt;img src=x onerror=alert(1)&gt; Title"') !== false && strpos($page_html['body'], '&amp;lt;img') === false,
		'and its mobile label is escaped once');

	section('An inactive plugin has no pages');
	$sign_in($admin);
	$pm->deactivate($name);
	SandboxPlugins::reset();
	$result = harness_call_logic($page_logic, 'admin_sandbox_page_logic', array('plugin' => $name, 'page' => 'main'), 'GET');
	check((bool)$result->error, 'Its admin page is refused');
	$slugs->execute(array('sbx-' . $name . '%'));
	check($slugs->fetchAll() === array(), 'and its menu entries are gone');

} catch (\Throwable $e) {
	check(false, 'suite ran to completion', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

unset($_SESSION['usr_user_id'], $_SESSION['loggedin'], $_SESSION['permission']);
harness_finish();

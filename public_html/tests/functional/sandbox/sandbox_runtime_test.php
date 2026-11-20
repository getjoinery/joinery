<?php
/** @joinery-test
 * name: sandbox_runtime
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 180
 */
/**
 * The fenced runtime (specs/sandboxed_php_plugins.md WP3, design 4): what a
 * sandboxed PHP plugin's code finds under the names native code uses.
 *
 * What is pinned here, run unfenced through the same PHP command line and,
 * where this box has the plugin's user, in the real fence:
 *  - Globalvars answers the plugin's own settings, by their manifest names
 *    and their full names, and the site's three public facts; any other
 *    setting (a secret, the database's, a captcha's private key) is unset;
 *  - SessionControl says who is viewing (id, level, time zone) and answers a
 *    level check;
 *  - DbConnector throws, saying to use a model;
 *  - PathHelper gives paths in the tree; a config-file setting is unset and,
 *    fenced, the config file cannot be read;
 *  - core classes resolve from the tree, the plugin's own from src/includes
 *    and src/data, and a plugin's class cannot shadow a core one;
 *  - FormWriterV2HTML5 draws a form in the fence (V5), and a database
 *    check in its validation fails with the stand-in's message;
 *  - a plugin setting named like a core one answers only by its full name;
 *    a namespaced class of the plugin's resolves; in the fence the grants'
 *    edges hold: no non-.php file of includes/, no other plugin's
 *    plugin.json, no utils/ script but the runner's.
 *
 * @version 1.1 - reviewer2 F1, F2, F5
 *
 * Run: php tests/run.php db --filter=sandbox_runtime
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
harness_boot();

$plugin_php = <<<'PHP'
<?php
function sbx_catch(callable $fn) {
	try {
		return array('ok' => $fn());
	} catch (Throwable $e) {
		return array('threw' => get_class($e), 'message' => $e->getMessage());
	}
}

function on_ui(array $input) {
	$g = Globalvars::get_instance();
	$s = SessionControl::get_instance();
	$out = array();
	$out['own'] = $g->get_setting('greeting');
	$out['own_debug_bare'] = $g->get_setting('debug');
	$out['own_debug_full'] = $g->get_setting('sbx_' . SandboxPipe::$envelope['plugin'] . '__debug');
	$out['namespaced'] = class_exists('Acme\\Deep\\Ns') ? \Acme\Deep\Ns::where() : null;
	$out['walls'] = array();
	foreach (array('includes/core_tier_features.json', 'plugins/store/plugin.json', 'utils/upgrade.php', '../config/Globalvars_site.php') as $rel) {
		$out['walls'][$rel] = @file_get_contents(PathHelper::getIncludePath($rel)) !== false;
	}
	$out['own_full'] = $g->get_setting('sbx_' . SandboxPipe::$envelope['plugin'] . '__greeting');
	$out['site_name'] = $g->get_setting('site_name');
	$out['webDir'] = $g->get_setting('webDir');
	$out['tz'] = $g->get_setting('default_timezone');
	$out['secrets'] = array();
	foreach (array('hcaptcha_private', 'captcha_private', 'anti_spam_answer', 'dbpassword', 'dbusername', 'apikey', 'stripe_secret_key', 'composerAutoLoad') as $name) {
		$out['secrets'][$name] = $g->get_setting($name);
	}
	$out['user_id'] = $s->get_user_id();
	$out['permission'] = $s->get_permission();
	$out['logged_in'] = $s->is_logged_in();
	$out['user_tz'] = $s->get_timezone();
	$out['check_low'] = sbx_catch(function () use ($s) { return $s->check_permission(0); });
	$out['check_high'] = sbx_catch(function () use ($s) { return $s->check_permission(10); });
	$out['db'] = sbx_catch(function () { return DbConnector::get_instance()->get_db_link(); });
	$out['path'] = PathHelper::getIncludePath('includes/Validator.php');
	$out['config_readable'] = @file_get_contents(PathHelper::getSiteRoot() . '/config/Globalvars_site.php') !== false;
	$out['config_setting'] = $g->config_file_setting('dbpassword');
	$out['core_class'] = class_exists('Validator');
	$out['own_include'] = class_exists('SbxHelper') ? SbxHelper::hello() : null;
	$out['own_data'] = class_exists('SbxThing') ? (new SbxThing())->name() : null;
	$out['shadow'] = (new ReflectionClass('LibraryFunctions'))->getFileName();
	$out['form'] = sbx_catch(function () {
		$fw = new FormWriterV2HTML5('sbxform', array('action' => '/somewhere'));
		ob_start();
		$fw->begin_form();
		$fw->textinput('who', 'Who', array('validation' => array('required' => true)));
		$fw->dropinput('pick', 'Pick', array('options' => array('a' => 'A', 'b' => 'B')));
		$fw->submitbutton('go', 'Go');
		$fw->end_form();
		return ob_get_clean();
	});
	$out['unique'] = sbx_catch(function () {
		$fw = new FormWriterV2HTML5('sbxform2', array('action' => '/somewhere'));
		ob_start();
		$fw->textinput('email', 'Email', array('validation' => array('unique' => array('table' => 'usr_users', 'column' => 'usr_email'))));
		ob_end_clean();
		return $fw->validate(array('email' => 'a@b.c'));
	});
	return $out;
}
PHP;

$files = array(
	'plugin.php' => $plugin_php,
	'includes/SbxHelper.php' => "<?php\nclass SbxHelper { public static function hello() { return 'from includes'; } }\n",
	'data/SbxThing.php' => "<?php\nclass SbxThing { public function name() { return 'from data'; } }\n",
	'includes/Deep/Ns.php' => "<?php\nnamespace Acme\\Deep;\n\nclass Ns { public static function where() { return 'namespaced'; } }\n",
	// A plugin's class named like a core one: core's wins.
	'includes/LibraryFunctions.php' => "<?php\nclass LibraryFunctions { }\n",
);
$manifest = array(
	'settings' => array(array('name' => 'greeting', 'label' => 'Greeting', 'type' => 'text', 'default' => 'Hello there'),
		// Named like a core setting FormWriter reads: never answers to the bare name.
		array('name' => 'debug', 'label' => 'Debug', 'type' => 'text', 'default' => 'plugin-debug')),
	'limits' => array('memory_mb' => 32, 'timeout_ms' => 5000),
);

/** Checks on one run's output. */
function sbx_runtime_checks(array $result, User $admin, string $label): void {
	check($result["status"] === SandboxLog::STATUS_OK, $label . ": the call is answered", json_encode(array($result["status"], $result["message"])));
	$o = is_array($result['output'] ?? null) ? $result['output'] : array();
	$settings = Globalvars::get_instance();
	check(array_key_exists('own_debug_bare', $o) && $o['own_debug_bare'] === null && ($o['own_debug_full'] ?? null) === 'plugin-debug',
		$label . ': a setting named like a core one answers only by its full name', json_encode(array($o['own_debug_bare'] ?? 'missing', $o['own_debug_full'] ?? null)));
	check(($o['namespaced'] ?? null) === 'namespaced', $label . ': a namespaced class of its own resolves');
	check(($o['own'] ?? null) === 'Hello there' && ($o['own_full'] ?? null) === 'Hello there',
		$label . ': its own setting, by its manifest name and its full name', json_encode(array($o['own'] ?? null, $o['own_full'] ?? null)));
	check(($o['site_name'] ?? null) === (string)$settings->get_setting('site_name') && ($o['webDir'] ?? null) === (string)$settings->get_setting('webDir')
		&& ($o['tz'] ?? null) !== null, $label . ': the site\'s public facts');
	$leaked = array_filter((array)($o['secrets'] ?? array('x' => 'missing')), function ($v) { return $v !== null; });
	check($leaked === array(), $label . ': every other setting is unset, a secret or not', json_encode($leaked));
	check(($o['user_id'] ?? null) === (int)$admin->key && ($o['permission'] ?? null) === 10 && ($o['logged_in'] ?? null) === true,
		$label . ': who is viewing, and their level', json_encode(array($o['user_id'] ?? null, $o['permission'] ?? null)));
	check(($o['user_tz'] ?? '') === 'Europe/Paris', $label . ': and their time zone', (string)($o['user_tz'] ?? ''));
	check(($o['check_low']['ok'] ?? null) === true && ($o['check_high']['ok'] ?? null) === true,
		$label . ': a level check answers at the viewer\'s level', json_encode(array($o['check_low'] ?? null, $o['check_high'] ?? null)));
	check(strpos((string)($o['db']['message'] ?? ''), 'no database; use a model') !== false, $label . ': DbConnector refuses, saying to use a model', json_encode($o['db'] ?? null));
	check(($o['path'] ?? '') === PathHelper::getIncludePath('includes/Validator.php'), $label . ': PathHelper gives tree paths', (string)($o['path'] ?? ''));
	check(array_key_exists('config_setting', $o) && $o['config_setting'] === null, $label . ': a config-file setting answers unset');
	if ($label === 'Fenced') {
		check(($o['config_readable'] ?? true) === false, $label . ': the config file cannot be read');
		$read = array_filter((array)($o['walls'] ?? array('none' => true)));
		check($read === array(), $label . ': nor a non-.php file in includes/, another plugin\'s plugin.json, or another utils/ script', json_encode($o['walls'] ?? null));
	}
	check(($o['core_class'] ?? false) === true, $label . ': a core class resolves');
	check(($o['own_include'] ?? null) === 'from includes' && ($o['own_data'] ?? null) === 'from data', $label . ': its own classes resolve from src/includes and src/data');
	check(strpos((string)($o['shadow'] ?? ''), '/src/') === false && str_ends_with((string)($o['shadow'] ?? ''), '/public_html/includes/LibraryFunctions.php'),
		$label . ': its class named like a core one does not shadow core\'s', (string)($o['shadow'] ?? ''));
	$form = (string)($o['form']['ok'] ?? '');
	check($form !== '' && strpos($form, '<form') !== false && strpos($form, 'name="who"') !== false && strpos($form, '<select') !== false,
		$label . ': FormWriterV2HTML5 draws a form (V5)', json_encode($o['form'] ?? null));
	check(strpos((string)($o['unique']['message'] ?? ''), 'no database') !== false || ($o['unique']['threw'] ?? '') === 'SandboxRuntimeException',
		$label . ': its database check fails with the stand-in\'s message', json_encode($o['unique'] ?? null));
}

try {
	$admin = make_user('SbxRuntimeAdmin', 10);
	$admin->set('usr_timezone', 'Europe/Paris');
	$admin->save();

	section('Unfenced, the same PHP command line');
	$p = sandbox_fixture_plugin($manifest, array('files' => $files));
	$name = $p['name'];
	$pm = PluginManager::getInstance();
	$pm->install($name, false);
	sandbox_approve($name);
	$pm->activate($name);
	SandboxPlugins::reset();
	SandboxRunner::$command_override = function (array $envelope) {
		$cmd = SandboxRunner::command($envelope);
		return array_slice($cmd, array_search('--', $cmd, true) + 1);
	};
	harness_defer(function () { SandboxRunner::$command_override = null; });
	sbx_runtime_checks(SandboxHost::ui($name, 'go', array(), $admin), $admin, 'Unfenced');
	$member = make_user('SbxRuntimeMember', 0);
	$m = SandboxHost::ui($name, 'go', array(), $member);
	check(($m['output']['check_low']['ok'] ?? null) === true && ($m['output']['check_high']['threw'] ?? '') === 'SandboxRuntimeException'
		&& ($m['output']['permission'] ?? null) === 0, 'A member viewer passes a level-0 check and is refused level 10', json_encode(array($m['output']['check_high'] ?? null)));
	SandboxRunner::$command_override = null;

	section('In the fence');
	$fence_name = 'sbxruntime';
	if (SandboxRunner::unavailable('hts_none') === SandboxRunner::NO_FENCE || SandboxRunner::unavailable('hts_none') === SandboxRunner::OLD_LAUNCHER) {
		harness_skip('The fenced run', 'this box has no launcher 2.1 with the allowlist');
	} else {
		$ready = function_exists('posix_getpwnam') && posix_getpwnam(SandboxRunner::userFor($fence_name)) !== false;
		sandbox_fixture_plugin($manifest, array('name' => $fence_name, 'files' => $files, 'keep_folder' => !$ready));
		if (!$ready) {
			harness_skip('The fenced run', 'jpx-' . $fence_name . ' is not made yet; the root step makes it within a minute of this folder, which is left in place. Run again then.');
		} else {
			$pm->install($fence_name, false);
			sandbox_approve($fence_name);
			$pm->activate($fence_name);
			SandboxPlugins::reset();
			$started = microtime(true);
			$result = SandboxHost::ui($fence_name, 'go', array(), $admin);
			sbx_runtime_checks($result, $admin, 'Fenced');
			check(true, 'A fenced call with the runtime and a form, no asks, took ' . (int)round((microtime(true) - $started) * 1000) . ' ms (V4)');
		}
	}
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();

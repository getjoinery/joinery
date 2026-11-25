<?php
/** @joinery-test
 * name: sandbox_manifest
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * A sandboxed plugin's manifest (SandboxManifest) and the contents rule
 * (SandboxPlugins::namesAreSandboxed, isSandboxedDir), with no database.
 *
 * What is pinned here:
 *  - a full, valid manifest parses, and the names the platform derives from it
 *    (class, setting) are the documented ones;
 *  - a manifest that declares models is refused, pointing at src/data/ (they
 *    are model files: spec sandboxed_plugin_model_files);
 *  - every name that becomes code, SQL or a URL is held to its pattern: a
 *    table section's model and columns that could carry PHP, SQL or a path
 *    are refused (they are checked against the model files at install);
 *  - the shapes the doors rely on (permissions, schedules, settings, pages)
 *    are refused when wrong, with a message naming the place;
 *  - a package is sandboxed only with exactly manifest.json and a real src/ directory.
 *
 * Run: php tests/unit/sandbox_manifest_test.php
 *
 * @version 1.1 - models are model files: a manifest's models key is refused; a table section is held to name patterns
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$valid = array(
	'name' => 'notifier',
	'title' => 'Notifier',
	'version' => '1.0.0',
	'platform' => 1, 'id' => '0123456789abcdef0123456789abcdef',
	'description' => 'Posts chosen signals to a chat webhook.',
	'permissions' => array('actions' => array('notification_unread_count'), 'records' => array('Post' => array('read'))),
	'signals' => array('purchase.completed'),
	'schedules' => array(array('name' => 'digest', 'frequency' => 'daily', 'time' => '08:00')),
	'outbound_hosts' => array('hooks.slack.com'),
	'settings' => array(
		array('name' => 'webhook_url', 'label' => 'Webhook URL', 'type' => 'url', 'secret' => true),
		array('name' => 'channel', 'label' => 'Channel', 'type' => 'text', 'default' => 'general'),
	),
	'admin_pages' => array(array('slug' => 'log', 'title' => 'Sent messages', 'sections' => array(
		array('type' => 'table', 'model' => 'SentLog', 'columns' => array('create_time', 'signal', 'message'), 'order' => '-create_time', 'limit' => 50),
		array('type' => 'form', 'handler' => 'send_test', 'fields' => array(array('name' => 'text', 'label' => 'Text', 'type' => 'textarea')), 'submit_label' => 'Send a test'),
		array('type' => 'settings', 'title' => 'Settings'),
	))),
	'menu' => array('title' => 'Notifier'),
	'limits' => array('memory_mb' => 32, 'timeout_ms' => 8000),
);

/** The problems a manifest has, after one change to the valid one. */
function problems_with(array $base, callable $change): array {
	$m = $base;
	$change($m);
	return SandboxManifest::problems($m, 'notifier');
}

function mentions(array $problems, string $needle): bool {
	foreach ($problems as $p) {
		if (stripos($p, $needle) !== false) {
			return true;
		}
	}
	return false;
}

section('A full manifest');
$problems = SandboxManifest::problems($valid, 'notifier');
check($problems === array(), 'The worked example is valid', implode(' | ', $problems));
$m = SandboxManifest::fromArray($valid, 'notifier');
check(SandboxManifest::classFor('notifier', 'SentLog') === 'Sbx_notifier_SentLog', 'Model class: Sbx_ + plugin + _ + model');
check(SandboxManifest::classFor('chat_bridge', 'Item') === 'Sbx_chat_bridge_Item', 'A plugin name with an underscore keeps it');
check(SandboxModelDeclarations::tableFor('notifier', 'snt_sent_logs') === 'sbx_notifier__snt_sent_logs', 'Table: sbx_ + plugin + __ + the model file\'s table');
check(SandboxManifest::settingName('notifier', 'webhook_url') === 'sbx_notifier__webhook_url', 'Setting: sbx_ + plugin + __ + name');
check($m->limits() === array('memory_mb' => 32, 'timeout_ms' => 8000), 'Limits are read');
check(SandboxManifest::fromArray(array('name' => 'tiny', 'title' => 'Tiny', 'version' => '0.1.0', 'platform' => 1, 'id' => '0123456789abcdef0123456789abcdef'), 'tiny')->limits()
	=== array('memory_mb' => SandboxManifest::DEFAULT_MEMORY_MB, 'timeout_ms' => SandboxManifest::DEFAULT_TIMEOUT_MS),
	'A manifest with no limits gets the defaults');

$p = problems_with($valid, function (&$x) { $x['models'] = array(array('name' => 'SentLog', 'fields' => array())); });
check(mentions($p, 'models are not declared in the manifest') && mentions($p, 'src/data/'), 'A manifest that declares models is refused, pointing at src/data/',
	implode(' | ', $p));

section('Names that become code, SQL or URLs');
$hostile_models = array('Sent{Log}', 'SentLog;', 'sentLog', 'Sent Log', 'A', str_repeat('A', 21), 'Sent_Log', "SentLog\n");
foreach ($hostile_models as $bad) {
	$p = problems_with($valid, function (&$x) use ($bad) { $x['admin_pages'][0]['sections'][0]['model'] = $bad; });
	check(mentions($p, "model names one of this plugin's model classes"), 'A table section\'s model ' . json_encode($bad) . ' is refused');
}
foreach (array("drop table", 'x;y', 'Message', 'a-b', '9x', str_repeat('a', 41)) as $bad) {
	$p = problems_with($valid, function (&$x) use ($bad) { $x['admin_pages'][0]['sections'][0]['columns'][0] = $bad; });
	check(mentions($p, 'is not a column name'), 'A table section\'s column ' . json_encode($bad) . ' is refused', implode(' | ', $p));
}
foreach (array('Notifier', 'notifier!', '../etc', 'n', str_repeat('a', 21), '9lives', 'foo__bar', 'foo_', '_foo', "foo\n") as $bad) {
	$p = SandboxManifest::problems(array_merge($valid, array('name' => $bad)));
	check(mentions($p, 'name must be'), 'The plugin name ' . json_encode($bad) . ' is refused');
}
foreach (array('core', 'admin', 'profile', 'api') as $reserved) {
	$p = SandboxManifest::problems(array_merge($valid, array('name' => $reserved)));
	check(mentions($p, 'is reserved'), 'The reserved name ' . $reserved . ' is refused');
}
$p = SandboxManifest::problems($valid, 'other');
check(mentions($p, 'does not match its folder'), 'A name that is not its folder is refused');
$p = problems_with($valid, function (&$x) { $x['admin_pages'][0]['sections'][0]['order'] = '-create_time; drop'; });
check(mentions($p, 'order is a column'), 'A table section\'s order is a column name, never SQL');
$p = problems_with($valid, function (&$x) { $x['admin_pages'][0]['slug'] = '../x'; });
check(mentions($p, 'needs a slug'), 'A page slug cannot be a path');
$p = problems_with($valid, function (&$x) { $x['admin_pages'][0]['sections'][1]['handler'] = 'run;rm'; });
check(mentions($p, 'handler'), 'A form handler is a plain name');

section('Door shapes');
$cases = array(
	'an unknown top-level key'                 => array(function (&$x) { $x['hooks'] = array(); }, "unknown key 'hooks'"),
	'a platform that is not a number'          => array(function (&$x) { $x['platform'] = '1'; }, 'platform'),
	'a version that is not x.y.z'              => array(function (&$x) { $x['version'] = 'v1'; }, 'version'),
	'an action name with a space'              => array(function (&$x) { $x['permissions']['actions'][] = 'do it'; }, 'not an action name'),
	'a record verb that is not one of four'    => array(function (&$x) { $x['permissions']['records']['Post'] = array('write'); }, "'write' is not one of"),
	'records given as a list'                  => array(function (&$x) { $x['permissions']['records'] = array('Post'); }, 'must map a model'),
	'a signal that is not noun.verb'           => array(function (&$x) { $x['signals'] = array('purchase'); }, 'not a signal name'),
	'an outbound host with a scheme'           => array(function (&$x) { $x['outbound_hosts'] = array('https://hooks.slack.com'); }, 'not a hostname'),
	'an outbound host that is an address'      => array(function (&$x) { $x['outbound_hosts'] = array('10.0.0.1'); }, 'not a hostname'),
	'an outbound wildcard'                     => array(function (&$x) { $x['outbound_hosts'] = array('*.slack.com'); }, 'not a hostname'),
	'a daily schedule with no time'            => array(function (&$x) { unset($x['schedules'][0]['time']); }, 'needs a time'),
	'a weekly schedule with no day'            => array(function (&$x) { $x['schedules'][0]['frequency'] = 'weekly'; }, 'day_of_week'),
	'a secret setting with a default'          => array(function (&$x) { $x['settings'][0]['default'] = 'x'; }, 'cannot carry a default'),
	'a secret boolean'                         => array(function (&$x) { $x['settings'][0]['type'] = 'boolean'; }, 'only a text or url setting can be secret'),
	'a select setting with no options'         => array(function (&$x) { $x['settings'][1]['type'] = 'select'; }, 'needs options'),
	'a table with no columns'                  => array(function (&$x) { $x['admin_pages'][0]['sections'][0]['columns'] = array(); }, 'columns must list'),
	'a table on a profile page'                => array(function (&$x) { $x['profile_pages'] = $x['admin_pages']; }, 'a member or public page cannot show a table'),
	'a menu with no admin pages'               => array(function (&$x) { $x['admin_pages'] = array(); }, 'needs at least one admin page'),
	'a memory limit past what a manifest may write' => array(function (&$x) { $x['limits']['memory_mb'] = SandboxManifest::MEMORY_SHAPE_MAX_MB + 1; }, 'memory_mb'),
	'a timeout above the ceiling'              => array(function (&$x) { $x['limits']['timeout_ms'] = 60000; }, 'timeout_ms'),
);
foreach ($cases as $label => list($change, $needle)) {
	$p = problems_with($valid, $change);
	check(mentions($p, $needle), "Refused: $label", implode(' | ', $p));
}
$threw = '';
try {
	SandboxManifest::fromJson('{not json', 'notifier');
} catch (SandboxManifestException $e) {
	$threw = $e->getMessage();
}
check(strpos($threw, 'not a JSON object') !== false, 'Text that is not JSON is refused');

section('A value of the wrong kind anywhere is refused, never thrown');
// Every scalar in the worked example, replaced in turn with a list, an
// object, a number and null: the validator answers each with problems, or
// passes it, but never throws.
$paths = array();
$walk = function ($node, $path) use (&$walk, &$paths) {
	foreach ($node as $k => $v) {
		$here = array_merge($path, array($k));
		if (is_array($v)) {
			$walk($v, $here);
		}
		$paths[] = $here;
	}
};
$walk($valid, array());
$threw = array();
foreach ($paths as $path) {
	foreach (array(array(), array('x' => array()), 7, null, true) as $bad) {
		$m = $valid;
		$ref = &$m;
		foreach ($path as $k) {
			$ref = &$ref[$k];
		}
		$ref = $bad;
		unset($ref);
		try {
			SandboxManifest::problems($m, 'notifier');
		} catch (Throwable $e) {
			$threw[] = implode('.', $path) . ' = ' . json_encode($bad) . ': ' . $e->getMessage();
		}
	}
}
check($threw === array(), 'No value in any place makes the validator throw (' . count($paths) . ' places)', implode(' | ', array_slice($threw, 0, 5)));
section('What makes a package sandboxed');
$kinds = function (array $map) {
	return function ($name) use ($map) { return $map[$name] ?? 'file'; };
};
$shape = $kinds(array('manifest.json' => 'file', 'src' => 'dir'));
check(SandboxPlugins::namesAreSandboxed(array('manifest.json', 'src'), $shape), 'manifest.json and src/');
check(SandboxPlugins::namesAreSandboxed(array('src', 'manifest.json'), $shape), 'in either order');
check(!SandboxPlugins::namesAreSandboxed(array('manifest.json', 'notifier.wasm'), $shape), 'a module file beside the manifest is not');
check(!SandboxPlugins::namesAreSandboxed(array('manifest.json', 'src', 'helper.php'), $shape), 'a third entry is not');
check(!SandboxPlugins::namesAreSandboxed(array('manifest.json'), $shape), 'the manifest alone is not');
check(!SandboxPlugins::namesAreSandboxed(array('plugin.json', 'src'), $shape), 'plugin.json is not the manifest');
check(!SandboxPlugins::namesAreSandboxed(array('manifest.json', 'src'), $kinds(array('src' => 'link'))), 'a symlinked src is not');
check(!SandboxPlugins::namesAreSandboxed(array('manifest.json', 'src'), $kinds(array('src' => 'file'))), 'a src that is a file is not');
check(!SandboxPlugins::namesAreSandboxed(array('manifest.json', 'src'), $kinds(array('manifest.json' => 'link', 'src' => 'dir'))),
	'a symlinked manifest is not');

// The same rule on a real folder.
$shape_dir = harness_scratch_dir('sbx_shape');
$mk = function (string $name) use ($shape_dir) {
	$d = $shape_dir . '/' . $name;
	mkdir($d, 0755, true);
	file_put_contents($d . '/manifest.json', '{}');
	return $d;
};
$d = $mk('good');
mkdir($d . '/src');
check(SandboxPlugins::isSandboxedDir($d), 'A folder of manifest.json and a real src/ is sandboxed');
$d = $mk('module');
file_put_contents($d . '/module.wasm', "\0asm");
check(!SandboxPlugins::isSandboxedDir($d), 'a folder of manifest.json and a module file is not');
$d = $mk('third');
mkdir($d . '/src');
file_put_contents($d . '/extra.php', "<?php\n");
check(!SandboxPlugins::isSandboxedDir($d), 'nor one with a third entry');
$d = $mk('linked');
mkdir($shape_dir . '/elsewhere');
symlink($shape_dir . '/elsewhere', $d . '/src');
check(!SandboxPlugins::isSandboxedDir($d), 'nor one whose src is a symlink');
$d = $mk('srcfile');
file_put_contents($d . '/src', 'x');
check(!SandboxPlugins::isSandboxedDir($d), 'nor one whose src is a file');
symlink($shape_dir . '/good', $shape_dir . '/goodlink');
check(!SandboxPlugins::isSandboxedDir($shape_dir . '/goodlink'), 'nor a symlink to a sandboxed folder');

harness_finish();

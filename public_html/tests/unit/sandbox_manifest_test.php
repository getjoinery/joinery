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
 * (SandboxPlugins::namesAreSandboxed), with no database and no files.
 *
 * What is pinned here:
 *  - a full, valid manifest parses, and the names the platform derives from it
 *    (class, table, prefix, setting) are the documented ones;
 *  - every name that becomes code, SQL or a URL is held to its pattern: a
 *    model or field that could carry PHP, SQL or a path is refused;
 *  - the shapes the doors rely on (permissions, schedules, settings, pages)
 *    are refused when wrong, with a message naming the place;
 *  - the credential's scope is the declared permissions plus every verb on the
 *    plugin's own models;
 *  - a package is sandboxed only with exactly manifest.json and one .wasm.
 *
 * Run: php tests/unit/sandbox_manifest_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$valid = array(
	'name' => 'notifier',
	'title' => 'Notifier',
	'version' => '1.0.0',
	'platform' => 1,
	'description' => 'Posts chosen signals to a chat webhook.',
	'permissions' => array('actions' => array('notification_unread_count'), 'records' => array('Post' => array('read'))),
	'signals' => array('purchase.completed'),
	'schedules' => array(array('name' => 'digest', 'frequency' => 'daily', 'time' => '08:00')),
	'outbound_hosts' => array('hooks.slack.com'),
	'settings' => array(
		array('name' => 'webhook_url', 'label' => 'Webhook URL', 'type' => 'url', 'secret' => true),
		array('name' => 'channel', 'label' => 'Channel', 'type' => 'text', 'default' => 'general'),
	),
	'models' => array(array('name' => 'SentLog', 'label' => 'Sent messages', 'fields' => array(
		array('name' => 'signal', 'type' => 'string', 'max_length' => 100, 'index' => true),
		array('name' => 'message', 'type' => 'text', 'required' => true),
		array('name' => 'delivered', 'type' => 'boolean'),
	))),
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
check(SandboxManifest::tableFor('notifier', 'SentLog') === 'sbx_notifier__sent_log', 'Table: sbx_ + plugin + __ + snake model');
check(SandboxManifest::prefixFor('notifier', 'SentLog') === 'notifier__sent_log', 'Column prefix: plugin + __ + snake model');
check(SandboxManifest::settingName('notifier', 'webhook_url') === 'sbx_notifier__webhook_url', 'Setting: sbx_ + plugin + __ + name');
check($m->limits() === array('memory_mb' => 32, 'timeout_ms' => 8000), 'Limits are read');
check(SandboxManifest::fromArray(array('name' => 'tiny', 'title' => 'Tiny', 'version' => '0.1.0', 'platform' => 1), 'tiny')->limits()
	=== array('memory_mb' => SandboxManifest::DEFAULT_MEMORY_MB, 'timeout_ms' => SandboxManifest::DEFAULT_TIMEOUT_MS),
	'A manifest with no limits gets the defaults');

$scope = $m->scopeEntries();
check(in_array('notification_unread_count', $scope, true) && in_array('Post:read', $scope, true), 'The credential carries the declared permissions');
foreach (ApiKey::RECORD_VERBS as $verb) {
	check(in_array('Sbx_notifier_SentLog:' . $verb, $scope, true), "and $verb on its own model");
}
check(!in_array('Post:create', $scope, true), 'and nothing it did not declare');

section('Names that become code, SQL or URLs');
$hostile_models = array('Sent{Log}', 'SentLog;', 'sentLog', 'Sent Log', 'A', str_repeat('A', 21), 'Sent_Log', "SentLog\n");
foreach ($hostile_models as $bad) {
	$p = problems_with($valid, function (&$x) use ($bad) { $x['models'][0]['name'] = $bad; });
	check(mentions($p, 'models[0] needs a name'), 'The model name ' . json_encode($bad) . ' is refused');
}
foreach (array("drop table", 'x;y', 'Message', 'a-b', 'id', 'create_time', 'event_id', str_repeat('a', 21)) as $bad) {
	$p = problems_with($valid, function (&$x) use ($bad) { $x['models'][0]['fields'][0]['name'] = $bad; });
	check(!empty($p), 'The field name ' . json_encode($bad) . ' is refused', implode(' | ', $p));
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
$p = problems_with($valid, function (&$x) { $x['models'][0]['fields'][0]['type'] = 'varchar(9999)'; });
check(mentions($p, 'type must be one of'), 'A field type is one of the listed words, never SQL');
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
	'a table of a model it does not declare'   => array(function (&$x) { $x['admin_pages'][0]['sections'][0]['model'] = 'User'; }, "this plugin's models"),
	'a table column that is not a field'       => array(function (&$x) { $x['admin_pages'][0]['sections'][0]['columns'][] = 'usr_password'; }, 'is not a field'),
	'a table on a profile page'                => array(function (&$x) { $x['profile_pages'] = $x['admin_pages']; }, 'a profile page cannot show a table'),
	'a menu with no admin pages'               => array(function (&$x) { $x['admin_pages'] = array(); }, 'needs at least one admin page'),
	'a memory limit above the ceiling'         => array(function (&$x) { $x['limits']['memory_mb'] = 512; }, 'memory_mb'),
	'a timeout above the ceiling'              => array(function (&$x) { $x['limits']['timeout_ms'] = 60000; }, 'timeout_ms'),
	'a model declared twice'                   => array(function (&$x) { $x['models'][] = $x['models'][0]; }, 'declared twice'),
	'two models told apart only by case'       => array(function (&$x) { $m = $x['models'][0]; $m['name'] = 'Sentlog'; $x['models'][] = $m; }, 'not told apart by case'),
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

section('Names the database would cut');
$p = SandboxManifest::problems(array_merge($valid, array('name' => 'abcdefghij_klmnopqrs',
	'models' => array(array('name' => 'AbcdefghijKlmnopqrst', 'fields' => array(array('name' => 'x', 'type' => 'text')))),
	'admin_pages' => array(), 'menu' => null)));
check(mentions($p, 'too long for the database'), 'Plugin and model names too long together are refused', implode(' | ', $p));
$p = SandboxManifest::problems(array_merge($valid, array('name' => 'abcdefghij',
	'models' => array(array('name' => 'Item', 'fields' => array(array('name' => 'x', 'type' => 'text')))),
	'admin_pages' => array(), 'menu' => null)), 'abcdefghij');
check(!mentions($p, 'too long'), 'Ordinary names fit', implode(' | ', $p));

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
$caught = '';
try {
	SandboxManifest::fromArray(array('name' => 'notifier', 'title' => 'T', 'version' => '1.0.0', 'platform' => 1,
		'models' => array(array('name' => 'Item', 'fields' => array(array('name' => 'x', 'type' => array()))))), 'notifier');
} catch (SandboxManifestException $e) {
	$caught = $e->getMessage();
}
check($caught !== '', 'A field type that is not text is refused as a manifest problem', $caught);

section('What makes a package sandboxed');
$plain = function () { return true; };
check(SandboxPlugins::namesAreSandboxed(array('manifest.json', 'notifier.wasm'), $plain), 'manifest.json and one .wasm');
check(!SandboxPlugins::namesAreSandboxed(array('manifest.json', 'a.wasm', 'b.wasm'), $plain), 'two .wasm files is not');
check(!SandboxPlugins::namesAreSandboxed(array('manifest.json', 'notifier.wasm', 'helper.php'), $plain), 'a PHP file beside them is not');
check(!SandboxPlugins::namesAreSandboxed(array('plugin.json', 'notifier.wasm'), $plain), 'plugin.json is not the manifest');
check(!SandboxPlugins::namesAreSandboxed(array('manifest.json', 'notifier.php'), $plain), 'no .wasm is not');
check(!SandboxPlugins::namesAreSandboxed(array('manifest.json', '../x.wasm'), $plain), 'a path is not a module name');
check(!SandboxPlugins::namesAreSandboxed(array('manifest.json', 'notifier.wasm'), function ($n) { return $n !== 'notifier.wasm'; }),
	'a module that is not a plain file (a link, a folder) is not');

harness_finish();

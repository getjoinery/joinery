<?php
/**
 * Manifest-declared data (specs/plugin_tiers.md WP3), over real requests.
 *
 * A throwaway sandboxed plugin — a folder holding manifest.json and one .wasm —
 * goes through the same lifecycle as any plugin (PluginManager install,
 * activate, deactivate, uninstall), and its declared model is used through
 * the record endpoints with a key scoped to it.
 *
 * What is pinned here:
 *  - native plugin loading skips the folder; the sandboxed tier finds it;
 *  - install makes the model's table with the documented columns, and the row
 *    says the plugin is sandboxed;
 *  - an active plugin's model is a class by name and a record endpoint:
 *    create, list, read one, filter by a declared field, update, delete;
 *    an undeclared filter is refused, not ignored;
 *  - per-record authorization applies: a member's key is refused;
 *  - deactivated, the model is gone from the API; uninstalled, its table is
 *    dropped and the row is the record.
 *
 * USAGE (CLI only): php tests/functional/sandbox/sandbox_data_test.php [base_url] [origin_ip]
 *
 * @version 1.0
 */

/** @joinery-test
 * name: sandbox_data
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/../api/api_test_harness.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
api_test_boot($argv);

$name = 'hts_' . bin2hex(random_bytes(3));
$class = SandboxManifest::classFor($name, 'Item');
$table = SandboxManifest::tableFor($name, 'Item');
$prefix = SandboxManifest::prefixFor($name, 'Item');
$dir = SandboxPlugins::dirFor($name);
$db = DbConnector::get_instance()->get_db_link();

harness_defer(function () use ($name, $dir, $db, $table, $prefix) {
	$db->exec('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
	$db->exec('DROP SEQUENCE IF EXISTS ' . $table . '_' . $prefix . '_id_seq');
	$db->prepare('DELETE FROM amu_admin_menus WHERE amu_slug LIKE ?')->execute(array('sbx-' . $name . '%'));
	$db->prepare('DELETE FROM plg_plugins WHERE plg_name = ?')->execute(array($name));
	foreach (glob($dir . '/*') ?: array() as $file) {
		@unlink($file);
	}
	@rmdir($dir);
});

try {
	section('A sandboxed folder');
	mkdir($dir, 0755);
	file_put_contents($dir . '/manifest.json', json_encode(array(
		'name' => $name, 'title' => 'HarnessTest sandboxed data', 'version' => '1.0.0', 'platform' => 1,
		'models' => array(array('name' => 'Item', 'fields' => array(
			array('name' => 'title', 'type' => 'string', 'max_length' => 80, 'required' => true),
			array('name' => 'qty', 'type' => 'integer', 'index' => true),
			array('name' => 'done', 'type' => 'boolean'),
			array('name' => 'notes', 'type' => 'text'),
			array('name' => 'payload', 'type' => 'json'),
		))),
		'admin_pages' => array(array('slug' => 'items', 'title' => 'Items', 'sections' => array(
			array('type' => 'table', 'model' => 'Item', 'columns' => array('title', 'qty')),
		))),
		'menu' => array('title' => 'HarnessTest items'),
	), JSON_PRETTY_PRINT));
	file_put_contents($dir . '/module.wasm', "\0asm\x01\0\0\0");
	SandboxPlugins::reset();

	check(SandboxPlugins::isSandboxed($name), 'The folder is a sandboxed plugin');
	check(!array_key_exists($name, PluginHelper::getAvailablePlugins()), 'Native plugin loading skips it');

	section('Install');
	$pm = PluginManager::getInstance();
	$pm->install($name, false);
	$row = Plugin::get_by_plugin_name($name);
	check($row && $row->get('plg_status') === 'inactive', 'It installs, inactive', $row ? (string)$row->get('plg_status') : 'no row');
	$meta = $row ? $row->get_plugin_metadata() : null;
	check(!empty($meta['sandboxed']) && $meta['name'] === 'HarnessTest sandboxed data', 'Its row describes it from the manifest');
	$cols = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = " . $db->quote($table))
		->fetchAll(PDO::FETCH_KEY_PAIR);
	$expected = array($prefix . '_id', $prefix . '_title', $prefix . '_qty', $prefix . '_done', $prefix . '_notes',
		$prefix . '_payload', $prefix . '_create_time', $prefix . '_update_time', $prefix . '_delete_time');
	check(array_diff($expected, array_keys($cols)) === array(), 'Its table ' . $table . ' has the documented columns', json_encode($cols));
	check(($cols[$prefix . '_qty'] ?? '') === 'bigint' && ($cols[$prefix . '_payload'] ?? '') === 'jsonb'
		&& ($cols[$prefix . '_done'] ?? '') === 'boolean', 'with the declared types');

	section('Activate');
	sandbox_approve($name);
	$pm->activate($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	check(SandboxPlugins::isActive($name), 'It is active');
	check(class_exists($class) && is_subclass_of($class, 'SandboxRecord'), "Its model is the class $class");
	$active_models = LibraryFunctions::discover_model_classes(array('include_plugins' => true, 'plugin_status' => 'active'));
	check(in_array($class, $active_models, true), 'and an active model to discovery');
	$menu = $db->prepare('SELECT count(*) FROM amu_admin_menus WHERE amu_slug = ?');
	$menu->execute(array('sbx-' . $name));
	check((int)$menu->fetchColumn() === 1, 'Its menu entry is added');

	section('The record endpoints');
	$admin = make_user('SbxData', 5);
	$scope = implode(',', array_map(function ($v) use ($class) { return $class . ':' . $v; }, ApiKey::RECORD_VERBS));
	$key = make_machine_key($admin->key, 'sbx-' . $name, 4);
	$key['api_key']->set('apk_scope', $scope);
	$key['api_key']->save();
	$h = key_headers($key['api_key']->get('apk_public_key'), $key['secret_key']);

	$r = api_request('POST', '/api/v1/' . $class, $h, array($prefix . '_title' => 'First', $prefix . '_qty' => 3,
		$prefix . '_done' => 'false', $prefix . '_payload' => '{"a":1}'), true);
	check($r['status'] === 200, 'A record is created', $r['status'] . ' ' . substr($r['raw'], 0, 300));
	$id = (int)($r['json']['data'][$prefix . '_id'] ?? 0);
	check($id > 0, 'and the answer carries its id', substr($r['raw'], 0, 200));
	$r = api_request('POST', '/api/v1/' . $class, $h, array($prefix . '_title' => 'Second', $prefix . '_qty' => 7), true);
	check($r['status'] === 200, 'and another');

	$r = api_request('GET', '/api/v1/' . $class . 's?numperpage=10', $h);
	check($r['status'] === 200 && (int)($r['json']['num_results'] ?? -1) === 2, 'The list reads both', $r['status'] . ' ' . substr($r['raw'], 0, 300));
	$r = api_request('GET', '/api/v1/' . $class . 's?numperpage=100000', $h);
	check($r['status'] === 200 && (int)($r['json']['data']['numperpage'] ?? $r['json']['numperpage'] ?? 0) === 200,
		'A page asked for past 200 rows answers with 200 at most', substr($r['raw'], 0, 200));
	$r = api_request('GET', '/api/v1/' . $class . 's?qty=7', $h);
	check($r['status'] === 200 && (int)($r['json']['num_results'] ?? -1) === 1, 'A declared field filters', substr($r['raw'], 0, 300));
	$r = api_request('GET', '/api/v1/' . $class . 's?colour=red', $h);
	check($r['status'] === 400, 'An undeclared filter is refused, not ignored', $r['status'] . ' ' . substr($r['raw'], 0, 200));
	$r = api_request('GET', '/api/v1/' . $class . '/' . $id, $h);
	check($r['status'] === 200 && ($r['json']['data'][$prefix . '_title'] ?? '') === 'First', 'One record reads');
	$r = api_request('PUT', '/api/v1/' . $class . '/' . $id . '?' . $prefix . '_title=Renamed', $h);
	check($r['status'] === 200 && ($r['json']['data'][$prefix . '_title'] ?? '') === 'Renamed', 'An update saves', substr($r['raw'], 0, 200));
	check(($r['json']['data'][$prefix . '_update_time'] ?? null) !== null, 'and stamps update_time');
	// As a module writes through its API door: fields as a JSON object body.
	$r = api_request('POST', '/api/v1/' . $class, $h, array($prefix . '_title' => 'As JSON', $prefix . '_qty' => 11));
	$json_id = (int)($r['json']['data'][$prefix . '_id'] ?? 0);
	check($r['status'] === 200 && $json_id > 0 && ($r['json']['data'][$prefix . '_title'] ?? '') === 'As JSON'
		&& (int)($r['json']['data'][$prefix . '_qty'] ?? 0) === 11, 'A create takes a JSON body', substr($r['raw'], 0, 200));
	$r = api_request('PUT', '/api/v1/' . $class . '/' . $json_id, $h, array($prefix . '_qty' => 12));
	check($r['status'] === 200 && (int)($r['json']['data'][$prefix . '_qty'] ?? 0) === 12, 'and an update', substr($r['raw'], 0, 200));
	$r = api_request('POST', '/api/v1/' . $class, $h, array($prefix . '_qty' => 1), true);
	check($r['status'] === 400, 'A required field is required', $r['status'] . ' ' . substr($r['raw'], 0, 200));
	$r = api_request('DELETE', '/api/v1/' . $class . '/' . $id, $h);
	check($r['status'] === 200 && !empty($r['json']['data'][$prefix . '_delete_time']), 'A delete is a soft delete', substr($r['raw'], 0, 200));

	$member = make_user('SbxMember', 0);
	$mkey = make_machine_key($member->key, 'sbx-member-' . $name, 4);
	$mh = key_headers($mkey['api_key']->get('apk_public_key'), $mkey['secret_key']);
	$r = api_request('POST', '/api/v1/' . $class, $mh, array($prefix . '_title' => 'Member'), true);
	check($r['status'] !== 200, 'A member cannot write a sandboxed model (staff only)', (string)$r['status']);

	section('Deactivate');
	$pm->deactivate($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	check(!SandboxPlugins::isActive($name), 'It is inactive');
	$r = api_request('GET', '/api/v1/' . $class . 's', key_headers(make_machine_key($admin->key, 'sbx-plain-' . $name, 4)['api_key']->get('apk_public_key'), ''));
	$plain = make_machine_key($admin->key, 'sbx-plain2-' . $name, 4);
	$r = api_request('GET', '/api/v1/' . $class . 's', key_headers($plain['api_key']->get('apk_public_key'), $plain['secret_key']));
	check($r['status'] === 400 || $r['status'] === 404, 'Its model is no longer a record endpoint', $r['status'] . ' ' . substr($r['raw'], 0, 200));
	$menu->execute(array('sbx-' . $name));
	check((int)$menu->fetchColumn() === 0, 'and its menu entry is gone');
	$still = (int)$db->query('SELECT count(*) FROM ' . $table)->fetchColumn();
	check($still === 3, 'Its data stays for when it comes back', (string)$still);

	section('Uninstall');
	// The key acts as the admin; revoking it first is what the plugin's own
	// uninstall does (WP5). Here the test's keys are its own fixtures.
	$pm->uninstall($name);
	$exists = $db->query("SELECT to_regclass(" . $db->quote($table) . ")")->fetchColumn();
	check($exists === null, 'Its table is dropped');
	$seq = $db->query("SELECT to_regclass(" . $db->quote($table . '_' . $prefix . '_id_seq') . ")")->fetchColumn();
	check($seq === null, 'and its id sequence');

	section('The longest names a manifest may use');
	// A 20-character plugin name with the longest model name that still fits
	// the database's 63-character names, made, used and removed.
	$long = 'hts' . bin2hex(random_bytes(3)) . 'abcdefghijk';
	$long_model = 'Ab';
	for ($c = 'C'; strlen($long_model) < 20; $c++) {
		$try = $long_model . $c;
		$probe = array('name' => $long, 'title' => 'T', 'version' => '1.0.0', 'platform' => 1,
			'models' => array(array('name' => $try, 'fields' => array(array('name' => 'abcdefghijklmnopqrst', 'type' => 'string')))));
		if (SandboxManifest::problems($probe, $long) !== array()) {
			break;
		}
		$long_model = $try;
	}
	$long_dir = SandboxPlugins::dirFor($long);
	$long_class = SandboxManifest::classFor($long, $long_model);
	$long_table = SandboxManifest::tableFor($long, $long_model);
	$long_prefix = SandboxManifest::prefixFor($long, $long_model);
	harness_defer(function () use ($long, $long_dir, $db, $long_table, $long_prefix) {
		$db->exec('DROP TABLE IF EXISTS ' . $long_table . ' CASCADE');
		$db->exec('DROP SEQUENCE IF EXISTS ' . $long_table . '_' . $long_prefix . '_id_seq');
		$db->prepare('DELETE FROM plg_plugins WHERE plg_name = ?')->execute(array($long));
		foreach (glob($long_dir . '/*') ?: array() as $file) {
			@unlink($file);
		}
		@rmdir($long_dir);
	});
	mkdir($long_dir, 0755);
	// The longest menu values a manifest allows: a 30-character page slug, an
	// 80-character page title, a 40-character menu title, and an admin and a
	// profile page sharing the slug.
	$long_slug = 'p' . str_repeat('x', 29);
	$long_title = str_repeat('T', 80);
	file_put_contents($long_dir . '/manifest.json', json_encode(array('name' => $long, 'title' => 'HarnessTest long', 'version' => '1.0.0', 'platform' => 1,
		'models' => array(array('name' => $long_model, 'fields' => array(array('name' => 'abcdefghijklmnopqrst', 'type' => 'string')))),
		'admin_pages' => array(array('slug' => $long_slug, 'title' => $long_title, 'sections' => array(array('type' => 'text', 'text' => 'x')))),
		'profile_pages' => array(array('slug' => $long_slug, 'title' => $long_title, 'sections' => array(array('type' => 'text', 'text' => 'x')))),
		'menu' => array('title' => str_repeat('M', 40)))));
	harness_defer(function () use ($db, $long) {
		$db->prepare('DELETE FROM amu_admin_menus WHERE amu_slug LIKE ?')->execute(array('sbx-' . $long . '%'));
	});
	file_put_contents($long_dir . '/module.wasm', "\0asm\x01\0\0\0");
	SandboxPlugins::reset();
	check(strlen($long_table . '_' . $long_prefix . '_id_seq') <= 63, 'Its sequence name fits (' . strlen($long_table . '_' . $long_prefix . '_id_seq') . ' characters)');
	$pm->install($long, false);
	sandbox_approve($long);
	$pm->activate($long);
	SandboxPlugins::reset();
	SandboxModels::reset();
	$record = new $long_class(NULL);
	$record->set($long_prefix . '_abcdefghijklmnopqrst', 'x');
	$record->save();
	check((int)$record->key > 0 && (int)$record->get($long_prefix . '_id') === (int)$record->key, 'A row is made and its id read back through the sequence');
	$menus = $db->prepare('SELECT amu_slug FROM amu_admin_menus WHERE amu_slug LIKE ? ORDER BY amu_slug');
	$menus->execute(array('sbx-' . $long . '%'));
	$menu_slugs = $menus->fetchAll(PDO::FETCH_COLUMN);
	check(count($menu_slugs) === 3 && in_array(SandboxPluginManager::profileMenuSlug($long, $long_slug), $menu_slugs, true),
		'Its menu entries fit, the admin and profile page of one slug two entries', implode(', ', $menu_slugs));
	$pm->deactivate($long);
	$pm->uninstall($long);
	$gone = $db->query("SELECT to_regclass(" . $db->quote($long_table) . ")")->fetchColumn();
	check($gone === null, 'and it uninstalls cleanly');

	$row = Plugin::get_by_plugin_name($name);
	check($row && $row->get('plg_status') === Plugin::STATUS_UNINSTALLED, 'and the row stays as the record');

} catch (\Throwable $e) {
	check(false, 'suite ran to completion', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();

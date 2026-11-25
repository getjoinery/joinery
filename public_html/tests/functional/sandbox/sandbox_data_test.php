<?php
/**
 * A sandboxed plugin's models, from its model files (spec
 * sandboxed_plugin_model_files), over real requests.
 *
 * A throwaway sandboxed plugin — manifest.json and src/, its model a regular
 * model file in src/data — goes through the same lifecycle as any plugin, and
 * its model is reached through the API: by the plugin's own key (through
 * core's relay, as its code reaches it), by staff, and by members.
 *
 * What is pinned here:
 *  - install reads the model file in the parser jail, makes its table with
 *    the declared columns and types, and keeps the checked declarations on
 *    the plugin's row with a hash of the file; a file that is not legal stops
 *    the install, naming the file;
 *  - the plugin's own requests create, read, list, change and delete its
 *    rows, whatever its API flags, and may set the owner column (a member's
 *    row the plugin saves for them); nobody else creates or changes a row;
 *  - staff delete (a soft delete) at the model's delete level, the row's
 *    owner given no pass; a model with no delete time has no API delete;
 *  - reading through the API follows the file once approved: staff read
 *    every row, a member their own; an update that opens more waits on an
 *    approval, and until then the model is as narrow as before;
 *  - the own-request exemption is on the class: with it set, a core model is
 *    still held to its levels, and a sandboxed key still cannot set a core
 *    model's owner;
 *  - a call is refused while the model files differ from what the site read;
 *  - the stored copy is the only source: with the folder gone, the classes,
 *    discovery and the delete rules stand;
 *  - deactivated, the model leaves the API; uninstalled, its table and rules go.
 *
 * USAGE (CLI only): php tests/functional/sandbox/sandbox_data_test.php [base_url] [origin_ip]
 *
 * @version 2.1 - a change of class waits on an approval (reviewer1 R2)
 * @version 2.0 - models from model files: own key, staff, members, approval of reach, hash check, stored copy
 *                (spec sandboxed_plugin_model_files)
 * @version 1.2 - the API's lookup by name (api_model_class) follows activation
 * @version 1.1 - the admin is at content's write and soft-delete levels (6, 7)
 * @version 1.0
 */

/** @joinery-test
 * name: sandbox_data
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
require_once(__DIR__ . '/../api/api_test_harness.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
api_test_boot($argv);

$db = DbConnector::get_instance()->get_db_link();
$items = array(
	'title'   => array('type' => 'varchar(80)', 'required' => true),
	'usr_user_id' => array('type' => 'int8', 'is_nullable' => true),
	'qty'     => array('type' => 'int8', 'index' => true),
	'done'    => array('type' => 'bool', 'default' => false),
	'notes'   => array('type' => 'text'),
	'payload' => array('type' => 'jsonb'),
);
$fk = array('protected:foreign_key_actions' => array('qdi_usr_user_id' => array('action' => 'cascade')));
$item_file = sandbox_model_file('Gizmo', 'qdi', $items, $fk);

/** One ask of the plugin's own, as its code makes it: through core's relay, with its key. */
function sbx_data_ask(string $name, array $ask): array {
	return (new SandboxRelay($name))->handle($ask);
}

try {
	section('A model file that is not legal stops the install');
	$bad = sandbox_fixture_plugin(array(), array('files' => array('data/gizmos_class.php' => sandbox_model_file('Gizmo', 'qdi', $items,
		array('sealed_fields' => array('qdi_notes'))))));
	$pm = PluginManager::getInstance();
	$why = '';
	try {
		$pm->install($bad['name'], false);
	} catch (Exception $e) {
		$why = $e->getMessage();
	}
	check(strpos($why, 'gizmos_class.php') !== false && strpos($why, 'Gizmo::$sealed_fields is not available') !== false,
		'Install is refused, naming the file and the thing', $why);
	check($db->query("SELECT to_regclass('sbx_" . $bad['name'] . "__qdi_gizmos')")->fetchColumn() === null, 'and no table is made');

	section('Install');
	$p = sandbox_fixture_plugin(array(
		'admin_pages' => array(array('slug' => 'items', 'title' => 'Items', 'sections' => array(
			array('type' => 'table', 'model' => 'Gizmo', 'columns' => array('title', 'qdi_qty')),
		))),
		'menu' => array('title' => 'HarnessTest gizmos'),
	), array('files' => array('data/gizmos_class.php' => $item_file)));
	$name = $p['name'];
	$class = SandboxManifest::classFor($name, 'Gizmo');
	$table = 'sbx_' . $name . '__qdi_gizmos';
	check(SandboxPlugins::isSandboxed($name) && !array_key_exists($name, PluginHelper::getAvailablePlugins()), 'The folder is sandboxed, and native loading skips it');
	$pm->install($name, false);
	$row = Plugin::get_by_plugin_name($name);
	check($row && $row->get('plg_status') === 'inactive', 'It installs, inactive', $row ? (string)$row->get('plg_status') : 'no row');
	$meta = json_decode((string)$row->get('plg_metadata'), true);
	check(isset($meta[SandboxModels::METADATA_KEY]['models']['Gizmo']) && strlen($meta[SandboxModels::METADATA_KEY]['files']['gizmos_class.php']['sha256'] ?? '') === 64,
		'Its row keeps the checked declarations and the file\'s hash');
	$cols = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = " . $db->quote($table))->fetchAll(PDO::FETCH_KEY_PAIR);
	check(array_keys($cols) == array_keys($cols) && count(array_diff(array('qdi_gizmo_id', 'qdi_title', 'qdi_usr_user_id', 'qdi_qty', 'qdi_done', 'qdi_notes',
		'qdi_payload', 'qdi_create_time', 'qdi_delete_time'), array_keys($cols))) === 0, "Its table $table has the declared columns", json_encode($cols));
	check(($cols['qdi_qty'] ?? '') === 'bigint' && ($cols['qdi_payload'] ?? '') === 'jsonb' && ($cols['qdi_done'] ?? '') === 'boolean'
		&& ($cols['qdi_title'] ?? '') === 'character varying', 'with the declared types');

	section('Activate');
	$approver = sandbox_approve($name);
	$pm->activate($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	check(class_exists($class) && is_subclass_of($class, 'SandboxRecord') && $class::$tablename === $table, "Its model is the class $class, on $table");
	check(in_array($class, LibraryFunctions::discover_model_classes(array('include_plugins' => true, 'plugin_status' => 'active')), true)
		&& LibraryFunctions::api_model_class($class) === $class, 'an active model to discovery, and the API finds it by name');
	$rule = $db->prepare('SELECT del_action FROM del_deletion_rules WHERE del_target_table = ? AND del_target_column = ?');
	$rule->execute(array($table, 'qdi_usr_user_id'));
	check($rule->fetchColumn() === 'cascade', 'Its delete rule is registered');

	section('The plugin\'s own requests');
	$member = make_user('SbxDataMember', 0);
	$other = make_user('SbxDataOther', 0);
	$a = sbx_data_ask($name, array('ask' => 'record.create', 'model' => 'Gizmo', 'fields' => array('qdi_title' => 'First', 'qdi_qty' => 3,
		'qdi_usr_user_id' => (int)$member->key, 'qdi_payload' => array('a' => 1))));
	$id = (int)($a['record']['qdi_gizmo_id'] ?? 0);
	check(!empty($a['ok']) && $id > 0, 'It creates a row', json_encode($a));
	check((int)($a['record']['qdi_usr_user_id'] ?? 0) === (int)$member->key, 'for the member it names: its own request sets the owner', json_encode($a['record'] ?? null));
	sbx_data_ask($name, array('ask' => 'record.create', 'model' => 'Gizmo', 'fields' => array('qdi_title' => 'Second', 'qdi_qty' => 7, 'qdi_usr_user_id' => (int)$other->key)));
	$a = sbx_data_ask($name, array('ask' => 'record.list', 'model' => 'Gizmo', 'filters' => array('deleted' => false)));
	check(($a['total'] ?? null) === 2, 'It lists every row, not narrowed to the admin who approved it', json_encode($a));
	$a = sbx_data_ask($name, array('ask' => 'record.list', 'model' => 'Gizmo', 'filters' => array('qty' => 7)));
	check(($a['total'] ?? null) === 1, 'A column filters, with or without the prefix', json_encode($a));
	$a = sbx_data_ask($name, array('ask' => 'record.get', 'model' => 'Gizmo', 'id' => $id));
	check(($a['record']['qdi_title'] ?? '') === 'First', 'It reads one, though the file does not open the model to the API', json_encode($a));
	$a = sbx_data_ask($name, array('ask' => 'record.update', 'model' => 'Gizmo', 'id' => $id, 'fields' => array('qdi_title' => 'Renamed')));
	check(($a['record']['qdi_title'] ?? '') === 'Renamed', 'It changes one', json_encode($a));
	$a = sbx_data_ask($name, array('ask' => 'record.create', 'model' => 'Gizmo', 'fields' => array('qdi_qty' => 1)));
	check(empty($a['ok']), 'A required column is required', json_encode($a));

	section('Nobody else creates or changes a row');
	$staff = make_user('SbxDataStaff', 7);
	$scope = implode(',', array_map(function ($v) use ($class) { return $class . ':' . $v; }, ApiKey::RECORD_VERBS));
	$key_for = function (User $user, string $label) use ($scope) {
		$k = make_machine_key($user->key, $label, 4);
		$k['api_key']->set('apk_scope', $scope);
		$k['api_key']->save();
		return key_headers($k['api_key']->get('apk_public_key'), $k['secret_key']);
	};
	$h = $key_for($staff, 'sbx-staff-' . $name);
	$r = api_request('POST', '/api/v1/' . $class, $h, array('qdi_title' => 'Staff'), true);
	check($r['status'] === 404, 'Staff cannot create one', $r['status'] . ' ' . substr($r['raw'], 0, 200));
	$r = api_request('PUT', '/api/v1/' . $class . '/' . $id . '?qdi_title=Staff', $h);
	check($r['status'] === 404, 'nor change one', $r['status'] . ' ' . substr($r['raw'], 0, 200));
	$r = api_request('GET', '/api/v1/' . $class . 's', $h);
	check($r['status'] === 404, 'nor read them, while its file does not open it to the API', (string)$r['status']);

	section('Deleting: staff at the delete level, the owner given no pass');
	$mh = $key_for($member, 'sbx-member-' . $name);
	$r = api_request('DELETE', '/api/v1/' . $class . '/' . $id, $mh);
	check($r['status'] !== 200 && !(new $class($id, TRUE))->get('qdi_delete_time'), 'The row\'s own member cannot delete it through the API', (string)$r['status']);
	$low = $key_for(make_user('SbxDataEditor', 6), 'sbx-editor-' . $name);
	$r = api_request('DELETE', '/api/v1/' . $class . '/' . $id, $low);
	check($r['status'] !== 200, 'nor staff below the delete level (content: 7)', (string)$r['status']);
	$r = api_request('DELETE', '/api/v1/' . $class . '/' . $id, $h);
	check($r['status'] === 200 && !empty($r['json']['data']['qdi_delete_time']), 'Staff at the level delete it, to the trash', $r['status'] . ' ' . substr($r['raw'], 0, 200));

	section('The class, not the caller, decides');
	SandboxRecord::setRequestingPlugin($name);
	$identity = array('current_user_id' => (int)$member->key, 'current_user_permission' => 0);
	$own = new $class(NULL);
	$threw = false;
	try {
		$own->authenticate_write($identity);
	} catch (SystemAuthenticationError $e) {
		$threw = true;
	}
	check(!$threw, 'Its own request passes its own model\'s levels');
	$threw = false;
	try {
		(new Post(NULL))->authenticate_write($identity);
	} catch (SystemAuthenticationError $e) {
		$threw = true;
	}
	check($threw, 'and a core model in the same process is still held to its levels');
	ApiAuth::$sandbox_viewer = (int)$member->key;
	$threw = false;
	try {
		$own->authenticate_write($identity);
	} catch (SystemAuthenticationError $e) {
		$threw = true;
	}
	check($threw, 'A request that names a member is not the plugin\'s own');
	ApiAuth::$sandbox_viewer = null;
	SandboxRecord::setRequestingPlugin(null);

	section('Reading through the API, once approved');
	$open_file = sandbox_model_file('Gizmo', 'qdi', $items, $fk + array('api_readable' => true));
	file_put_contents($p['dir'] . '/src/data/gizmos_class.php', $open_file);
	SandboxModels::reset();
	$envelope_refused = '';
	try {
		SandboxHost::envelope($name, 'on_ui', array());
	} catch (SandboxManifestException $e) {
		$envelope_refused = $e->getMessage();
	}
	check(strpos($envelope_refused, 'model files changed') !== false, 'A call is refused while the file differs from what the site read', $envelope_refused);
	$pm->sync();
	SandboxPlugins::reset();
	SandboxModels::reset();
	check(SandboxConsent::needsApproval($name) && in_array("Its gizmo records can be read through the site's API by staff at level 5 and above",
		SandboxConsent::pending($name)['model_reach'] ?? array(), true), 'After a sync, opening it to the API waits on an approval', json_encode(SandboxConsent::pending($name)));
	check($class::$api_readable === false && $class::$owner_column === false, 'and until then the model is as narrow as before');
	$r = api_request('GET', '/api/v1/' . $class . 's', $h);
	check($r['status'] === 404, 'so staff still cannot read it', (string)$r['status']);
	sandbox_approve($name);
	(new PluginManager())->activate($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	check($class::$api_readable === true && $class::$owner_column === 'qdi_usr_user_id', 'Approved, it is open as its file says');
	$r = api_request('GET', '/api/v1/' . $class . 's?numperpage=10', $h);
	check($r['status'] === 200 && (int)($r['json']['num_results'] ?? -1) === 2, 'Staff read every row', $r['status'] . ' ' . substr($r['raw'], 0, 200));
	$r = api_request('GET', '/api/v1/' . $class . 's?numperpage=10', $mh);
	check($r['status'] === 200 && (int)($r['json']['num_results'] ?? -1) === 1, 'and a member their own', $r['status'] . ' ' . substr($r['raw'], 0, 200));
	section('A change of class is a change of reach');
	file_put_contents($p['dir'] . '/src/data/gizmos_class.php', sandbox_model_file('Gizmo', 'qdi', $items, $fk + array('api_readable' => true, 'access_class' => 'personal')));
	$pm->sync();
	SandboxPlugins::reset();
	SandboxModels::reset();
	$pending = SandboxConsent::pending($name)['model_reach'] ?? array();
	check(in_array("Its gizmo records can be read through the site's API by staff at level 6 and above", $pending, true)
		&& in_array('Its gizmo records can be deleted by staff at level 8 and above', $pending, true),
		'An update from content to personal waits on an approval, saying the levels that result (reviewer1 R2)', json_encode($pending));
	check($class::$api_readable === false && $class::access_level('soft_delete') === 10, 'and until then it is not read through the API, and only a superadmin deletes');
	sandbox_approve($name);
	(new PluginManager())->activate($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	check($class::$api_readable === true && $class::access_level('soft_delete') === 8, 'Approved, it reads and deletes at personal\'s levels');
	file_put_contents($p['dir'] . '/src/data/gizmos_class.php', $open_file);
	$pm->sync();
	sandbox_approve($name);
	(new PluginManager())->activate($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	try {
		SandboxHost::envelope($name, 'on_ui', array());
		check(true, 'After the sync a call goes ahead');
	} catch (SandboxManifestException $e) {
		check(false, 'After the sync a call goes ahead', $e->getMessage());
	}

	section('A sandboxed key on a core model with an owner');
	$grant = sandbox_fixture_plugin(array('permissions' => array('records' => array('Notification' => array('create', 'read')))));
	$pm->install($grant['name'], false);
	$grant_approver = sandbox_approve($grant['name']);
	$pm->activate($grant['name']);
	SandboxPlugins::reset();
	$a = sbx_data_ask($grant['name'], array('ask' => 'record.create', 'model' => 'Notification', 'fields' => array(
		'ntf_usr_user_id' => (int)$member->key, 'ntf_type' => 'sbx_data_test', 'ntf_title' => 'From a plugin')));
	$ntf = (int)($a['record']['ntf_notification_id'] ?? 0);
	if ($ntf) {
		harness_register_model('Notification', $ntf);
	}
	check($ntf > 0 && (int)($a['record']['ntf_usr_user_id'] ?? 0) === (int)$grant_approver->key,
		'It cannot set the owner: the row is stamped as the admin its key acts as', json_encode($a));

	section('A model that keeps no deleted rows');
	$flat = sandbox_fixture_plugin(array(), array('files' => array('data/marks_class.php' => sandbox_model_file('Mark', 'qdm',
		array('label' => array('type' => 'text')), array('field_specifications' => array(
			'qdm_mark_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
			'qdm_label' => array('type' => 'text')))))));
	$pm->install($flat['name'], false);
	sandbox_approve($flat['name']);
	$pm->activate($flat['name']);
	SandboxPlugins::reset();
	SandboxModels::reset();
	$mark_class = SandboxManifest::classFor($flat['name'], 'Mark');
	$a = sbx_data_ask($flat['name'], array('ask' => 'record.create', 'model' => 'Mark', 'fields' => array('qdm_label' => 'x')));
	$mark = (int)($a['record']['qdm_mark_id'] ?? 0);
	$mark_scope = implode(',', array_map(function ($v) use ($mark_class) { return $mark_class . ':' . $v; }, ApiKey::RECORD_VERBS));
	$mk = make_machine_key($staff->key, 'sbx-mark-' . $flat['name'], 4);
	$mk['api_key']->set('apk_scope', $mark_scope);
	$mk['api_key']->save();
	$r = api_request('DELETE', '/api/v1/' . $mark_class . '/' . $mark, key_headers($mk['api_key']->get('apk_public_key'), $mk['secret_key']));
	check($mark > 0 && $r['status'] === 404, 'has no delete through the API', $mark . ' ' . $r['status']);

	section('The stored copy is the only source');
	$moved = $p['dir'] . '.away';
	rename($p['dir'], $moved);
	harness_defer(function () use ($moved, $p) {
		if (is_dir($moved) && !is_dir($p['dir'])) {
			rename($moved, $p['dir']);
		}
	});
	SandboxPlugins::reset();
	SandboxModels::reset();
	check(in_array($class, SandboxModels::classesFor($name), true), 'With its folder gone, its class is still declared');
	check(in_array($class, LibraryFunctions::discover_model_classes(array('include_plugins' => true, 'require_tablename' => true)), true),
		'and discovery still lists its table');
	DeletionRule::resetModelRegistry();
	DeletionRule::pruneOrphanedRules();
	$rule->execute(array($table, 'qdi_usr_user_id'));
	check($rule->fetchColumn() === 'cascade', 'so a prune keeps its delete rules');
	rename($moved, $p['dir']);
	SandboxPlugins::reset();
	SandboxModels::reset();

	section('Deactivate and uninstall');
	$pm->deactivate($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	check(LibraryFunctions::api_model_class($class) === null, 'Deactivated, the API no longer finds its model');
	check((int)$db->query('SELECT count(*) FROM ' . $table)->fetchColumn() === 2, 'Its data stays for when it comes back');
	$pm->uninstall($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	check($db->query("SELECT to_regclass(" . $db->quote($table) . ")")->fetchColumn() === null
		&& $db->query("SELECT to_regclass(" . $db->quote($table . '_qdi_gizmo_id_seq') . ")")->fetchColumn() === null, 'Uninstalled, its table and id sequence are dropped');
	$rule->execute(array($table, 'qdi_usr_user_id'));
	check($rule->fetchColumn() === false, 'and its delete rules go');

	section('Names the database would cut');
	$long = 'hts' . bin2hex(random_bytes(3)) . 'abcdefghijk';
	$lp = sandbox_fixture_plugin(array(), array('name' => $long, 'files' => array('data/longs_class.php' => sandbox_model_file('Long', 'qdl',
		array(), array('tablename' => 'qdl_' . str_repeat('x', 40))))));
	$why = '';
	try {
		$pm->install($long, false);
	} catch (Exception $e) {
		$why = $e->getMessage();
	}
	check(strpos($why, 'longer than the database keeps') !== false, 'A table name the database would cut is refused', $why);
} catch (\Throwable $e) {
	check(false, 'suite ran to completion', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();

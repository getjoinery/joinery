<?php
/** @joinery-test
 * name: plugin_install_identity
 * tier: test-db
 * env: dev-only
 * needs: []
 * covers: [public_html/includes/PluginInstallIdentity.php, public_html/data/plugin_install_ledger_class.php]
 */
/**
 * One id per install of a plugin, and the ledger of what it made (spec
 * plugin_install_identity). Against a native fixture plugin written under
 * plugins/ for the run and removed after it:
 *   - install gives the row a 32-hex install id; an upgrade keeps it; a
 *     reinstall after uninstall gets a new one
 *   - the ledger names its class table, the table its migration made, its
 *     declared setting and its migration record
 *   - uninstall removes a setting an earlier version declared (A2), the table
 *     a migration made (A4) and the migration record, and strikes the ledger
 *   - what uninstall could not remove stays in the ledger, and the next
 *     install finishes it first, or refuses naming it (I1, I8)
 *   - a thing another install's ledger holds is refused (I12)
 *   - the removal request names the install id, and root refuses it once the
 *     row holds another install's id (A10)
 *   - a package is refused while the earlier plugin of its name waits for its
 *     files to be removed (I9), and a sandboxed package whose manifest id is
 *     not the installed one's (D4)
 *   - a hand-made scoped API key loses the gone plugin's class and actions,
 *     and one with nothing else is switched off (A11)
 *   - a fresh sandboxed install removes its own prefixed leftovers (D6)
 *   - the backfill gives an installed row with no id one, and stamps its
 *     sandboxed files and call log
 *
 * Run:  php tests/functional/plugins/plugin_install_identity_test.php
 *
 * @version 1.1 - reviewer2 R1-R4, R9: a longer table's sequence, a core setting, keys switched off,
 *               approvals of other sandboxed plugins, an uninstalled row's leftover folder
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
harness_boot();
harness_test_mode();

$manager = PluginManager::getInstance();
$db = DbConnector::get_instance()->get_db_link();
$queue_dir = RootState::queueDir(PathHelper::getSiteRoot());

$NAME = 'zzinstid';
$TABLE = 'zzi_rows';
$MADE = 'zzi_made_by_migration';
$SETTING_OLD = 'zzinstid_old_color';
$SETTING = 'zzinstid_color';
$VIEW = 'zzi_left_view';
$LONGER = 'zzi_rows_archive';   // a longer-named table whose sequence starts like zzi_rows'
// A core setting with a row: a ledger that names it must not remove it (R2).
$CORE_SETTING = null;
foreach (SettingsDeclarations::all() as $sname => $d) {
	if (($d['_source'] ?? '') === 'core') {
		$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
		$q->execute(array($sname));
		$v = $q->fetchColumn();
		if ($v !== false) { $CORE_SETTING = array($sname, $v); break; }
	}
}

$write_plugin = function (array $settings, bool $migration) use ($NAME, $TABLE, $MADE) {
	$dir = PathHelper::getIncludePath('plugins/' . $NAME);
	if (!is_dir($dir . '/data')) { mkdir($dir . '/data', 0755, true); }
	$manifest = array('name' => 'Install id fixture', 'version' => '1.0.0', 'description' => 'Harness fixture; safe to delete');
	if ($settings) {
		$manifest['settings'] = array();
		foreach ($settings as $s) {
			$manifest['settings'][] = array('name' => $s, 'default' => 'blue');
		}
	}
	file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT));
	file_put_contents($dir . '/data/zzinstid_rows_class.php', "<?php\n"
		. "class ZziFixtureRow extends SystemBase {\n"
		. "\tpublic static \$prefix = 'zzi';\n"
		. "\tpublic static \$tablename = '$TABLE';\n"
		. "\tpublic static \$pkey_column = 'zzi_row_id';\n"
		. "\tpublic static \$field_specifications = array(\n"
		. "\t\t'zzi_row_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),\n"
		. "\t\t'zzi_label' => array('type'=>'varchar(64)'),\n"
		. "\t);\n"
		. "}\n");
	if ($migration) {
		if (!is_dir($dir . '/migrations')) { mkdir($dir . '/migrations', 0755, true); }
		file_put_contents($dir . '/migrations/migrations.php', "<?php\nreturn array(array('id' => 'zzi_make_table', 'version' => '1.0.0', 'up' => function (\$dbconnector) {\n"
			. "\t\$dbconnector->get_db_link()->exec('CREATE TABLE IF NOT EXISTS $MADE (x int)');\n\treturn true;\n}));\n");
	}
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $p) {
		@chmod($p->getPathname(), $p->isDir() ? 0755 : 0644);
	}
	@chmod($dir, 0755);
	return $dir;
};
$cleanup = function () use ($db, $NAME, $TABLE, $MADE, $VIEW, $LONGER, $queue_dir) {
	$db->exec("DROP TABLE IF EXISTS $LONGER CASCADE");
	$db->prepare("DELETE FROM plg_plugins WHERE plg_name = 'zzsbx'")->execute();
	$db->prepare("DELETE FROM sbi_sandbox_installs WHERE sbi_plugin = 'zzconsent'")->execute();
	harness_remove_tree(PathHelper::getIncludePath('plugins/' . $NAME));
	$db->prepare('DELETE FROM pil_plugin_install_ledger WHERE pil_plugin = ?')->execute(array($NAME));
	$db->prepare('DELETE FROM plm_plugin_migrations WHERE plm_plugin_name = ?')->execute(array($NAME));
	$db->prepare('DELETE FROM plv_plugin_versions WHERE plv_plugin_name = ?')->execute(array($NAME));
	$db->prepare('DELETE FROM plg_plugins WHERE plg_name = ?')->execute(array($NAME));
	$db->prepare("DELETE FROM stg_settings WHERE stg_name LIKE 'zzinstid\\_%'")->execute();
	$db->exec("DROP VIEW IF EXISTS $VIEW");
	$db->exec("DROP TABLE IF EXISTS $TABLE CASCADE");
	$db->exec("DROP TABLE IF EXISTS $MADE CASCADE");
	foreach (glob($queue_dir . '/*.json') ?: array() as $f) {
		$r = json_decode((string)file_get_contents($f), true);
		if (is_array($r) && in_array($NAME, array($r['args']['name'] ?? '', $r['args']['plugin'] ?? ''), true)) {
			@unlink($f);
		}
	}
};
$ledger = function (string $id, string $kind = '') use ($db) {
	$q = $db->prepare('SELECT pil_kind || \' \' || pil_name FROM pil_plugin_install_ledger WHERE pil_install_id = ?' . ($kind !== '' ? ' AND pil_kind = ?' : ''));
	$q->execute($kind !== '' ? array($id, $kind) : array($id));
	return $q->fetchAll(PDO::FETCH_COLUMN);
};
$exists = function (string $rel) use ($db) {
	$q = $db->prepare('SELECT to_regclass(?) IS NOT NULL');
	$q->execute(array($rel));
	return (bool)$q->fetchColumn();
};
$setting_exists = function (string $name) use ($db) {
	$q = $db->prepare('SELECT COUNT(*) FROM stg_settings WHERE stg_name = ?');
	$q->execute(array($name));
	return (int)$q->fetchColumn() > 0;
};
$throws = function (callable $fn) {
	try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage() ?: '(empty)'; }
};

$cleanup();
harness_defer($cleanup);

// ---------------------------------------------------------------------------
section('Install gives the row an id, and the ledger names what it made');

$db->exec("CREATE TABLE $LONGER (zzi_archive_id serial PRIMARY KEY)");
$write_plugin(array($SETTING_OLD, $SETTING), true);
$manager->install($NAME, false);
$row = Plugin::get_by_plugin_name($NAME);
$id1 = (string)$row->get('plg_install_id');
check(preg_match('/^[0-9a-f]{32}$/', $id1) === 1, 'the row has a 32-hex install id', $id1);
check(PluginInstallIdentity::idOf($NAME) === $id1, 'idOf() reads it');
check(in_array("table $TABLE", $ledger($id1), true), 'the ledger names its class table');
check(in_array("table $MADE", $ledger($id1), true) && $exists($MADE), 'and the table its migration made, which no class file names', implode(', ', $ledger($id1)));
check(in_array("setting $SETTING_OLD", $ledger($id1), true), 'and its declared settings');
check(count($ledger($id1, 'migration')) === 1, 'and its migration record');
check(in_array("action $NAME/", $ledger($id1), true) && in_array('model_class ZziFixtureRow', $ledger($id1), true),
	'and its actions and model class, for API key scopes');

$manager->activate($NAME);
check($setting_exists($SETTING_OLD) && $setting_exists($SETTING), 'activation seeds its settings');
check(!(bool)preg_grep('/^sequence ' . $LONGER . '_/', $ledger($id1)), "a longer-named table's sequence is not recorded as its own (R1)",
	implode(', ', $ledger($id1, 'sequence')));

// ---------------------------------------------------------------------------
section('An upgrade keeps the id; a version that stops declaring a setting leaves it in the ledger');

$write_plugin(array($SETTING), true);
$manager->install($NAME, false);
check((string)Plugin::get_by_plugin_name($NAME)->get('plg_install_id') === $id1, 'installing over the installed plugin keeps its id');
check(in_array("setting $SETTING_OLD", $ledger($id1), true), 'the setting it no longer declares is still in the ledger');

// ---------------------------------------------------------------------------
section('A thing another install holds is refused (I12)');

$msg = $throws(function () use ($NAME, $SETTING) {
	PluginInstallIdentity::record(str_repeat('e', 32), 'zzother', 'setting', array($SETTING));
});
check(strpos($msg, "belongs to plugin '$NAME'") !== false, 'recording it under another install names the plugin that holds it', $msg);

// ---------------------------------------------------------------------------
section('A hand-made scoped key is checked at uninstall (A11)');

$owner = make_user('InstIdKeyOwner', 10);
$mixed = make_machine_key((int)$owner->key, 'mixed')['api_key'];
ApiKey::updateColumns((int)$mixed->key, array('apk_scope' => 'ZziFixtureRow:read,' . $NAME . '/do_thing,Location:read'));
$only = make_machine_key((int)$owner->key, 'only')['api_key'];
ApiKey::updateColumns((int)$only->key, array('apk_scope' => 'ZziFixtureRow:read'));
$off = make_machine_key((int)$owner->key, 'off')['api_key'];
ApiKey::updateColumns((int)$off->key, array('apk_scope' => 'ZziFixtureRow:read,Location:read', 'apk_is_active' => false));
// Another sandboxed plugin approved to read the fixture's model (R9).
$db->prepare("INSERT INTO sbi_sandbox_installs (sbi_plugin, sbi_consent) VALUES ('zzconsent', ?)")
	->execute(array(json_encode(array('records' => array('ZziFixtureRow' => array('read'), 'Location' => array('read')), 'actions' => array($NAME . '/do_thing', 'other_action')))));
// A thing a later version handed to core stays core's (R2).
if ($CORE_SETTING !== null) {
	PluginInstallIdentity::record($id1, $NAME, 'setting', array($CORE_SETTING[0]));
}

// ---------------------------------------------------------------------------
section('Uninstall removes everything in the ledger');

$manager->deactivate($NAME);
$request_id = $manager->uninstall($NAME, 4500);
check(!$setting_exists($SETTING_OLD), 'the setting an earlier version declared is removed (A2)');
check(!$setting_exists($SETTING), 'and the one it declares');
check(!$exists($MADE), 'the table its migration made is dropped (A4)');
check(!$exists($TABLE), 'and its class table');
check(count(new MultiPluginMigration(array('plugin_name' => $NAME))) === 0, 'its migration record is removed');
check($ledger($id1) === array(), 'and its ledger is empty', implode(', ', $ledger($id1)));
check((string)Plugin::get_by_plugin_name($NAME)->get('plg_install_id') === $id1, 'the uninstalled row keeps the id the request names');
$mixed = new ApiKey((int)$mixed->key, TRUE);
check($mixed->scope() === array('Location:read'), "a key's entries for the gone plugin's class and actions are removed", (string)$mixed->get('apk_scope'));
$only = new ApiKey((int)$only->key, TRUE);
check(!$only->get('apk_is_active') && $only->scope() === array('-'), 'a key that named nothing else is switched off, its scope reaching nothing (R3)',
	(string)$only->get('apk_scope'));
$off = new ApiKey((int)$off->key, TRUE);
check($off->scope() === array('Location:read'), 'a key already switched off is scrubbed too (R3)', (string)$off->get('apk_scope'));
$consent = json_decode((string)$db->query("SELECT sbi_consent FROM sbi_sandbox_installs WHERE sbi_plugin = 'zzconsent'")->fetchColumn(), true);
check(!isset($consent['records']['ZziFixtureRow']) && isset($consent['records']['Location']) && $consent['actions'] === array('other_action'),
	"another sandboxed plugin's approval loses the gone plugin's model and actions (R9)", json_encode($consent));
check($exists($LONGER) && (bool)$db->query("SELECT pg_get_serial_sequence('$LONGER', 'zzi_archive_id') IS NOT NULL")->fetchColumn(),
	"the longer-named table keeps its sequence (R1)");
if ($CORE_SETTING !== null) {
	$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
	$q->execute(array($CORE_SETTING[0]));
	check($q->fetchColumn() === $CORE_SETTING[1], "a core setting in its ledger survives the uninstall (R2)", $CORE_SETTING[0]);
}

$body = json_decode((string)@file_get_contents($queue_dir . '/' . $request_id . '.json'), true);
check(($body['args']['install_id'] ?? null) === $id1, 'the removal request names the install id');

// ---------------------------------------------------------------------------
section('Root refuses an old request once the row holds another install (A10)');

$plugins_dir = PathHelper::getIncludePath('plugins');
check(PluginRemoval::refusal($NAME, $plugins_dir, Plugin::get_by_plugin_name($NAME), $id1) === '', 'the request for this install may remove the files');
check(PluginRemoval::refusal($NAME, $plugins_dir, Plugin::get_by_plugin_name($NAME), str_repeat('f', 32)) !== '', 'a request naming another install is refused');

// ---------------------------------------------------------------------------
section('A package waits while the earlier plugin of its name is being removed (I9)');

check(PluginInstallIdentity::removalPending($NAME), 'its removal request is still queued');
$why = PluginInstallIdentity::installRefusal($NAME, PathHelper::getIncludePath('plugins/' . $NAME), PathHelper::getIncludePath('plugins/' . $NAME));
check(strpos($why, 'still being removed') !== false, 'a package of that name is refused until it has run', $why);
@unlink($queue_dir . '/' . $request_id . '.json');
check(!PluginInstallIdentity::removalPending($NAME), 'once the request is no longer queued, nothing waits');

// ---------------------------------------------------------------------------
section('What uninstall could not remove is finished by the next install, or refuses it (I1, I8)');

$db->exec("CREATE VIEW $VIEW AS SELECT 1 AS x");
PluginInstallIdentity::record($id1, $NAME, 'table', array($VIEW));      // a "table" DROP TABLE cannot drop
$db->prepare("INSERT INTO stg_settings (stg_name, stg_value) VALUES (?, 'stale')")->execute(array($SETTING_OLD));
PluginInstallIdentity::record($id1, $NAME, 'setting', array($SETTING_OLD));
$msg = $throws(function () use ($manager, $NAME) { $manager->install($NAME, false); });
check(strpos($msg, "table $VIEW") !== false, 'install refuses, naming what the earlier install left', $msg);
check(!$setting_exists($SETTING_OLD), 'what it could remove is gone already');
check($ledger($id1) === array("table $VIEW"), 'and struck from the ledger; the rest stays', implode(', ', $ledger($id1)));
$db->exec("DROP VIEW $VIEW");
$manager->install($NAME, false);
$id2 = (string)Plugin::get_by_plugin_name($NAME)->get('plg_install_id');
check(preg_match('/^[0-9a-f]{32}$/', $id2) === 1 && $id2 !== $id1, 'with it gone, the install goes ahead under a new id');
check($ledger($id1) === array(), 'and the earlier ledger is finished');
check(count(new MultiPluginMigration(array('plugin_name' => $NAME))) === 1 && $exists($MADE),
	'the new install ran its own migration (no record of the earlier one was left to skip it)');

// ---------------------------------------------------------------------------
section('A sandboxed package with another manifest id is a different plugin (D4)');

$scratch = harness_scratch_dir('instid');
$mk = function (string $sub, ?string $id) use ($scratch) {
	$d = $scratch . '/' . $sub;
	@mkdir($d . '/src', 0755, true);
	$m = array('name' => 'zzsbx', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1);
	if ($id !== null) { $m['id'] = $id; }
	file_put_contents($d . '/manifest.json', json_encode($m));
	return $d;
};
$db->exec("INSERT INTO plg_plugins (plg_name, plg_status, plg_active) VALUES ('zzsbx', 'inactive', 0)");
$a = $mk('a', str_repeat('a', 32));
$b = $mk('b', str_repeat('b', 32));
$a2 = $mk('a2', str_repeat('a', 32));
$none = $mk('none', null);
check(strpos(PluginInstallIdentity::installRefusal('zzsbx', $a, $b), 'A different plugin') === 0, 'another id over an installed one is refused');
check(PluginInstallIdentity::installRefusal('zzsbx', $a, $a2) === '', 'the same id is an upgrade');
check(PluginInstallIdentity::installRefusal('zzsbx', $none, $b) === '', 'an installed copy from before ids existed takes the package\'s');
$db->exec("UPDATE plg_plugins SET plg_status = 'uninstalled' WHERE plg_name = 'zzsbx'");
check(PluginInstallIdentity::installRefusal('zzsbx', $a, $b) === '', 'over an uninstalled plugin whose files are still there, the ids are not compared (R4)');
$problems = SandboxManifest::problems(array('name' => 'zzsbx', 'title' => 'x', 'version' => '1.0.0', 'platform' => 1));
check((bool)preg_grep('/^id is required/', $problems), 'a manifest with no id is refused', implode('; ', $problems));

// ---------------------------------------------------------------------------
section('A fresh sandboxed install removes its own leftovers (D6)');

$fx = sandbox_fixture_plugin(array('settings' => array(array('name' => 'tone', 'label' => 'Tone', 'type' => 'text'))));
$sbx = $fx['name'];
$left_table = 'sbx_' . $sbx . '__ghost';
$db->exec("CREATE TABLE $left_table (x int)");
$db->prepare("INSERT INTO stg_settings (stg_name, stg_value) VALUES (?, 'old')")->execute(array('sbx_' . $sbx . '__tone'));
$manager->install($sbx, false);
check(!$exists($left_table), 'a table under its prefix that no ledger holds is dropped');
check(!$setting_exists('sbx_' . $sbx . '__tone'), 'and a setting under its prefix, so it is remade, not inherited');
$sid = (string)PluginInstallIdentity::idOf($sbx);
check(preg_match('/^[0-9a-f]{32}$/', $sid) === 1, 'the sandboxed install has an id');

// ---------------------------------------------------------------------------
section('The backfill gives an installed row with no id one, and stamps what it kept');

Plugin::updateColumns((int)Plugin::get_by_plugin_name($sbx)->key, array('plg_install_id' => null));
$file = new File(NULL);
$file->set('fil_name', 'instid_' . bin2hex(random_bytes(4)) . '.txt');
$file->set('fil_title', 'kept');
$file->set('fil_source', 'sbx_' . $sbx);
$file->set('fil_private', true);
$file->set('fil_usr_user_id', (int)$owner->key);
SystemBase::server_initiated_write(function () use ($file) { $file->save(); });
$log = new SandboxLog(NULL);
$log->set('sbl_plugin', $sbx);
$log->set('sbl_kind', SandboxLog::KIND_TEST);
$log->set('sbl_status', SandboxLog::STATUS_OK);
SystemBase::server_initiated_write(function () use ($log) { $log->save(); });
harness_defer(function () use ($db, $file) {
	$db->prepare('DELETE FROM fil_files WHERE fil_file_id = ?')->execute(array((int)$file->key));
});
PluginInstallIdentity::backfill();
$sid = (string)PluginInstallIdentity::idOf($sbx);
check(preg_match('/^[0-9a-f]{32}$/', $sid) === 1, 'the row gets an id');
check((string)(new File((int)$file->key, TRUE))->get('fil_install_id') === $sid, 'the file it kept carries the id');
check((string)(new SandboxLog((int)$log->key, TRUE))->get('sbl_install_id') === $sid, 'and its call log row');

harness_finish();

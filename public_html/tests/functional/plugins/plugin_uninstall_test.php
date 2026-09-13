<?php
/** @joinery-test
 * name: plugin_uninstall
 * tier: test-db
 * env: dev-only
 * needs: []
 * covers: [public_html/includes/PluginRemoval.php]
 */
/**
 * PluginManager::uninstall() on a read-only tree (specs/post_release_fleet_defects.md B1).
 *
 * The web side cannot delete plugins/<name>, so uninstall keeps the row as the
 * record (`uninstalled`, stamped) and queues a `remove_plugin` root request
 * that the host timer carries out. What this pins, against a fixture plugin
 * written under plugins/ for the run and removed after it:
 *   - uninstall leaves the row `uninstalled` with the stamp set, the directory
 *     untouched, the tables dropped, and exactly one queued remove_plugin request
 *   - an is_system plugin is refused and queues nothing
 *   - sync() after uninstall adds no row and changes no status; the stale marker
 *     leaves the row alone too
 *   - activating an uninstalled plugin is refused
 *   - install after uninstall clears the stamp and the request record
 *   - the installed-plugin lists leave the row out
 *
 * Writes go to the test database; the fixture directory and the request file
 * are removed by the test itself.
 *
 * Run:  php tests/functional/plugins/plugin_uninstall_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();
harness_test_mode();

$manager = PluginManager::getInstance();
$db = DbConnector::get_instance()->get_db_link();
$site_root = PathHelper::getSiteRoot();
$queue_dir = $site_root . '/' . RootRequest::QUEUE_DIR;

$NAME = 'zzuninstallfix';
$SYS  = 'zzuninstallsys';
$TABLE = 'zuf_fixture_rows';

/** Write a fixture plugin under plugins/. */
$write_plugin = function (string $name, array $manifest_extra = array(), bool $with_table = true) use ($TABLE) {
    $dir = PathHelper::getIncludePath('plugins/' . $name);
    @mkdir($dir . '/data', 0777, true);
    $manifest = array_merge(array(
        'name' => 'Uninstall fixture ' . $name,
        'version' => '1.0.0',
        'description' => 'Harness fixture; safe to delete',
    ), $manifest_extra);
    file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT));
    if ($with_table) {
        $class = 'ZufFixtureRow' . ucfirst($name);
        file_put_contents($dir . '/data/' . $name . '_rows_class.php', "<?php\n"
            . "class $class extends SystemBase {\n"
            . "\tpublic static \$prefix = 'zuf';\n"
            . "\tpublic static \$tablename = '$TABLE';\n"
            . "\tpublic static \$pkey_column = 'zuf_row_id';\n"
            . "\tpublic static \$field_specifications = array(\n"
            . "\t\t'zuf_row_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),\n"
            . "\t\t'zuf_label' => array('type'=>'varchar(64)'),\n"
            . "\t);\n"
            . "}\n");
    }
    return $dir;
};
$rm_tree = function (string $dir) {
    if (!is_dir($dir)) { return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST) as $p) {
        $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname());
    }
    @rmdir($dir);
};
$queued_removals = function () use ($queue_dir, $NAME) {
    $ids = array();
    foreach (glob($queue_dir . '/*.json') ?: array() as $f) {
        $r = json_decode((string)file_get_contents($f), true);
        if (is_array($r) && ($r['kind'] ?? '') === 'remove_plugin' && ($r['args']['name'] ?? '') === $NAME) {
            $ids[] = basename($f, '.json');
        }
    }
    return $ids;
};
$drop_queued = function () use ($queue_dir, $queued_removals) {
    // The dev host timer would otherwise pick a fixture request up: root reads
    // the LIVE database, finds no row, and records a failed request.
    foreach ($queued_removals() as $id) { @unlink($queue_dir . '/' . $id . '.json'); }
};
$table_exists = function () use ($db, $TABLE) {
    return (bool)$db->query("SELECT to_regclass('" . $TABLE . "') IS NOT NULL")->fetchColumn();
};
$row_count = function (string $name) use ($db) {
    $q = $db->prepare('SELECT COUNT(*) FROM plg_plugins WHERE plg_name = ?');
    $q->execute(array($name));
    return (int)$q->fetchColumn();
};
$throws = function (callable $fn) {
    try { $fn(); return ''; } catch (Exception $e) { return $e->getMessage() ?: '(empty)'; }
};

// Nothing of an earlier run may be lying about.
foreach (array($NAME, $SYS) as $n) {
    $rm_tree(PathHelper::getIncludePath('plugins/' . $n));
    $db->prepare('DELETE FROM plg_plugins WHERE plg_name = ?')->execute(array($n));
}
$db->exec("DROP TABLE IF EXISTS $TABLE CASCADE");
$drop_queued();

harness_defer(function () use ($rm_tree, $db, $NAME, $SYS, $TABLE, $drop_queued) {
    foreach (array($NAME, $SYS) as $n) {
        $rm_tree(PathHelper::getIncludePath('plugins/' . $n));
        $db->prepare('DELETE FROM plg_plugins WHERE plg_name = ?')->execute(array($n));
    }
    $db->exec("DROP TABLE IF EXISTS $TABLE CASCADE");
    $drop_queued();
});

// ---------------------------------------------------------------------------
section('Install the fixture');

$dir = $write_plugin($NAME);
$manager->install($NAME, false);
$plugin = Plugin::get_by_plugin_name($NAME);
check($plugin !== null && $plugin->get('plg_status') === 'inactive', 'the fixture installs inactive');
check($table_exists(), 'and its table exists');
check($plugin->get('plg_uninstalled_time') === null, 'with no uninstalled stamp');

// ---------------------------------------------------------------------------
section('Uninstall keeps the row, drops the data, leaves the files to root');

$request_id = $manager->uninstall($NAME, 4500);
$plugin = Plugin::get_by_plugin_name($NAME);
check($plugin !== null, 'the row is still there');
check($plugin->is_uninstalled() && $plugin->get('plg_status') === Plugin::STATUS_UNINSTALLED, 'in the uninstalled state');
check($plugin->get('plg_uninstalled_time') !== null, 'with the stamp set', (string)$plugin->get('plg_uninstalled_time'));
check((int)$plugin->get('plg_active') === 0, 'and the active flag cleared');
check(!$table_exists(), 'the table is dropped');
check(is_dir($dir) && is_file($dir . '/plugin.json'), 'the directory is untouched (root removes it)');
$queued = $queued_removals();
check(count($queued) === 1, 'exactly one remove_plugin request is queued', implode(',', $queued));
check($request_id !== '' && $queued === array($request_id), 'and uninstall() returned its id');
check($plugin->get_remove_request_id() === $request_id, 'the row records the request id');
$body = json_decode((string)file_get_contents($queue_dir . '/' . $request_id . '.json'), true);
check(($body['args'] ?? null) === array('name' => $NAME) && ($body['requested_by'] ?? 0) === 4500,
    'the request carries the name and who asked, nothing else', json_encode($body['args'] ?? null));
check(!$plugin->is_active(), 'is_active() is false');
$drop_queued();

// ---------------------------------------------------------------------------
section('Installed-plugin lists leave the record out');

$listed = MultiPlugin::get_all_plugins_with_status();
$entry = null;
foreach ($listed as $e) { if ($e['name'] === $NAME) { $entry = $e; } }
check($entry !== null && $entry['directory_exists'] && $entry['plugin']->is_uninstalled(),
    'the plugins page still lists it, as uninstalled, while the directory exists');
check(strpos($entry['status_badge'], 'Uninstalled') !== false, 'with an Uninstalled badge', $entry['status_badge']);
check(in_array($NAME, MarketplaceClient::local_names('plugin'), true),
    'the marketplace counts it present while its directory is still on disk');

$version_handler = PathHelper::getIncludePath('includes/management_api/version_handler.php');
require_once($version_handler);
$versions = version_handler(array());
check(!array_key_exists($NAME, $versions['plugin_versions']), 'the management API lists no version for it');

$refused = $throws(function () use ($manager, $NAME) { $manager->activate($NAME); });
check(strpos($refused, 'uninstalled') !== false, 'activating it is refused', $refused);

// ---------------------------------------------------------------------------
section('sync() after uninstall adds nothing and changes nothing');

$before = $row_count($NAME);
$base_sync = new ReflectionMethod('AbstractExtensionManager', 'sync');
$result = $base_sync->invoke($manager, array());
check(!in_array($NAME, $result['added'], true), 'the directory still on disk registers no new row');
check($row_count($NAME) === $before && $before === 1, 'one row, as before');
$plugin = Plugin::get_by_plugin_name($NAME);
check($plugin->is_uninstalled(), 'and its status is unchanged');
check($plugin->get_remove_request_id() === $request_id, 'the metadata refresh kept the request record');

$mark = new ReflectionMethod('AbstractExtensionManager', 'markStaleAgainstManifest');
$mark->invoke($manager, array('some_other_plugin'));
$plugin = Plugin::get_by_plugin_name($NAME);
check($plugin->is_uninstalled(), 'the stale marker leaves an uninstalled row alone');

// Once the directory is gone the row is the record.
$rm_tree($dir);
$result = $base_sync->invoke($manager, array());
check($row_count($NAME) === 1 && Plugin::get_by_plugin_name($NAME)->is_uninstalled(),
    'with the directory gone, sync has nothing to do and the record stays');
check(!in_array($NAME, MarketplaceClient::local_names('plugin'), true),
    'the marketplace no longer counts it present');
$listed = MultiPlugin::get_all_plugins_with_status();
$entry = null;
foreach ($listed as $e) { if ($e['name'] === $NAME) { $entry = $e; } }
check($entry !== null && !$entry['directory_exists'] && strpos($entry['status_badge'], 'Uninstalled') !== false
    && strpos($entry['status_badge'], 'Missing') === false,
    'the plugins page shows the record as Uninstalled, not Missing', $entry['status_badge'] ?? '(absent)');

// ---------------------------------------------------------------------------
section('Install after uninstall clears the record');

$write_plugin($NAME);
$manager->install($NAME, false);
$plugin = Plugin::get_by_plugin_name($NAME);
check($plugin->get('plg_status') === 'inactive', 'the row is inactive again');
check($plugin->get('plg_uninstalled_time') === null, 'the stamp is cleared');
check($plugin->get_remove_request_id() === '', 'and so is the request record');
check($table_exists(), 'the table is back');
check($row_count($NAME) === 1, 'still one row');

// Uninstall once more with the directory already gone: nothing to queue.
$manager->uninstall($NAME);
$drop_queued();
$rm_tree(PathHelper::getIncludePath('plugins/' . $NAME));
$second = $manager->uninstall($NAME);
check($second === '' && $queued_removals() === array(), 'uninstalling with no directory queues no request');

// ---------------------------------------------------------------------------
section('An is_system plugin cannot be uninstalled');

$sys_dir = $write_plugin($SYS, array('is_system' => true), false);
$manager->install($SYS, false);
$sys = Plugin::get_by_plugin_name($SYS);
check($sys !== null && (bool)$sys->get('plg_is_system'), 'the fixture installs with plg_is_system from its manifest');
$before_queue = count(glob($queue_dir . '/*.json') ?: array());
$refused = $throws(function () use ($manager, $SYS) { $manager->uninstall($SYS); });
check(strpos($refused, 'is_system') !== false, 'uninstall is refused naming is_system', $refused);
$sys = Plugin::get_by_plugin_name($SYS);
check($sys->get('plg_status') === 'inactive' && $sys->get('plg_uninstalled_time') === null, 'the row is untouched');
check(count(glob($queue_dir . '/*.json') ?: array()) === $before_queue, 'and nothing was queued');
check(is_dir($sys_dir), 'the directory is untouched');

// The row flag alone is enough, even with a manifest that no longer says so.
file_put_contents($sys_dir . '/plugin.json', json_encode(array('name' => 'Sys', 'version' => '1.0.0')));
$refused = $throws(function () use ($manager, $SYS) { $manager->uninstall($SYS); });
check(strpos($refused, 'is_system') !== false, 'a row flagged is_system is refused whatever the manifest says now', $refused);

harness_finish();

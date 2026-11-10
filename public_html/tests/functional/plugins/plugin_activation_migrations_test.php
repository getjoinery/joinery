<?php
/** @joinery-test
 * name: plugin_activation_migrations
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Activating a plugin runs its pending migrations.
 *
 * sync() runs migrations only for active plugins, so without this a migration
 * shipped while a plugin was off, or one for a plugin the upgrade itself turns
 * on, waited for the next deploy.
 *
 * What is pinned here, on a fixture plugin written under plugins/ for the run:
 *  - a migration added while the plugin is off is applied when it is activated,
 *    and lastActivationMigrations() names it;
 *  - a migration whose up function returns false (no error text) stops the
 *    activation: the plugin stays off and the migration is not recorded applied.
 *
 * Run: php tests/run.php db --filter=plugin_activation_migrations
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();
harness_test_mode();

$manager = PluginManager::getInstance();
$db = DbConnector::get_instance()->get_db_link();
$NAME = 'zzactmigfix';
$dir = PathHelper::getIncludePath('plugins/' . $NAME);

$rm_tree = function (string $dir) {
	if (!is_dir($dir)) { return; }
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST) as $p) {
		$p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname());
	}
	@rmdir($dir);
};
$clean = function () use ($rm_tree, $db, $NAME, $dir) {
	$rm_tree($dir);
	$db->prepare('DELETE FROM plm_plugin_migrations WHERE plm_plugin_name = ?')->execute(array($NAME));
	$db->prepare('DELETE FROM plg_plugins WHERE plg_name = ?')->execute(array($NAME));
};
// The tree's modes (755/644): a group-writable directory in the live tree is
// what the publish gate refuses.
$write = function (string $path, string $content) {
	@mkdir(dirname($path), 0755, true);
	@chmod(dirname($path), 0755);
	file_put_contents($path, $content);
	@chmod($path, 0644);
};
$migrations = function (array $ups) use ($write, $dir) {
	$php = "<?php\nreturn [\n";
	foreach ($ups as $id => $returns) {
		$php .= "\t['id' => '$id', 'version' => '1.0.0', 'up' => function (\$dbconnector) { return $returns; }],\n";
	}
	$write($dir . '/migrations/migrations.php', $php . "];\n");
};
$applied = function (string $id) use ($db, $NAME): bool {
	$q = $db->prepare("SELECT 1 FROM plm_plugin_migrations WHERE plm_plugin_name = ? AND plm_migration_id = ? AND plm_status = 'applied'");
	$q->execute(array($NAME, $id));
	return (bool)$q->fetchColumn();
};

$clean();
harness_defer($clean);

$write($dir . '/plugin.json', json_encode(array(
	'name' => 'Activation migrations fixture', 'version' => '1.0.0', 'description' => 'Harness fixture; safe to delete',
), JSON_PRETTY_PRINT));
$manager->install($NAME, false);
if (PluginHelper::isPluginActive($NAME)) {
	$manager->deactivate($NAME);
}
check(!PluginHelper::isPluginActive($NAME), 'the fixture is installed and off');

section('A migration added while the plugin was off runs at activation');
$migrations(array('zz_001_ok' => 'true'));
$manager->activate($NAME);
check(PluginHelper::isPluginActive($NAME), 'the plugin is on');
check($applied('zz_001_ok'), 'its migration is recorded applied');
check(array_column($manager->lastActivationMigrations(), 'id') === array('zz_001_ok'), 'and lastActivationMigrations() names it',
	json_encode($manager->lastActivationMigrations()));

section('A migration that returns false stops the activation');
$manager->deactivate($NAME);
$migrations(array('zz_001_ok' => 'true', 'zz_002_false' => 'false'));
$error = '';
try {
	$manager->activate($NAME);
} catch (Exception $e) {
	$error = $e->getMessage();
}
check(strpos($error, 'zz_002_false') !== false, 'activation throws, naming the migration', $error);
check(!PluginHelper::isPluginActive($NAME), 'the plugin stays off');
check(!$applied('zz_002_false'), 'the migration is not recorded applied');
check($manager->lastActivationMigrations() === array(), 'and nothing is reported applied');

harness_finish();

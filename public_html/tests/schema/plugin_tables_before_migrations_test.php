<?php
/** @joinery-test
 * name: plugin_tables_before_migrations
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * update_database adds plugin tables and columns BEFORE it runs migrations.
 *
 * Plugin schema used to land only in the Plugin & Theme Sync step at the very
 * end, so a migration that read or wrote a new plugin column failed on its first
 * run ("column does not exist") and passed on the second. That is an ordering
 * fault dressed as a flaky migration. The fix is a pass over active plugins'
 * data classes — tables created, columns added, nothing else — placed before
 * the migrations step. This test reads the pipeline and the pass to pin both.
 *
 * Run: php tests/run.php safe --filter=plugin_tables_before_migrations
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginManager.php'));

section('The pipeline order');
$src = file_get_contents(PathHelper::getIncludePath('utils/update_database.php'));
$core_tables_at = strpos($src, '$database_updater->runCoreTablesOnly()');
$plugin_pass_at = strpos($src, 'PluginManager::getInstance()->syncTables()');
$migrations_at  = strpos($src, '"-----MIGRATIONS-----<br>\n"');
$full_sync_at   = strpos($src, '$plugin_manager->sync()');
check($core_tables_at !== false && $plugin_pass_at !== false && $migrations_at !== false && $full_sync_at !== false,
	'all four steps are present in update_database.php');
check($core_tables_at < $plugin_pass_at, 'core tables are created before the plugin table pass (plugin tables may reference core ones)');
check($plugin_pass_at < $migrations_at, 'the plugin table pass runs BEFORE migrations');
check($migrations_at < $full_sync_at, 'the full plugin sync still runs after migrations');

section('The pass is additive only');
check(method_exists('PluginManager', 'syncTables'), 'PluginManager::syncTables() exists');
$m = new ReflectionMethod('PluginManager', 'syncTables');
check($m->isPublic() && !$m->isStatic(), 'it is a public instance method, like sync()');
$pm_src = file_get_contents(PathHelper::getIncludePath('includes/PluginManager.php'));
$start = strpos($pm_src, 'public function syncTables()');
$end   = strpos($pm_src, 'public function sync(array $options', $start);
$body  = substr($pm_src, $start, $end - $start);
check(strpos($body, 'runPluginTablesOnly(') !== false, 'it creates tables and adds columns via runPluginTablesOnly()');
foreach (array('runPendingMigrations', 'manageUniqueConstraints', 'manageIndexes', 'manageForeignKeys',
               'processAdvancedColumnOperations', 'syncMenus', 'syncSettings') as $not_here) {
	check(strpos($body, $not_here . '(') === false, "it does not do {$not_here} — that stays in the full sync");
}
check(strpos($body, "new DatabaseUpdater(false, true /* upgrade */, false)") !== false,
	'upgrade=true / cleanup=false: nothing is ever dropped by this pass');

harness_finish();

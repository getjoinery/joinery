<?php
/** @joinery-test
 * name: plugin_tables_before_migrations
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [public_html/utils/update_database.php, public_html/includes/PluginManager.php]
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
 * update_database.php has two passes that make tables: the full run
 * (update_database()) and the additive one upgrade.php runs from the staged
 * tree before the swap (update_database_schema_ahead()). Each is read on its
 * own, from its own function body: an order taken from the first occurrence
 * anywhere in the file compares one pass's step with the other's.
 *
 * Run: php tests/run.php safe --filter=plugin_tables_before_migrations
 *
 * @version 1.2 - pins the second index pass after migrations (Step 4.7)
 * @version 1.1 - the order is checked inside each pass's own function, and the schema-ahead pass is held to
 *                core tables before plugin tables with no migration or full sync; covers names the two files it
 *                reads as text, so an edit to either runs it
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginManager.php'));

section('The pipeline order');
$src = file_get_contents(PathHelper::getIncludePath('utils/update_database.php'));
// One function's body: from its signature to the next top-level function, or
// to the end of the file's function definitions.
$body_of = function ($signature) use ($src) {
	$start = strpos($src, $signature);
	if ($start === false) {
		return '';
	}
	$next = strpos($src, "\n\tfunction ", $start + strlen($signature));
	$end = strpos($src, '// Run the database update if not included', $start);
	$stop = ($next !== false && ($end === false || $next < $end)) ? $next : $end;
	return substr($src, $start, ($stop === false ? strlen($src) : $stop) - $start);
};
$full = $body_of('function update_database($verbose');
$core_tables_at = strpos($full, '->runCoreTablesOnly()');
$plugin_pass_at = strpos($full, 'PluginManager::getInstance()->syncTables()');
$migrations_at  = strpos($full, '"-----MIGRATIONS-----<br>\n"');
$full_sync_at   = strpos($full, '$plugin_manager->sync()');
check($core_tables_at !== false && $plugin_pass_at !== false && $migrations_at !== false && $full_sync_at !== false,
	'all four steps are present in update_database()');
check($core_tables_at < $plugin_pass_at, 'core tables are created before the plugin table pass (plugin tables may reference core ones)');
check($plugin_pass_at < $migrations_at, 'the plugin table pass runs BEFORE migrations');
check($migrations_at < $full_sync_at, 'the full plugin sync still runs after migrations');
$index_retry_at = strpos($full, '"-----INDEXES (after migrations)-----<br>\n"');
$foreign_keys_at = strpos($full, '"-----FOREIGN KEYS-----<br>\n"');
check($index_retry_at !== false && $migrations_at < $index_retry_at && $index_retry_at < $foreign_keys_at,
	'a unique index the data refused gets its second try after migrations (which fix such data), before foreign keys');

section('The schema-ahead pass: the same order, and nothing but tables and columns');
$ahead = $body_of('function update_database_schema_ahead(');
$ahead_core = strpos($ahead, '->runCoreTablesOnly()');
$ahead_plugins = strpos($ahead, 'PluginManager::getInstance()->syncTables()');
check($ahead !== '' && $ahead_core !== false && $ahead_plugins !== false && $ahead_core < $ahead_plugins,
	'it creates core tables before the plugin table pass');
foreach (array('-----MIGRATIONS-----', '->sync()', 'runPendingMigrations', 'fixPrimaryKeys', 'manageUniqueConstraints',
               'manageIndexes', 'manageForeignKeys', 'processAdvancedColumnOperations') as $not_here) {
	check(strpos($ahead, $not_here) === false, "it does not do {$not_here}");
}
check(strpos($ahead, 'new DatabaseUpdater(false, false, false)') !== false,
	'its updater is neither upgrade nor cleanup: nothing changes type and nothing is dropped');

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

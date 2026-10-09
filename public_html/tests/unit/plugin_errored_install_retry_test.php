<?php
/** @joinery-test
 * name: plugin_errored_install_retry
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * A plugin left in 'error' by a failed install is retried when its files change
 * (PluginManager::retryErroredPlugins, called from sync()), instead of waiting
 * for someone to press Repair after the fix that cures it has shipped.
 *
 * Uses the items plugin, which is installed on dev. Runs in the test database.
 *
 * Run: php tests/unit/plugin_errored_install_retry_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$manager = PluginManager::getInstance();
$plugin = Plugin::get_by_plugin_name('items');
if (!$plugin) {
	harness_skip('the items plugin has no row in the test database');
	harness_finish();
}

section('An errored plugin is retried only when its files changed');

$plugin->set('plg_status', 'error');
$plugin->set('plg_install_error', 'Plugin migration failed: Class "X" not found');
$plugin->save();

$none = $manager->retryErroredPlugins(array());
check($none === array(), 'nothing changed, nothing retried');
$still = Plugin::get_by_plugin_name('items');
check($still->get('plg_status') === 'error', 'an errored plugin whose files did not change stays errored');

$out = $manager->retryErroredPlugins(array('items'));
check(isset($out['items']), 'a changed errored plugin is retried');
$after = Plugin::get_by_plugin_name('items');
if (($out['items'] ?? '') === 'installed') {
	check($after->get('plg_status') !== 'error', 'a retry that installs clears the error');
	check(!$after->get('plg_install_error'), 'and the saved message is gone');
	check(!$after->is_active(), 'and activation stays with a person');
} else {
	check($after->get('plg_status') === 'error', 'a retry that fails again keeps the plugin errored (' . substr((string)$out['items'], 0, 160) . ')');
	check((string)$after->get('plg_install_error') !== '', 'and records the newest message');
}

section('A plugin that is not errored is left alone');

$after->set('plg_status', 'inactive');
$after->set('plg_install_error', null);
$after->save();
$before_time = $after->get('plg_update_time');
$skip = $manager->retryErroredPlugins(array('items'));
check($skip === array(), 'a changed plugin that is not in error is not retried');

section('A name with no row is skipped, not fatal');
check($manager->retryErroredPlugins(array('no_such_plugin_xyz')) === array(), 'an unknown name is ignored');

harness_finish();

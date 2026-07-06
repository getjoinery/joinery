<?php
/**
 * Menu probe for the Phase 3 gate (phase3_gate.sh): proves a plugin
 * profileMenu entry reaches shipped apps with no release.
 *
 * `add` syncs the mailbox plugin's declared menus PLUS a probe entry
 * into the menu store (no plugin.json edit — the declared array is passed
 * explicitly). `remove` re-syncs straight from plugin.json; the prune step
 * drops the probe because it is in the plugin's recorded slugs but no
 * longer declared.
 *
 * Usage:
 *   php menu_probe.php add
 *   php menu_probe.php remove
 *
 * Prints the probe slug on add.
 *
 * @version 1.0.0
 */

require_once('/var/www/html/joinerytest/public_html/tests/functional/api/api_test_harness.php');
harness_require_debug_mode();

require_once(PathHelper::getIncludePath('includes/PluginManager.php'));

const PROBE_SLUG = 'mailbox-phase3-probe';
const PLUGIN = 'mailbox';

$manager = PluginManager::getInstance();
$cmd = $argv[1] ?? '';

if ($cmd === 'add') {
	$helper = PluginHelper::getInstance(PLUGIN);
	$declared = array(
		'admin'   => $helper->getAdminMenuItems(),
		'profile' => array_merge($helper->getProfileMenuItems(), array(array(
			'slug'       => PROBE_SLUG,
			'title'      => 'Phase3 Probe',
			'url'        => '/profile/mailbox/mailbox',
			'order'      => 999,
			'permission' => 0,
			'icon'       => 'envelope',
		))),
	);
	$manager->syncMenus(PLUGIN, $declared);
	echo PROBE_SLUG . "\n";
} elseif ($cmd === 'remove') {
	// Truth from plugin.json; prune removes the probe slug.
	$manager->syncMenus(PLUGIN);
} else {
	fwrite(STDERR, "usage: php menu_probe.php add|remove\n");
	exit(1);
}
?>

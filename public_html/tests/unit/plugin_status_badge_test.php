<?php
/** @joinery-test
 * name: plugin_status_badge
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The Plugins page badge says Active or Inactive from plg_active, the flag the
 * loaders and the row's own actions read. A plugin whose install failed and was
 * then repaired ran with plg_status still 'inactive', and the page said Inactive
 * beside a Deactivate action (wp5-mgr2, 2026-10-09).
 *
 * Run: php tests/unit/plugin_status_badge_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$badge = function ($status, $active, $error = null) {
	$p = new Plugin(NULL);
	$p->set('plg_name', 'badge_fixture');
	$p->set('plg_status', $status);
	$p->set('plg_active', $active);
	$p->set('plg_install_error', $error);
	return strip_tags($p->get_status_badge());
};

section('Active or Inactive follows plg_active');

check($badge('inactive', 1) === 'Active', 'switched on with a stale inactive status reads Active', $badge('inactive', 1));
check($badge('active', 0) === 'Inactive', 'switched off with a stale active status reads Inactive', $badge('active', 0));
check($badge('active', 1) === 'Active', 'switched on and active reads Active');
check($badge('installed', 0) === 'Installed', 'installed and not switched on reads Installed');
check($badge('inactive', 0) === 'Inactive', 'switched off reads Inactive');

section('An error and an uninstall still say so');

check($badge('inactive', 1, 'Plugin migration failed') === 'Error', 'an install error reads Error, whatever the flag');
check(strpos($badge(Plugin::STATUS_UNINSTALLED, 0), 'Uninstalled') === 0, 'an uninstalled plugin reads Uninstalled');

harness_finish();

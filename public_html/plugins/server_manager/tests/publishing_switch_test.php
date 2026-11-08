<?php
/** @joinery-test
 * name: publishing_switch
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * Whether a Server Manager site publishes releases (DeploymentHelper::
 * isUpgradeServer(), SmPublishing):
 *
 *   - with the setting off and nothing published, it is a management node for
 *     its own sites only: no upgrade server, no publishing, and the migration
 *     that turns the setting on leaves it off
 *   - a site that has published a release is an upgrade server, setting or not
 *   - the setting on makes any site one
 *
 * Release rows go to the test database and are deleted.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/publishing_switch_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('migrations/upgrade_server_active_from_published.php'));

$db = DbConnector::get_instance()->get_db_link();
$db->exec("DELETE FROM upg_upgrades WHERE upg_major_version = 96");
$others = (int)$db->query('SELECT count(*) FROM upg_upgrades')->fetchColumn();

check(PluginHelper::isPluginActive('server_manager'), 'Server Manager is active on the test database');
harness_set_setting_mem('upgrade_server_active', '0');

// ---------------------------------------------------------------------------
section('Setting off, nothing published: a management node only');

if ($others === 0) {
	check(DeploymentHelper::isUpgradeServer() === false, 'not an upgrade server');
	check(SmPublishing::enabled() === false, 'publishing is off');
	check(DeploymentHelper::servedVersion() === '', 'it serves no release');
	ob_start();
	upgrade_server_active_from_published();
	$said = ob_get_clean();
	check($said === '', 'the migration leaves the setting off', $said);
	check(strpos(SmPublishing::off_text(), 'This deployment publishes upgrades') !== false,
		'the refusal names the setting that turns publishing on');
} else {
	harness_skip('setting off, nothing published', "the test database holds {$others} release rows another suite left");
}

// ---------------------------------------------------------------------------
section('Setting off, a release published: an upgrade server');

$u = new Upgrade(NULL);
$u->set('upg_major_version', 96);
$u->set('upg_minor_version', 0);
$u->set('upg_patch_version', 1);
$u->set('upg_name', 'joinery-core-96.0.1.tar.gz');
$u->prepare();
$u->save();
harness_defer(function () use ($db) { $db->exec("DELETE FROM upg_upgrades WHERE upg_major_version = 96"); });

check(DeploymentHelper::isUpgradeServer() === true, 'an upgrade server');
check(SmPublishing::enabled() === true, 'publishing is on');

// ---------------------------------------------------------------------------
section('Setting on: an upgrade server whatever it has published');

$db->exec("DELETE FROM upg_upgrades WHERE upg_major_version = 96");
harness_set_setting_mem('upgrade_server_active', '1');
check(DeploymentHelper::isUpgradeServer() === true, 'an upgrade server');
check(SmPublishing::enabled() === true, 'publishing is on');

harness_finish();

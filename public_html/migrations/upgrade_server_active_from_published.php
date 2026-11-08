<?php
/**
 * A site that publishes releases says so with upgrade_server_active. A site
 * running Server Manager that has published at least one release is serving
 * them already, so the setting is turned on there; the Upgrades and Staged
 * Rollout menu items follow it. A Server Manager site that has never
 * published is left as it is, off.
 *
 * Idempotent: a site with the setting on, without Server Manager, or with no
 * release is left alone.
 */
function upgrade_server_active_from_published() {
	$settings = Globalvars::get_instance();
	if ($settings->get_setting('upgrade_server_active')) {
		return true;
	}
	if (!PluginHelper::isPluginActive('server_manager')) {
		return true;
	}
	$q = DbConnector::get_instance()->get_db_link()->query('SELECT 1 FROM upg_upgrades LIMIT 1');
	if ($q === false || $q->fetchColumn() === false) {
		return true;
	}
	Setting::put('upgrade_server_active', '1');
	echo "  This site has published releases: turned on upgrade_server_active.\n";
	return true;
}
?>

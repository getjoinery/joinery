<?php
/**
 * Server Manager Plugin - Uninstall
 *
 * Removes plugin settings and admin menu entries.
 * Tables are dropped by the plugin system automatically.
 *
 * @version 1.0
 */
function server_manager_uninstall() {
	try {
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();

		// Remove plugin-specific settings
		$sql = "DELETE FROM stg_settings WHERE stg_name LIKE 'server_manager_%'";
		$q = $dblink->prepare($sql);
		$q->execute();

		// Clean up admin menu entries
		$sql = "DELETE FROM amu_admin_menus WHERE amu_defaultpage LIKE '/plugins/server_manager/%' OR amu_defaultpage LIKE '/admin/server_manager%'";
		$q = $dblink->prepare($sql);
		$q->execute();

		return true;
	} catch (Exception $e) {
		error_log("Server Manager plugin uninstall failed: " . $e->getMessage());
		return false;
	}
}
?>

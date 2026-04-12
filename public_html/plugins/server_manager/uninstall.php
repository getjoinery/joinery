<?php
/**
 * Server Manager Plugin - Uninstall
 *
 * Removes plugin settings.
 * Admin menus are removed automatically by the declarative menu system.
 * Tables are dropped by the plugin system automatically.
 *
 * @version 1.1
 */
function server_manager_uninstall() {
	try {
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();

		// Remove plugin-specific settings
		$sql = "DELETE FROM stg_settings WHERE stg_name LIKE 'server_manager_%'";
		$q = $dblink->prepare($sql);
		$q->execute();

		return true;
	} catch (Exception $e) {
		error_log("Server Manager plugin uninstall failed: " . $e->getMessage());
		return false;
	}
}
?>

<?php
/**
 * Email Forwarding Plugin - Uninstall Script
 *
 * @version 1.0
 */

function email_forwarding_uninstall() {
	try {
		$db = DbConnector::get_instance()->get_db_link();

		// Drop tables in dependency order
		$db->exec("DROP TABLE IF EXISTS efl_email_forwarding_logs CASCADE");
		$db->exec("DROP TABLE IF EXISTS efa_email_forwarding_aliases CASCADE");
		$db->exec("DROP TABLE IF EXISTS efd_email_forwarding_domains CASCADE");

		// Drop orphaned sequences (CASCADE doesn't always clean these up)
		$db->exec("DROP SEQUENCE IF EXISTS efl_email_forwarding_logs_efl_email_forwarding_log_id_seq CASCADE");
		$db->exec("DROP SEQUENCE IF EXISTS efa_email_forwarding_aliases_efa_email_forwarding_alias_id_seq CASCADE");
		$db->exec("DROP SEQUENCE IF EXISTS efd_email_forwarding_domains_efd_email_forwarding_domain_id_seq CASCADE");

		// Remove settings
		$db->exec("DELETE FROM stg_settings WHERE stg_name LIKE 'email_forwarding_%'");

		// Remove admin menu entry
		$db->exec("DELETE FROM amu_admin_menus WHERE amu_slug = 'incoming' AND amu_setting_activate = 'email_forwarding_enabled'");

		// Remove migration records
		$db->exec("DELETE FROM plm_plugin_migrations WHERE plm_plugin_name = 'email_forwarding'");

		return true;
	} catch (Exception $e) {
		error_log("Email Forwarding uninstall failed: " . $e->getMessage());
		return false;
	}
}
?>

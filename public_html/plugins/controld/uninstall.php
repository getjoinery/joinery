<?php
/**
 * Uninstall function for controld plugin
 * This function will be called when the plugin is uninstalled
 * @return bool True on success, false on failure
 */
function controld_uninstall() {
    try {
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        // Drop all ControlD tables in dependency order
        // Based on the data classes found in the plugin
        $tables_to_drop = [
            'cds_ctldservices',         // Services
            'cdb_ctlddevice_backups',   // Device backups
            'cdr_ctldrules',           // Rules
            'cdf_ctldfilters',         // Filters
            'cdd_ctlddevices',         // Devices
            'cdp_ctldprofiles',        // Profiles
            'cda_ctldaccounts'         // Accounts (parent)
        ];
        
        foreach ($tables_to_drop as $table) {
            $sql = "DROP TABLE IF EXISTS {$table} CASCADE";
            $q = $dblink->prepare($sql);
            $q->execute();
        }
        
        // Remove all plugin settings using the naming convention
        $sql = "DELETE FROM stg_settings WHERE stg_name LIKE 'controld_%'";
        $q = $dblink->prepare($sql);
        $q->execute();
        
        // Remove admin menu entries
        $sql = "DELETE FROM amu_admin_menus WHERE amu_defaultpage LIKE '/plugins/controld/%'";
        $q = $dblink->prepare($sql);
        $q->execute();
        
        return true;
        
    } catch (Exception $e) {
        error_log("ControlD plugin uninstall failed: " . $e->getMessage());
        return false;
    }
}
?>
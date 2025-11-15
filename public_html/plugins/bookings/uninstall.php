<?php
/**
 * Uninstall function for bookings plugin
 * This function will be called when the plugin is uninstalled
 * @return bool True on success, false on failure
 */
function bookings_uninstall() {
    try {
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        // Drop in reverse dependency order to handle foreign key constraints
        $tables_to_drop = [
            'bkn_bookings',      // Child table first (references booking types)
            'bkt_booking_types'  // Parent table second
        ];
        
        foreach ($tables_to_drop as $table) {
            $sql = "DROP TABLE IF EXISTS {$table} CASCADE";
            $q = $dblink->prepare($sql);
            $q->execute();
        }
        
        // Remove plugin-specific settings
        $sql = "DELETE FROM stg_settings WHERE stg_name LIKE 'bookings_%'";
        $q = $dblink->prepare($sql);
        $q->execute();
        
        // Clean up any admin menu entries (if plugin added any)
        $sql = "DELETE FROM amu_admin_menus WHERE amu_defaultpage LIKE '/plugins/bookings/%'";
        $q = $dblink->prepare($sql);
        $q->execute();
        
        return true;
        
    } catch (Exception $e) {
        error_log("Bookings plugin uninstall failed: " . $e->getMessage());
        return false;
    }
}
?>
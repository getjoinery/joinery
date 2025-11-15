<?php
/**
 * Uninstall function for items plugin
 * This function will be called when the plugin is uninstalled
 * @return bool True on success, false on failure
 */
function items_uninstall() {
    try {
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        // Drop in reverse dependency order to handle foreign key constraints
        $tables_to_drop = [
            'itr_item_relations',    // Child table first
            'itm_items',            // Parent table second
            'itt_item_relation_types' // Reference table last
        ];
        
        foreach ($tables_to_drop as $table) {
            $sql = "DROP TABLE IF EXISTS {$table} CASCADE";
            $q = $dblink->prepare($sql);
            $q->execute();
        }
        
        // Remove plugin-specific settings
        $sql = "DELETE FROM stg_settings WHERE stg_name LIKE 'items_%'";
        $q = $dblink->prepare($sql);
        $q->execute();
        
        // Clean up any admin menu entries (if plugin added any)
        $sql = "DELETE FROM amu_admin_menus WHERE amu_defaultpage LIKE '/plugins/items/%'";
        $q = $dblink->prepare($sql);
        $q->execute();
        
        return true;
        
    } catch (Exception $e) {
        error_log("Items plugin uninstall failed: " . $e->getMessage());
        return false;
    }
}
?>
<?php
/**
 * Items Plugin Migrations
 * 
 * This file defines database migrations for the items plugin.
 * Tables are created automatically from data class field specifications.
 * Migrations are only for settings, initial data, indexes, and configuration.
 */

return [
    [
        'id' => '001_items_initial_setup',
        'version' => '1.0.0',
        'description' => 'Initial items system setup and default data',
        'up' => function($dbconnector) {
            // Tables are created automatically from Item, ItemRelationType, and ItemRelation data classes
            // This migration only handles settings, initial data, indexes, etc.
            
            $dblink = $dbconnector->get_db_link();

            // Plugin settings (if any) are declared in plugin.json and seeded automatically.

            // Add default item relation types (if needed)
            // Note: Check if these already exist to avoid duplicates
            $check_sql = "SELECT COUNT(*) as count FROM itt_item_relation_types WHERE itt_name = 'Contains'";
            $check_q = $dblink->prepare($check_sql);
            $check_q->execute();
            $result = $check_q->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0) {
                $sql = "INSERT INTO itt_item_relation_types (itt_name) VALUES ('Contains')";
                $q = $dblink->prepare($sql);
                $q->execute();
                
                $sql = "INSERT INTO itt_item_relation_types (itt_name) VALUES ('Related To')";
                $q = $dblink->prepare($sql);
                $q->execute();
            }
            
            return true;
        },
        'down' => function($dbconnector) {
            $dblink = $dbconnector->get_db_link();

            // Settings removal is handled by PluginManager::uninstall() via the manifest.

            // Remove default relation types we added
            $sql = "DELETE FROM itt_item_relation_types WHERE itt_name IN ('Contains', 'Related To')";
            $q = $dblink->prepare($sql);
            $q->execute();
            
            // Tables will be dropped by uninstall script, not here
            return true;
        }
    ]
];
?>
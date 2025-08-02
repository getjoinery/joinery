<?php
/**
 * ControlD Plugin Migrations
 * 
 * This file defines database migrations for the controld plugin.
 * Tables are created automatically from data class field specifications.
 * Migrations are only for settings, initial data, indexes, and configuration.
 * 
 * Converted from old format - see migrations_old.php for reference
 */

return [
    [
        'id' => '001_controld_initial_setup',
        'version' => '1.0.0',
        'description' => 'Add ControlD API key setting',
        'up' => function($dbconnector) {
            // Tables are created automatically from ControlD data classes
            // This migration only handles settings and configuration
            
            $dblink = $dbconnector->get_db_link();
            
            // Add ControlD API key setting (converted from migration 20250104)
            $check_sql = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'controld_key'";
            $check_q = $dblink->prepare($check_sql);
            $check_q->execute();
            $result = $check_q->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0) {
                $sql = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) 
                        VALUES ('controld_key', '', 1, NOW(), NOW(), 'general')";
                $q = $dblink->prepare($sql);
                $q->execute();
            }
            
            return true;
        },
        'down' => function($dbconnector) {
            $dblink = $dbconnector->get_db_link();
            
            // Remove ControlD API key setting
            $sql = "DELETE FROM stg_settings WHERE stg_name = 'controld_key'";
            $q = $dblink->prepare($sql);
            $q->execute();
            
            return true;
        }
    ],
    [
        'id' => '002_controld_admin_menu',
        'version' => '1.0.0',
        'depends_on' => ['001_controld_initial_setup'],
        'description' => 'Add ControlD admin menu entry',
        'up' => function($dbconnector) {
            $dblink = $dbconnector->get_db_link();
            
            // Add admin menu entry (converted from migration 20250201)
            $check_sql = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_defaultpage = '/plugins/controld/admin/admin_ctld_accounts'";
            $check_q = $dblink->prepare($check_sql);
            $check_q->execute();
            $result = $check_q->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0) {
                $sql = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_disable, amu_icon, amu_slug, amu_setting_activate) 
                        VALUES ('Accounts', NULL, '/plugins/controld/admin/admin_ctld_accounts', 5, 8, 0, '', 'accounts', 'controld_key')";
                $q = $dblink->prepare($sql);
                $q->execute();
            }
            
            return true;
        },
        'down' => function($dbconnector) {
            $dblink = $dbconnector->get_db_link();
            
            // Remove admin menu entry
            $sql = "DELETE FROM amu_admin_menus WHERE amu_defaultpage = '/plugins/controld/admin/admin_ctld_accounts'";
            $q = $dblink->prepare($sql);
            $q->execute();
            
            return true;
        }
    ]
];
?>
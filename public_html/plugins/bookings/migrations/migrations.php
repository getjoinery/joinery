<?php
/**
 * Bookings Plugin Migrations
 * 
 * This file defines database migrations for the bookings plugin.
 * Tables are created automatically from data class field specifications.
 * Migrations are only for settings, initial data, indexes, and configuration.
 */

return [
    [
        'id' => '001_booking_initial_setup', 
        'version' => '1.0.0',
        'description' => 'Initial booking system setup and default data',
        'up' => function($dbconnector) {
            // Tables are created automatically from BookingType and Booking data classes
            // This migration only handles settings, initial data, etc.
            
            $dblink = $dbconnector->get_db_link();
            
            // Add plugin settings
            $sql = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name) 
                    VALUES ('bookings_enabled', '1', 1, NOW(), NOW(), 'general')";
            $q = $dblink->prepare($sql);
            $q->execute();
            
            // Add default booking types (if needed)
            // Check if any booking types already exist to avoid duplicates
            $check_sql = "SELECT COUNT(*) as count FROM bkt_booking_types";
            $check_q = $dblink->prepare($check_sql);
            $check_q->execute();
            $result = $check_q->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0) {
                // Add a default standard booking type
                $sql = "INSERT INTO bkt_booking_types (bkt_name, bkt_description_plain, bkt_description_html, bkt_status, bkt_create_time) 
                        VALUES ('Standard', 'Standard booking type', 'Standard booking type', 1, NOW())";
                $q = $dblink->prepare($sql);
                $q->execute();
            }
            
            return true;
        },
        'down' => function($dbconnector) {
            $dblink = $dbconnector->get_db_link();
            
            // Remove settings
            $sql = "DELETE FROM stg_settings WHERE stg_name LIKE 'bookings_%'";
            $q = $dblink->prepare($sql);
            $q->execute();
            
            // Remove default booking types we added (be careful not to remove user-created ones)
            $sql = "DELETE FROM bkt_booking_types WHERE bkt_name = 'Standard' AND bkt_description_plain = 'Standard booking type'";
            $q = $dblink->prepare($sql);
            $q->execute();
            
            // Tables will be dropped by uninstall script, not here
            return true;
        }
    ]
];
?>
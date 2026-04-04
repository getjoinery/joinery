<?php
/**
 * ScrollDaddy Plugin Migrations
 *
 * This file defines database migrations for the scrolldaddy plugin.
 * Tables are created automatically from data class field specifications.
 * Migrations are only for settings, initial data, indexes, and configuration.
 */

return [
    [
        'id' => '001_scrolldaddy_initial_setup',
        'version' => '1.0.0',
        'description' => 'Add ScrollDaddy DNS service settings',
        'up' => function($dbconnector) {
            $dblink = $dbconnector->get_db_link();

            $settings_to_add = [
                'scrolldaddy_dns_host' => '',
                'scrolldaddy_dns_internal_url' => '',
            ];

            foreach ($settings_to_add as $name => $default_value) {
                $check_sql = "SELECT count(1) as count FROM stg_settings WHERE stg_name = ?";
                $check_q = $dblink->prepare($check_sql);
                $check_q->execute([$name]);
                $result = $check_q->fetch(PDO::FETCH_ASSOC);

                if ($result['count'] == 0) {
                    $sql = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
                            VALUES (?, ?, 1, NOW(), NOW(), 'general')";
                    $q = $dblink->prepare($sql);
                    $q->execute([$name, $default_value]);
                }
            }

            return true;
        },
        'down' => function($dbconnector) {
            $dblink = $dbconnector->get_db_link();
            $sql = "DELETE FROM stg_settings WHERE stg_name LIKE 'scrolldaddy_%'";
            $q = $dblink->prepare($sql);
            $q->execute();
            return true;
        }
    ]
];
?>

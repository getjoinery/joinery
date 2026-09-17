<?php
/**
 * Bookings Plugin Migrations
 * 
 * This file defines database migrations for the bookings plugin.
 *
 * Tables are created automatically from data class field specifications.
 * Migrations are only for settings, initial data, indexes, and configuration.
 *
 * @version 1.1.0 - 002_booking_type_prefix: bkt_booking_types -> bty_booking_types
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

            // Plugin settings are declared in plugin.json and seeded automatically.

            // Add default booking types (if needed)
            // Check if any booking types already exist to avoid duplicates
            $check_sql = "SELECT COUNT(*) as count FROM bty_booking_types";
            $check_q = $dblink->prepare($check_sql);
            $check_q->execute();
            $result = $check_q->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0) {
                // Add a default standard booking type
                $sql = "INSERT INTO bty_booking_types (bty_name, bty_description_plain, bty_description_html, bty_status, bty_create_time) 
                        VALUES ('Standard', 'Standard booking type', 'Standard booking type', 1, NOW())";
                $q = $dblink->prepare($sql);
                $q->execute();
            }
            
            return true;
        },
        'down' => function($dbconnector) {
            $dblink = $dbconnector->get_db_link();

            // Settings removal is handled by PluginManager::uninstall() via the manifest.

            // Remove default booking types we added (be careful not to remove user-created ones)
            $sql = "DELETE FROM bty_booking_types WHERE bty_name = 'Standard' AND bty_description_plain = 'Standard booking type'";
            $q = $dblink->prepare($sql);
            $q->execute();
            
            // Tables will be dropped by uninstall script, not here
            return true;
        }
    ],
    [
        // BookingType takes a prefix of its own: bkt was shared with core
        // BackupTarget (specs/implemented/shared_prefixes_first_three.md). The plugin's
        // additive pass has created bty_booking_types and
        // bkn_bookings.bkn_bty_booking_type_id from the specs, empty, before
        // this runs; this copies every booking type across with its id,
        // carries the sequence, fills the new booking column from the old,
        // drops the old column (its foreign key goes with it) and then the
        // old table. Idempotent: no old table, nothing to do.
        'id' => '002_booking_type_prefix',
        'version' => '1.3.0',
        'description' => 'bkt_booking_types -> bty_booking_types; bkn_bkt_booking_type_id -> bkn_bty_booking_type_id',
        'up' => function($dbconnector) {
            $db = $dbconnector->get_db_link();
            $exists = function ($table) use ($db) {
                $q = $db->prepare("SELECT to_regclass(:t)");
                $q->execute(array(':t' => 'public.' . $table));
                return $q->fetchColumn() !== null;
            };
            $has_column = function ($table, $column) use ($db) {
                $q = $db->prepare(
                    "SELECT 1 FROM information_schema.columns
                      WHERE table_schema = 'public' AND table_name = :t AND column_name = :c");
                $q->execute(array(':t' => $table, ':c' => $column));
                return $q->fetchColumn() !== false;
            };

            if (!$exists('bkt_booking_types')) {
                return true; // already migrated, or born with the new name
            }
            if (!$exists('bty_booking_types')) {
                throw new Exception('bty_booking_types not yet created - run the schema pass first');
            }

            // Every column, old name => new name, primary key first.
            $columns = array();
            foreach (array_keys(BookingType::$field_specifications) as $new) {
                $columns['bkt_' . substr($new, 4)] = $new;
            }
            $old_cols = implode(', ', array_keys($columns));
            $new_cols = implode(', ', array_values($columns));
            $copied = $db->exec(
                "INSERT INTO bty_booking_types ({$new_cols})
                 SELECT {$old_cols} FROM bkt_booking_types
                  WHERE bkt_booking_type_id NOT IN (SELECT bty_booking_type_id FROM bty_booking_types)");

            // The new table's serial starts at 1; move it past every id just
            // kept. The sequence is not OWNED BY the column, so its name is
            // read off the column default.
            $q = $db->query(
                "SELECT pg_get_expr(d.adbin, d.adrelid) FROM pg_attrdef d
                   JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
                  WHERE d.adrelid = 'public.bty_booking_types'::regclass AND a.attname = 'bty_booking_type_id'");
            if (!preg_match("/nextval\\('([^']+)'/", (string)$q->fetchColumn(), $m)) {
                throw new Exception('bty_booking_types.bty_booking_type_id has no sequence default');
            }
            $db->exec(
                "SELECT setval('{$m[1]}',
                               GREATEST((SELECT coalesce(max(bty_booking_type_id), 0) FROM bty_booking_types), 1),
                               (SELECT count(*) > 0 FROM bty_booking_types))");

            if ($has_column('bkn_bookings', 'bkn_bkt_booking_type_id')) {
                if (!$has_column('bkn_bookings', 'bkn_bty_booking_type_id')) {
                    throw new Exception('bkn_bookings.bkn_bty_booking_type_id not yet created - run the schema pass first');
                }
                $db->exec(
                    "UPDATE bkn_bookings SET bkn_bty_booking_type_id = bkn_bkt_booking_type_id
                      WHERE bkn_bty_booking_type_id IS NULL AND bkn_bkt_booking_type_id IS NOT NULL");
                $db->exec("ALTER TABLE bkn_bookings DROP COLUMN bkn_bkt_booking_type_id");
            }

            $db->exec("DROP TABLE bkt_booking_types");
            $db->exec("DROP SEQUENCE IF EXISTS bkt_booking_types_bkt_booking_type_id_seq");
            error_log("bookings 002_booking_type_prefix: {$copied} booking types carried to bty_booking_types, old table dropped");
            return true;
        },
        'down' => function($dbconnector) {
            return true; // a rename is not undone; the model names the new table
        }
    ]
];
?>
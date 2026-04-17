<?php
/**
 * Test Database Helper
 *
 * Provides utilities for tests to check if the test database is in sync
 * with the live database, and display appropriate warnings/links.
 *
 * Usage:
 *   require_once(PathHelper::getIncludePath('includes/TestDatabaseHelper.php'));
 *   TestDatabaseHelper::checkAndWarn();  // Displays warning if out of sync
 *
 * Version: 1.00
 */

class TestDatabaseHelper {

    private static $schema_diff = null;

    /**
     * Check if test database is in sync with live and display warning if not.
     * Returns true if in sync, false if not.
     *
     * @param bool $block_if_out_of_sync If true, stops execution when out of sync
     * @return bool
     */
    public static function checkAndWarn($block_if_out_of_sync = false) {
        $diff = self::getSchemaComparison();

        if ($diff['is_in_sync']) {
            echo "<div style='background: #d4edda; border: 1px solid #28a745; padding: 10px; margin: 10px 0; border-radius: 4px;'>";
            echo "<strong style='color: #155724;'>✓ Test database is in sync with live database.</strong>";
            echo "</div>";
            return true;
        }

        // Out of sync - display warning
        echo "<div style='background: #fff3cd; border: 2px solid #ffc107; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
        echo "<h3 style='color: #856404; margin-top: 0;'>⚠️ Test Database Out of Sync</h3>";
        echo "<p style='color: #856404;'>The test database schema does not match the live database. Tests may fail due to missing tables or columns.</p>";

        // Show summary of differences
        if (!empty($diff['live_only_tables'])) {
            echo "<p style='color: #856404;'><strong>Missing tables:</strong> " . htmlspecialchars(implode(', ', $diff['live_only_tables'])) . "</p>";
        }

        if (!empty($diff['column_differences'])) {
            $missing_cols = [];
            foreach ($diff['column_differences'] as $table => $cols) {
                if (!empty($cols['live_only'])) {
                    foreach ($cols['live_only'] as $col) {
                        $missing_cols[] = "{$table}.{$col}";
                    }
                }
            }
            if (!empty($missing_cols)) {
                $display_cols = array_slice($missing_cols, 0, 5);
                $more = count($missing_cols) > 5 ? ' and ' . (count($missing_cols) - 5) . ' more...' : '';
                echo "<p style='color: #856404;'><strong>Missing columns:</strong> " . htmlspecialchars(implode(', ', $display_cols)) . $more . "</p>";
            }
        }

        echo "<p style='margin-bottom: 0;'>";
        echo "<a href='/admin/admin_test_database' style='background: #ffc107; color: #212529; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold;'>";
        echo "→ Go to Test Database Management to sync databases</a>";
        echo "</p>";
        echo "</div>";

        if ($block_if_out_of_sync) {
            echo "<p><strong>Tests cannot continue until the database is synchronized.</strong></p>";
            exit(1);
        }

        return false;
    }

    /**
     * Quick check if databases are in sync (no output)
     *
     * @return bool
     */
    public static function isInSync() {
        $diff = self::getSchemaComparison();
        return $diff['is_in_sync'];
    }

    /**
     * Get detailed schema comparison
     *
     * @return array
     */
    public static function getSchemaComparison() {
        // Cache the result
        if (self::$schema_diff !== null) {
            return self::$schema_diff;
        }

        $settings = Globalvars::get_instance();
        $live_db = $settings->get_setting('dbname');
        $test_db = $settings->get_setting('dbname_test');
        $db_user = $settings->get_setting('dbusername');
        $db_pass = $settings->get_setting('dbpassword');

        self::$schema_diff = self::compareSchemas($live_db, $test_db, $db_user, $db_pass);
        return self::$schema_diff;
    }

    /**
     * Compare schemas between two databases
     */
    private static function compareSchemas($live_db, $test_db, $db_user, $db_pass) {
        $diff = [
            'live_only_tables' => [],
            'test_only_tables' => [],
            'column_differences' => [],
            'is_in_sync' => true
        ];

        try {
            $live_schema = self::getDatabaseSchema($live_db, $db_user, $db_pass);
            $test_schema = self::getDatabaseSchema($test_db, $db_user, $db_pass);

            if ($live_schema === false) {
                $diff['error'] = "Could not connect to live database '{$live_db}'";
                $diff['is_in_sync'] = false;
                return $diff;
            }

            if ($test_schema === false) {
                $diff['error'] = "Could not connect to test database '{$test_db}'";
                $diff['is_in_sync'] = false;
                return $diff;
            }

            // Find tables only in live
            $diff['live_only_tables'] = array_values(array_diff(array_keys($live_schema), array_keys($test_schema)));

            // Find tables only in test
            $diff['test_only_tables'] = array_values(array_diff(array_keys($test_schema), array_keys($live_schema)));

            // Compare columns in shared tables
            $shared_tables = array_intersect(array_keys($live_schema), array_keys($test_schema));
            foreach ($shared_tables as $table) {
                $live_columns = $live_schema[$table];
                $test_columns = $test_schema[$table];

                $live_only_cols = array_values(array_diff($live_columns, $test_columns));
                $test_only_cols = array_values(array_diff($test_columns, $live_columns));

                if (!empty($live_only_cols) || !empty($test_only_cols)) {
                    $diff['column_differences'][$table] = [
                        'live_only' => $live_only_cols,
                        'test_only' => $test_only_cols
                    ];
                }
            }

            // Determine if in sync
            if (!empty($diff['live_only_tables']) ||
                !empty($diff['test_only_tables']) ||
                !empty($diff['column_differences'])) {
                $diff['is_in_sync'] = false;
            }

        } catch (Exception $e) {
            $diff['error'] = $e->getMessage();
            $diff['is_in_sync'] = false;
        }

        return $diff;
    }

    /**
     * Get schema (tables and columns) for a database
     */
    private static function getDatabaseSchema($dbname, $db_user, $db_pass) {
        try {
            $pdo = new PDO(
                "pgsql:host=localhost;port=5432;dbname={$dbname}",
                $db_user,
                $db_pass
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Get all tables
            $sql = "SELECT table_name FROM information_schema.tables
                    WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
                    ORDER BY table_name";
            $stmt = $pdo->query($sql);
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $schema = [];
            foreach ($tables as $table) {
                // Get columns for each table
                $sql = "SELECT column_name FROM information_schema.columns
                        WHERE table_schema = 'public' AND table_name = ?
                        ORDER BY ordinal_position";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$table]);
                $schema[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            return $schema;

        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Clear cached schema comparison (useful after sync)
     */
    public static function clearCache() {
        self::$schema_diff = null;
    }
}

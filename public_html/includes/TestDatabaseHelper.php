<?php
/**
 * Test Database Helper
 *
 * Owns the test database: the schema comparison tests use to decide whether the
 * copy has drifted, and the copy engine that rebuilds it.
 *
 * Usage:
 *   TestDatabaseHelper::checkAndWarn();          // Warn if the copy has drifted
 *   TestDatabaseHelper::copy('structure');       // Rebuild (schema + reference data)
 *
 * Version: 2.01
 */

class TestDatabaseHelper {

    /**
     * Structure: schema, constraints, and the reference tables below. This is
     * what the model suites need and it is the default.
     * Full: every row of every table. A deliberate, separately-chosen act.
     */
    const MODE_STRUCTURE = 'structure';
    const MODE_FULL      = 'full';

    /**
     * A reference table is seeded with data because the site cannot boot
     * against the copy without it. That is a promise the table is small; over
     * this size the copy fails naming it, rather than quietly re-inflating.
     */
    const REFERENCE_TABLE_MAX_BYTES = 52428800; // 50 MB

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

    // ==================================================================
    // The copy engine
    // ==================================================================

    /**
     * Tables whose *data* crosses into a structure copy.
     *
     * The model suites read no production rows — ModelTester creates a fresh
     * parent row for every foreign key it resolves. What they do need is for
     * the site to boot while pointed at the copy, and booting reads these:
     * Globalvars::get_setting() falls through to stg_settings on whichever
     * connection is live, which after set_test_mode() is this database, and
     * the plugin registry decides which classes resolve at all.
     *
     * Everything else arrives as an empty table with its constraints intact.
     *
     * A near-identical judgment is made for installers by
     * utils/create_install_sql.php ($essential_tables). The two lists serve
     * different jobs and stay separate — an installer must not ship this
     * site's settings row — but a table added to one is worth a look at the
     * other.
     *
     * Deliberately absent: `timezone` (9.7 MB of IANA DST transitions that no
     * code on the platform reads — `zone`, which address_class.php does read,
     * is 96 KB and is here).
     *
     * @return string[]
     */
    public static function referenceTables() {
        return array(
            'stg_settings',        // get_setting() reads this through the test connection
            'plg_plugins',         // decides which plugins are active, so which classes resolve
            'amu_admin_menus',     // cheap; keeps an admin page under test from looking broken
            'zone',                // IANA zone names (address_class.php)
            'cco_country_codes',   // reference data
            'emt_email_templates', // reference data; the send path reads it
        );
    }

    /**
     * Rebuild the test database from live.
     *
     * The restore lands in a staging database first, with ON_ERROR_STOP and
     * pipefail so any failed statement fails the whole copy loudly — a plain
     * `pg_dump | psql` into the final name reports psql's exit code (0 even
     * when statements error mid-stream) and, worse, a test run holding
     * connections during the restore can make constraint DDL fail silently,
     * leaving a copy with missing primary keys that every test-db suite then
     * trips over. Once staging restores cleanly, the swap (terminate, drop,
     * rename) takes under a second, so concurrent test runs get a torn moment
     * instead of a torn copy.
     *
     * @param string $mode MODE_STRUCTURE (default) or MODE_FULL
     * @return array ['success' => bool, 'message' => string]
     */
    public static function copy($mode = self::MODE_STRUCTURE) {
        $settings = Globalvars::get_instance();
        $live_db  = $settings->get_setting('dbname');
        $test_db  = $settings->get_setting('dbname_test');
        $db_user  = $settings->get_setting('dbusername');
        $password = $settings->get_setting('dbpassword');

        if ($mode !== self::MODE_STRUCTURE && $mode !== self::MODE_FULL) {
            return array('success' => false, 'message' => "Unknown copy mode '{$mode}'.");
        }

        // Safety: never let the live database be the target.
        if ($test_db === $live_db) {
            return array('success' => false,
                'message' => 'SAFETY BLOCK: Test database name is the same as live database. Aborting.');
        }
        if (strpos($test_db, 'test') === false) {
            return array('success' => false,
                'message' => 'SAFETY BLOCK: Test database name does not contain "test". Aborting.');
        }
        // A name containing "test" is not proof it is a throwaway. The
        // installer's create_test_site() provisions a whole separate SITE named
        // "{main_site}_test" with a live database of that name, and a
        // neighbouring config can name that same database as its dbname_test.
        // Rebuilding then drops a running site's data. Live always wins.
        $owner = self::siteOwningDatabase($test_db);
        if ($owner !== null) {
            return array('success' => false,
                'message' => "SAFETY BLOCK: '{$test_db}' is the live database of the site at {$owner}, "
                    . "not a throwaway copy — rebuilding it would destroy that site's data. "
                    . "Point dbname_test at a database no site uses.");
        }

        // Reference tables are only seeded on a structure copy; a full copy
        // carries them like everything else.
        $reference_tables = array();
        $notes = array();
        if ($mode === self::MODE_STRUCTURE) {
            $checked = self::checkReferenceTables($live_db, $db_user, $password);
            if (!$checked['ok']) {
                return array('success' => false, 'message' => $checked['message']);
            }
            $reference_tables = $checked['tables'];
            $notes = $checked['notes'];
        }

        putenv("PGPASSWORD={$password}");

        $esc_user    = escapeshellarg($db_user);
        $esc_test    = escapeshellarg($test_db);
        $esc_live    = escapeshellarg($live_db);
        $staging_db  = $test_db . '_staging';
        $esc_staging = escapeshellarg($staging_db);

        $fail = function ($message) use ($esc_user, $esc_staging) {
            exec("dropdb -U {$esc_user} --if-exists {$esc_staging} 2>&1");
            putenv("PGPASSWORD");
            return array('success' => false, 'message' => $message);
        };

        $output = array();
        $return_var = 0;

        // Step 1: Fresh staging database
        self::terminateConnections($staging_db, $db_user, $password);
        exec("dropdb -U {$esc_user} --if-exists {$esc_staging} 2>&1", $output, $return_var);
        if ($return_var !== 0) {
            return $fail("Failed to drop stale staging database. Output: " . implode("\n", $output));
        }
        $output = array();
        exec("createdb -U {$esc_user} {$esc_staging} 2>&1", $output, $return_var);
        if ($return_var !== 0) {
            return $fail("Failed to create staging database. Output: " . implode("\n", $output));
        }

        // Step 2: Structure. On a full copy this single pass carries the data
        // too (and pg_dump emits its own setval calls, so step 3 is skipped).
        $dump_args = ($mode === self::MODE_STRUCTURE) ? '--schema-only ' : '';
        $pipeline = "set -o pipefail; pg_dump {$dump_args}-U {$esc_user} {$esc_live}"
            . " | psql -q -v ON_ERROR_STOP=1 -U {$esc_user} -d {$esc_staging} 2>&1";
        $output = array();
        exec('bash -c ' . escapeshellarg($pipeline), $output, $return_var);
        if ($return_var !== 0) {
            return $fail("Restore into staging failed (nothing replaced — the previous test copy is untouched). Output tail: "
                . implode("\n", array_slice($output, -15)));
        }

        if ($mode === self::MODE_STRUCTURE) {
            // Step 2b: Reference data, one dump carrying every allowlisted table.
            $table_args = '';
            foreach ($reference_tables as $table) {
                $table_args .= '--table=' . escapeshellarg('public.' . $table) . ' ';
            }
            $pipeline = "set -o pipefail; pg_dump --data-only --no-owner --no-privileges {$table_args}-U {$esc_user} {$esc_live}"
                . " | psql -q -v ON_ERROR_STOP=1 -U {$esc_user} -d {$esc_staging} 2>&1";
            $output = array();
            exec('bash -c ' . escapeshellarg($pipeline), $output, $return_var);
            if ($return_var !== 0) {
                return $fail("Reference-table data restore failed (nothing replaced). Output tail: "
                    . implode("\n", array_slice($output, -15)));
            }

            // Step 3: Sequences. A full pg_dump emits setval for every
            // sequence; neither --schema-only nor --data-only --table=X emits
            // any. Left alone, a seeded table's sequence sits at its start
            // value while rows occupy ids 1..N and the next insert collides.
            // Sequences on the empty tables are correctly left alone.
            $seq_result = self::advanceSequences($staging_db, $db_user, $password, $reference_tables);
            if ($seq_result !== true) {
                return $fail("Reference data restored but the sequence sweep failed: " . $seq_result);
            }
        }

        // Step 4: Swap staging into place (short window; retry if a test run
        // reconnects between terminate and drop).
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            self::terminateConnections($test_db, $db_user, $password);
            $output = array();
            exec("dropdb -U {$esc_user} --if-exists {$esc_test} 2>&1", $output, $return_var);
            if ($return_var === 0) {
                break;
            }
            sleep(2);
        }
        if ($return_var !== 0) {
            return $fail("Could not drop the old test database to swap in the fresh copy (connections keep grabbing it). Output: "
                . implode("\n", $output));
        }

        try {
            $pdo = self::adminConnection($db_user, $password);
            // Identifiers can't be bound — quote defensively even though both
            // names come from config, not user input.
            $quote_ident = function ($name) { return '"' . str_replace('"', '""', $name) . '"'; };
            $pdo->exec("ALTER DATABASE " . $quote_ident($staging_db) . " RENAME TO " . $quote_ident($test_db));
        } catch (PDOException $e) {
            // Deliberately NOT $fail() here: the old test database is already
            // gone, so the staging copy is the only copy — dropping it too
            // would turn a failed rename into total loss. Leave it in place;
            // the next rebuild clears it as stale staging in step 1.
            putenv("PGPASSWORD");
            return array('success' => false,
                'message' => "The old test database was dropped but renaming '{$staging_db}' to '{$test_db}' failed: "
                    . $e->getMessage() . " The restored copy is intact as '{$staging_db}'; run the rebuild again to retry.");
        }

        putenv("PGPASSWORD");
        self::clearCache();

        if ($mode === self::MODE_FULL) {
            return array('success' => true,
                'message' => "Full copy complete: '{$test_db}' now holds every row of '{$live_db}'.");
        }

        $message = "Structure copy complete: '{$test_db}' has the schema of '{$live_db}' with no content. "
            . "Seeded reference tables: " . implode(', ', $reference_tables) . ".";
        if (!empty($notes)) {
            $message .= " " . implode(' ', $notes);
        }
        return array('success' => true, 'message' => $message);
    }

    /**
     * Validate the reference allowlist against live before copying anything.
     *
     * Two ways an entry stops being safe to seed, both caught here so they
     * surface as a named refusal rather than a confusing restore error:
     *   - it grew past REFERENCE_TABLE_MAX_BYTES, so it is content now, not
     *     configuration;
     *   - it gained a foreign key to a table that is NOT seeded, so its rows
     *     would reference rows the copy deliberately does not have.
     *
     * @return array ['ok' => bool, 'message' => string, 'tables' => string[], 'notes' => string[]]
     */
    private static function checkReferenceTables($live_db, $db_user, $password) {
        $wanted = self::referenceTables();
        $tables = array();
        $notes  = array();

        try {
            $pdo = new PDO("pgsql:host=localhost;port=5432;dbname={$live_db}", $db_user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            return array('ok' => false, 'message' => "Could not connect to live database '{$live_db}': " . $e->getMessage(),
                'tables' => array(), 'notes' => array());
        }

        foreach ($wanted as $table) {
            $stmt = $pdo->prepare("SELECT to_regclass('public.' || ?) IS NOT NULL AS present");
            $stmt->execute(array($table));
            if (!$stmt->fetchColumn()) {
                // A table the allowlist names but this install does not have
                // is not a failure — it is an install without that feature.
                $notes[] = "Skipped '{$table}' (not present in live).";
                continue;
            }

            $stmt = $pdo->prepare("SELECT pg_total_relation_size('public.' || ?)");
            $stmt->execute(array($table));
            $size = (int)$stmt->fetchColumn();
            if ($size > self::REFERENCE_TABLE_MAX_BYTES) {
                return array('ok' => false, 'tables' => array(), 'notes' => array(),
                    'message' => "Reference table '{$table}' is " . self::formatBytes($size)
                        . ", over the " . self::formatBytes(self::REFERENCE_TABLE_MAX_BYTES)
                        . " limit. A seeded table is meant to be configuration, not content. "
                        . "Either take it off TestDatabaseHelper::referenceTables() or raise the limit deliberately.");
            }

            $tables[] = $table;
        }

        // Outbound foreign keys must land inside the seeded set.
        foreach ($tables as $table) {
            $stmt = $pdo->prepare(
                "SELECT DISTINCT confrelid::regclass::text AS target
                 FROM pg_constraint
                 WHERE contype = 'f' AND conrelid = ('public.' || ?)::regclass"
            );
            $stmt->execute(array($table));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $target) {
                $bare = preg_replace('/^public\./', '', (string)$target);
                // A self-referencing FK is exempt (the table IS seeded), but
                // note the limit of that: a data-only COPY checks each row as
                // it loads, so a child row arriving before its parent would
                // still fail the restore. No seeded table has any FK today;
                // if one gains a self-reference, seeding needs ordered inserts
                // or a deferrable constraint, decided then.
                if ($bare !== $table && !in_array($bare, $tables, true)) {
                    return array('ok' => false, 'tables' => array(), 'notes' => array(),
                        'message' => "Reference table '{$table}' has a foreign key to '{$bare}', which is not seeded. "
                            . "Its rows would point at rows the copy does not have. "
                            . "Either add '{$bare}' to TestDatabaseHelper::referenceTables() or drop '{$table}' from it.");
                }
            }
        }

        return array('ok' => true, 'message' => '', 'tables' => $tables, 'notes' => $notes);
    }

    /**
     * Set each seeded table's sequence past its highest existing id.
     *
     * setval(seq, max, true) leaves the next value at max+1. With no rows,
     * setval(seq, 1, false) leaves the next value at 1 — which is what an
     * untouched sequence would have done anyway, and is correct.
     *
     * The link from column to sequence is read out of the column DEFAULT, not
     * from pg_get_serial_sequence(). That function answers from the OWNED BY
     * dependency, and DatabaseUpdater creates sequences without one — so it
     * returns NULL for every column on this platform, in live as well as here,
     * and a sweep built on it would silently do nothing.
     *
     * @return true|string true, or an error message
     */
    private static function advanceSequences($staging_db, $db_user, $password, array $tables) {
        try {
            $pdo = new PDO("pgsql:host=localhost;port=5432;dbname={$staging_db}", $db_user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            foreach ($tables as $table) {
                $stmt = $pdo->prepare(
                    "SELECT column_name, column_default
                     FROM information_schema.columns
                     WHERE table_schema = 'public' AND table_name = ?
                       AND column_default LIKE 'nextval(%'"
                );
                $stmt->execute(array($table));

                $quoted_table = 'public."' . str_replace('"', '""', $table) . '"';

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    // nextval('seq_name'::regclass) — take what is inside the
                    // quotes and hand it back as a literal, which is a valid
                    // regclass reference however the name was quoted.
                    if (!preg_match("/nextval\('([^']+)'::regclass\)/", $row['column_default'], $m)) {
                        continue;
                    }
                    // Identifiers can't be bound; the column name comes from
                    // the catalog, not from user input.
                    $col = '"' . str_replace('"', '""', $row['column_name']) . '"';
                    $max = "(SELECT MAX({$col}) FROM {$quoted_table})";
                    $pdo->query("SELECT setval(" . $pdo->quote($m[1]) . ", COALESCE({$max}, 1), {$max} IS NOT NULL)");
                }
            }
        } catch (PDOException $e) {
            return $e->getMessage();
        }

        return true;
    }

    /**
     * Size of the live database, for showing the cost of a full copy at the
     * moment of choosing one.
     *
     * @return string|false e.g. "1303 MB", or false if it cannot be read
     */
    public static function liveDatabaseSize() {
        $settings = Globalvars::get_instance();
        try {
            $pdo = self::adminConnection($settings->get_setting('dbusername'), $settings->get_setting('dbpassword'));
            $stmt = $pdo->prepare("SELECT pg_size_pretty(pg_database_size(?))");
            $stmt->execute(array($settings->get_setting('dbname')));
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Size of the test database, or false when no copy is provisioned.
     *
     * @return string|false
     */
    public static function testDatabaseSize() {
        $settings = Globalvars::get_instance();
        try {
            $pdo = self::adminConnection($settings->get_setting('dbusername'), $settings->get_setting('dbpassword'));
            $stmt = $pdo->prepare("SELECT pg_size_pretty(pg_database_size(datname)) FROM pg_database WHERE datname = ?");
            $stmt->execute(array($settings->get_setting('dbname_test')));
            $size = $stmt->fetchColumn();
            return ($size === false) ? false : $size;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * The site directory whose LIVE database carries this name, or null.
     *
     * Reads sibling configs by regex rather than including them — including one
     * would execute another site's PHP against this object.
     *
     * Unreadable neighbours simply do not match, which leaves behaviour exactly
     * as it was before this check existed rather than blocking on a permission
     * problem.
     *
     * @return string|null
     */
    private static function siteOwningDatabase($dbname) {
        $sites_dir = dirname(PathHelper::getSiteRoot());
        $this_site = PathHelper::getSiteRoot();

        $configs = glob($sites_dir . '/*/config/Globalvars_site.php');
        if ($configs === false) {
            return null;
        }

        foreach ($configs as $config) {
            $site = dirname(dirname($config));
            if ($site === $this_site) {
                continue; // this site's own dbname is checked separately
            }
            if (!is_readable($config)) {
                continue;
            }
            $contents = file_get_contents($config);
            if ($contents === false) {
                continue;
            }
            // Anchored to the assignment statement so a commented-out line
            // earlier in the file cannot supply the match.
            if (preg_match("/^\\s*\\\$this->settings\\['dbname'\\]\\s*=\\s*'([^']*)'/m", $contents, $m) && $m[1] === $dbname) {
                return $site;
            }
        }

        return null;
    }

    private static function adminConnection($db_user, $password) {
        $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=postgres", $db_user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    private static function terminateConnections($dbname, $db_user, $password) {
        try {
            $pdo = self::adminConnection($db_user, $password);
            $stmt = $pdo->prepare("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()");
            $stmt->execute(array($dbname));
        } catch (PDOException $e) {
            // Non-fatal — the drop that follows will surface a real blocker
        }
    }

    private static function formatBytes($bytes) {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return $bytes . ' bytes';
    }
}

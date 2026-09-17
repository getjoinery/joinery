<?php
/**
 * Two models take a prefix of their own (specs/implemented/shared_prefixes_first_three.md).
 *
 * abt was AbTest's and AppBridgeToken's; AbTest is abx, and its table takes
 * the scheme's name: abt_tests -> abx_ab_tests, primary key abx_ab_test_id.
 * del was DebugEmailLog's and DeletionRule's; DebugEmailLog is dbl:
 * del_debug_email_logs -> dbl_debug_email_logs. The variant's foreign key
 * follows its target: abv_variants.abv_abt_test_id -> abv_abx_ab_test_id.
 *
 * update_database creates the new tables and the new variant column from the
 * specs, empty, before this runs (migration 187's pattern). This copies every
 * row across with its id, points each new sequence past the highest id, fills
 * the new variant column from the old, drops the old column (its foreign key
 * goes with it) and then the old tables and their sequences. The migration
 * runner holds the transaction.
 *
 * Idempotent: a database with no old table (a fresh install, or a node
 * already migrated) is left alone.
 */
function shared_prefixes_first_three() {
    $db = DbConnector::get_instance()->get_db_link();

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

    // Copy a table's rows into its renamed successor, id for id, and carry the
    // sequence. $columns maps old column => new column, primary key first.
    $carry = function ($old_table, $new_table, array $columns) use ($db, $exists) {
        if (!$exists($old_table)) {
            echo "  {$old_table}: already gone\n";
            return false;
        }
        if (!$exists($new_table)) {
            throw new Exception("{$new_table} not yet created - run the schema pass first");
        }
        $old_cols = implode(', ', array_keys($columns));
        $new_cols = implode(', ', array_values($columns));
        $old_pkey = array_key_first($columns);
        $new_pkey = $columns[$old_pkey];

        $copied = $db->exec(
            "INSERT INTO {$new_table} ({$new_cols})
             SELECT {$old_cols} FROM {$old_table}
              WHERE {$old_pkey} NOT IN (SELECT {$new_pkey} FROM {$new_table})");

        // The new table's serial starts at 1; move it past every id just kept.
        // update_database names a primary key's sequence {table}_{pkey}_seq and
        // does not make it OWNED BY the column, so the name is read off the
        // column default, and the old sequence is dropped by name below.
        $q = $db->query(
            "SELECT pg_get_expr(d.adbin, d.adrelid) FROM pg_attrdef d
               JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
              WHERE d.adrelid = 'public.{$new_table}'::regclass AND a.attname = '{$new_pkey}'");
        if (!preg_match("/nextval\('([^']+)'/", (string)$q->fetchColumn(), $m)) {
            throw new Exception("{$new_table}.{$new_pkey} has no sequence default");
        }
        $db->exec(
            "SELECT setval('{$m[1]}',
                           GREATEST((SELECT coalesce(max({$new_pkey}), 0) FROM {$new_table}), 1),
                           (SELECT count(*) > 0 FROM {$new_table}))");
        echo "  {$old_table} -> {$new_table}: {$copied} rows copied, sequence carried\n";
        return true;
    };

    // --- AbTest: abt_tests -> abx_ab_tests, then the variant's foreign key ---
    $tests_moved = $carry('abt_tests', 'abx_ab_tests', array(
        'abt_test_id'               => 'abx_ab_test_id',
        'abt_entity_type'           => 'abx_entity_type',
        'abt_entity_id'             => 'abx_entity_id',
        'abt_status'                => 'abx_status',
        'abt_conversion_event_type' => 'abx_conversion_event_type',
        'abt_epsilon'               => 'abx_epsilon',
        'abt_cold_start_threshold'  => 'abx_cold_start_threshold',
        'abt_winner_abv_variant_id' => 'abx_winner_abv_variant_id',
        'abt_create_time'           => 'abx_create_time',
        'abt_update_time'           => 'abx_update_time',
        'abt_delete_time'           => 'abx_delete_time',
    ));

    if ($exists('abv_variants') && $has_column('abv_variants', 'abv_abt_test_id')) {
        if (!$has_column('abv_variants', 'abv_abx_ab_test_id')) {
            throw new Exception('abv_variants.abv_abx_ab_test_id not yet created - run the schema pass first');
        }
        $q = $db->query(
            "UPDATE abv_variants SET abv_abx_ab_test_id = abv_abt_test_id
              WHERE abv_abx_ab_test_id IS NULL AND abv_abt_test_id IS NOT NULL");
        $copied = $q->rowCount();
        // Dropping the column drops the foreign key that pinned abt_tests.
        $db->exec("ALTER TABLE abv_variants DROP COLUMN abv_abt_test_id");
        // The spec says NOT NULL; the schema pass could not apply it to an
        // empty column and would only do so on its next run. The old column
        // was NOT NULL, so every value is here now.
        $db->exec("ALTER TABLE abv_variants ALTER COLUMN abv_abx_ab_test_id SET NOT NULL");
        echo "  abv_variants: abv_abt_test_id -> abv_abx_ab_test_id, {$copied} values copied, old column dropped\n";
    } else {
        echo "  abv_variants.abv_abt_test_id: already gone\n";
    }

    if ($tests_moved) {
        $db->exec("DROP TABLE abt_tests");
        $db->exec("DROP SEQUENCE IF EXISTS abt_tests_abt_test_id_seq");
        echo "  abt_tests: old table dropped\n";
    }

    // --- DebugEmailLog: del_debug_email_logs -> dbl_debug_email_logs ---
    if ($carry('del_debug_email_logs', 'dbl_debug_email_logs', array(
        'del_debug_email_log_id' => 'dbl_debug_email_log_id',
        'del_message'            => 'dbl_message',
        'del_service'            => 'dbl_service',
        'del_status'             => 'dbl_status',
        'del_create_time'        => 'dbl_create_time',
    ))) {
        $db->exec("DROP TABLE del_debug_email_logs");
        $db->exec("DROP SEQUENCE IF EXISTS del_debug_email_logs_del_debug_email_log_id_seq");
        // An orphan from the release that renamed the table to its plural.
        $db->exec("DROP SEQUENCE IF EXISTS del_debug_email_log_del_debug_email_log_id_seq");
        echo "  del_debug_email_logs: old table dropped\n";
    }
}
?>

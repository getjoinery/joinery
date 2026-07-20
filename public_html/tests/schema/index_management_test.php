<?php
/** @joinery-test
 * name: index_management
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * Integration test for declarative index management in DatabaseUpdater.
 *
 * Runs entirely inside a single transaction that is rolled back at the end, so
 * it creates a throwaway real table, exercises manageIndexes() against stub
 * model classes, asserts the live pg_indexes state, and persists nothing.
 *
 * CLI:  php tests/schema/index_management_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));

// --------------------------------------------------------------------------
// Stub model classes — the table is created by the test, not update_database.
// --------------------------------------------------------------------------
$TEST_TABLE = 'zz_idx_test';

class IdxStubPhase1 {
    public static $tablename = 'zz_idx_test';
    public static $field_specifications = array(
        'zit_id'           => array('type' => 'int8'),
        'zit_usr_user_id'  => array('type' => 'int8', 'index' => true),
        'zit_subject_type' => array('type' => 'varchar(32)', 'index_with' => array('zit_subject_id')),
        'zit_subject_id'   => array('type' => 'int8'),
        'zit_email'        => array('type' => 'varchar(255)'),
        'zit_delete_time'  => array('type' => 'timestamp'),
    );
}

// Same as Phase1 but the composite index declaration is removed (obsolete-drop test).
class IdxStubPhase2 {
    public static $tablename = 'zz_idx_test';
    public static $field_specifications = array(
        'zit_id'          => array('type' => 'int8'),
        'zit_usr_user_id' => array('type' => 'int8', 'index' => true),
        'zit_subject_type'=> array('type' => 'varchar(32)'),
        'zit_subject_id'  => array('type' => 'int8'),
        'zit_email'       => array('type' => 'varchar(255)'),
        'zit_delete_time' => array('type' => 'timestamp'),
    );
}

// Advanced surface: partial, expression, and partial-unique indexes.
class IdxStubPhase3 {
    public static $tablename = 'zz_idx_test';
    public static $field_specifications = array(
        'zit_id'          => array('type' => 'int8'),
        'zit_usr_user_id' => array('type' => 'int8', 'index' => true),
        'zit_subject_type'=> array('type' => 'varchar(32)', 'index_with' => array('zit_subject_id')),
        'zit_subject_id'  => array('type' => 'int8'),
        'zit_email'       => array('type' => 'varchar(255)'),
        'zit_delete_time' => array('type' => 'timestamp'),
    );
    public static $index_specifications = array(
        array('columns' => array('zit_subject_id'), 'where' => 'zit_delete_time IS NULL'),
        array('columns' => array('LOWER(zit_email)')),
        array('columns' => array('zit_email'), 'unique' => true, 'where' => 'zit_delete_time IS NULL'),
    );
}

function table_indexes($dblink, $table) {
    $q = $dblink->prepare("SELECT indexname FROM pg_indexes WHERE schemaname='public' AND tablename = :t ORDER BY indexname");
    $q->execute([':t' => $table]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

$dblink = DbConnector::get_instance()->get_db_link();
$dblink->beginTransaction();

try {
    // Clean slate inside the transaction.
    $dblink->exec("DROP TABLE IF EXISTS {$TEST_TABLE} CASCADE");
    $dblink->exec("CREATE TABLE {$TEST_TABLE} (
        zit_id           bigserial PRIMARY KEY,
        zit_usr_user_id  bigint,
        zit_subject_type varchar(32),
        zit_subject_id   bigint,
        zit_email        varchar(255),
        zit_delete_time  timestamp
    )");
    // A hand-made index that must NEVER be dropped (no reserved suffix).
    $dblink->exec("CREATE INDEX zz_idx_test_handmade ON {$TEST_TABLE} (zit_subject_type)");

    // ---------------- Phase 1: create plain indexes ----------------
    section("Phase 1: create plain field-level indexes");
    $updater = new DatabaseUpdater(false, true, true); // verbose, upgrade, cleanup
    $r1 = $updater->manageIndexes([IdxStubPhase1::class]);
    $idx = table_indexes($dblink, $TEST_TABLE);

    $single = "{$TEST_TABLE}_usr_user_id_idx";
    $composite_exact = "{$TEST_TABLE}_subject_type_subject_id_idx";
    ok("manageIndexes succeeded", $r1['success'] === true);
    ok("single-column index created ($single)", in_array($single, $idx));
    ok("composite index created ($composite_exact)", in_array($composite_exact, $idx));
    ok("added count is 2", count($r1['indexes_added']) === 2);

    // Idempotency
    $r1b = $updater->manageIndexes([IdxStubPhase1::class]);
    ok("second run adds nothing (idempotent)", count($r1b['indexes_added']) === 0);

    // Hand-made + PK survive
    ok("hand-made non-_idx index still present", in_array('zz_idx_test_handmade', table_indexes($dblink, $TEST_TABLE)));

    // ---------------- Dedupe: equivalent index under another name ----------------
    section("Dedupe: equivalent btree under a different name is not duplicated");
    $dblink->exec("CREATE INDEX zz_handmade_userid ON {$TEST_TABLE} (zit_usr_user_id)");
    // Drop the managed one so only the legacy-named equivalent remains.
    $dblink->exec("DROP INDEX {$single}");
    $rd = $updater->manageIndexes([IdxStubPhase1::class]);
    ok("no recreate when equivalent exists under legacy name", !in_array($single, $rd['indexes_added']));
    $dblink->exec("DROP INDEX zz_handmade_userid");
    // restore managed single index for later phases
    $updater->manageIndexes([IdxStubPhase1::class]);

    // ---------------- Phase 2: obsolete drop ----------------
    section("Phase 2: obsolete index dropped under cleanup");
    $r2 = $updater->manageIndexes([IdxStubPhase2::class]); // composite no longer declared
    ok("composite index dropped", in_array($composite_exact, $r2['indexes_removed']));
    ok("composite gone from pg_indexes", !in_array($composite_exact, table_indexes($dblink, $TEST_TABLE)));
    ok("single index retained", in_array($single, table_indexes($dblink, $TEST_TABLE)));
    ok("hand-made survives cleanup", in_array('zz_idx_test_handmade', table_indexes($dblink, $TEST_TABLE)));
    ok("PK index survives cleanup", in_array("{$TEST_TABLE}_pkey", table_indexes($dblink, $TEST_TABLE)));

    // upgrade-only mode must NOT drop
    $updater->manageIndexes([IdxStubPhase1::class]); // re-create composite
    $upgradeOnly = new DatabaseUpdater(false, true, false); // upgrade, no cleanup
    $ru = $upgradeOnly->manageIndexes([IdxStubPhase2::class]);
    ok("upgrade-only does not drop obsolete", count($ru['indexes_removed']) === 0
          && in_array($composite_exact, table_indexes($dblink, $TEST_TABLE)));

    // ---------------- Phase 3: advanced indexes ----------------
    section("Phase 3: partial, expression, and partial-unique indexes");
    $r3 = $updater->manageIndexes([IdxStubPhase3::class]);
    $idx3 = table_indexes($dblink, $TEST_TABLE);
    // Find the discriminator-named partial index and the expression index.
    $partial = array_values(array_filter($idx3, function ($n) { return preg_match('/_[0-9a-f]{4}_idx$/', $n); }));
    $expr    = array_values(array_filter($idx3, function ($n) { return preg_match('/_[0-9a-f]{8}_idx$/', $n); }));
    $uidx    = array_values(array_filter($idx3, function ($n) { return substr($n, -5) === '_uidx'; }));
    ok("partial index created (discriminator-named)", count($partial) >= 1);
    ok("expression index created (hash-named)", count($expr) >= 1);
    ok("partial-unique index created (_uidx)", count($uidx) >= 1);

    // Partial-unique semantics: duplicate among ACTIVE rows is rejected,
    // but allowed when one of the pair is soft-deleted.
    section("Phase 3: partial-unique duplicate handling");
    // soft-deleted duplicate is fine (both rows, one deleted)
    $dblink->exec("INSERT INTO {$TEST_TABLE} (zit_email, zit_delete_time) VALUES ('dup@x.com', NULL)");
    $dblink->exec("INSERT INTO {$TEST_TABLE} (zit_email, zit_delete_time) VALUES ('dup@x.com', now())");
    $r3b = $updater->manageIndexes([IdxStubPhase3::class]); // re-run: index already exists, idempotent
    ok("partial-unique tolerates soft-deleted duplicate (still present)",
          count(array_filter(table_indexes($dblink, $TEST_TABLE), function ($n) { return substr($n, -5) === '_uidx'; })) >= 1);

    // Now make two ACTIVE duplicates and a fresh table to prove the skip-with-warning path.
    $dblink->exec("DROP TABLE IF EXISTS {$TEST_TABLE} CASCADE");
    $dblink->exec("CREATE TABLE {$TEST_TABLE} (
        zit_id bigserial PRIMARY KEY, zit_usr_user_id bigint, zit_subject_type varchar(32),
        zit_subject_id bigint, zit_email varchar(255), zit_delete_time timestamp)");
    $dblink->exec("INSERT INTO {$TEST_TABLE} (zit_email, zit_delete_time) VALUES ('dup@x.com', NULL)");
    $dblink->exec("INSERT INTO {$TEST_TABLE} (zit_email, zit_delete_time) VALUES ('dup@x.com', NULL)");
    $r3c = $updater->manageIndexes([IdxStubPhase3::class]);
    $warned = false;
    foreach ($r3c['warnings'] as $w) { if (strpos($w, '_uidx') !== false) { $warned = true; } }
    ok("active-row duplicate skips unique index with warning", $warned);
    ok("no _uidx created while active duplicates exist",
          count(array_filter(table_indexes($dblink, $TEST_TABLE), function ($n) { return substr($n, -5) === '_uidx'; })) === 0);

} catch (Exception $e) {
    ok("index management ran without exception", false, $e->getMessage());
} finally {
    $dblink->rollBack();
}

harness_finish();

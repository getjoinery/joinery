<?php
/** @joinery-test
 * name: retired_column_constraints
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * A column that is live in the database but no longer declared in a model's
 * $field_specifications is retired: the platform never writes it again. If it
 * carries NOT NULL, every INSERT the platform builds omits it and dies on the
 * constraint — one dead column makes the whole table un-insertable. Upgrades
 * only add columns (drops are cleanup-only), so the constraint outlives the
 * code that satisfied it.
 *
 * DatabaseUpdater relaxes that constraint on every run. This test proves it,
 * and proves it stops there: declared columns keep their NOT NULL, primary keys
 * are untouched, the column and its data survive, and a table whose class
 * declares no specifications at all is left completely alone.
 *
 * Runs inside a transaction that is rolled back, so it persists nothing.
 *
 * CLI:  php tests/schema/retired_column_constraints_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));

$TEST_TABLE = 'zz_retired_col_test';

// The current specifications: zrc_storage_driver is gone (retired), the rest stay.
class RetiredColStub {
    public static $tablename = 'zz_retired_col_test';
    public static $field_specifications = array(
        'zrc_id'    => array('type' => 'int8'),
        'zrc_name'  => array('type' => 'varchar(255)', 'is_nullable' => false),
        'zrc_title' => array('type' => 'varchar(255)'),
    );
}

// A table with no declared specifications at all — not spec-managed, so nothing
// about it is "retired" and no constraint of its may be touched.
class UnspecedStub {
    public static $tablename = 'zz_retired_col_test';
    public static $field_specifications = array();
}

function column_nullability($dblink, $table) {
    $q = $dblink->prepare("SELECT column_name, is_nullable FROM information_schema.columns
                           WHERE table_schema='public' AND table_name = :t");
    $q->execute([':t' => $table]);
    return $q->fetchAll(PDO::FETCH_KEY_PAIR);
}

$dblink = DbConnector::get_instance()->get_db_link();
$dblink->beginTransaction();

try {
    $dblink->exec("DROP TABLE IF EXISTS {$TEST_TABLE} CASCADE");
    $dblink->exec("CREATE TABLE {$TEST_TABLE} (
        zrc_id             bigserial PRIMARY KEY,
        zrc_name           varchar(255) NOT NULL,
        zrc_title          varchar(255),
        zrc_storage_driver varchar(32)  NOT NULL,
        zrc_legacy_note    varchar(255)
    )");
    $dblink->exec("INSERT INTO {$TEST_TABLE} (zrc_name, zrc_storage_driver) VALUES ('row one', 'local')");

    // ---------------- The retired constraint is relaxed ----------------
    section("Retired NOT NULL is relaxed on an ordinary run");
    // No upgrade, no cleanup — the mode a deploy actually runs in.
    $updater = new DatabaseUpdater(false, false, false);
    $r = $updater->processAdvancedColumnOperations([RetiredColStub::class], false);
    $nullable = column_nullability($dblink, $TEST_TABLE);

    ok("column processing succeeded", $r['success'] === true);
    ok("retired column no longer NOT NULL", ($nullable['zrc_storage_driver'] ?? '') === 'YES');
    ok("relaxation is reported", (bool)count(array_filter($r['messages'], function ($m) {
        return strpos($m, 'zrc_storage_driver') !== false;
    })));

    section("An INSERT that omits the retired column now succeeds");
    $inserted = false;
    try {
        $dblink->exec("INSERT INTO {$TEST_TABLE} (zrc_name, zrc_title) VALUES ('row two', 'no driver')");
        $inserted = true;
    } catch (PDOException $e) {
        $inserted = false;
    }
    ok("INSERT without the retired column succeeds", $inserted);

    section("Nothing else is disturbed");
    ok("declared NOT NULL column keeps its constraint", ($nullable['zrc_name'] ?? '') === 'NO');
    ok("primary key stays NOT NULL", ($nullable['zrc_id'] ?? '') === 'NO');
    ok("retired column still exists (not dropped)", array_key_exists('zrc_storage_driver', $nullable));
    ok("retired column's data survives", $dblink->query(
        "SELECT zrc_storage_driver FROM {$TEST_TABLE} WHERE zrc_name = 'row one'")->fetchColumn() === 'local');
    ok("an already-nullable undeclared column is left alone", ($nullable['zrc_legacy_note'] ?? '') === 'YES');

    section("Idempotent");
    $r2 = $updater->processAdvancedColumnOperations([RetiredColStub::class], false);
    ok("second run relaxes nothing further", count(array_filter($r2['messages'], function ($m) {
        return strpos($m, 'Relaxed NOT NULL') !== false;
    })) === 0);

    // ---------------- A table with no specifications is untouched ----------------
    section("A class declaring no specifications is left alone");
    $dblink->exec("DROP TABLE IF EXISTS {$TEST_TABLE} CASCADE");
    $dblink->exec("CREATE TABLE {$TEST_TABLE} (
        zrc_id   bigserial PRIMARY KEY,
        zrc_name varchar(255) NOT NULL
    )");
    $updater->processAdvancedColumnOperations([UnspecedStub::class], false);
    $nullable_unspeced = column_nullability($dblink, $TEST_TABLE);
    ok("unspeced table keeps its NOT NULL", ($nullable_unspeced['zrc_name'] ?? '') === 'NO');

} catch (Exception $e) {
    ok("retired-column handling ran without exception", false, $e->getMessage());
} finally {
    $dblink->rollBack();
}

harness_finish();

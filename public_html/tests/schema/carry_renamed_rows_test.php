<?php
/** @joinery-test
 * name: carry_renamed_rows
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * A migration that renames a table copies its rows with the columns the old
 * table actually has (DatabaseUpdater::carryRenamedRows). The column map is
 * written against the newest shape of the old table. A node that skipped the
 * release adding one of those columns has an older shape, and copying by the
 * fixed map failed the whole upgrade: the drive soak rig, 0.8.256 to 0.8.452,
 * whose del_debug_email_logs had no del_message.
 *
 * Runs inside a transaction that is rolled back, so it persists nothing.
 *
 * CLI:  php tests/schema/carry_renamed_rows_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$OLD = 'zz_carry_renamed_old';
$NEW = 'zz_carry_renamed_new';
$MAP = array(
    'zco_id'      => 'zcn_id',
    'zco_subject' => 'zcn_subject',
    // Added to the old table by a later release this node never had.
    'zco_message' => 'zcn_message',
);

$dblink = DbConnector::get_instance()->get_db_link();
$dblink->beginTransaction();

try {
    $dblink->exec("DROP TABLE IF EXISTS {$OLD} CASCADE");
    $dblink->exec("DROP TABLE IF EXISTS {$NEW} CASCADE");
    // The old table in its older shape: no zco_message.
    $dblink->exec("CREATE TABLE {$OLD} (zco_id int8 PRIMARY KEY, zco_subject text)");
    $dblink->exec("CREATE TABLE {$NEW} (zcn_id int8 PRIMARY KEY, zcn_subject text, zcn_message text)");
    $dblink->exec("INSERT INTO {$OLD} VALUES (3, 'three'), (7, 'seven')");

    section('An old table missing a mapped column still carries its rows');
    $copied = DatabaseUpdater::carryRenamedRows($dblink, $OLD, $NEW, $MAP);
    ok('both rows copied', $copied === 2, "copied {$copied}");
    $rows = $dblink->query("SELECT zcn_id, zcn_subject, zcn_message FROM {$NEW} ORDER BY zcn_id")->fetchAll(PDO::FETCH_NUM);
    ok('ids and the columns it had are kept', $rows[0][0] == 3 && $rows[0][1] === 'three' && $rows[1][0] == 7 && $rows[1][1] === 'seven',
        json_encode($rows));
    ok('the column it lacked is left at its default', $rows[0][2] === null && $rows[1][2] === null, json_encode($rows));

    section('A rerun copies nothing twice');
    $again = DatabaseUpdater::carryRenamedRows($dblink, $OLD, $NEW, $MAP);
    ok('nothing copied on a rerun', $again === 0, "copied {$again}");

    section('The full shape carries every column');
    $dblink->exec("ALTER TABLE {$OLD} ADD COLUMN zco_message text");
    $dblink->exec("INSERT INTO {$OLD} VALUES (9, 'nine', 'the message')");
    $copied = DatabaseUpdater::carryRenamedRows($dblink, $OLD, $NEW, $MAP);
    $msg = $dblink->query("SELECT zcn_message FROM {$NEW} WHERE zcn_id = 9")->fetchColumn();
    ok('the new row and its message are carried', $copied === 1 && $msg === 'the message', "copied {$copied}, message " . var_export($msg, true));

    section('No primary key, no carry');
    $dblink->exec("DROP TABLE {$OLD}");
    $dblink->exec("CREATE TABLE {$OLD} (zco_subject text)");
    $threw = false;
    try {
        DatabaseUpdater::carryRenamedRows($dblink, $OLD, $NEW, $MAP);
    } catch (Exception $e) {
        $threw = strpos($e->getMessage(), 'zco_id') !== false;
    }
    ok('an old table without the primary key is refused, by name', $threw);

} catch (Exception $e) {
    ok('carry ran without exception', false, $e->getMessage());
} finally {
    $dblink->rollBack();
}

harness_finish();

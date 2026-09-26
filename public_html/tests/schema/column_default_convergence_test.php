<?php
/** @joinery-test
 * name: column_default_convergence
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * A declared default the database disagrees with. update_database sets a
 * missing default, and a boolean or number default that differs is set to the
 * spec's value on an upgrade run: both sides have one spelling, so the
 * difference is real. dev's bkt_backup_targets.bkt_mint_run_keys defaulted to
 * true against a spec of false, left by an earlier version of the spec, and was
 * only ever reported. A string or expression default that differs is still only
 * reported, because Postgres rewrites those ('x'::character varying, now()).
 *
 * Runs inside a transaction that is rolled back, so it persists nothing.
 *
 * CLI:  php tests/schema/column_default_convergence_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));

$TEST_TABLE = 'zz_default_convergence_test';

class DefaultConvergenceStub {
    public static $tablename = 'zz_default_convergence_test';
    public static $field_specifications = array(
        'zdc_id'    => array('type' => 'int8'),
        'zdc_flag'  => array('type' => 'bool', 'default' => false),
        'zdc_on'    => array('type' => 'bool', 'default' => true),
        'zdc_count' => array('type' => 'int4', 'default' => 7),
        'zdc_zero'  => array('type' => 'int4', 'default' => 0),
        'zdc_neg'   => array('type' => 'int4', 'default' => -1),
        'zdc_ratio' => array('type' => 'numeric(4,2)', 'default' => 0.5),
        'zdc_label' => array('type' => 'varchar(32)', 'default' => 'new'),
        'zdc_when'  => array('type' => 'timestamp(6)', 'default' => 'now()'),
        'zdc_since' => array('type' => 'bool', 'default' => false),
    );
}

function live_defaults($dblink, $table) {
    $q = $dblink->prepare("SELECT column_name, column_default FROM information_schema.columns
                           WHERE table_schema = 'public' AND table_name = :t");
    $q->execute([':t' => $table]);
    return $q->fetchAll(PDO::FETCH_KEY_PAIR);
}

function mentions($list, $needle) {
    return count(array_filter($list, function ($m) use ($needle) { return strpos($m, $needle) !== false; }));
}

$dblink = DbConnector::get_instance()->get_db_link();
$dblink->beginTransaction();

try {
    $dblink->exec("DROP TABLE IF EXISTS {$TEST_TABLE} CASCADE");
    $dblink->exec("CREATE TABLE {$TEST_TABLE} (
        zdc_id    bigserial PRIMARY KEY,
        zdc_flag  boolean      DEFAULT true,
        zdc_on    boolean      DEFAULT true,
        zdc_count integer      DEFAULT 5,
        zdc_zero  integer      DEFAULT 10,
        zdc_neg   integer      DEFAULT -1,
        zdc_ratio numeric(4,2) DEFAULT 0.50,
        zdc_label varchar(32)  DEFAULT 'old',
        zdc_when  timestamp(6) DEFAULT now(),
        zdc_since boolean
    )");

    section('An ordinary run changes no default');
    $plain = new DatabaseUpdater(false, false, false);
    $plain->processAdvancedColumnOperations([DefaultConvergenceStub::class], false);
    $d = live_defaults($dblink, $TEST_TABLE);
    ok('the differing boolean default is left as it is without --upgrade', $d['zdc_flag'] === 'true', $d['zdc_flag']);

    section('An upgrade run sets a differing boolean or number default to the spec');
    $upgrade = new DatabaseUpdater(false, true, false);
    $r = $upgrade->processAdvancedColumnOperations([DefaultConvergenceStub::class], false);
    $d = live_defaults($dblink, $TEST_TABLE);
    ok('column processing succeeded', $r['success'] === true, implode('; ', $r['errors']));
    ok('boolean true -> the spec\'s false (the bkt_mint_run_keys case)', $d['zdc_flag'] === 'false', $d['zdc_flag']);
    ok('number 5 -> the spec\'s 7', $d['zdc_count'] === '7', $d['zdc_count']);
    ok('number 10 -> the spec\'s 0 (a substring match took 10 for 0)', $d['zdc_zero'] === '0', $d['zdc_zero']);
    ok('each change is reported with the old value', mentions($r['messages'], 'Changed column default: ' . $TEST_TABLE . '.zdc_flag DEFAULT false (was true)') === 1
        && mentions($r['messages'], 'Changed column default:') === 3, implode(' | ', $r['messages']));
    ok('a missing default is set as before', $d['zdc_since'] === 'false', (string)$d['zdc_since']);

    section('An equal default is left alone, however Postgres spells it');
    ok('boolean true = true', $d['zdc_on'] === 'true', $d['zdc_on']);
    ok('-1 stays -1', mentions($r['messages'], '.zdc_neg') === 0, (string)$d['zdc_neg']);
    ok('0.50 on numeric(4,2) = 0.5', mentions($r['messages'], '.zdc_ratio') === 0, (string)$d['zdc_ratio']);
    ok('now() = now()', mentions($r['messages'], '.zdc_when') === 0 && mentions($r['warnings'], '.zdc_when') === 0);

    section('A differing string default is reported, never rewritten');
    ok('the string default is unchanged', strpos((string)$d['zdc_label'], "'old'") === 0, (string)$d['zdc_label']);
    ok('and named as drift', mentions($r['warnings'], "Column default drift (not changed): {$TEST_TABLE}.zdc_label") === 1, implode(' | ', $r['warnings']));

    section('Idempotent');
    $r2 = $upgrade->processAdvancedColumnOperations([DefaultConvergenceStub::class], false);
    ok('a second run changes no default', mentions($r2['messages'], 'Changed column default:') === 0 && mentions($r2['messages'], 'Set missing column default:') === 0,
        implode(' | ', $r2['messages']));

} catch (Exception $e) {
    ok('default convergence ran without exception', false, $e->getMessage());
} finally {
    $dblink->rollBack();
}

harness_finish();

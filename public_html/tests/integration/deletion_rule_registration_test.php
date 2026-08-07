<?php
/**
 * Integration test for DeletionRule::registerModelRules()'s override/warning
 * semantics and DeletionRule::pruneOrphanedRules()
 * (specs/implemented/deletion_rule_autodetector_table_guess_bug.md).
 *
 * Registers a handful of in-process fixture "model" classes (never touching
 * any real business table) directly against the real del_deletion_rules
 * table, asserting:
 *   - an explicit 'source_table' override registers against exactly that table
 *   - a column that resolves by naming convention (real 'usr' prefix) with no
 *     declared action registers as prevent, with a message naming the model
 *     and column - an undeclared relationship must fail loudly, never guess
 *     a destructive cascade
 *   - an ambiguous prefix (two models claim it) resolves by matching the
 *     entity embedded in the column name, never by discovery order
 *   - a declared $foreign_key_actions key that resolves neither by
 *     convention nor by an override returns a warning and registers nothing
 *   - an FK-shaped column with NO declaration that also fails to resolve by
 *     convention registers nothing and produces no warning (not every
 *     unresolved column is a configuration bug)
 *   - the primary key column is never treated as a foreign key
 *   - pruneOrphanedRules() removes rules referencing a table no loaded model
 *     declares, and leaves rules for real tables (e.g. usr_users) alone
 *
 * Writes and cleans up its own del_deletion_rules rows (target tables
 * prefixed zzfix_, never used by a real model). Run:
 *   php tests/integration/deletion_rule_registration_test.php
 *
 * @version 1.2
 */
/** @joinery-test
 * name: deletion_rule_registration
 * tier: db
 * env: dev-only
 * needs: []
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('data/deletion_rule_class.php'));

// --- Fixture "model" classes - plain classes with just the statics
// registerModelRules() reads via reflection. None of these tablenames are
// ever created for real; they only ever appear as strings in del_deletion_rules.

class ZZFixtureOverrideModel {
    public static $tablename = 'zzfix_override_target';
    public static $prefix = 'zzo';
    public static $pkey_column = 'zzo_id';
    public static $field_specifications = [
        'zzo_id'             => ['type' => 'int8'],
        'zzo_owner_user_id'  => ['type' => 'int4'],
    ];
    protected static $foreign_key_actions = [
        'zzo_owner_user_id' => ['action' => 'cascade', 'source_table' => 'usr_users'],
    ];
}

class ZZFixtureConventionModel {
    public static $tablename = 'zzfix_convention_target';
    public static $prefix = 'zzc';
    public static $pkey_column = 'zzc_id';
    public static $field_specifications = [
        'zzc_id'           => ['type' => 'int8'],
        'zzc_usr_user_id'  => ['type' => 'int4'],
    ];
}

class ZZFixtureWarnModel {
    public static $tablename = 'zzfix_warn_target';
    public static $prefix = 'zzw';
    public static $pkey_column = 'zzw_id';
    public static $field_specifications = [
        'zzw_id'              => ['type' => 'int8'],
        'zzw_mystery_thing'   => ['type' => 'int4'],
    ];
    protected static $foreign_key_actions = [
        'zzw_mystery_thing' => ['action' => 'cascade'],
    ];
}

class ZZFixtureSkipModel {
    public static $tablename = 'zzfix_skip_target';
    public static $prefix = 'zzs';
    public static $pkey_column = 'zzs_id';
    public static $field_specifications = [
        'zzs_id'              => ['type' => 'int8'],
        'zzs_owner_user_id'   => ['type' => 'int4'],
    ];
}

class ZZFixturePkeyModel {
    public static $tablename = 'zzfix_pkey_target';
    public static $prefix = 'zzp';
    public static $pkey_column = 'zzp_usr_user_id';
    public static $field_specifications = [
        'zzp_usr_user_id' => ['type' => 'int8', 'serial' => true],
    ];
}

$db = DbConnector::get_instance()->get_db_link();

function rules_for_target($db, $target_table) {
    $stmt = $db->prepare("SELECT * FROM del_deletion_rules WHERE del_target_table = ?");
    $stmt->execute([$target_table]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function cleanup_fixture_rows($db) {
    $db->prepare("DELETE FROM del_deletion_rules WHERE del_target_table LIKE 'zzfix\\_%' ESCAPE '\\'")->execute();
}

// Start clean in case a prior interrupted run left rows behind.
cleanup_fixture_rows($db);

try {
    // --- Explicit source_table override ------------------------------------
    $warnings = DeletionRule::registerModelRules('ZZFixtureOverrideModel');
    $rows = rules_for_target($db, 'zzfix_override_target');
    ok('explicit source_table override: no warnings', empty($warnings));
    ok('explicit source_table override: exactly one rule registered', count($rows) === 1);
    ok('explicit source_table override: registers against the declared source table',
        count($rows) === 1 && $rows[0]['del_source_table'] === 'usr_users');
    ok('explicit source_table override: uses the declared action',
        count($rows) === 1 && $rows[0]['del_action'] === 'cascade');

    // --- Convention-based resolution against a REAL model prefix -----------
    $warnings = DeletionRule::registerModelRules('ZZFixtureConventionModel');
    $rows = rules_for_target($db, 'zzfix_convention_target');
    ok('convention resolution via real "usr" prefix: no warnings', empty($warnings));
    ok('convention resolution via real "usr" prefix: exactly one rule registered', count($rows) === 1);
    ok('convention resolution via real "usr" prefix: resolves to usr_users',
        count($rows) === 1 && $rows[0]['del_source_table'] === 'usr_users');
    ok('convention resolution with no declared override: registers prevent, never a guessed cascade',
        count($rows) === 1 && $rows[0]['del_action'] === 'prevent');
    ok('undeclared relationship: prevent message names the model and column',
        count($rows) === 1
        && strpos((string)$rows[0]['del_message'], 'ZZFixtureConventionModel') !== false
        && strpos((string)$rows[0]['del_message'], 'zzc_usr_user_id') !== false);

    // --- Ambiguous prefix resolves by entity match, never discovery order ---
    // 'bkt' is claimed by both BackupTarget (bkt_backup_targets) and
    // BookingType (bkt_booking_types). The column name embeds the entity, so
    // each resolves to its own table; an abbreviated entity (bkt_target)
    // matches neither and must stay unrecognized.
    ok('ambiguous prefix: bkn_bkt_booking_type_id resolves to bkt_booking_types',
        DeletionRule::getSourceTableFromColumn('bkn_bkt_booking_type_id', 'bkn') === 'bkt_booking_types');
    ok('ambiguous prefix: mgn_bkt_backup_target_id resolves to bkt_backup_targets',
        DeletionRule::getSourceTableFromColumn('mgn_bkt_backup_target_id', 'mgn') === 'bkt_backup_targets');
    ok('ambiguous prefix: msg_cnv_conversation_id resolves to cnv_conversations, not content versions',
        DeletionRule::getSourceTableFromColumn('msg_cnv_conversation_id', 'msg') === 'cnv_conversations');
    ok('ambiguous prefix with abbreviated entity: bkh_bkt_target_id stays unrecognized',
        DeletionRule::getSourceTableFromColumn('bkh_bkt_target_id', 'bkh') === null);
    ok('unambiguous prefix: entity match is not required (usr resolves as before)',
        DeletionRule::getSourceTableFromColumn('ord_usr_user_id', 'ord') === 'usr_users');

    // --- Declared override that resolves neither by convention nor source_table
    $warnings = DeletionRule::registerModelRules('ZZFixtureWarnModel');
    $rows = rules_for_target($db, 'zzfix_warn_target');
    ok('unresolvable declared override: produces exactly one warning', count($warnings) === 1);
    ok('unresolvable declared override: names the column in the warning',
        count($warnings) === 1 && strpos($warnings[0], 'zzw_mystery_thing') !== false);
    ok('unresolvable declared override: registers nothing', count($rows) === 0);

    // --- FK-shaped column with no declaration at all, unresolvable by convention
    $warnings = DeletionRule::registerModelRules('ZZFixtureSkipModel');
    $rows = rules_for_target($db, 'zzfix_skip_target');
    ok('undeclared unresolvable column: no warning (not a configuration bug)', empty($warnings));
    ok('undeclared unresolvable column: registers nothing', count($rows) === 0);

    // --- Primary key is never treated as a foreign key ----------------------
    $warnings = DeletionRule::registerModelRules('ZZFixturePkeyModel');
    $rows = rules_for_target($db, 'zzfix_pkey_target');
    ok('primary key column: no warnings', empty($warnings));
    ok('primary key column: registers nothing', count($rows) === 0);

    // --- pruneOrphanedRules() ------------------------------------------------
    // Freshly (re-)register a real, on-disk model (Order's ord_usr_user_id ->
    // usr_users, a relationship that already resolved correctly even before
    // this fix) as a control: both sides are real tables, so this row must
    // survive pruning untouched. Idempotent and safe - this is exactly what
    // any normal sync already does for the Order model.
    require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
    DeletionRule::registerModelRules('Order');
    $stmt = $db->prepare(
        "SELECT del_id FROM del_deletion_rules WHERE del_target_table = 'ord_orders' AND del_source_table = 'usr_users'"
    );
    $stmt->execute();
    $control_id = $stmt->fetchColumn();
    ok('sanity: control rule (usr_users -> ord_orders) registered', $control_id !== false);

    $fixture_rows_before = count(rules_for_target($db, 'zzfix_override_target'))
        + count(rules_for_target($db, 'zzfix_convention_target'));
    ok('sanity: fixture rows exist before pruning', $fixture_rows_before === 2);

    $prune_messages = DeletionRule::pruneOrphanedRules();

    $stmt = $db->prepare("SELECT COUNT(*) FROM del_deletion_rules WHERE del_id = ?");
    $stmt->execute([$control_id]);
    ok('pruneOrphanedRules: a real usr_users -> ord_orders rule survives pruning',
        (int)$stmt->fetchColumn() === 1);

    ok('pruneOrphanedRules: removes rules for fixture (non-real) tables',
        count(rules_for_target($db, 'zzfix_override_target')) === 0
        && count(rules_for_target($db, 'zzfix_convention_target')) === 0);

    ok('pruneOrphanedRules: reports what it pruned', count($prune_messages) >= 2);

} finally {
    cleanup_fixture_rows($db);
}

harness_finish();

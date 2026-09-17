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
 *   - pruneOrphanedRules() also removes rules whose table a model declares
 *     but this database does not have (an inactive plugin's), proven by
 *     renaming a real plugin table away inside a transaction that is rolled
 *     back - one such rule refused every file delete on a site without the
 *     store plugin
 *
 * Writes and cleans up its own del_deletion_rules rows (target tables
 * prefixed zzfix_, never used by a real model). Run:
 *   php tests/integration/deletion_rule_registration_test.php
 *
 * @version 1.8 - a rule about a table this database does not have is pruned too
 * @version 1.7 - no prefix has two owners (InboundEmailFilter took ief); the walk is pinned by the invariant
 * @version 1.6 - cnv is a single-owner prefix (ContentVersion took cvn); the shared case is fil
 * @version 1.5 - the ambiguous-prefix cases move to cnv/fil; bty is a single-owner prefix
 * @version 1.4 - pins DeletionRule::pluralForms(), the one pluralization the engine and the
 *   validator share (y -> ies included)
 * @version 1.3 - bkh_bkt_backup_target_id carries the full entity and resolves; the abbreviated
 *   form is kept as the hypothetical that must stay unrecognized
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
require_once(PathHelper::getIncludePath('data/deletion_rules_class.php'));

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

    // --- Every prefix has one owner; a column resolves by its prefix ---
    // The six pairs that once shared a prefix are gone (specs/implemented/
    // shared_prefixes_first_three.md, shared_prefix_content_version.md,
    // shared_prefix_relay_cloud_provision.md, shared_prefix_inbound_email_filter.md),
    // so the prefix names the table and the entity part is not consulted.
    // The entity tie-break in getSourceTableFromColumn() stays for the day a
    // pair is created on purpose (the scaffolder only warns), but nothing
    // here can exercise it without one: the registry is built from files.
    $owners = ClassAutoloader::modelPrefixes();
    $shared = array_filter($owners, function ($classes) { return count($classes) > 1; });
    ok('no prefix is declared by two models', count($shared) === 0, json_encode($shared));
    ok('single-owner prefix: pst_fil_file_id resolves to fil_files',
        DeletionRule::getSourceTableFromColumn('pst_fil_file_id', 'pst') === 'fil_files');
    ok('single-owner prefix: msg_cnv_conversation_id resolves to cnv_conversations',
        DeletionRule::getSourceTableFromColumn('msg_cnv_conversation_id', 'msg') === 'cnv_conversations');
    ok('single-owner prefix: bkn_bty_booking_type_id resolves to bty_booking_types',
        DeletionRule::getSourceTableFromColumn('bkn_bty_booking_type_id', 'bkn') === 'bty_booking_types');
    ok('single-owner prefix: mgn_bkt_backup_target_id resolves to bkt_backup_targets',
        DeletionRule::getSourceTableFromColumn('mgn_bkt_backup_target_id', 'mgn') === 'bkt_backup_targets');
    ok('abbreviated entity under a single-owner prefix: bkh_bkt_target_id resolves by prefix alone',
        DeletionRule::getSourceTableFromColumn('bkh_bkt_target_id', 'bkh') === 'bkt_backup_targets');
    ok('the full entity: bkh_bkt_backup_target_id resolves to bkt_backup_targets',
        DeletionRule::getSourceTableFromColumn('bkh_bkt_backup_target_id', 'bkh') === 'bkt_backup_targets');
    ok('an unknown prefix stays unrecognized',
        DeletionRule::getSourceTableFromColumn('pst_zzz_thing_id', 'pst') === null);
    // The tie-break accepts every correct plural the validator's pkey check
    // does, from the one definition - a y -> ies table under a shared prefix
    // must resolve, not silently register nothing.
    ok('pluralForms: +s, +es, y -> ies and the uncountable form',
        DeletionRule::pluralForms('cat_category') === array('cat_category', 'cat_categorys', 'cat_categoryes', 'cat_categories')
        && in_array('bkh_backup_history', DeletionRule::pluralForms('bkh_backup_history'), true)
        && in_array('adr_addresses', DeletionRule::pluralForms('adr_address'), true));
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
        "SELECT del_deletion_rule_id FROM del_deletion_rules WHERE del_target_table = 'ord_orders' AND del_source_table = 'usr_users'"
    );
    $stmt->execute();
    $control_id = $stmt->fetchColumn();
    ok('sanity: control rule (usr_users -> ord_orders) registered', $control_id !== false);

    $fixture_rows_before = count(rules_for_target($db, 'zzfix_override_target'))
        + count(rules_for_target($db, 'zzfix_convention_target'));
    ok('sanity: fixture rows exist before pruning', $fixture_rows_before === 2);

    $prune_messages = DeletionRule::pruneOrphanedRules();

    $stmt = $db->prepare("SELECT COUNT(*) FROM del_deletion_rules WHERE del_deletion_rule_id = ?");
    $stmt->execute([$control_id]);
    ok('pruneOrphanedRules: a real usr_users -> ord_orders rule survives pruning',
        (int)$stmt->fetchColumn() === 1);

    ok('pruneOrphanedRules: removes rules for fixture (non-real) tables',
        count(rules_for_target($db, 'zzfix_override_target')) === 0
        && count(rules_for_target($db, 'zzfix_convention_target')) === 0);

    ok('pruneOrphanedRules: reports what it pruned', count($prune_messages) >= 2);

    // --- a table a model declares but this database lacks ---------------------
    // The store plugin's prq_product_requirements has rules from fil_files and
    // qst_questions. On a site where the plugin is not active the table is not
    // there, and the engine's COUNT against it failed every file delete. The
    // table is renamed away inside a transaction so the database is exactly
    // as it was afterwards, whatever happens in between.
    $absent = DeletionRule::tablesAbsentFromDatabase(['fil_files', 'zzfix_no_such_table']);
    ok('tablesAbsentFromDatabase: names only the tables that are not there',
        $absent === ['zzfix_no_such_table' => true]);

    require_once(PathHelper::getIncludePath('plugins/store/data/product_requirements_class.php'));
    DeletionRule::registerModelRules('ProductRequirement');
    $prq_before = count(rules_for_target($db, 'prq_product_requirements'));
    ok('sanity: prq_product_requirements has rules to lose', $prq_before >= 1);

    $db->beginTransaction();
    try {
        $db->exec('ALTER TABLE prq_product_requirements RENAME TO zzfix_prq_hidden');
        $gone_messages = DeletionRule::pruneOrphanedRules();
        $prq_during = count(rules_for_target($db, 'prq_product_requirements'));
        $stmt = $db->prepare("SELECT COUNT(*) FROM del_deletion_rules WHERE del_deletion_rule_id = ?");
        $stmt->execute([$control_id]);
        $control_during = (int)$stmt->fetchColumn();
    } finally {
        $db->rollBack();
    }
    ok('pruneOrphanedRules: a rule about a table this database lacks is pruned', $prq_during === 0);
    // At least the rules targeting the table; rules it is the SOURCE of go too.
    ok('pruneOrphanedRules: says why', count(array_filter($gone_messages, function ($m) {
        return strpos($m, 'prq_product_requirements') !== false && strpos($m, 'not in this database') !== false;
    })) >= $prq_before);
    ok('pruneOrphanedRules: a rule whose tables are all present survives', $control_during === 1);
    ok('the rollback put the rules back', count(rules_for_target($db, 'prq_product_requirements')) === $prq_before);

} finally {
    cleanup_fixture_rows($db);
}

harness_finish();

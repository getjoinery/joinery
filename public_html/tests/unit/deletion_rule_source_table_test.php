<?php
/** @joinery-test
 * name: deletion_rule_source_table
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Unit test for the deletion-rule auto-detector's source-table resolution
 * (specs/implemented/deletion_rule_autodetector_table_guess_bug.md): a real
 * prefix -> tablename lookup built from every loaded model, instead of a
 * guessed pluralization.
 *
 * Runs offline: discovers real model classes from disk (no DB writes) and
 * reflects into DeletionRule's private static resolver.
 * Run: php tests/unit/deletion_rule_source_table_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('data/deletion_rule_class.php'));

function resolve($column, $own_prefix) {
    $method = new ReflectionMethod('DeletionRule', 'getSourceTableFromColumn');
    return $method->invoke(null, $column, $own_prefix);
}

// --- Real Class B mis-guesses from the bug spec now resolve correctly -----
ok('aip_rcr_run_id (own prefix aip) resolves to the real rcr_recipe_runs table',
    resolve('aip_rcr_run_id', 'aip') === 'rcr_recipe_runs');

ok('evt_svy_survey_id (own prefix evt) resolves to svy_surveys, not the old "svy_surveies" guess',
    resolve('evt_svy_survey_id', 'evt') === 'svy_surveys');

// Six-segment column the old 4-segment regex never even matched
ok('ieg_iea_inbound_email_alias_id (own prefix ieg) resolves via the iea prefix',
    resolve('ieg_iea_inbound_email_alias_id', 'ieg') === 'iea_inbound_email_aliases');

// Suffixed column ("_leader") the old regex also never matched
ok('evt_usr_user_id_leader (own prefix evt) resolves via the usr prefix',
    resolve('evt_usr_user_id_leader', 'evt') === 'usr_users');

ok('msg_usr_user_id_sender (own prefix msg) resolves via the usr prefix',
    resolve('msg_usr_user_id_sender', 'msg') === 'usr_users');

// --- Columns that must NOT resolve (never a guess) -------------------------
ok('a primary-key-shaped column whose middle segment is not a real model prefix resolves to null',
    resolve('act_activation_code_id', 'act') === null);

ok('a role-named column (no target-model prefix segment) resolves to null, not a guess',
    resolve('rcp_owner_user_id', 'rcp') === null);

ok('a self-referential parent column resolves to null absent an explicit source_table',
    resolve('evt_parent_event_id', 'evt') === null);

ok('an external id column (not an internal FK) resolves to null',
    resolve('usr_mailing_list_provider_id', 'usr') === null);

ok('a column not prefixed with the given own_prefix resolves to null',
    resolve('evt_svy_survey_id', 'xyz') === null);

ok('a column with no underscore-separated remainder resolves to null',
    resolve('evt_id', 'evt') === null);

harness_finish();

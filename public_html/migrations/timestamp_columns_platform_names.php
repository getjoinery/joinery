<?php
/**
 * Timestamp columns carry the platform's spelling.
 *
 * The platform names a row's timestamps {prefix}_create_time,
 * {prefix}_update_time and {prefix}_delete_time; eighteen columns on
 * fourteen tables spelled them _created_time, _updated_time,
 * _modified_time or _modify_time. Each takes the platform name.
 *
 * update_database adds every new column from the spec before this runs.
 * This copies the old value across and drops the old column, one column
 * at a time. A table that is absent (its plugin is not active here) or a
 * column already gone is left alone. The migration runner holds the
 * transaction.
 */
function timestamp_columns_platform_names() {
    $db = DbConnector::get_instance()->get_db_link();

    $renames = array(
        array('abt_tests', 'abt_modified_time', 'abt_update_time'),
        array('abv_variants', 'abv_modified_time', 'abv_update_time'),
        array('aqa_ai_queued_actions', 'aqa_created_time', 'aqa_create_time'),
        array('cmt_comments', 'cmt_created_time', 'cmt_create_time'),
        array('del_deletion_rules', 'del_created_time', 'del_create_time'),
        array('imi_inbound_mailbox_search_index', 'imi_created_time', 'imi_create_time'),
        array('imi_inbound_mailbox_search_index', 'imi_updated_time', 'imi_update_time'),
        array('pkc_passkey_credentials', 'pkc_created_time', 'pkc_create_time'),
        array('pks_passkey_ceremonies', 'pks_created_time', 'pks_create_time'),
        array('pri_product_requirement_instances', 'pri_created_time', 'pri_create_time'),
        array('spm_seo_page_metadata', 'spm_modify_time', 'spm_update_time'),
        array('uev_user_encryption_vaults', 'uev_created_time', 'uev_create_time'),
        array('uev_user_encryption_vaults', 'uev_updated_time', 'uev_update_time'),
        array('uew_user_encryption_wrappings', 'uew_created_time', 'uew_create_time'),
        array('vle_vault_entries', 'vle_created_time', 'vle_create_time'),
        array('vle_vault_entries', 'vle_updated_time', 'vle_update_time'),
        array('vlk_vault_keyring', 'vlk_created_time', 'vlk_create_time'),
        array('vlk_vault_keyring', 'vlk_updated_time', 'vlk_update_time'),
    );

    $has_column = function ($table, $column) use ($db) {
        $q = $db->prepare(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = :t AND column_name = :c");
        $q->execute(array(':t' => $table, ':c' => $column));
        return $q->fetchColumn() !== false;
    };

    foreach ($renames as $r) {
        list($table, $old, $new) = $r;

        $q = $db->prepare("SELECT to_regclass(:t)");
        $q->execute(array(':t' => 'public.' . $table));
        if ($q->fetchColumn() === null) {
            echo "  {$table}: absent here, skipped\n";
            continue;
        }
        if (!$has_column($table, $old)) {
            echo "  {$table}.{$old}: already gone\n";
            continue;
        }
        if (!$has_column($table, $new)) {
            throw new Exception("{$table}.{$new} not yet added - run the schema pass first");
        }

        // The spec pass filled a defaulted new column (create_time default
        // now()) on every existing row; the old value is the true one.
        $q = $db->prepare("UPDATE {$table} SET {$new} = {$old} WHERE {$old} IS NOT NULL");
        $q->execute();
        $copied = $q->rowCount();
        $db->exec("ALTER TABLE {$table} DROP COLUMN {$old}");
        echo "  {$table}: {$old} -> {$new}, {$copied} values copied, old column dropped\n";
    }
}
?>

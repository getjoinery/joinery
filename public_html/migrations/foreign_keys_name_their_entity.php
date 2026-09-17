<?php
/**
 * Foreign-key columns name their target's full entity.
 *
 * A column such as ajr_mgn_node_id resolved to mgn_managed_nodes only while
 * exactly one model claimed the prefix mgn; the deletion engine matches the
 * entity in the name against the candidate tables, and 'node' matches none
 * of them. Each of these columns takes the full entity
 * (ajr_mgn_managed_node_id), so it resolves by name whatever else claims
 * the prefix.
 *
 * update_database adds every new column from the spec before this runs.
 * This copies the old value across and drops the old column, table by
 * table. A table that is absent (its plugin is not active here) or already
 * migrated is left alone. The migration runner holds the transaction.
 */
function foreign_keys_name_their_entity() {
    $db = DbConnector::get_instance()->get_db_link();

    $renames = array(
        array('ajr_agent_join_requests', 'ajr_mgn_node_id', 'ajr_mgn_managed_node_id'),
        array('cvp_customer_cloud_provisions', 'cvp_mgn_node_id', 'cvp_mgn_managed_node_id'),
        array('inc_incident_records', 'inc_mgn_node_id', 'inc_mgn_managed_node_id'),
        array('mjb_management_jobs', 'mjb_mgn_node_id', 'mjb_mgn_managed_node_id'),
        array('rdm_registered_domains', 'rdm_mgn_node_id', 'rdm_mgn_managed_node_id'),
        array('mgh_managed_hosts', 'mgh_mgn_host_node_id', 'mgh_mgn_managed_node_id'),
        array('mgn_managed_nodes', 'mgn_mgh_host_id', 'mgn_mgh_managed_host_id'),
        array('cvp_customer_cloud_provisions', 'cvp_cca_account_id', 'cvp_cca_customer_cloud_account_id'),
        array('htr_hosted_trials', 'htr_cvp_provision_id', 'htr_cvp_customer_cloud_provision_id'),
        array('mfd_mailbox_fleet_domain_claims', 'mfd_mft_slot_id', 'mfd_mft_mailbox_fleet_slot_id'),
        array('mft_mailbox_fleet_slots', 'mft_mfs_shard_id', 'mft_mfs_mailbox_fleet_shard_id'),
        array('rcp_relay_cloud_provisions', 'rcp_mfs_shard_id', 'rcp_mfs_mailbox_fleet_shard_id'),
        array('aia_message_attachments', 'aia_aim_message_id', 'aia_aim_conversation_message_id'),
        array('aip_recipe_item_log', 'aip_rcr_run_id', 'aip_rcr_recipe_run_id'),
        array('pro_products', 'pro_emt_receipt_template_id', 'pro_emt_email_template_id'),
        array('uew_user_encryption_wrappings', 'uew_pkc_credential_id', 'uew_pkc_passkey_credential_id'),
        array('bkh_backup_history', 'bkh_bkt_target_id', 'bkh_bkt_backup_target_id'),
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

        $q = $db->prepare("UPDATE {$table} SET {$new} = {$old} WHERE {$new} IS NULL AND {$old} IS NOT NULL");
        $q->execute();
        $copied = $q->rowCount();
        $db->exec("ALTER TABLE {$table} DROP COLUMN {$old}");
        echo "  {$table}: {$old} -> {$new}, {$copied} values copied, old column dropped\n";
    }
}
?>

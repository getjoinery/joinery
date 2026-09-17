<?php
/**
 * Primary keys name their table: {prefix}_{singular}_id.
 *
 * Twenty-three models keyed their rows with a bare {prefix}_id (mgn_id) or
 * a shortened entity (rcr_run_id). Each takes the platform form
 * (mgn_managed_node_id, rcr_recipe_run_id), so a column carrying the key
 * elsewhere (mjb_mgn_managed_node_id) reads back to its table by name.
 *
 * What update_database does before this runs, in upgrade mode: the spec
 * pass adds the new column with its own sequence; the primary-key fix
 * fills the new column from that sequence (1..N in physical order — not
 * the old ids), drops the old primary key and makes the new column the
 * key. This migration then puts the real ids in place, per table:
 *
 *   1. drop the primary key, CASCADE — a foreign-key constraint that
 *      depends on it goes with it, and the foreign-key step that follows
 *      migrations materializes every declared one again;
 *   2. copy old -> new (with the key off: Postgres checks uniqueness per
 *      row during an UPDATE, and swapping ids in place would collide
 *      mid-statement);
 *   3. put the primary key on the new column;
 *   4. set the new sequence past the highest id — by its real name, read
 *      off the column default, because the platform's sequences are not
 *      OWNED BY their column and pg_get_serial_sequence() knows nothing of
 *      them;
 *   5. drop the old column, and its sequence by name.
 *
 * On a run without the primary-key fix (no --upgrade) the new column is
 * simply NULL throughout; the same five steps apply. A table that is absent
 * (its plugin is not active here) or already keyed by the new column is
 * left alone. The migration runner holds the transaction, so a failure
 * leaves the old key in place.
 * A table whose plugin is inactive here has no spec pass to add the new
 * column, so the column and its sequence are renamed in place instead.
 */
function primary_keys_name_their_table() {
    $db = DbConnector::get_instance()->get_db_link();

    $renames = array(
        array('ahb_agent_heartbeats',         'ahb_id',                          'ahb_agent_heartbeat_id'),
        array('ajr_agent_join_requests',      'ajr_id',                          'ajr_agent_join_request_id'),
        array('aim_conversation_messages',    'aim_message_id',                  'aim_conversation_message_id'),
        array('aia_message_attachments',      'aia_attachment_id',               'aia_message_attachment_id'),
        array('aip_recipe_item_log',          'aip_log_id',                      'aip_recipe_item_log_id'),
        array('bkh_backup_history',           'bkh_id',                          'bkh_backup_history_id'),
        array('bkt_backup_targets',           'bkt_id',                          'bkt_backup_target_id'),
        array('cex_entry_exceptions',         'cex_calendar_entry_exception_id', 'cex_entry_exception_id'),
        array('cal_entries',                  'cal_calendar_entry_id',           'cal_entry_id'),
        array('cca_customer_cloud_accounts',  'cca_id',                          'cca_customer_cloud_account_id'),
        array('cvp_customer_cloud_provisions','cvp_id',                          'cvp_customer_cloud_provision_id'),
        array('del_deletion_rules',           'del_id',                          'del_deletion_rule_id'),
        array('htr_hosted_trials',            'htr_id',                          'htr_hosted_trial_id'),
        array('inc_incident_records',         'inc_id',                          'inc_incident_record_id'),
        array('mgh_managed_hosts',            'mgh_id',                          'mgh_managed_host_id'),
        array('mgn_managed_nodes',            'mgn_id',                          'mgn_managed_node_id'),
        array('mjb_management_jobs',          'mjb_id',                          'mjb_management_job_id'),
        array('pas_persona_allowed_senders',  'pas_allowed_sender_id',           'pas_persona_allowed_sender_id'),
        array('pbs_persona_blocked_senders',  'pbs_blocked_sender_id',           'pbs_persona_blocked_sender_id'),
        array('rcr_recipe_runs',              'rcr_run_id',                      'rcr_recipe_run_id'),
        array('rdm_registered_domains',       'rdm_id',                          'rdm_registered_domain_id'),
        array('rcp_relay_cloud_provisions',   'rcp_id',                          'rcp_relay_cloud_provision_id'),
        array('ssr_sealed_secret_registry',   'ssr_id',                          'ssr_sealed_secret_registry_id'),
    );

    $has_column = function ($table, $column) use ($db) {
        $q = $db->prepare(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = :t AND column_name = :c");
        $q->execute(array(':t' => $table, ':c' => $column));
        return $q->fetchColumn() !== false;
    };
    $sequence_of = function ($table, $column) use ($db) {
        $q = $db->prepare(
            "SELECT pg_get_expr(d.adbin, d.adrelid) FROM pg_attrdef d
               JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
              WHERE d.adrelid = (:t)::regclass AND a.attname = :c");
        $q->execute(array(':t' => 'public.' . $table, ':c' => $column));
        return preg_match("/nextval\\('([^']+)'/", (string)$q->fetchColumn(), $m) ? $m[1] : null;
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
            // The table is here but its plugin is not active, so no spec
            // pass added the new column and none will until the plugin is
            // activated. Rename the column and its sequence in place; the
            // key and the column default follow the rename.
            $old_sequence = $sequence_of($table, $old);
            $db->exec("ALTER TABLE {$table} RENAME COLUMN {$old} TO {$new}");
            if ($old_sequence !== null && $old_sequence === "{$table}_{$old}_seq") {
                $db->exec("ALTER SEQUENCE {$old_sequence} RENAME TO {$table}_{$new}_seq");
            }
            echo "  {$table}: {$old} -> {$new}, renamed in place (plugin not active here)\n";
            continue;
        }
        $new_sequence = $sequence_of($table, $new);
        if ($new_sequence === null) {
            throw new Exception("{$table}.{$new} has no sequence default - the schema pass did not finish");
        }
        $old_sequence = $sequence_of($table, $old);

        // 1. the key comes off, and any foreign key that depends on it
        $q = $db->prepare("SELECT conname FROM pg_constraint WHERE conrelid = (:t)::regclass AND contype = 'p'");
        $q->execute(array(':t' => 'public.' . $table));
        $pkey_constraint = $q->fetchColumn();
        if ($pkey_constraint !== false) {
            $db->exec("ALTER TABLE {$table} DROP CONSTRAINT \"{$pkey_constraint}\" CASCADE");
        }

        // 2. the real ids
        $q = $db->prepare("UPDATE {$table} SET {$new} = {$old}");
        $q->execute();
        $copied = $q->rowCount();

        // 3. the key goes on the new column
        $db->exec("ALTER TABLE {$table} ALTER COLUMN {$new} SET NOT NULL");
        $db->exec("ALTER TABLE {$table} ADD CONSTRAINT \"{$table}_pkey\" PRIMARY KEY ({$new})");

        // 4. the new sequence continues past the highest id
        $db->exec(
            "SELECT setval('{$new_sequence}',
                           GREATEST((SELECT coalesce(max({$new}), 0) FROM {$table}), 1),
                           (SELECT count(*) > 0 FROM {$table}))");

        // 5. the old column and its sequence
        $db->exec("ALTER TABLE {$table} DROP COLUMN {$old}");
        if ($old_sequence !== null && $old_sequence !== $new_sequence) {
            $db->exec("DROP SEQUENCE IF EXISTS {$old_sequence}");
        }
        echo "  {$table}: {$old} -> {$new}, {$copied} ids kept, sequence carried, old column dropped\n";
    }
}
?>

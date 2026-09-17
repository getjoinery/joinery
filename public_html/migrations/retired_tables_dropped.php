<?php
/**
 * Tables of retired features are gone.
 *
 * update_database never drops a table, so a table whose feature was retired
 * stays on every node that ever had it. These nine are retired on the
 * record — each named with what replaced it:
 *
 *   cdb_ctlddevice_backups, cdd_ctlddevices, cdf_ctldfilters,
 *   cdp_ctldprofiles, cdr_ctldrules, cds_ctldservices
 *       the ControlD-era ScrollDaddy schema; renamed to sd* / sb*
 *       (specs/implemented/scrolldaddy-rename-ctld-to-sd.md)
 *   cls_cart_logs
 *       cart logging, no code since 2021, not in the installer
 *   ers_recurring_email_logs
 *       the recurring mailer, no entry point since 2021; its last two callers
 *       went in 2026 (8ec04cde, 19266f40)
 *   iem_inbound_emails
 *       the Mailgun-stored inbound store; iem_inbound_email_messages is the
 *       mailbox (plugins/mailbox/docs/overview.md)
 *   lck_license_keys
 *       license keys live on own_ownerships.own_license_key
 *       (specs/implemented/store_own_once_products.md). Dropped only when
 *       empty: a node whose keys were never carried over keeps the table and
 *       says so, and maintenance_scripts/dev_tools/carry_over_license_keys_to_ownerships.php
 *       is the carry-over.
 *   rqt_requirement_types
 *       requirement types are discovered from includes/requirements/, never
 *       registered (specs/implemented/product_requirements_refactor.md)
 *
 * NOT here, deliberately: bke_backup_key_escrow. Nothing writes it, but its
 * rows are the sealed node keys for pre-envelope archives still in buckets
 * and the agent-signing-key backup (specs/implemented/backups_core_and_incremental.md,
 * server_manager_permanent_node_delete.md: keep).
 *
 * Idempotent: a table already gone is skipped. The migration runner holds
 * the transaction.
 */
function retired_tables_dropped() {
    $db = DbConnector::get_instance()->get_db_link();

    $unconditional = array(
        'cdb_ctlddevice_backups', 'cdd_ctlddevices', 'cdf_ctldfilters',
        'cdp_ctldprofiles', 'cdr_ctldrules', 'cds_ctldservices',
        'cls_cart_logs', 'ers_recurring_email_logs', 'iem_inbound_emails',
        'rqt_requirement_types',
    );
    $only_when_empty = array('lck_license_keys');

    $exists = function ($table) use ($db) {
        $q = $db->prepare("SELECT to_regclass(:t)");
        $q->execute(array(':t' => 'public.' . $table));
        return $q->fetchColumn() !== null;
    };

    foreach ($unconditional as $table) {
        if (!$exists($table)) {
            echo "  {$table}: already gone\n";
            continue;
        }
        $rows = (int)$db->query("SELECT count(*) FROM {$table}")->fetchColumn();
        $db->exec("DROP TABLE {$table}");
        echo "  {$table}: dropped ({$rows} rows)\n";
    }

    foreach ($only_when_empty as $table) {
        if (!$exists($table)) {
            echo "  {$table}: already gone\n";
            continue;
        }
        $rows = (int)$db->query("SELECT count(*) FROM {$table}")->fetchColumn();
        if ($rows > 0) {
            echo "  {$table}: KEPT - {$rows} rows never carried over; run "
               . "maintenance_scripts/dev_tools/carry_over_license_keys_to_ownerships.php --apply, then drop by hand\n";
            continue;
        }
        $db->exec("DROP TABLE {$table}");
        echo "  {$table}: dropped (empty)\n";
    }

    // Sequences the platform created for these tables are not OWNED BY a
    // column, so a DROP TABLE leaves them behind.
    $q = $db->query(
        "SELECT sequencename FROM pg_sequences
          WHERE schemaname = 'public'
            AND sequencename ~ '^(cdb_ctlddevice_backups|cdd_ctlddevices|cdf_ctldfilters|cdp_ctldprofiles|cdr_ctldrules|cds_ctldservices|cls_cart_logs|ers_recurring_email_logs|iem_inbound_emails|rqt_requirement_types|lck_license_keys)_'");
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $sequence) {
        $table = preg_replace('/_[a-z]{3}_[a-z_]+_seq$/', '', $sequence);
        if ($exists($table)) {
            continue; // the table stayed (lck with rows); its sequence stays too
        }
        $db->exec("DROP SEQUENCE IF EXISTS {$sequence}");
        echo "  {$sequence}: dropped\n";
    }
}
?>

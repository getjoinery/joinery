<?php
function rename_inbound_email_to_mailbox() {
    $dblink = DbConnector::get_instance()->get_db_link();
    $total = 0;

    // 1. Plugin registry row (must precede PluginManager::sync()).
    $stmt = $dblink->prepare("UPDATE plg_plugins SET plg_name='mailbox' WHERE plg_name='inbound_email'");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  plg_plugins: renamed $n row(s)\n";
    $total += $n;

    // 2. Applied-migration ledger. Without this, sync under the new name sees
    //    zero applied migrations for 'mailbox' and re-runs all 13.
    $stmt = $dblink->prepare("UPDATE plm_plugin_migrations SET plm_plugin_name='mailbox' WHERE plm_plugin_name='inbound_email'");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  plm_plugin_migrations: rebound $n row(s)\n";
    $total += $n;

    // 3. Version tracking (row may not exist on every deployment; 0 rows is fine).
    $stmt = $dblink->prepare("UPDATE plv_plugin_versions SET plv_plugin_name='mailbox' WHERE plv_plugin_name='inbound_email'");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  plv_plugin_versions: rebound $n row(s)\n";
    $total += $n;

    // 4. Scheduled-task plugin binding (rows exist only where tasks were registered).
    $stmt = $dblink->prepare("UPDATE sct_scheduled_tasks SET sct_plugin_name='mailbox' WHERE sct_plugin_name='inbound_email'");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  sct_scheduled_tasks: rebound $n task(s)\n";
    $total += $n;

    // 5. Menu slugs, so plugin.json menu sync updates the existing rows in place.
    $stmt = $dblink->prepare("UPDATE amu_admin_menus SET amu_slug='mailbox' WHERE amu_slug='inbound-email-mailbox'");
    $stmt->execute();
    $total += $stmt->rowCount();
    $stmt = $dblink->prepare("UPDATE amu_admin_menus SET amu_slug='mailbox-reader' WHERE amu_slug='incoming'");
    $stmt->execute();
    $total += $stmt->rowCount();

    // 6. Setting keys. Two keys collapse (mailbox_mailbox_* would be wrong),
    //    so they are renamed explicitly BEFORE the generic prefix swap.
    //    Guard first: drop any old-prefixed row whose renamed target already
    //    exists (e.g. a setting auto-written under the new name by code that
    //    already reads plugins/mailbox/ before this migration ran) so the
    //    rename below never hits the stg_name unique constraint.
    $stmt = $dblink->prepare("
        DELETE FROM stg_settings a
        USING stg_settings b
        WHERE a.stg_name LIKE 'inbound\\_email\\_%'
          AND (
                b.stg_name = 'mailbox_' || substring(a.stg_name from 15)
                OR (a.stg_name = 'inbound_email_mailbox_retention_days' AND b.stg_name = 'mailbox_retention_days')
                OR (a.stg_name = 'inbound_email_mailbox_max_per_window' AND b.stg_name = 'mailbox_max_per_window')
              )
    ");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  stg_settings: dropped $n stale duplicate row(s) before rename\n";
    $total += $n;

    $stmt = $dblink->prepare("UPDATE stg_settings SET stg_name='mailbox_retention_days' WHERE stg_name='inbound_email_mailbox_retention_days'");
    $stmt->execute();
    $total += $stmt->rowCount();
    $stmt = $dblink->prepare("UPDATE stg_settings SET stg_name='mailbox_max_per_window' WHERE stg_name='inbound_email_mailbox_max_per_window'");
    $stmt->execute();
    $total += $stmt->rowCount();
    $stmt = $dblink->prepare("
        UPDATE stg_settings
        SET stg_name = 'mailbox_' || substring(stg_name from 15)
        WHERE stg_name LIKE 'inbound\\_email\\_%'
    ");
    $stmt->execute();
    $n = $stmt->rowCount();
    echo "  stg_settings: renamed $n setting key(s)\n";
    $total += $n;

    // 7. Local raw-message storage: rewrite keys and move the directory.
    //    Only driver='local' rows reference files under {site_root}/storage/.
    //    inline/remote/cloud rows keep their stored keys (reads use the stored
    //    key verbatim, so old-prefix keys on other drivers remain valid).
    //    The messages table is plugin-owned — it only exists where the plugin
    //    was ever installed, so guard before touching it.
    $stmt = $dblink->prepare("SELECT to_regclass('iem_inbound_email_messages')");
    $stmt->execute();
    if ($stmt->fetchColumn() !== null) {
        $stmt = $dblink->prepare("
            UPDATE iem_inbound_email_messages
            SET iem_raw_storage_key = 'mailbox/' || substring(iem_raw_storage_key from 15)
            WHERE iem_raw_storage_driver = 'local'
              AND iem_raw_storage_key LIKE 'inbound\\_email/%'
        ");
        $stmt->execute();
        $n = $stmt->rowCount();
        if ($n > 0) echo "  iem raw storage keys: rewrote $n row(s)\n";
        $total += $n;
    }

    $site_root = rtrim(PathHelper::getSiteRoot(), '/');
    $old_dir = $site_root . '/storage/inbound_email';
    $new_dir = $site_root . '/storage/mailbox';
    if (is_dir($old_dir) && !is_dir($new_dir)) {
        if (rename($old_dir, $new_dir)) {
            echo "  storage: moved inbound_email/ -> mailbox/\n";
        } else {
            echo "  WARNING: could not move $old_dir to $new_dir - move it manually\n";
        }
    }

    // 8. Internal CLAUDE.md/GEMINI.md agent-file content (agf_agent_files):
    //    the docs-index line and the "Inbound email testing" bullet's admin
    //    reference. Table/column references in that bullet are untouched.
    $stmt = $dblink->prepare("
        UPDATE agf_agent_files
        SET agf_content = REPLACE(
                             REPLACE(
                               REPLACE(agf_content, 'plugins/inbound_email/docs/overview.md', 'plugins/mailbox/docs/overview.md'),
                               '[Inbound Email Plugin]', '[Mailbox Plugin]'
                             ),
                             'in the inbound_email admin', 'in the mailbox admin'
                           )
        WHERE agf_name = 'Internal CLAUDE.md'
          AND agf_content LIKE '%plugins/inbound_email/docs/overview.md%'
    ");
    $stmt->execute();
    $n = $stmt->rowCount();
    if ($n > 0) echo "  agf_agent_files: updated $n record(s)\n";
    $total += $n;

    echo "Rename migration: $total row(s) updated.\n";
    return true;
}
?>

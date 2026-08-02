<?php
/**
 * Per-node backup key escrow is gone: every backup now seals its own key to the
 * recovery key as it is made, so there is no node key to escrow. The recovery
 * public key and its possession proof moved to core setting names in the
 * previous migration; these two rows are what is left behind.
 *
 * Nothing reads them. Removing them keeps every stored setting declared, which
 * is the property that stops orphan rows accumulating unnoticed.
 *
 * Idempotent: deleting rows that are already gone is a no-op.
 */
function purge_escrow_setting_rows() {
    $db = DbConnector::get_instance()->get_db_link();

    $retired = array(
        'server_manager_escrow_public_key',
        'server_manager_escrow_public_key_proven_fpr',
    );

    // Refuse to strand the operator: if the core setting somehow never received
    // the value, leave the old row alone so it can still be recovered by hand
    // rather than silently deleting the only copy of a proven key.
    $read = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
    $read->execute(array('backup_recovery_public_key'));
    $carried = trim((string)$read->fetchColumn());

    $read->execute(array($retired[0]));
    $old = trim((string)$read->fetchColumn());

    if ($old !== '' && $carried === '') {
        echo "  WARNING: backup_recovery_public_key is empty but the old escrow key is not.\n";
        echo "  Leaving the retired rows in place so the key is not lost. Re-run after update_database.\n";
        return true;
    }

    $del = $db->prepare('DELETE FROM stg_settings WHERE stg_name = ?');
    foreach ($retired as $name) {
        $del->execute(array($name));
        if ($del->rowCount() > 0) {
            echo "  Removed retired setting {$name}.\n";
        }
    }

    return true;
}
?>

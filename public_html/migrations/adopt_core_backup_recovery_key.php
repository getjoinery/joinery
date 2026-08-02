<?php
/**
 * The recovery keypair is core infrastructure, not a server_manager concern:
 * a standalone site with no fleet still needs one to encrypt its own backups.
 * Carry the configured public key and its possession proof onto the core
 * setting names so an operator who already ran the ceremony never runs it
 * twice — losing the proof would silently stop encrypted backups.
 *
 * Idempotent: a core value that is already set is never overwritten.
 */
function adopt_core_backup_recovery_key() {
    $db = DbConnector::get_instance()->get_db_link();

    $pairs = array(
        'server_manager_escrow_public_key'            => 'backup_recovery_public_key',
        'server_manager_escrow_public_key_proven_fpr' => 'backup_recovery_public_key_proven_fpr',
    );

    $read = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
    $upsert = $db->prepare(
        "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
         VALUES (?, ?, 1, NOW(), NOW(), 'backups')
         ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value, stg_update_time = NOW()");

    foreach ($pairs as $old_name => $new_name) {
        $read->execute(array($old_name));
        $old_value = $read->fetchColumn();
        if ($old_value === false || trim((string)$old_value) === '') {
            echo "  No value for {$old_name}; nothing to carry over.\n";
            continue;
        }

        $read->execute(array($new_name));
        $new_value = $read->fetchColumn();
        if ($new_value !== false && trim((string)$new_value) !== '') {
            echo "  {$new_name} already set; leaving it alone.\n";
            continue;
        }

        $upsert->execute(array($new_name, (string)$old_value));
        echo "  Carried {$old_name} over to {$new_name}.\n";
    }

    return true;
}
?>

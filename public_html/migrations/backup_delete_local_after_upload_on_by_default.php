<?php
/**
 * The local copy of a backup run's leftovers is removed once uploaded.
 *
 * Archives and dumps stream to the bucket and are never on the server; what a
 * run leaves behind is the chain's metadata artifact and a standalone
 * archive's envelope sidecar (the staging directory a whole-site archive uses
 * for its dump is the engine's own and goes with its exit, whatever this
 * setting says). Keeping those until they age out is a convenience for a
 * restore that would otherwise download them; on a 25 GB node it is the
 * difference between a backup that runs and one that fills the disk
 * (specs/implemented/backup_offloaded_files.md § Settings).
 *
 * A settings.json default only seeds a row that does not exist yet, so stored
 * '0' rows move with the default. A deliberate '0' is indistinguishable from
 * an untouched one and does not need to be: the platform is pre-launch and
 * flipping wholesale is the intent — the same call spam_learning_on_by_default
 * made. An operator who wants the local copy back turns the setting off again.
 *
 * Idempotent: re-running finds no stored '0'.
 */
function backup_delete_local_after_upload_on_by_default() {
    $db = DbConnector::get_instance()->get_db_link();

    $q = $db->prepare(
        "UPDATE stg_settings SET stg_value = '1', stg_update_time = now()
          WHERE stg_name = 'backup_delete_local_after_upload' AND stg_value = '0'");
    $q->execute();
    echo $q->rowCount() > 0
        ? "  backup_delete_local_after_upload turned on (was at the old factory default '0')\n"
        : "  backup_delete_local_after_upload: already on or unset\n";
}
?>

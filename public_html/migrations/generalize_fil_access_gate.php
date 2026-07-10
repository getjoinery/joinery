<?php
/**
 * Generalize the event access-gate FK on files into a provider + reference.
 *
 * fil_evt_event_id -> fil_access_provider ('event_registration') + fil_access_ref.
 * Self-guarded on information_schema so it no-ops once the column is gone.
 */
function generalize_fil_access_gate() {
    $dblink = DbConnector::get_instance()->get_db_link();

    $exists = $dblink->query("
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'fil_files' AND column_name = 'fil_evt_event_id'
    ")->fetchColumn();
    if (!$exists) {
        echo "fil_evt_event_id already removed — nothing to do\n";
        return true;
    }

    $stmt = $dblink->prepare("
        UPDATE fil_files
        SET fil_access_provider = 'event_registration', fil_access_ref = fil_evt_event_id
        WHERE fil_evt_event_id IS NOT NULL AND fil_access_provider IS NULL
    ");
    $stmt->execute();
    echo "Backfilled " . $stmt->rowCount() . " file access-gate rows\n";

    $dblink->exec("ALTER TABLE fil_files DROP COLUMN IF EXISTS fil_evt_event_id");
    echo "Dropped fil_evt_event_id\n";

    return true;
}
?>

<?php
/**
 * Generalize the event access-gate FK on videos into a provider + reference.
 *
 * vid_evt_event_id -> vid_access_provider ('event_registration') + vid_access_ref.
 * Self-guarded on information_schema so it no-ops once the column is gone.
 */
function generalize_vid_access_gate() {
    $dblink = DbConnector::get_instance()->get_db_link();

    $exists = $dblink->query("
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'vid_videos' AND column_name = 'vid_evt_event_id'
    ")->fetchColumn();
    if (!$exists) {
        echo "vid_evt_event_id already removed — nothing to do\n";
        return true;
    }

    $stmt = $dblink->prepare("
        UPDATE vid_videos
        SET vid_access_provider = 'event_registration', vid_access_ref = vid_evt_event_id
        WHERE vid_evt_event_id IS NOT NULL AND vid_access_provider IS NULL
    ");
    $stmt->execute();
    echo "Backfilled " . $stmt->rowCount() . " video access-gate rows\n";

    $dblink->exec("ALTER TABLE vid_videos DROP COLUMN IF EXISTS vid_evt_event_id");
    echo "Dropped vid_evt_event_id\n";

    return true;
}
?>

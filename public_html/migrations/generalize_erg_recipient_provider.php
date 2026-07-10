<?php
/**
 * Generalize the recipient-group target columns into a provider + reference.
 *
 * erg_evt_event_id / erg_grp_group_id -> erg_provider + erg_reference_id.
 * Backfills event rows first, then group rows (only where not already set),
 * then drops both old columns. Self-guarded on information_schema.
 */
function generalize_erg_recipient_provider() {
    $dblink = DbConnector::get_instance()->get_db_link();

    $exists = $dblink->query("
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'erg_email_recipient_groups' AND column_name = 'erg_evt_event_id'
    ")->fetchColumn();
    if (!$exists) {
        echo "erg_evt_event_id already removed — nothing to do\n";
        return true;
    }

    $stmt = $dblink->prepare("
        UPDATE erg_email_recipient_groups
        SET erg_provider = 'event', erg_reference_id = erg_evt_event_id
        WHERE erg_evt_event_id IS NOT NULL AND erg_provider IS NULL
    ");
    $stmt->execute();
    echo "Backfilled " . $stmt->rowCount() . " event recipient-group rows\n";

    $stmt = $dblink->prepare("
        UPDATE erg_email_recipient_groups
        SET erg_provider = 'group', erg_reference_id = erg_grp_group_id
        WHERE erg_grp_group_id IS NOT NULL AND erg_provider IS NULL
    ");
    $stmt->execute();
    echo "Backfilled " . $stmt->rowCount() . " group recipient-group rows\n";

    $dblink->exec("ALTER TABLE erg_email_recipient_groups DROP COLUMN IF EXISTS erg_evt_event_id");
    $dblink->exec("ALTER TABLE erg_email_recipient_groups DROP COLUMN IF EXISTS erg_grp_group_id");
    echo "Dropped erg_evt_event_id and erg_grp_group_id\n";

    return true;
}
?>

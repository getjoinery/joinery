<?php
/**
 * Generalize the event FK on messages into a polymorphic context.
 *
 * msg_evt_event_id -> msg_context_type + msg_context_id. Backfills existing
 * event-attached messages as type 'event', then drops the old column. Self-
 * guarded on information_schema so it no-ops once the column is gone.
 */
function generalize_msg_event_context() {
    $dblink = DbConnector::get_instance()->get_db_link();

    $exists = $dblink->query("
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'msg_messages' AND column_name = 'msg_evt_event_id'
    ")->fetchColumn();
    if (!$exists) {
        echo "msg_evt_event_id already removed — nothing to do\n";
        return true;
    }

    $stmt = $dblink->prepare("
        UPDATE msg_messages
        SET msg_context_type = 'event', msg_context_id = msg_evt_event_id
        WHERE msg_evt_event_id IS NOT NULL AND msg_context_id IS NULL
    ");
    $stmt->execute();
    echo "Backfilled " . $stmt->rowCount() . " message context rows from msg_evt_event_id\n";

    $dblink->exec("ALTER TABLE msg_messages DROP COLUMN IF EXISTS msg_evt_event_id");
    echo "Dropped msg_evt_event_id\n";

    return true;
}
?>

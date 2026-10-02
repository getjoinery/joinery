<?php
/**
 * Today's cases become incidents (incident_triage.md WP1, Data).
 *
 * Each case a node's agent opened gets what an incident has: its plain title,
 * a triage, and a timeline. The read stamp becomes triage (read and cleared is
 * resolved, read and still active is looking, unread is new) and a human's
 * note becomes a note event. The old columns (inc_human_note, inc_read_time,
 * inc_read_by) are no longer declared, so they are read here only while they
 * are still there.
 *
 * Idempotent: a case that already has a timeline event is left alone, and
 * every case written here gets at least its opened event. The migration
 * runner holds one transaction around the whole carry-over.
 *
 * The incident table belongs to the server_manager plugin, so the table, the
 * events table and every column read or written are checked for first: a
 * node where the plugin is inactive has none of them, or an old table without
 * the new columns (plugin tables sync for active plugins only).
 */
function incident_cases_carry_triage() {
    $db = DbConnector::get_instance()->get_db_link();

    foreach (array('inc_incident_records', 'ine_incident_events') as $table) {
        $q = $db->prepare("SELECT to_regclass(?)");
        $q->execute(array($table));
        if ($q->fetchColumn() === null) {
            echo "  incident carry-over: no $table here (server_manager not active), nothing to carry\n";
            return;
        }
    }
    $q = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'inc_incident_records'");
    $q->execute();
    $cols = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
    foreach (array('inc_title', 'inc_triage', 'inc_triage_time', 'inc_triage_usr_user_id', 'inc_severity') as $need) {
        if (!isset($cols[$need])) {
            echo "  incident carry-over: inc_incident_records has no $need here (server_manager not active), nothing to carry\n";
            return;
        }
    }
    $has_note = isset($cols['inc_human_note']);
    $has_read = isset($cols['inc_read_time']) && isset($cols['inc_read_by']);

    $select = "SELECT inc_incident_record_id AS id, inc_source AS source, inc_status AS status, inc_reason AS reason,
                      inc_close_reason AS close_reason, inc_title AS title,
                      COALESCE(inc_opened_time, inc_first_seen_time, inc_create_time) AS opened,
                      COALESCE(inc_closed_time, inc_update_time, inc_create_time) AS closed,
                      COALESCE(inc_update_time, inc_create_time) AS touched"
        . ($has_note ? ", inc_human_note AS note" : ", NULL AS note")
        . ($has_read ? ", inc_read_time AS read_time, inc_read_by AS read_by" : ", NULL AS read_time, NULL AS read_by")
        . " FROM inc_incident_records r
           WHERE NOT EXISTS (SELECT 1 FROM ine_incident_events e WHERE e.ine_inc_incident_record_id = r.inc_incident_record_id)
           ORDER BY inc_incident_record_id";
    $rows = $db->query($select)->fetchAll(PDO::FETCH_ASSOC);

    $event = $db->prepare(
        "INSERT INTO ine_incident_events (ine_inc_incident_record_id, ine_time, ine_kind, ine_usr_user_id, ine_text, ine_data)
         VALUES (?, ?, ?, ?, ?, ?)");
    $update = $db->prepare(
        "UPDATE inc_incident_records
            SET inc_title = ?, inc_severity = 'warning', inc_triage = ?, inc_triage_time = ?, inc_triage_usr_user_id = ?
          WHERE inc_incident_record_id = ?");
    // A user who has since been deleted is no longer a valid reference.
    $user_exists = $db->prepare("SELECT 1 FROM usr_users WHERE usr_user_id = ?");

    $carried = 0;
    foreach ($rows as $r) {
        $closed = $r['status'] === 'closed';
        $event->execute(array($r['id'], $r['opened'], 'opened', null, $r['reason'], null));
        if ($closed) {
            $event->execute(array($r['id'], $r['closed'], 'cleared', null, $r['close_reason'], null));
        }
        $reader = null;
        if ($r['read_by'] !== null) {
            $user_exists->execute(array((int)$r['read_by']));
            $reader = $user_exists->fetchColumn() ? (int)$r['read_by'] : null;
        }
        if (trim((string)$r['note']) !== '') {
            $event->execute(array($r['id'], $r['read_time'] ?? $r['touched'], 'note', $reader, $r['note'], null));
        }
        $triage = 'new';
        $triage_time = null;
        if ($r['read_time'] !== null) {
            $triage = $closed ? 'resolved' : 'looking';
            $triage_time = $r['read_time'];
            $event->execute(array($r['id'], $r['read_time'], 'triage', $reader, null,
                json_encode(array('from' => 'new', 'to' => $triage, 'carried' => 'marked read'))));
        }
        $title = trim((string)$r['title']);
        if ($title === '') {
            $title = class_exists('IncidentTitles') ? IncidentTitles::for_source((string)$r['source']) : (string)$r['source'];
        }
        $update->execute(array($title, $triage, $triage_time, $reader, $r['id']));
        $carried++;
    }
    echo "  incident carry-over: $carried case(s) given a title, a triage and a timeline\n";
}
?>

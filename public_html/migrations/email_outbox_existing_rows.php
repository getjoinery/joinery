<?php
/**
 * The outbox (specs/email_outbox.md, S5 and E1), for what was already there.
 *
 *  - A finished row (Sent, Permanent failure, Deleted) keeps no body.
 *  - A Locked row with no lock time is from before the outbox (before the
 *    B2 fix a send that threw left a row Locked for good), and a row at 7
 *    (the removed non-recurring mailer error) was never written by anything:
 *    both go to Permanent failure, so nothing old is sent late. A row the
 *    new code locked has a lock time and is left alone.
 *  - A row at 1 (the removed Queued status, never written) goes to Deleted.
 *  - The debug email log goes: its table, its setting row and its menu row
 *    (core menu seeding never prunes, the drop_event_sessions_menu precedent).
 *
 * Idempotent.
 */
function email_outbox_existing_rows() {
    $db = DbConnector::get_instance()->get_db_link();

    $cleared = $db->exec('UPDATE equ_queued_emails SET equ_body = NULL, equ_message_extras = NULL'
        . ' WHERE equ_status IN (4, 5, 8) AND (equ_body IS NOT NULL OR equ_message_extras IS NOT NULL)');
    $ended = $db->exec("UPDATE equ_queued_emails SET equ_status = 8, equ_body = NULL, equ_message_extras = NULL,"
        . " equ_error = coalesce(equ_error, 'Left from before the outbox; not sent.')"
        . ' WHERE (equ_status = 3 AND equ_locked_time IS NULL) OR equ_status = 7');
    $dropped = $db->exec('UPDATE equ_queued_emails SET equ_status = 5, equ_body = NULL, equ_message_extras = NULL WHERE equ_status = 1');
    echo "Outbox: cleared $cleared finished body(ies), ended $ended old row(s), withdrew $dropped never-approved row(s)\n";

    $db->exec('DROP TABLE IF EXISTS dbl_debug_email_logs');
    $db->exec("DELETE FROM stg_settings WHERE stg_name = 'email_debug_mode'");
    $db->exec("DELETE FROM amu_admin_menus WHERE amu_slug = 'email-debug-logs'");
    echo "Removed the debug email log\n";

    return true;
}
?>

<?php
function drop_event_sessions_menu() {
    $dblink = DbConnector::get_instance()->get_db_link();

    // The Event Sessions menu entry (core-event-sessions) is a deep link, not
    // a destination: /profile/event_sessions requires an evt_event_id and
    // renders an error when reached with no id, which is the only way a menu
    // row can reach it. admin_menus.json no longer declares it, but core menu
    // seeding is insert-only and never prunes, so existing installs keep the
    // stale row unless it is removed here (same insert-only precedent as
    // v136/v139). The events flow (web and native) still deep-links into the
    // page with an id, which is unaffected. Idempotent.
    $stmt = $dblink->prepare("
        DELETE FROM amu_admin_menus
        WHERE amu_slug = 'core-event-sessions' AND amu_location = 'user_dropdown'
    ");
    $stmt->execute();
    echo "Removed " . $stmt->rowCount() . " dead Event Sessions menu row(s)\n";

    return true;
}
?>

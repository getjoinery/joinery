<?php
function drop_event_manager_event_sessions_menu() {
    $dblink = DbConnector::get_instance()->get_db_link();

    // event_manager's plugin.json previously declared an "Event Sessions"
    // profile menu (slug 'event-manager-event-sessions') pointing at
    // /profile/event_sessions — a deep link that requires an evt_event_id and
    // errors when reached with none (the same dead row v140 removed for the old
    // core-event-sessions slug). The manifest no longer declares it, but
    // PluginManager::syncMenus only prunes slugs still listed in the plugin's
    // stored _menu_slugs metadata, so an install whose metadata already
    // advanced past this slug keeps the row. Remove it deterministically here.
    // Idempotent.
    $stmt = $dblink->prepare("
        DELETE FROM amu_admin_menus
        WHERE amu_slug = 'event-manager-event-sessions'
    ");
    $stmt->execute();
    echo "Removed " . $stmt->rowCount() . " dead event_manager Event Sessions menu row(s)\n";

    return true;
}
?>

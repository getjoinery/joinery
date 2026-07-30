<?php
function menu_redesign_row_updates() {
    $dblink = DbConnector::get_instance()->get_db_link();

    // Core menu seeding is insert-only (overwrite=false), so changes to
    // admin_menus.json never touch existing rows. Realign the rows the menu
    // redesign changed. Idempotent: re-running sets the same values.

    // The /profile entry names the page it opens — the member dashboard.
    $stmt = $dblink->prepare(
        "UPDATE amu_admin_menus SET amu_menudisplay = 'Dashboard'
         WHERE amu_slug = 'core-profile' AND amu_menudisplay = 'My Profile'"
    );
    $stmt->execute();
    echo 'Renamed ' . $stmt->rowCount() . " core-profile row(s) to Dashboard\n";

    // The Utilities page checks permission 10; the menu row said 6, so
    // permission 6-9 users saw a link that bounced them.
    $stmt = $dblink->prepare(
        "UPDATE amu_admin_menus SET amu_min_permission = 10
         WHERE amu_slug = 'core-admin-utilities' AND amu_min_permission < 10"
    );
    $stmt->execute();
    echo 'Raised ' . $stmt->rowCount() . " core-admin-utilities row(s) to permission 10\n";

    return true;
}
?>

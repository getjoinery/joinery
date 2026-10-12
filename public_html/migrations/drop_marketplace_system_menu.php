<?php
function drop_marketplace_system_menu() {
    $dblink = DbConnector::get_instance()->get_db_link();

    // The Marketplace is reached from Add New on the Plugins and Themes pages,
    // not from the System menu. admin_menus.json dropped system-marketplace,
    // but core menu seeding is insert-only and never prunes, so existing
    // installs keep the stale row unless it is removed here (same precedent as
    // v140 and v213). The /admin/admin_marketplace page is unaffected.
    // Idempotent.
    $stmt = $dblink->prepare("
        DELETE FROM amu_admin_menus
        WHERE amu_slug = 'system-marketplace' AND amu_location = 'admin_sidebar'
    ");
    $stmt->execute();
    echo "Removed " . $stmt->rowCount() . " Marketplace System-menu row(s)\n";

    return true;
}
?>

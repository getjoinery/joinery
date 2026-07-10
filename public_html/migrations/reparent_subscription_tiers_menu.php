<?php
function reparent_subscription_tiers_menu() {
    $dblink = DbConnector::get_instance()->get_db_link();

    // The Subscription Tiers admin menu (core, slug 'subscription-tiers') was
    // parented under 'products'. The store extraction moved the Products menu to
    // the store plugin, which re-creates that parent row (new id) on every menu
    // sync — orphaning subscription-tiers' parent FK on existing installs, and
    // making a fresh core-only seed skip it (parent not yet present). admin_menus.json
    // now parents it under the core 'users' menu; core menu seeding is insert-only
    // and never re-parents an existing row, so realign the existing row here.
    // Idempotent: re-running sets the same parent.
    $users_id = $dblink->query(
        "SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = 'users' LIMIT 1"
    )->fetchColumn();

    if ($users_id === false) {
        echo "Users menu not found; leaving subscription-tiers parent unchanged\n";
        return true;
    }

    $stmt = $dblink->prepare(
        "UPDATE amu_admin_menus SET amu_parent_menu_id = ?
         WHERE amu_slug = 'subscription-tiers'"
    );
    $stmt->execute(array($users_id));
    echo "Re-parented " . $stmt->rowCount() . " subscription-tiers menu row(s) under Users\n";

    return true;
}
?>

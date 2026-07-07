<?php
function native_member_screen_menu_flips() {
    $dblink = DbConnector::get_instance()->get_db_link();

    // Route flips for the native member screens (specs/implemented/
    // mobile_native_member_screens.md). admin_menus.json declares the
    // nativeScreen values and corrected URLs, but core menu seeding is
    // insert-only (preserves admin customizations), so existing rows move
    // here — same pattern as v136. Idempotent.
    $flips = array(
        'core-profile'       => array('screen' => 'profile',       'url' => '/profile'),
        'core-orders'        => array('screen' => 'orders',        'url' => '/profile/orders'),
        'core-subscriptions' => array('screen' => 'subscriptions', 'url' => '/profile/subscriptions'),
        'core-events'        => array('screen' => 'events',        'url' => '/profile/events'),
    );
    $stmt = $dblink->prepare("
        UPDATE amu_admin_menus
        SET amu_native_screen = ?, amu_defaultpage = ?
        WHERE amu_slug = ? AND amu_location = 'user_dropdown'
    ");
    $n = 0;
    foreach ($flips as $slug => $flip) {
        $stmt->execute(array($flip['screen'], $flip['url'], $slug));
        $n += $stmt->rowCount();
    }
    echo "Flipped $n core member menu entries to native destinations\n";

    // The app_navigation tab pinning still names the mailbox entry by its
    // pre-rename slug (the inbound_email -> mailbox rename missed it), so
    // the Email tab silently dropped out of every app's tab bar. settings.json
    // now declares the corrected default; existing rows move here.
    $stmt = $dblink->prepare("
        UPDATE stg_settings
        SET stg_value = replace(stg_value, '\"inbound-email-mailbox\"', '\"mailbox\"')
        WHERE stg_name = 'app_navigation' AND stg_value LIKE '%inbound-email-mailbox%'
    ");
    $stmt->execute();
    echo "Repaired app_navigation mailbox slug on " . $stmt->rowCount() . " row(s)\n";

    return true;
}
?>

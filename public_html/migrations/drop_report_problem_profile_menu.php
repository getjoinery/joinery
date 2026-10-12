<?php
function drop_report_problem_profile_menu() {
    $dblink = DbConnector::get_instance()->get_db_link();

    // Report a problem is an admin-menu item (report-problem). The member menu
    // (and the mobile apps' navigation, which reads it) no longer carries one.
    // admin_menus.json dropped core-report-problem, but core menu seeding is
    // insert-only and never prunes, so existing installs keep the stale row
    // unless it is removed here (same precedent as v140). The /report_problem
    // page and the error-page report links are unaffected. Idempotent.
    $stmt = $dblink->prepare("
        DELETE FROM amu_admin_menus
        WHERE amu_slug = 'core-report-problem' AND amu_location = 'user_dropdown'
    ");
    $stmt->execute();
    echo "Removed " . $stmt->rowCount() . " Report a problem member-menu row(s)\n";

    return true;
}
?>

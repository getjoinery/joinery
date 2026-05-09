<?php
/**
 * One-time data backfill for usr_terms_accepted_time + usr_lastlogin_time cleanup.
 *
 * Schema additions/removals are handled by update_database from
 * $field_specifications before this runs:
 *   - usr_terms_accepted_time has been added (nullable timestamp(6))
 *   - usr_lastlogin_time no longer has a default of now()
 *
 * This migration:
 *   1. Stamps usr_terms_accepted_time = usr_signup_date::timestamp for any user
 *      with at least one real (non-logout) entry in log_logins. Skips users who
 *      already have a non-null value (so re-runs are no-ops).
 *   2. NULLs out usr_lastlogin_time for users with no real log_logins entry —
 *      those values were fictionally stamped by the old default => now() spec
 *      on insert and never represented an actual login.
 */
function migration_terms_accepted_backfill() {
    $dblink = DbConnector::get_instance()->get_db_link();

    // Real, non-logout login types: LOGIN_FORM=1, LOGIN_COOKIE=2, LOGIN_FACEBOOK_CONNECT=4.
    $stmt = $dblink->prepare("
        UPDATE usr_users
        SET usr_terms_accepted_time = usr_signup_date::timestamp
        WHERE usr_terms_accepted_time IS NULL
          AND EXISTS (
              SELECT 1 FROM log_logins
              WHERE log_usr_user_id = usr_users.usr_user_id
                AND log_login_type IN (1, 2, 4)
          )
    ");
    $stmt->execute();
    $stamped = $stmt->rowCount();
    echo "Stamped usr_terms_accepted_time for $stamped user(s).\n";

    $stmt = $dblink->prepare("
        UPDATE usr_users
        SET usr_lastlogin_time = NULL
        WHERE usr_lastlogin_time IS NOT NULL
          AND NOT EXISTS (
              SELECT 1 FROM log_logins
              WHERE log_usr_user_id = usr_users.usr_user_id
                AND log_login_type IN (1, 2, 4)
          )
    ");
    $stmt->execute();
    $cleared = $stmt->rowCount();
    echo "Cleared fictional usr_lastlogin_time on $cleared user(s).\n";

    return true;
}
?>

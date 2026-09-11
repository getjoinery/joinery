<?php
/**
 * The system user (User::USER_SYSTEM, id 2) holds permission 0.
 *
 * It is an attribution id — the owner of a sync task's event-log rows and of
 * files nobody in particular owns — never a login. Nothing reads its
 * permission: the one caller that needs a level alongside it passes one
 * explicitly. Installs seeded it at 10, which left every node with a
 * permission-10 row on a placeholder email that nobody watches.
 *
 * Idempotent: re-running finds the row already at 0.
 */
function system_user_no_permission() {
    $db = DbConnector::get_instance()->get_db_link();

    $q = $db->prepare(
        "UPDATE usr_users SET usr_permission = 0
          WHERE usr_user_id = :id AND usr_permission <> 0");
    $q->execute(array(':id' => User::USER_SYSTEM));
    echo $q->rowCount() > 0
        ? "  system user (id " . User::USER_SYSTEM . ") permission set to 0\n"
        : "  system user (id " . User::USER_SYSTEM . "): already at 0\n";
}
?>

<?php
/**
 * Learning from spam corrections is on by default.
 *
 * The capability was gated off because it once implied installing a scanner.
 * It no longer does: the scanner ships with the mail stack, so learning is a
 * pure settings toggle everywhere it can work at all — and where it cannot (a
 * box with no local mail stack) the setting is inert, the Settings page
 * disables the control with the reason, and the learning task skips with a
 * message rather than failing.
 *
 * A plugin.json default only seeds a row that does not exist yet, so stored
 * '0' rows move with the default. A deliberate '0' is indistinguishable from
 * an untouched one and does not need to be: the platform is pre-launch and
 * flipping wholesale is the intent — the same call the master switch's own
 * default flip made (spam_filtering_one_switch).
 *
 * Idempotent: re-running finds no stored '0'.
 */
function spam_learning_on_by_default() {
    $db = DbConnector::get_instance()->get_db_link();

    $q = $db->prepare(
        "UPDATE stg_settings SET stg_value = '1'
          WHERE stg_name = 'mailbox_spam_learning_enabled' AND stg_value = '0'");
    $q->execute();
    echo $q->rowCount() > 0
        ? "  mailbox_spam_learning_enabled turned on (was at the old factory default '0')\n"
        : "  mailbox_spam_learning_enabled: already on or unset\n";
}
?>

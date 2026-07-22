<?php
/**
 * Spam filtering: one switch, on by default
 * (specs/mailbox_spam_filtering_simplification.md D5).
 *
 * A plugin.json default only seeds rows that do not exist yet, so deployments
 * that already stored a value need this to move with the defaults:
 *
 *  1. Rename. mailbox_content_spam_filtering_enabled conflated two things —
 *     "install a local rspamd" and "run the learning loop". Only the second is
 *     a choice worth offering, so the row carries its stored value over to
 *     mailbox_spam_learning_enabled and the old name is dropped. Scanner
 *     installation is derived now (MailboxSpamPolicy), not stored.
 *
 *  2. Default flip. mailbox_spam_filtering_enabled shipped at '0', which meant
 *     a deployment that never found the switch filed spam straight into the
 *     inbox while its relay was scoring every message. Stored '0' rows flip to
 *     '1'. A deliberate '0' is indistinguishable from an untouched one and does
 *     not need to be: the platform is pre-launch and flipping wholesale is the
 *     intent.
 *
 * Idempotent: re-running finds no old-named row and no stored '0'.
 */
function spam_filtering_one_switch() {
    $db = DbConnector::get_instance()->get_db_link();

    // 1a. Guard the rename against a target row that already exists (the
    //     plugin.json default may have been seeded before this ran), so the
    //     UPDATE below cannot hit the stg_name unique constraint. The stored
    //     preference wins over the freshly-seeded default.
    $q = $db->prepare(
        "DELETE FROM stg_settings
          WHERE stg_name = 'mailbox_spam_learning_enabled'
            AND EXISTS (SELECT 1 FROM stg_settings o
                         WHERE o.stg_name = 'mailbox_content_spam_filtering_enabled')");
    $q->execute();
    if ($q->rowCount() > 0) {
        echo "  mailbox_spam_learning_enabled: dropped seeded default in favor of the stored value\n";
    }

    // 1b. Carry the stored value over under the new name.
    $q = $db->prepare(
        "UPDATE stg_settings SET stg_name = 'mailbox_spam_learning_enabled'
          WHERE stg_name = 'mailbox_content_spam_filtering_enabled'");
    $q->execute();
    echo $q->rowCount() > 0
        ? "  mailbox_content_spam_filtering_enabled renamed to mailbox_spam_learning_enabled\n"
        : "  mailbox_content_spam_filtering_enabled: no stored row (nothing to rename)\n";

    // 2. Flip the master switch on where it still sits at the old factory '0'.
    $q = $db->prepare(
        "UPDATE stg_settings SET stg_value = '1'
          WHERE stg_name = 'mailbox_spam_filtering_enabled' AND stg_value = '0'");
    $q->execute();
    echo $q->rowCount() > 0
        ? "  mailbox_spam_filtering_enabled flipped on (was at the old factory default '0')\n"
        : "  mailbox_spam_filtering_enabled: already on or unset\n";
}
?>

<?php
/**
 * IMAP feeds never connect unencrypted.
 *
 * The 'none' encryption mode sent a feed's full-mailbox credentials across the
 * network in the clear, and it is no longer offered (specs/implemented/imap_client_hardening.md
 * Q1). A feed still set to it moves to SSL/TLS; one on the plaintext default
 * port 143 moves to 993 with it, since 143 speaks plaintext or STARTTLS, not
 * implicit TLS. An operator whose server only offers STARTTLS picks it in the
 * feed editor.
 *
 * Idempotent: re-running finds no feed still at 'none'.
 *
 * The feeds table belongs to the mailbox plugin, so it is checked for before it
 * is touched, and so is every column named: a site that activated the plugin
 * once and turned it off keeps the table but stops receiving its columns.
 */
function imap_feeds_no_plaintext() {
    $db = DbConnector::get_instance()->get_db_link();

    $q = $db->prepare("SELECT to_regclass('iia_inbound_imap_accounts')");
    $q->execute();
    if ($q->fetchColumn() === null) {
        echo "  IMAP feeds: no feeds table here (mailbox not active), nothing to move\n";
        return;
    }
    $q = $db->prepare(
        "SELECT count(1) FROM information_schema.columns
          WHERE table_name = 'iia_inbound_imap_accounts'
            AND column_name IN ('iia_imap_encryption', 'iia_imap_port')");
    $q->execute();
    if ((int)$q->fetchColumn() < 2) {
        echo "  IMAP feeds: feeds table lacks the connection columns here, nothing to move\n";
        return;
    }

    $q = $db->prepare(
        "UPDATE iia_inbound_imap_accounts
            SET iia_imap_encryption = 'ssl',
                iia_imap_port = CASE WHEN iia_imap_port = 143 THEN 993 ELSE iia_imap_port END
          WHERE iia_imap_encryption = 'none'");
    $q->execute();
    echo $q->rowCount() > 0
        ? "  IMAP feeds moved off unencrypted connections: " . $q->rowCount() . "\n"
        : "  IMAP feeds: none on an unencrypted connection\n";
}
?>

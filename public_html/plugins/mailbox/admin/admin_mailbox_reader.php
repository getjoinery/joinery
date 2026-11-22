<?php
/** @joinery-admin class=personal */
/**
 * Inbound Email - the old admin address of the mailbox reader.
 *
 * Mail is read in one place, the member reader at /profile/mailbox/mailbox,
 * for staff and members alike (superadmins get every mailbox there). The admin
 * section is configuration only; this address stays so bookmarks and old links
 * land in the reader.
 *
 * @version 2.0 - sends the visitor to the member reader
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_reader_logic.php'));

process_logic(admin_mailbox_reader_logic(array_merge($_GET, $_POST, $params ?? [])));
?>

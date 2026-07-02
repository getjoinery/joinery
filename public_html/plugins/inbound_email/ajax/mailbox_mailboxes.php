<?php
/**
 * Mailbox Reader AJAX — switcher data.
 *
 * GET. Returns the accessible mailboxes (address + unread/total/any-starred),
 * plus "All mail"/"Unmatched" counts for an all-access superadmin. Signed-in;
 * MailboxViewer decides which mailboxes the response contains (a member with
 * no grants gets an empty list).
 *
 * @version 1.1
 */
require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxService.php'));

header('Content-Type: application/json');

$session = SessionControl::get_instance();
if (!$session->get_user_id()) {
	http_response_code(403);
	echo json_encode(array('error' => 'forbidden'));
	exit();
}

$viewer = MailboxViewer::fromSession($session);
$service = new MailboxService($viewer);

echo json_encode($service->listMailboxes());

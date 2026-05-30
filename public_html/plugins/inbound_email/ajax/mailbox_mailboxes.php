<?php
/**
 * Mailbox Reader AJAX — switcher data.
 *
 * GET. Returns the accessible mailboxes (address + unread/total/any-starred),
 * plus "All mail"/"Unmatched" counts for an all-access superadmin. Staff-only.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxService.php'));

header('Content-Type: application/json');

$session = SessionControl::get_instance();
if ($session->get_permission() < 5) {
	http_response_code(403);
	echo json_encode(array('error' => 'forbidden'));
	exit();
}

$viewer = MailboxViewer::fromSession($session);
$service = new MailboxService($viewer);

echo json_encode($service->listMailboxes());

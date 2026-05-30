<?php
/**
 * Mailbox Reader AJAX — thread list.
 *
 * GET. Params: alias_id (optional; omit/blank = all accessible), sender,
 * subject, body, unread_only, starred_only, page. Returns the scoped
 * conversation list grouped by thread, latest-first. Staff-only.
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

$alias_id = (isset($_GET['alias_id']) && $_GET['alias_id'] !== '')
	? intval($_GET['alias_id']) : null;

$filters = array(
	'sender'       => isset($_GET['sender']) ? trim((string)$_GET['sender']) : '',
	'subject'      => isset($_GET['subject']) ? trim((string)$_GET['subject']) : '',
	'body'         => isset($_GET['body']) ? trim((string)$_GET['body']) : '',
	'unread_only'  => !empty($_GET['unread_only']),
	'starred_only' => !empty($_GET['starred_only']),
);

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;

echo json_encode($service->listThreads($alias_id, $filters, $page, 50));

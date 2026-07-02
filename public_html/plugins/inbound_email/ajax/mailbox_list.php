<?php
/**
 * Mailbox Reader AJAX — thread list.
 *
 * GET. Params: alias_id (optional; omit/blank = all accessible), q,
 * unread_only, starred_only, spam, page. Returns the scoped conversation
 * list grouped by thread, latest-first. Signed-in; every row is scoped by
 * MailboxViewer.
 *
 * @version 1.4
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

$alias_id = MailboxService::parseAliasParam($_GET['alias_id'] ?? null);

$filters = array(
	'q'            => isset($_GET['q']) ? trim((string)$_GET['q']) : '',
	'unread_only'  => !empty($_GET['unread_only']),
	'starred_only' => !empty($_GET['starred_only']),
	'spam'         => !empty($_GET['spam']),
	// Inbox view (specs/implemented/inbound_email_filters.md): hide archived mail.
	// The "All Mail" view omits this so archived conversations stay reachable.
	'inbox'        => !empty($_GET['inbox']),
);

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$folder_id = isset($_GET['folder_id']) && $_GET['folder_id'] !== '' ? intval($_GET['folder_id']) : null;

echo json_encode($service->listThreads($alias_id, $filters, $page, 50, $folder_id));

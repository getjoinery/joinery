<?php
/**
 * Mailbox Reader AJAX — messages in a thread.
 *
 * GET. Params: thread_key (required), alias_id (optional). Returns every
 * in-scope message in the thread, chronological, each WITH its plain/HTML body
 * for client-side sandboxed rendering. Empty array if the thread is outside
 * scope. Signed-in; MailboxViewer scopes the thread expansion. Inline cid:
 * images are resolved to short-lived signed URLs before the body is returned
 * (the sandboxed reader iframe sends no cookies, so the URL must authorize
 * itself).
 *
 * @version 1.3
 */
require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));

header('Content-Type: application/json');

$session = SessionControl::get_instance();
if (!$session->get_user_id()) {
	http_response_code(403);
	echo json_encode(array('error' => 'forbidden'));
	exit();
}

$viewer = MailboxViewer::fromSession($session);
$service = new MailboxService($viewer);

$thread_key = isset($_GET['thread_key']) ? (string)$_GET['thread_key'] : '';
if ($thread_key === '') {
	echo json_encode(array('messages' => array()));
	exit();
}

$alias_id = MailboxService::parseAliasParam($_GET['alias_id'] ?? null);

$messages = $service->getThread($alias_id, $thread_key);
$messages = MailboxService::resolveInlineImages($messages);

echo json_encode(array(
	'messages' => $messages,
	// Folder ids this thread currently belongs to — pre-checks the move/labels control.
	'folders'  => $service->threadFolderIds($alias_id, $thread_key),
));

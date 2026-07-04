<?php
/**
 * Mailbox Reader AJAX — send a Reply / Reply-All / Forward AS the mailbox, or
 * (mode=new) a New Message starting a fresh conversation.
 *
 * POST (multipart, for attachment uploads). Signed-in + MailboxViewer::canCompose()
 * (a grant means full access to the mailbox: reading it and sending as it);
 * per-alias scope is enforced inside MailboxSender — a reply/forward's source
 * message must be accessible to the viewer (and is also the sending identity);
 * a new message's `alias_id` must itself be one of the viewer's grants.
 * CSRF: the reader's persistent mailbox_reader_csrf token (validated, not
 * consumed) — the same token the single-button actions use.
 *
 * On success stores the sent copy as an outbound row and returns its id; on
 * failure returns the user-facing error so the reader shows it inline and keeps
 * the draft (no row is stored). Accepted uploads persist onto the outbound
 * manifest (MailboxSender::persistOutboundUploads()) so the sent copy shows
 * what was attached.
 *
 * @version 1.3
 */
require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxSender.php'));

header('Content-Type: application/json');

$session = SessionControl::get_instance();
if (!$session->get_user_id()) {
	http_response_code(403);
	echo json_encode(array('error' => 'forbidden'));
	exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo json_encode(array('error' => 'method_not_allowed'));
	exit();
}

$token = $_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['mailbox_reader_csrf'])
		|| !hash_equals((string)$_SESSION['mailbox_reader_csrf'], (string)$token)) {
	http_response_code(403);
	echo json_encode(array('error' => 'csrf'));
	exit();
}

$params = array(
	'mode'      => $_POST['mode'] ?? '',
	'source_id' => $_POST['source_id'] ?? 0,
	'alias_id'  => $_POST['alias_id'] ?? 0,
	'to'        => $_POST['to'] ?? '',
	'cc'        => $_POST['cc'] ?? '',
	'subject'   => $_POST['subject'] ?? '',
	'body'      => $_POST['body'] ?? '',
);

$files = MailboxSender::collectUploads();

$viewer = MailboxViewer::fromSession($session);
if (!$viewer->canCompose()) {
	http_response_code(403);
	echo json_encode(array('error' => 'forbidden'));
	exit();
}
$sender = new MailboxSender($viewer);

try {
	$result = $sender->send($params, $files);
	echo json_encode(array('ok' => true, 'outbound_id' => $result['outbound_id']));
} catch (MailboxSenderException $e) {
	echo json_encode(array('ok' => false, 'error' => $e->getMessage()));
} catch (Throwable $e) {
	error_log('mailbox_send: ' . $e->getMessage());
	echo json_encode(array('ok' => false, 'error' => 'An unexpected error prevented sending.'));
}

<?php
/**
 * Mailbox Reader AJAX — state mutations.
 *
 * POST, CSRF-protected. action ∈ {mark_read, mark_unread, star, unstar, delete,
 * set_membership, create_folder}.
 * Targets are either ids[] (message ids) OR a thread_key (expanded server-side
 * via messageIdsInThread, optionally narrowed by alias_id). Every mutation
 * re-checks scope in SQL, so a crafted id/thread for an un-granted mailbox
 * affects nothing. Staff-only.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo json_encode(array('error' => 'method_not_allowed'));
	exit();
}

// CSRF: a persistent per-session reader token (the reader makes repeated
// actions, so it is validated but not consumed). Issued by the reader logic.
$token = $_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['mailbox_reader_csrf'])
		|| !hash_equals((string)$_SESSION['mailbox_reader_csrf'], (string)$token)) {
	http_response_code(403);
	echo json_encode(array('error' => 'csrf'));
	exit();
}

$viewer = MailboxViewer::fromSession($session);
$service = new MailboxService($viewer);

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';

$alias_id = MailboxService::parseAliasParam($_POST['alias_id'] ?? null);

// Resolve target ids: explicit ids[] or a thread_key expanded server-side.
$ids = array();
if (isset($_POST['ids']) && is_array($_POST['ids'])) {
	foreach ($_POST['ids'] as $id) {
		$ids[] = intval($id);
	}
} elseif (isset($_POST['thread_key']) && $_POST['thread_key'] !== '') {
	$ids = $service->messageIdsInThread($alias_id, (string)$_POST['thread_key']);
}

if (!count($ids)) {
	echo json_encode(array('count' => 0));
	exit();
}

switch ($action) {
	case 'mark_read':
		$count = $service->markRead($ids, true);
		break;
	case 'mark_unread':
		$count = $service->markRead($ids, false);
		break;
	case 'star':
		$count = $service->setStarred($ids, true);
		break;
	case 'unstar':
		$count = $service->setStarred($ids, false);
		break;
	case 'delete':
		$count = $service->softDelete($ids);
		break;
	case 'set_membership':
		// Move / labels: add or remove a folder membership for the thread's messages.
		// Two-way sync then pushes it to the source (COPY / MOVE / EXPUNGE).
		$folder_id = intval($_POST['folder_id'] ?? 0);
		$present = !empty($_POST['present']) && $_POST['present'] !== '0';
		$count = $service->setMembership($ids, $folder_id, $present);
		break;
	case 'create_folder':
		// Create a label/folder locally and apply it to the thread. The folder is
		// created on the source during the next sync push (its pending flag), then
		// the membership COPYs into it. Returns the new folder so the UI updates.
		$folder = $service->createFolder(intval($alias_id ?? 0), (string)($_POST['name'] ?? ''));
		if ($folder === null) {
			http_response_code(400);
			echo json_encode(array('error' => 'create_failed'));
			exit();
		}
		$count = $service->setMembership($ids, intval($folder['id']), true);
		echo json_encode(array('folder' => $folder, 'count' => $count));
		exit();
	default:
		http_response_code(400);
		echo json_encode(array('error' => 'unknown_action'));
		exit();
}

echo json_encode(array('count' => $count));

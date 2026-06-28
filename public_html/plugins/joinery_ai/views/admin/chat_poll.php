<?php
/**
 * Joinery AI — chat poll endpoint (AJAX, JSON).
 * URL: /admin/joinery_ai/chat_poll?message_id=N
 *
 * The delivery channel for an asynchronous turn. chat_send / chat_confirm create
 * (or flip) an assistant row to RUNNING and run the turn off the request; the
 * page polls this owner-scoped endpoint until the row is COMPLETE (returns the
 * rendered bubble) or FAILED (returns the error). A row left RUNNING past the
 * stale ceiling — its worker process died — is reaped to FAILED here so the page
 * shows an error instead of spinning forever.
 */
header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRender.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatAsync.php'));

function chat_poll_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$session = SessionControl::get_instance();
if (!$session->is_logged_in() || $session->get_permission() < 5) {
    chat_poll_fail('Not authorized.');
}
$uid = (int)$session->get_user_id();

$message_id = (int)LibraryFunctions::fetch_variable_local($_GET, 'message_id', 0);
if ($message_id <= 0) chat_poll_fail('Missing message id.');

$msg = new AiConversationMessage($message_id, true);
if (!$msg->key
        || $msg->get('aim_role') !== AiConversationMessage::ROLE_ASSISTANT
        || $msg->get('aim_delete_time')) {
    chat_poll_fail('Message not found.');
}

// Owner-scope through the parent conversation.
$conversation = new AiConversation((int)$msg->get('aim_aic_conversation_id'), true);
if (!$conversation->key
        || (int)$conversation->get('aic_owner_user_id') !== $uid
        || $conversation->get('aic_delete_time')) {
    chat_poll_fail('Message not found.');
}

// Reap a stale running row before reading its status.
if (ChatAsync::sweepMessage($msg)) {
    $msg->load();
}

$status = (string)$msg->get('aim_status');
$response = ['success' => true, 'status' => $status];

if ($status === AiConversationMessage::STATUS_COMPLETE) {
    $response['assistant_html'] = ChatRender::assistantBubble($msg, $session->get_timezone());
} elseif ($status === AiConversationMessage::STATUS_FAILED) {
    $response['error'] = (string)$msg->get('aim_error') ?: 'The assistant could not complete this turn.';
}

echo json_encode($response);
exit;

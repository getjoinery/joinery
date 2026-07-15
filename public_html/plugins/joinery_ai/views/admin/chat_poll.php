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
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSerializer.php'));

function chat_poll_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$session = SessionControl::get_instance();
// Any logged-in user. The /admin/* route is permission-gated (5), so this file
// stays admin-only at its /admin URL; it also backs /profile/joinery_ai/ for
// members, whose reads are owner-scoped and whose action surface is withheld.
if (!$session->is_logged_in()) {
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

// Locked-state contract: the window closed between turn start and this poll.
// Every branch below reads content (COMPLETE/FAILED decrypt sealed columns; the
// RUNNING scratch is plaintext) — withhold it and prompt unlock instead.
if (ChatSeal::isLocked($conversation)) {
    $response['locked']  = true;
    $response['message'] = 'Unlock your vault to view this reply.';
    echo json_encode($response);
    exit;
}

if ($status === AiConversationMessage::STATUS_COMPLETE
        || $status === AiConversationMessage::STATUS_CANCELLED) {
    // Cancelled settles like complete: the stored partial answer is rendered
    // (assistantBubble stamps a "Cancelled" marker off aim_status), and the
    // status field (already set above) tells the page which terminal it is.
    $model = ChatRender::conversationModel($conversation);
    $response['assistant_html'] = ChatRender::assistantBubble($msg, $session->get_timezone(), $model);
    $response['conversation_usage'] = ChatRender::conversationUsagePayload($conversation);
} elseif ($status === AiConversationMessage::STATUS_FAILED) {
    $response['error'] = (string)$msg->get('aim_error') ?: 'The assistant could not complete this turn.';
} else {
    // Still running — hand back the answer text written so far so the page can
    // show it as it streams. Plain text (not markdown): partial markdown renders
    // badly; the final swap to assistant_html does the markdown pass. The
    // runner's stage label + elapsed seconds ride along so the page can show
    // what's happening before the first token.
    // A protected turn streams into a RAM/tmpfs scratch (never the plaintext DB
    // column); read the partial from there. Standard reads aim_content.
    $response['partial_text'] = $conversation->isProtected()
        ? (string)(ChatAsync::readScratch((int)$msg->key) ?? '')
        : (string)$msg->get('aim_content');
    $response += ChatSerializer::runningExtras($msg);
}

echo json_encode($response);
exit;

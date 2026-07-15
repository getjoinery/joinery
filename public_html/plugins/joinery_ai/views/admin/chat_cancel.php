<?php
/**
 * Joinery AI — cancel an in-flight chat turn (AJAX, JSON).
 * URL: /admin/joinery_ai/chat_cancel  (POST)
 *
 * Records a "please stop" on a RUNNING assistant row (aim_cancel_requested). The
 * background worker running the turn re-reads that flag — at the AgentLoop
 * boundary and per stream chunk — and exits cleanly, marking the row CANCELLED
 * with whatever partial answer had streamed. This endpoint only sets the flag;
 * it never writes the terminal state itself, so the worker stays the single
 * finalizer and there is no cross-process race over the row's content.
 *
 * Mirrors the recipe Stop button (views/admin/stop_run.php) — a flag write, read
 * cooperatively by the running loop. Ownership flows through the parent
 * conversation, like the sibling chat endpoints (session + owner-scope, no CSRF
 * token — consistent with the other chat fetches).
 */
header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));

function chat_cancel_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$session = SessionControl::get_instance();
// Any logged-in user. The /admin/* route is permission-gated (5), so this file
// stays admin-only at its /admin URL; it also backs /profile/joinery_ai/ for
// members, whose turns are their own (owner-checked below).
if (!$session->is_logged_in()) {
    chat_cancel_fail('Not authorized.');
}
$uid = (int)$session->get_user_id();

$message_id = (int)LibraryFunctions::fetch_variable_local($_POST, 'message_id', 0);

$message = new AiConversationMessage($message_id, true);
if (!$message->key
        || $message->get('aim_role') !== AiConversationMessage::ROLE_ASSISTANT
        || $message->get('aim_delete_time')) {
    chat_cancel_fail('Message not found.');
}

// Owner-scope through the parent conversation (same shape as the other chat
// endpoints); a mismatched owner reads as "not found" so a user can't probe or
// cancel another user's turn.
$conversation = new AiConversation((int)$message->get('aim_aic_conversation_id'), true);
if (!$conversation->key
        || (int)$conversation->get('aic_owner_user_id') !== $uid
        || $conversation->get('aic_delete_time')) {
    chat_cancel_fail('Message not found.');
}

// Only a RUNNING turn can be cancelled. A completed/failed/cancelled turn is a
// benign no-op — the worker already finished, so report success and let the
// poll render the settled state.
if ((string)$message->get('aim_status') !== AiConversationMessage::STATUS_RUNNING) {
    echo json_encode(['success' => true, 'already_settled' => true]);
    exit;
}

// Set the cross-process flag via the targeted static writer — the row may be
// sealed and must never be save()d (that would decrypt-and-rewrite its content).
AiConversationMessage::updateColumns((int)$message->key, ['aim_cancel_requested' => true]);

echo json_encode(['success' => true]);
exit;

<?php
/**
 * Joinery AI — chat confirm/cancel endpoint (AJAX, JSON).
 * URL: /admin/joinery_ai/chat_confirm  (POST)
 *
 * Resolves a pending mutating call the assistant proposed. Confirm executes the
 * approved call through the shared loop and continues the turn; Cancel feeds a
 * "declined" tool result back so the model adapts. Either way the pending
 * assistant message is updated in place (one assistant row per turn), so the
 * transcript stays strictly alternating and replayable.
 *
 * Like chat_send, the resume runs AFTER the response is sent
 * (fastcgi_finish_request): the pending row flips to RUNNING, the page gets a
 * poll handle, and chat_poll surfaces the finished bubble. On a non-fpm SAPI the
 * resume runs synchronously and the reply rides back in this response.
 */
header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRender.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatAsync.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurn.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/CostGuard.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSend.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderException.php'));

function chat_confirm_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$session = SessionControl::get_instance();
// Any logged-in user. The /admin/* route is permission-gated (5), so this file
// stays admin-only at its /admin URL; it also backs /profile/joinery_ai/ for
// members, whose reads are owner-scoped and whose action surface is withheld.
if (!$session->is_logged_in()) {
    chat_confirm_fail('Not authorized.');
}
$uid = (int)$session->get_user_id();

$conversation_id = (int)LibraryFunctions::fetch_variable_local($_POST, 'conversation_id', 0);
$message_id      = (int)LibraryFunctions::fetch_variable_local($_POST, 'message_id', 0);
$decision        = (string)LibraryFunctions::fetch_variable_local($_POST, 'decision', '');

if (!in_array($decision, ['confirm', 'cancel'], true)) chat_confirm_fail('Invalid decision.');

$conversation = new AiConversation($conversation_id, true);
if (!$conversation->key
        || (int)$conversation->get('aic_owner_user_id') !== $uid
        || $conversation->get('aic_delete_time')) {
    chat_confirm_fail('Conversation not found.');
}

// Unlock-first: resuming a protected turn reads its sealed pending action + lead
// text and re-seals the reply — all in-window.
if (ChatSend::lockedForWrite($uid, $conversation->isProtected())) {
    echo json_encode([
        'success'         => true,
        'locked'          => true,
        'conversation_id' => (int)$conversation->key,
        'message'         => 'Unlock your vault to continue this protected chat.',
    ]);
    exit;
}

$msg = new AiConversationMessage($message_id, true);
if (!$msg->key
        || (int)$msg->get('aim_aic_conversation_id') !== (int)$conversation->key
        || $msg->get('aim_role') !== AiConversationMessage::ROLE_ASSISTANT) {
    chat_confirm_fail('Message not found.');
}

$pending = $msg->get('aim_pending_action');
if (is_string($pending)) $pending = json_decode($pending, true);
if (empty($pending) || !is_array($pending)) {
    chat_confirm_fail('There is no pending action to resolve (it may already have been handled).');
}

$lead_text = (string)$msg->get('aim_content');

if ($decision === 'confirm') {
    try {
        CostGuard::enforceGlobalCap();
    } catch (CapExceededException $e) {
        chat_confirm_fail('Joinery AI has reached its monthly token cap. Raise the cap in settings to continue.');
    }
}

// Flip the pending row to RUNNING so the page can poll it (and a duplicate
// confirm sees no pending action to resolve). Targeted UPDATE — a sealed,
// finalized message must not be save()d (that would unseal it).
AiConversationMessage::updateColumns((int)$msg->key, ['aim_status' => AiConversationMessage::STATUS_RUNNING]);

$tz = $session->get_timezone();
$payload = [
    'success'         => true,
    'conversation_id' => (int)$conversation->key,
    'message_id'      => (int)$msg->key,
];

if (ChatAsync::canDetach()) {
    $payload['status'] = AiConversationMessage::STATUS_RUNNING;
    echo json_encode($payload);
    ChatAsync::detach();
    ChatTurn::resumeAndFinalize($conversation, $uid, $msg, $pending, $lead_text, $decision);
    exit;
}

// Non-fpm fallback: resume synchronously, return the finished bubble.
ChatTurn::resumeAndFinalize($conversation, $uid, $msg, $pending, $lead_text, $decision);
$msg->load();
if ($msg->get('aim_status') === AiConversationMessage::STATUS_FAILED) {
    $payload['status'] = AiConversationMessage::STATUS_FAILED;
    $payload['error']  = (string)$msg->get('aim_error');
} else {
    $payload['status']         = AiConversationMessage::STATUS_COMPLETE;
    $payload['assistant_html'] = ChatRender::assistantBubble(
        $msg, $tz, ChatRender::conversationModel($conversation));
    $payload['conversation_usage'] = ChatRender::conversationUsagePayload($conversation);
}
echo json_encode($payload);
exit;

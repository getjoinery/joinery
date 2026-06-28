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
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/CostGuard.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderException.php'));

function chat_confirm_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$session = SessionControl::get_instance();
if (!$session->is_logged_in() || $session->get_permission() < 5) {
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
// confirm sees no pending action to resolve).
$msg->set('aim_status', AiConversationMessage::STATUS_RUNNING);
$msg->save();

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
    chat_resume_and_finalize($conversation, $uid, $msg, $pending, $lead_text, $decision);
    exit;
}

// Non-fpm fallback: resume synchronously, return the finished bubble.
chat_resume_and_finalize($conversation, $uid, $msg, $pending, $lead_text, $decision);
$msg->load();
if ($msg->get('aim_status') === AiConversationMessage::STATUS_FAILED) {
    $payload['status'] = AiConversationMessage::STATUS_FAILED;
    $payload['error']  = (string)$msg->get('aim_error');
} else {
    $payload['status']         = AiConversationMessage::STATUS_COMPLETE;
    $payload['assistant_html'] = ChatRender::assistantBubble($msg, $tz);
}
echo json_encode($payload);
exit;

// --- resume execution (runs after the response is sent under fpm) ---

/**
 * Continue the turn from the confirmation decision and fold the resumed reply
 * into the same assistant row (one assistant message per turn). Any failure
 * marks the row FAILED with an error the poller surfaces. Never echoes.
 */
function chat_resume_and_finalize(AiConversation $conversation, int $uid,
        AiConversationMessage $msg, array $pending, string $lead_text, string $decision): void {
    try {
        $turn = ChatRunner::resumeTurn($conversation, $uid, $pending, $lead_text, $decision);
    } catch (LlmProviderException $e) {
        error_log('[joinery_ai chat] resume provider error: ' . $e->getMessage());
        chat_confirm_mark_failed($msg, 'The AI provider returned an error. Try again.');
        return;
    } catch (Throwable $e) {
        error_log('[joinery_ai chat] resume failed: ' . $e->getMessage());
        chat_confirm_mark_failed($msg, 'The action could not be completed.');
        return;
    }

    $result = $turn['result'];
    $ctx    = $turn['context'];

    $resumed_text = ChatRunner::resolveAssistantText($result);
    $combined = trim($lead_text . (($lead_text !== '' && $resumed_text !== '') ? "\n\n" : '') . $resumed_text);
    if ($combined === '') $combined = ($decision === 'confirm') ? 'Done.' : 'Okay, I won’t do that.';

    $merged_trace = array_merge(chat_decode_trace($msg->get('aim_tool_calls')), $ctx->toolCalls());

    $msg->set('aim_content', $combined);
    $msg->set('aim_tool_calls', $merged_trace);
    $msg->set('aim_pending_action', !empty($result['pending_action']) ? $result['pending_action'] : null);
    $msg->set('aim_input_tokens', (int)$msg->get('aim_input_tokens') + (int)$result['input_tokens']);
    $msg->set('aim_output_tokens', (int)$msg->get('aim_output_tokens') + (int)$result['output_tokens']);
    $msg->set('aim_status', AiConversationMessage::STATUS_COMPLETE);
    $msg->save();

    $conversation->set('aic_total_input_tokens',
        (int)$conversation->get('aic_total_input_tokens') + (int)$result['input_tokens']);
    $conversation->set('aic_total_output_tokens',
        (int)$conversation->get('aic_total_output_tokens') + (int)$result['output_tokens']);
    $conversation->set('aic_update_time', gmdate('Y-m-d H:i:s'));
    $conversation->save();
}

function chat_confirm_mark_failed(AiConversationMessage $msg, string $error): void {
    $msg->set('aim_status', AiConversationMessage::STATUS_FAILED);
    $msg->set('aim_error', $error);
    $msg->save();
}

function chat_decode_trace($value): array {
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
    return is_array($value) ? $value : [];
}

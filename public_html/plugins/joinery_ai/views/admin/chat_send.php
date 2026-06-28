<?php
/**
 * Joinery AI — chat send endpoint (AJAX, JSON).
 * URL: /admin/joinery_ai/chat_send  (POST)
 *
 * Appends the user's message and an assistant placeholder, returns a poll
 * handle immediately, then runs one AgentLoop turn AFTER the response is sent
 * (fastcgi_finish_request) and finalizes the placeholder row in place. The page
 * polls chat_poll until the assistant row is complete or failed. A new
 * conversation is created when no conversation_id is supplied, seeded with the
 * default tool/model/action scope and a title derived from the first message.
 *
 * On a non-fpm SAPI (no fastcgi_finish_request) the turn runs synchronously
 * before responding and the reply rides back in this response — see ChatAsync.
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

function chat_send_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$session = SessionControl::get_instance();
if (!$session->is_logged_in() || $session->get_permission() < 5) {
    chat_send_fail('Not authorized.');
}
$uid = (int)$session->get_user_id();

if (!(string)Globalvars::get_instance()->get_setting('joinery_ai_chat_enabled')) {
    chat_send_fail('Chat is disabled.');
}

$message = trim((string)LibraryFunctions::fetch_variable_local($_POST, 'message', ''));
$conversation_id = (int)LibraryFunctions::fetch_variable_local($_POST, 'conversation_id', 0);

if ($message === '') chat_send_fail('Message cannot be empty.');
if (mb_strlen($message) > 8000) chat_send_fail('Message is too long.');

// Load (and authorize) or create the conversation.
$is_new = false;
if ($conversation_id > 0) {
    $conversation = new AiConversation($conversation_id, true);
    if (!$conversation->key
            || (int)$conversation->get('aic_owner_user_id') !== $uid
            || $conversation->get('aic_delete_time')) {
        chat_send_fail('Conversation not found.');
    }
} else {
    // New conversation — seed capability flags from the composer toggles
    // (both default off if not sent).
    $conversation = new AiConversation(NULL);
    $conversation->set('aic_owner_user_id', $uid);
    $conversation->set('aic_model', ChatRunner::defaultModel());
    $conversation->set('aic_data_access', !empty($_POST['data_access']) ? 't' : 'f');
    $conversation->set('aic_web_search', !empty($_POST['web_search']) ? 't' : 'f');
    $conversation->set('aic_title', chat_derive_title($message));
    $conversation->prepare();
    $conversation->save();
    $conversation->load();
    $is_new = true;
}

// Plugin-wide monthly ceiling (recipes + chat). Fail before spending tokens.
try {
    CostGuard::enforceGlobalCap();
} catch (CapExceededException $e) {
    chat_send_fail('Joinery AI has reached its monthly token cap. Raise the cap in settings to continue.');
}

// If a prior turn left an unconfirmed proposal, sending a new message abandons
// it — record that and clear the pending action so the transcript stays a
// valid, alternating history.
chat_clear_dangling_pending($conversation);

// Persist the user's message (complete on insert).
$user_msg = new AiConversationMessage(NULL);
$user_msg->set('aim_aic_conversation_id', (int)$conversation->key);
$user_msg->set('aim_role', AiConversationMessage::ROLE_USER);
$user_msg->set('aim_content', $message);
$user_msg->prepare();
$user_msg->save();
$user_msg->load();

// Create the assistant placeholder the page will poll. It is RUNNING until the
// turn (below) finalizes it.
$assistant_msg = new AiConversationMessage(NULL);
$assistant_msg->set('aim_aic_conversation_id', (int)$conversation->key);
$assistant_msg->set('aim_role', AiConversationMessage::ROLE_ASSISTANT);
$assistant_msg->set('aim_content', '');
$assistant_msg->set('aim_status', AiConversationMessage::STATUS_RUNNING);
$assistant_msg->prepare();
$assistant_msg->save();
$assistant_msg->load();

$tz = $session->get_timezone();
$user_time = LibraryFunctions::convert_time($user_msg->get('aim_create_time'), 'UTC', $tz, 'g:i A');

// Common fields for the immediate response.
$payload = [
    'success'         => true,
    'conversation_id' => (int)$conversation->key,
    'message_id'      => (int)$assistant_msg->key,
    'is_new'          => $is_new,
    'title'           => $conversation->get('aic_title'),
    'user_html'       => ChatRender::userBubble($message, $user_time),
];

if (ChatAsync::canDetach()) {
    // Tell the page to start polling, release the browser, then run the turn.
    $payload['status'] = AiConversationMessage::STATUS_RUNNING;
    echo json_encode($payload);
    ChatAsync::detach();
    chat_run_and_finalize($conversation, $uid, $assistant_msg);
    exit;
}

// Non-fpm fallback: run synchronously, then return the finished turn so the page
// can render it without polling.
chat_run_and_finalize($conversation, $uid, $assistant_msg);
$assistant_msg->load();
if ($assistant_msg->get('aim_status') === AiConversationMessage::STATUS_FAILED) {
    $payload['status'] = AiConversationMessage::STATUS_FAILED;
    $payload['error']  = (string)$assistant_msg->get('aim_error');
} else {
    $payload['status']         = AiConversationMessage::STATUS_COMPLETE;
    $payload['assistant_html'] = ChatRender::assistantBubble($assistant_msg, $tz);
}
echo json_encode($payload);
exit;

// --- turn execution (runs after the response is sent under fpm) ---

/**
 * Run one turn and write the result onto the pre-created placeholder row, then
 * roll up the conversation token totals. Any failure marks the row FAILED with
 * an error the poller surfaces. Never echoes — the response is already sent.
 */
function chat_run_and_finalize(AiConversation $conversation, int $uid,
        AiConversationMessage $assistant_msg): void {
    try {
        $turn = ChatRunner::runTurn($conversation, $uid, ChatAsync::streamSink($assistant_msg));
    } catch (LlmProviderException $e) {
        error_log('[joinery_ai chat] provider error: ' . $e->getMessage());
        chat_mark_failed($assistant_msg, LlmProviderException::friendlyMessage(LlmProviderException::classify($e)));
        return;
    } catch (Throwable $e) {
        error_log('[joinery_ai chat] turn failed: ' . $e->getMessage());
        chat_mark_failed($assistant_msg, 'The assistant could not complete this turn.');
        return;
    }

    $result = $turn['result'];
    $ctx    = $turn['context'];

    $assistant_msg->set('aim_content', ChatRunner::resolveAssistantText($result));
    $assistant_msg->set('aim_tool_calls', $ctx->toolCalls());
    if (!empty($result['pending_action'])) {
        $assistant_msg->set('aim_pending_action', $result['pending_action']);
    }
    $assistant_msg->set('aim_input_tokens', (int)$result['input_tokens']);
    $assistant_msg->set('aim_output_tokens', (int)$result['output_tokens']);
    $assistant_msg->set('aim_status', AiConversationMessage::STATUS_COMPLETE);
    $assistant_msg->save();

    // Roll up token totals + bump the thread's update time.
    $conversation->set('aic_total_input_tokens',
        (int)$conversation->get('aic_total_input_tokens') + (int)$result['input_tokens']);
    $conversation->set('aic_total_output_tokens',
        (int)$conversation->get('aic_total_output_tokens') + (int)$result['output_tokens']);
    $conversation->set('aic_update_time', gmdate('Y-m-d H:i:s'));
    $conversation->save();
}

function chat_mark_failed(AiConversationMessage $assistant_msg, string $error): void {
    $assistant_msg->set('aim_status', AiConversationMessage::STATUS_FAILED);
    $assistant_msg->set('aim_error', $error);
    $assistant_msg->save();
}

// --- helpers ---

function chat_derive_title(string $message): string {
    $t = trim(preg_replace('/\s+/', ' ', $message));
    if (mb_strlen($t) > 60) $t = mb_substr($t, 0, 57) . '…';
    return $t !== '' ? $t : 'New chat';
}

function chat_clear_dangling_pending(AiConversation $conversation): void {
    $rows = new MultiAiConversationMessage(
        ['conversation_id' => (int)$conversation->key,
         'role' => AiConversationMessage::ROLE_ASSISTANT, 'deleted' => false],
        ['aim_message_id' => 'DESC'], 1, 0
    );
    $rows->load();
    if (!count($rows)) return;
    $last = $rows->get(0);
    $pending = $last->get('aim_pending_action');
    if (is_string($pending)) $pending = json_decode($pending, true);
    if (empty($pending)) return;

    $txt = (string)$last->get('aim_content');
    $note = '_(Proposed an action; you continued without confirming, so it was not run.)_';
    $last->set('aim_content', $txt !== '' ? $txt . "\n\n" . $note : $note);
    $last->set('aim_pending_action', null);
    $last->save();
}

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
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurn.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/CostGuard.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatAttachmentIngest.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSend.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderException.php'));

function chat_send_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$session = SessionControl::get_instance();
// Any logged-in user. The /admin/* route is permission-gated (5), so this file
// stays admin-only at its /admin URL; it also backs /profile/joinery_ai/ for
// members, whose reads are owner-scoped and whose action surface is withheld.
if (!$session->is_logged_in()) {
    chat_send_fail('Not authorized.');
}
$uid = (int)$session->get_user_id();

if (!(string)Globalvars::get_instance()->get_setting('joinery_ai_chat_enabled')) {
    chat_send_fail('Chat is disabled.');
}

$message = trim((string)LibraryFunctions::fetch_variable_local($_POST, 'message', ''));
$conversation_id = (int)LibraryFunctions::fetch_variable_local($_POST, 'conversation_id', 0);

$has_uploads = ChatAttachmentIngest::hasUploads();
if ($message === '' && !$has_uploads) chat_send_fail('Message cannot be empty.');
if (mb_strlen($message) > 8000) chat_send_fail('Message is too long.');

// Load (and authorize) or build the conversation. A NEW conversation is built in
// memory but NOT saved until attachments validate, so a rejected upload never
// leaves an empty thread behind.
$is_new = false;
$new_title = '';
$new_instructions = '';
if ($conversation_id > 0) {
    $conversation = new AiConversation($conversation_id, true);
    if (!$conversation->key
            || (int)$conversation->get('aic_owner_user_id') !== $uid
            || $conversation->get('aic_delete_time')) {
        chat_send_fail('Conversation not found.');
    }
} else {
    $built = ChatSend::buildNewConversation($uid, $_POST, $message);
    $conversation     = $built['conversation'];
    $new_title        = $built['title'];
    $new_instructions = $built['instructions'];
    $is_new = true;
}

// Unlock-first: a protected conversation's turn must decrypt its history, so
// continuing/starting one requires an open vault window. Locked → tell the page
// to prompt unlock and resubmit, before anything is persisted.
$protected = $conversation->isProtected();
if (ChatSend::lockedForWrite($uid, $protected)) {
    echo json_encode([
        'success'         => true,
        'locked'          => true,
        'conversation_id' => $conversation_id,
        'message'         => 'Unlock your vault to continue this protected chat.',
    ]);
    exit;
}

// Validate uploads against the conversation's model + attachment mode BEFORE
// persisting anything (fail-loud on type/size/capability). Held in memory for
// the commit after the user message exists.
$attach = ChatAttachmentIngest::prepare($conversation);
if (!$attach['ok']) chat_send_fail($attach['error']);
$prepared_attachments = $attach['prepared'];

if ($is_new) {
    ChatSend::persistNewConversation($conversation, $protected, $new_title, $new_instructions);
}

// Plugin-wide monthly ceiling (recipes + chat). Fail before spending tokens.
try {
    CostGuard::enforceGlobalCap();
} catch (CapExceededException $e) {
    chat_send_fail('Joinery AI has reached its monthly token cap. Raise the cap in settings to continue.');
}

// Persist the user's message (complete on insert; content may be empty when the
// turn is attachments-only). Protected chats seal the content afterward.
$user_msg = ChatSend::persistUserMessage($conversation, $protected, $message);

// Store + link the validated attachments to this message (private File rows,
// text extracted once in the subprocess). Any file whose stored type drifts from
// the validated one is dropped and reported, so we can warn instead of letting
// the model silently receive nothing.
$attach_failures = ChatAttachmentIngest::commit($prepared_attachments, $user_msg, $conversation, $uid);

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
    'user_html'       => ChatRender::userBubble($message, $user_time, (int)$user_msg->key),
];

$attach_warning = ChatAttachmentIngest::failureWarning($attach_failures);
if ($attach_warning !== '') $payload['attachment_warning'] = $attach_warning;

if (ChatAsync::canDetach()) {
    // Tell the page to start polling, release the browser, then run the turn.
    $payload['status'] = AiConversationMessage::STATUS_RUNNING;
    echo json_encode($payload);
    ChatAsync::detach();
    ChatTurn::runAndFinalize($conversation, $uid, $assistant_msg);
    exit;
}

// Non-fpm fallback: run synchronously, then return the finished turn so the page
// can render it without polling.
ChatTurn::runAndFinalize($conversation, $uid, $assistant_msg);
$assistant_msg->load();
if ($assistant_msg->get('aim_status') === AiConversationMessage::STATUS_FAILED) {
    $payload['status'] = AiConversationMessage::STATUS_FAILED;
    $payload['error']  = (string)$assistant_msg->get('aim_error');
} else {
    $payload['status']         = AiConversationMessage::STATUS_COMPLETE;
    $payload['assistant_html'] = ChatRender::assistantBubble(
        $assistant_msg, $tz, ChatRender::conversationModel($conversation));
    $payload['conversation_usage'] = ChatRender::conversationUsagePayload($conversation);
}
echo json_encode($payload);
exit;

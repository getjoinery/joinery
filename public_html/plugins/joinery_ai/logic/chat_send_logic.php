<?php
/**
 * Joinery AI — send a message / start a chat (API action).
 * POST /api/v1/action/joinery_ai/chat_send
 *   { message, conversation_id?, data_access?, web_search?, model?, ...,
 *     attachments[] (multipart file uploads) }
 *
 * Appends the user's message (with any file attachments) and an assistant
 * placeholder, returns a poll handle immediately, then runs one turn AFTER the
 * response is flushed (register_shutdown_function → ChatAsync::detach) and
 * finalizes the placeholder in place. The client polls chat_poll until the row
 * is complete or failed. Omitting conversation_id creates a new conversation
 * seeded from any control fields present. On a non-fpm SAPI the turn runs
 * synchronously and the finished assistant turn rides back in this response.
 *
 * Uploads are validated (type + size + the selected model's vision/document
 * capability) BEFORE anything is persisted, so a rejected file never leaves a
 * dangling message or File row. Accepted files are stored as private File rows
 * (fil_source = ai_chat_upload), text is extracted once in the isolated
 * subprocess, and each is linked to the message. See specs/joinery_ai_file_uploads.md.
 */
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurn.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatWorkerSpawner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRender.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSerializer.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/CostGuard.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatAttachmentIngest.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSend.php'));

function chat_send_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));

    $session = SessionControl::get_instance();
    $uid = (int)$session->get_user_id();
    if (!$uid) return LogicResult::error('Sign in required.');

    if (!(string)Globalvars::get_instance()->get_setting('joinery_ai_chat_enabled')) {
        return LogicResult::error('Chat is disabled.');
    }

    $message = trim((string)($input['message'] ?? ''));
    $conversation_id = (int)($input['conversation_id'] ?? 0);
    if (mb_strlen($message) > 8000) return LogicResult::error('Message is too long.');

    $has_uploads = ChatAttachmentIngest::hasUploads();
    if ($message === '' && !$has_uploads) {
        return LogicResult::error('Message cannot be empty.');
    }

    // Load (and authorize) an existing conversation, or build a NEW one in memory
    // (seeded from control fields) WITHOUT saving yet — so a rejected upload
    // doesn't leave an empty conversation behind.
    $is_new = false;
    $new_title = '';
    $new_instructions = '';
    if ($conversation_id > 0) {
        $conversation = new AiConversation($conversation_id, true);
        if (!$conversation->key
                || (int)$conversation->get('aic_owner_user_id') !== $uid
                || $conversation->get('aic_delete_time')) {
            return LogicResult::error('Conversation not found.');
        }
    } else {
        $built = ChatSend::buildNewConversation($uid, $input, $message);
        $conversation     = $built['conversation'];
        $new_title        = $built['title'];
        $new_instructions = $built['instructions'];
        $is_new = true;
    }

    // Unlock-first: a protected conversation seals with the public key alone, but
    // the turn must decrypt its history to build the model payload — so continuing
    // (or starting) one requires an open vault window. Locked → prompt unlock
    // before anything is persisted; the client unlocks then resubmits.
    $protected = $conversation->isProtected();
    if (ChatSend::lockedForWrite($uid, $protected)) {
        return LogicResult::render([
            'locked'          => true,
            'conversation_id' => $conversation_id,
            'message'         => 'Unlock your vault to continue this protected chat.',
        ]);
    }

    // Validate uploads against the conversation's model + attachment mode BEFORE
    // persisting anything (fail-loud on type/size/capability). Same shared ingest
    // the web chat_send view uses.
    $attach = ChatAttachmentIngest::prepare($conversation);
    if (!$attach['ok']) return LogicResult::error($attach['error']);
    $prepared_attachments = $attach['prepared'];

    // Everything validated — commit. Persist the (new) conversation first, then
    // seal its title/instructions (the id the AD binds to now exists).
    if ($is_new) {
        ChatSend::persistNewConversation($conversation, $protected, $new_title, $new_instructions);
    }

    // Plugin-wide monthly ceiling (recipes + chat). Fail before spending tokens.
    try {
        CostGuard::enforceGlobalCap();
    } catch (CapExceededException $e) {
        return LogicResult::error('Joinery AI has reached its monthly token cap. Raise the cap in settings to continue.');
    }

    // Persist the user's message (complete on insert; content may be empty when
    // the turn is attachments-only). Protected chats seal the content afterward.
    $user_msg = ChatSend::persistUserMessage($conversation, $protected, $message);

    // Store + link the validated attachments to this message. A file whose stored
    // type drifts from the validated one is dropped and reported so the response
    // can warn, rather than the model silently receiving nothing.
    $attach_failures = ChatAttachmentIngest::commit($prepared_attachments, $user_msg, $conversation, $uid);

    $model_label = ChatRender::conversationModel($conversation);

    // Create the assistant placeholder the client polls; RUNNING until finalized.
    $assistant_msg = new AiConversationMessage(NULL);
    $assistant_msg->set('aim_aic_conversation_id', (int)$conversation->key);
    $assistant_msg->set('aim_role', AiConversationMessage::ROLE_ASSISTANT);
    $assistant_msg->set('aim_content', '');
    $assistant_msg->set('aim_status', AiConversationMessage::STATUS_RUNNING);
    $assistant_msg->prepare();
    $assistant_msg->save();
    $assistant_msg->load();

    $payload = [
        'conversation_id' => (int)$conversation->key,
        'message_id'      => (int)$assistant_msg->key,
        'is_new'          => $is_new,
        'title'           => (string)$conversation->get('aic_title'),
        'user_message'    => ChatSerializer::message($user_msg, $model_label),
    ];

    $attach_warning = ChatAttachmentIngest::failureWarning($attach_failures);
    if ($attach_warning !== '') $payload['attachment_warning'] = $attach_warning;

    // Run the turn in a detached CLI worker and hand back the poll handle now;
    // the client polls chat_poll until the placeholder row is complete/failed.
    // A protected conversation must NOT go to the CLI worker: its unlock window
    // lives in this web request's APCu segment (a separate CLI process can't see
    // the secret key to decrypt history), so it runs in-process below.
    if (!$protected && ChatWorkerSpawner::spawn((int)$assistant_msg->key)) {
        $payload['status'] = AiConversationMessage::STATUS_RUNNING;
        return LogicResult::render($payload);
    }

    // No background execution available — run synchronously and return the turn.
    ChatTurn::runAndFinalize($conversation, $uid, $assistant_msg);
    $assistant_msg->load();
    $payload['status'] = (string)$assistant_msg->get('aim_status');
    if ($payload['status'] === AiConversationMessage::STATUS_FAILED) {
        $payload['error'] = (string)$assistant_msg->get('aim_error');
    } else {
        $payload['assistant_message'] = ChatSerializer::message($assistant_msg, $model_label);
        $payload['usage_label']       = ChatSerializer::usageLabel($conversation);
    }
    return LogicResult::render($payload);
}

function chat_send_logic_descriptor() {
    return ['requires_session' => true,
            'description' => 'Send a message to the AI assistant (creates a conversation when conversation_id is omitted); returns a poll handle for the streaming reply.'];
}

<?php
/**
 * Joinery AI — send a message / start a chat (API action).
 * POST /api/v1/action/joinery_ai/chat_send
 *   { message, conversation_id?, data_access?, web_search?, model?, ... }
 *
 * Appends the user's message and an assistant placeholder, returns a poll handle
 * immediately, then runs one turn AFTER the response is flushed
 * (register_shutdown_function → ChatAsync::detach) and finalizes the placeholder
 * in place. The client polls chat_poll until the row is complete or failed.
 * Omitting conversation_id creates a new conversation seeded from any control
 * fields present. On a non-fpm SAPI the turn runs synchronously and the finished
 * assistant turn rides back in this response.
 */
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurn.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatWorkerSpawner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatControls.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRender.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSerializer.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/CostGuard.php'));

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
    if ($message === '') return LogicResult::error('Message cannot be empty.');
    if (mb_strlen($message) > 8000) return LogicResult::error('Message is too long.');

    // Load (and authorize) or create the conversation.
    $is_new = false;
    if ($conversation_id > 0) {
        $conversation = new AiConversation($conversation_id, true);
        if (!$conversation->key
                || (int)$conversation->get('aic_owner_user_id') !== $uid
                || $conversation->get('aic_delete_time')) {
            return LogicResult::error('Conversation not found.');
        }
    } else {
        // New conversation — seed model + controls from any recognized field in
        // the request; anything absent keeps its column default and resolves to
        // the plugin-setting default at run time.
        $conversation = new AiConversation(NULL);
        $conversation->set('aic_owner_user_id', $uid);
        $conversation->set('aic_model', ChatRunner::defaultModel());
        ChatControls::seedNewConversation($conversation, $input);
        $conversation->set('aic_title', ChatTurn::deriveTitle($message));
        $conversation->prepare();
        $conversation->save();
        $conversation->load();
        $is_new = true;
    }

    // Plugin-wide monthly ceiling (recipes + chat). Fail before spending tokens.
    try {
        CostGuard::enforceGlobalCap();
    } catch (CapExceededException $e) {
        return LogicResult::error('Joinery AI has reached its monthly token cap. Raise the cap in settings to continue.');
    }

    // A prior unconfirmed proposal is abandoned by sending a new message.
    ChatTurn::clearDanglingPending($conversation);

    // Persist the user's message (complete on insert).
    $user_msg = new AiConversationMessage(NULL);
    $user_msg->set('aim_aic_conversation_id', (int)$conversation->key);
    $user_msg->set('aim_role', AiConversationMessage::ROLE_USER);
    $user_msg->set('aim_content', $message);
    $user_msg->prepare();
    $user_msg->save();
    $user_msg->load();

    // Create the assistant placeholder the client polls; RUNNING until finalized.
    $assistant_msg = new AiConversationMessage(NULL);
    $assistant_msg->set('aim_aic_conversation_id', (int)$conversation->key);
    $assistant_msg->set('aim_role', AiConversationMessage::ROLE_ASSISTANT);
    $assistant_msg->set('aim_content', '');
    $assistant_msg->set('aim_status', AiConversationMessage::STATUS_RUNNING);
    $assistant_msg->prepare();
    $assistant_msg->save();
    $assistant_msg->load();

    $model = ChatRender::conversationModel($conversation);
    $payload = [
        'conversation_id' => (int)$conversation->key,
        'message_id'      => (int)$assistant_msg->key,
        'is_new'          => $is_new,
        'title'           => (string)$conversation->get('aic_title'),
        'user_message'    => ChatSerializer::message($user_msg, $model),
    ];

    // Run the turn in a detached CLI worker and hand back the poll handle now;
    // the client polls chat_poll until the placeholder row is complete/failed.
    if (ChatWorkerSpawner::spawn((int)$assistant_msg->key)) {
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
        $payload['assistant_message'] = ChatSerializer::message($assistant_msg, $model);
        $payload['usage_label']       = ChatSerializer::usageLabel($conversation);
    }
    return LogicResult::render($payload);
}

function chat_send_logic_api() {
    return ['requires_session' => true,
            'description' => 'Send a message to the AI assistant (creates a conversation when conversation_id is omitted); returns a poll handle for the streaming reply.'];
}

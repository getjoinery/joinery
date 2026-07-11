<?php
/**
 * Joinery AI — load one conversation and its turns (API action).
 * POST /api/v1/action/joinery_ai/chat_thread  { conversation_id }
 *
 * The structured-JSON counterpart to the web page's server-rendered transcript:
 * the conversation header plus every non-deleted message as a typed turn. Owner-
 * scoped; a foreign or deleted conversation reports "not found" like the rest.
 */
function chat_thread_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRender.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSerializer.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));

    $session = SessionControl::get_instance();
    $uid = (int)$session->get_user_id();
    if (!$uid) return LogicResult::error('Sign in required.');

    $conversation_id = (int)($input['conversation_id'] ?? 0);
    $conversation = new AiConversation($conversation_id, true);
    if (!$conversation->key
            || (int)$conversation->get('aic_owner_user_id') !== $uid
            || $conversation->get('aic_delete_time')) {
        return LogicResult::error('Conversation not found.');
    }

    // Locked protected conversation: return the header metadata + a `locked` flag,
    // no turns. The client prompts unlock (the vault vault-kek ceremony), then
    // re-loads. conversationSummary already withholds the title here.
    if (ChatSeal::isLocked($conversation)) {
        return LogicResult::render([
            'conversation' => ChatSerializer::conversationSummary($conversation) + ['locked' => true],
            'messages'     => [],
            'locked'       => true,
        ]);
    }

    $rows = new MultiAiConversationMessage(
        ['conversation_id' => (int)$conversation->key, 'deleted' => false],
        ['aim_message_id' => 'ASC']
    );
    $rows->load();

    $model = ChatRender::conversationModel($conversation);
    $messages = [];
    foreach ($rows as $row) $messages[] = ChatSerializer::message($row, $model);

    return LogicResult::render([
        'conversation' => ChatSerializer::conversationDetail($conversation),
        'messages'     => $messages,
    ]);
}

function chat_thread_logic_api() {
    return ['requires_session' => true,
            'description' => 'Load one AI chat conversation and its messages as structured turns.'];
}

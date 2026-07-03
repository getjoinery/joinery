<?php
/**
 * Joinery AI — pin / rename / delete a conversation (API action).
 * POST /api/v1/action/joinery_ai/chat_thread_action  { conversation_id, action, value? }
 *
 *   pin    { value: 0|1 | true|false }  toggle sort-above-the-rest
 *   rename { value: <title> }           set the title (trimmed, <=255 chars)
 *   delete                              soft-delete the conversation
 *
 * Ownership is enforced like the other chat actions: the conversation must
 * belong to the caller and not already be deleted.
 */
function chat_thread_action_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));

    $session = SessionControl::get_instance();
    $uid = (int)$session->get_user_id();
    if (!$uid) return LogicResult::error('Sign in required.');

    $conversation_id = (int)($input['conversation_id'] ?? 0);
    $action = (string)($input['action'] ?? '');
    $value  = $input['value'] ?? '';

    $conversation = new AiConversation($conversation_id, true);
    if (!$conversation->key
            || (int)$conversation->get('aic_owner_user_id') !== $uid
            || $conversation->get('aic_delete_time')) {
        return LogicResult::error('Conversation not found.');
    }

    switch ($action) {
        case 'pin':
            $pinned = ($value === true || $value === 1 || $value === '1' || $value === 'true');
            $conversation->set('aic_pinned', $pinned);
            $conversation->save();
            return LogicResult::render(['pinned' => $pinned]);

        case 'rename':
            $title = trim((string)$value);
            if ($title === '') return LogicResult::error('Title cannot be empty.');
            if (mb_strlen($title) > 255) $title = mb_substr($title, 0, 255);
            $conversation->set('aic_title', $title);
            $conversation->save();
            return LogicResult::render(['title' => $title]);

        case 'delete':
            $conversation->soft_delete();
            return LogicResult::render(['deleted' => true]);
    }

    return LogicResult::error('Unknown action.');
}

function chat_thread_action_logic_api() {
    return ['requires_session' => true,
            'description' => 'Pin, rename, or delete an AI chat conversation.'];
}

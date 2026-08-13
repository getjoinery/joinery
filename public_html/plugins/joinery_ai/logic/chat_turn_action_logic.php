<?php
/**
 * Joinery AI — per-turn actions (API action).
 * POST /api/v1/action/joinery_ai/chat_turn_action  { message_id, action }
 *
 * action: delete — soft-delete one turn. Deleting a user turn takes its reply
 * with it (the immediately-following assistant row, when there is one), so a
 * query and its answer leave together. ChatRunner rebuilds the transcript from
 * non-deleted rows and normalizes alternation, so a hole never yields two
 * same-role turns in a row. Ownership flows through the parent conversation.
 */
function chat_turn_action_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));

    $session = SessionControl::get_instance();
    $uid = (int)$session->get_user_id();
    if (!$uid) return LogicResult::error('Sign in required.');

    $message_id = (int)($input['message_id'] ?? 0);
    $action     = (string)($input['action'] ?? '');

    $message = new AiConversationMessage($message_id, true);
    if (!$message->key || $message->get('aim_delete_time')) {
        return LogicResult::error('Message not found.');
    }

    $conversation = new AiConversation((int)$message->get('aim_aic_conversation_id'), true);
    if (!$conversation->key
            || (int)$conversation->get('aic_owner_user_id') !== $uid
            || $conversation->get('aic_delete_time')) {
        return LogicResult::error('Message not found.');
    }

    if ($action !== 'delete') return LogicResult::error('Unknown action.');

    $role = $message->get('aim_role');
    $deleted_ids = [(int)$message->key];
    $message->soft_delete();

    if ($role === AiConversationMessage::ROLE_USER) {
        $db = DbConnector::get_instance()->get_db_link();
        $stmt = $db->prepare(
            'SELECT aim_message_id, aim_role FROM aim_conversation_messages '
            . 'WHERE aim_aic_conversation_id = ? AND aim_message_id > ? '
            . 'AND aim_delete_time IS NULL ORDER BY aim_message_id ASC LIMIT 1'
        );
        $stmt->execute([(int)$conversation->key, (int)$message->key]);
        $next = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($next && $next['aim_role'] === AiConversationMessage::ROLE_ASSISTANT) {
            $reply = new AiConversationMessage((int)$next['aim_message_id'], true);
            if ($reply->key && !$reply->get('aim_delete_time')) {
                $reply->soft_delete();
                $deleted_ids[] = (int)$reply->key;
            }
        }
    }

    return LogicResult::render(['deleted_ids' => $deleted_ids]);
}

function chat_turn_action_logic_descriptor() {
    return ['requires_session' => true,
            'description' => 'Delete one AI chat turn (a deleted user turn also removes its paired reply).'];
}

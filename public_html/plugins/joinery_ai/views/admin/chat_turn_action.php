<?php
/**
 * Joinery AI — per-turn (per-message) actions (AJAX, JSON).
 * URL: /admin/joinery_ai/chat_turn_action  (POST)
 *
 * Actions:
 *   - delete   soft-delete one message (user or assistant turn)
 *
 * Copy is handled entirely client-side (clipboard) and never hits this endpoint.
 * Deleting a single turn is safe for later model calls: ChatRunner rebuilds the
 * transcript from non-deleted rows and normalizes alternation, so a hole left by
 * a deleted turn never produces two same-role turns in a row.
 *
 * Ownership flows through the parent conversation, mirroring the other chat
 * endpoints: the message's conversation must belong to the current admin.
 */
header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));

function chat_turn_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$session = SessionControl::get_instance();
if (!$session->is_logged_in() || $session->get_permission() < 5) {
    chat_turn_fail('Not authorized.');
}
$uid = (int)$session->get_user_id();

$message_id = (int)LibraryFunctions::fetch_variable_local($_POST, 'message_id', 0);
$action     = (string)LibraryFunctions::fetch_variable_local($_POST, 'action', '');

$message = new AiConversationMessage($message_id, true);
if (!$message->key || $message->get('aim_delete_time')) {
    chat_turn_fail('Message not found.');
}

$conversation = new AiConversation((int)$message->get('aim_aic_conversation_id'), true);
if (!$conversation->key
        || (int)$conversation->get('aic_owner_user_id') !== $uid
        || $conversation->get('aic_delete_time')) {
    chat_turn_fail('Message not found.');
}

switch ($action) {
    case 'delete':
        // Delete the exchange: a query takes its answer with it. The reply is the
        // message immediately after this one in the thread; only pair it when that
        // next turn is actually an assistant row (an earlier delete could leave a
        // gap so the next surviving turn is another query, which we leave alone).
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

        echo json_encode(['success' => true, 'deleted_ids' => $deleted_ids]);
        break;

    default:
        chat_turn_fail('Unknown action.');
}
exit;

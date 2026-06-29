<?php
/**
 * Joinery AI — pin / rename / delete a chat thread (AJAX, JSON).
 * URL: /admin/joinery_ai/chat_thread_action  (POST)
 *
 * Actions:
 *   - pin    { value: 0|1 }   toggle whether the thread sorts above the rest
 *   - rename { value: <title> } set the thread title (trimmed, <=255 chars)
 *   - delete                   soft-delete the thread
 *
 * Ownership is enforced the same way as the other chat endpoints: the
 * conversation must belong to the current admin and not already be deleted.
 */
header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));

function chat_thread_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$session = SessionControl::get_instance();
// Any logged-in user. The /admin/* route is permission-gated (5), so this file
// stays admin-only at its /admin URL; it also backs /profile/joinery_ai/ for
// members, whose reads are owner-scoped and whose action surface is withheld.
if (!$session->is_logged_in()) {
    chat_thread_fail('Not authorized.');
}
$uid = (int)$session->get_user_id();

$conversation_id = (int)LibraryFunctions::fetch_variable_local($_POST, 'conversation_id', 0);
$action = (string)LibraryFunctions::fetch_variable_local($_POST, 'action', '');
$value  = LibraryFunctions::fetch_variable_local($_POST, 'value', '');

$conversation = new AiConversation($conversation_id, true);
if (!$conversation->key
        || (int)$conversation->get('aic_owner_user_id') !== $uid
        || $conversation->get('aic_delete_time')) {
    chat_thread_fail('Conversation not found.');
}

switch ($action) {
    case 'pin':
        $pinned = !empty($value) && $value !== '0';
        $conversation->set('aic_pinned', $pinned);
        $conversation->save();
        echo json_encode(['success' => true, 'pinned' => $pinned]);
        break;

    case 'rename':
        $title = trim((string)$value);
        if ($title === '') chat_thread_fail('Title cannot be empty.');
        if (mb_strlen($title) > 255) $title = mb_substr($title, 0, 255);
        $conversation->set('aic_title', $title);
        $conversation->save();
        echo json_encode(['success' => true, 'title' => $title]);
        break;

    case 'delete':
        $conversation->soft_delete();
        echo json_encode(['success' => true]);
        break;

    default:
        chat_thread_fail('Unknown action.');
}
exit;

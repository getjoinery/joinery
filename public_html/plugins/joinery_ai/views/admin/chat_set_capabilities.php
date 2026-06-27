<?php
/**
 * Joinery AI — set a chat's capability toggle (AJAX, JSON).
 * URL: /admin/joinery_ai/chat_set_capabilities  (POST)
 *
 * Flips one per-conversation capability (data_access or web_search) on an
 * existing chat. New chats carry their initial toggle state on the first
 * chat_send instead. Takes effect on the conversation's next turn.
 */
header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));

function chat_cap_fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$session = SessionControl::get_instance();
if (!$session->is_logged_in() || $session->get_permission() < 5) {
    chat_cap_fail('Not authorized.');
}
$uid = (int)$session->get_user_id();

$conversation_id = (int)LibraryFunctions::fetch_variable_local($_POST, 'conversation_id', 0);
$capability      = (string)LibraryFunctions::fetch_variable_local($_POST, 'capability', '');
$enabled         = !empty($_POST['enabled']);

$column_map = [
    'data_access' => 'aic_data_access',
    'web_search'  => 'aic_web_search',
];
if (!isset($column_map[$capability])) chat_cap_fail('Unknown capability.');

$conversation = new AiConversation($conversation_id, true);
if (!$conversation->key
        || (int)$conversation->get('aic_owner_user_id') !== $uid
        || $conversation->get('aic_delete_time')) {
    chat_cap_fail('Conversation not found.');
}

$conversation->set($column_map[$capability], $enabled ? 't' : 'f');
$conversation->save();

echo json_encode([
    'success'    => true,
    'capability' => $capability,
    'enabled'    => $enabled,
]);
exit;

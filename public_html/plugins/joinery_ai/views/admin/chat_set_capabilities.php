<?php
/**
 * Joinery AI — set a per-chat control (AJAX, JSON).
 * URL: /admin/joinery_ai/chat_set_capabilities  (POST)
 *
 * Writes one per-conversation control on an existing chat: the capability
 * toggles (data_access / web_search) and the model controls (model, temperature,
 * top_p, max_tokens, instructions, thinking_level). New chats carry their initial
 * state on the first chat_send instead. Takes effect on the conversation's next
 * turn.
 *
 * Two request shapes are accepted:
 *   - legacy toggle: { capability: data_access|web_search, enabled: 0|1 }
 *   - generic field: { field: <name>, value: <raw> }
 */
header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));

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

// Legacy toggle shape maps onto the generic field/value path.
$field = (string)LibraryFunctions::fetch_variable_local($_POST, 'field', '');
$value = LibraryFunctions::fetch_variable_local($_POST, 'value', '');
$capability = (string)LibraryFunctions::fetch_variable_local($_POST, 'capability', '');
if ($capability !== '') {
    $field = $capability;
    $value = !empty($_POST['enabled']) ? '1' : '0';
}

$conversation = new AiConversation($conversation_id, true);
if (!$conversation->key
        || (int)$conversation->get('aic_owner_user_id') !== $uid
        || $conversation->get('aic_delete_time')) {
    chat_cap_fail('Conversation not found.');
}

// Validate the field and compute the column + stored value. chat_field_value()
// is shared with chat_send (new-chat seeding) so both validate identically.
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatControls.php'));
try {
    [$column, $stored] = ChatControls::validate($field, $value);
} catch (InvalidArgumentException $e) {
    chat_cap_fail($e->getMessage());
}

$conversation->set($column, $stored);
$conversation->save();

echo json_encode(['success' => true, 'field' => $field]);
exit;

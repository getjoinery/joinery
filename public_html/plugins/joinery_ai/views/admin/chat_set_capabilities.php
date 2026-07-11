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
// Any logged-in user. The /admin/* route is permission-gated (5), so this file
// stays admin-only at its /admin URL; it also backs /profile/joinery_ai/ for
// members, whose reads are owner-scoped and whose action surface is withheld.
if (!$session->is_logged_in()) {
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

require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatControls.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatLevel.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));

// Changing the encryption level reseals/reveals stored content and enforces
// prerequisites — its own operation, not a plain column write.
if ($field === 'security_level') {
    $result = ChatLevel::changeLevel($conversation, (string)$value, $uid);
    if (!$result['ok']) chat_cap_fail($result['error']);
    echo json_encode(['success' => true, 'field' => $field, 'security_level' => $result['level']]);
    exit;
}

// Validate the field and compute the column + stored value.
try {
    [$column, $stored] = ChatControls::validate($field, $value);
} catch (InvalidArgumentException $e) {
    chat_cap_fail($e->getMessage());
}

// A Fortress chat pins inference to a local model — refuse a cloud model.
if ($field === 'model' && (string)$conversation->get('aic_security_level') === AiConversation::LEVEL_FORTRESS
        && $stored !== '' && !ChatLevel::isLocalModel((string)$stored)) {
    chat_cap_fail('This is a Fortress chat — it can only use a local model. '
        . 'Choose a local model, or lower the chat to Private/Standard first.');
}

// aic_instructions is content (sealed on a protected chat); every other control is
// cleartext. Persist via a targeted UPDATE so a sealed row is never decrypt-rewritten.
if ($column === 'aic_instructions' && $conversation->isProtected()) {
    if (ChatSeal::lockedForContentEdit($conversation)) {
        echo json_encode(['success' => true, 'locked' => true,
            'message' => 'Unlock your vault to edit this protected chat’s instructions.']);
        exit;
    }
    $cols = ChatSeal::resealConversationColumn($conversation, 'aic_instructions', $stored);
} else {
    $cols = [$column => $stored];
}
AiConversation::updateColumns((int)$conversation->key, $cols);

echo json_encode(['success' => true, 'field' => $field]);
exit;

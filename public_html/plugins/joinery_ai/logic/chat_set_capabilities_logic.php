<?php
/**
 * Joinery AI — set one per-chat control (API action).
 * POST /api/v1/action/joinery_ai/chat_set_capabilities
 *   { conversation_id, field, value }
 *   or the legacy toggle form { conversation_id, capability, enabled }
 *
 * Validates and persists one control (model, temperature, top_p, max_tokens,
 * instructions, thinking_level, or a data_access / web_search toggle) on an
 * existing conversation through the same ChatControls validator the web status
 * strip uses. New chats seed their controls on the first chat_send instead.
 */
function chat_set_capabilities_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatControls.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatLevel.php'));

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

    // Generic field/value form, or the legacy capability/enabled toggle form.
    if (array_key_exists('field', $input)) {
        $field = (string)$input['field'];
        $value = $input['value'] ?? '';
    } elseif (array_key_exists('capability', $input)) {
        $field = (string)$input['capability'];
        $value = !empty($input['enabled']) ? '1' : '0';
    } else {
        return LogicResult::error('Missing field.');
    }

    // Changing the encryption level is its own operation (it reseals/reveals stored
    // content and enforces prerequisites) — not a plain column write.
    if ($field === 'security_level') {
        $result = ChatLevel::changeLevel($conversation, (string)$value, $uid);
        if (!$result['ok']) return LogicResult::error($result['error']);
        return LogicResult::render(['field' => $field, 'security_level' => $result['level']]);
    }

    try {
        [$column, $stored] = ChatControls::validate($field, $value);
    } catch (InvalidArgumentException $e) {
        return LogicResult::error($e->getMessage());
    }

    // A Fortress chat pins inference to a local model — refuse a cloud model.
    if ($field === 'model' && (string)$conversation->get('aic_security_level') === AiConversation::LEVEL_FORTRESS
            && $stored !== '' && !ChatLevel::isLocalModel((string)$stored)) {
        return LogicResult::error('This is a Fortress chat — it can only use a local model. '
            . 'Choose a local model, or lower the chat to Private/Standard first.');
    }

    // aic_instructions is content (sealed on a protected chat); reseal it under the
    // vault (in-window). Every other control is cleartext. Either way persist via a
    // targeted UPDATE so a sealed title/instructions is never decrypt-rewritten.
    if ($column === 'aic_instructions' && $conversation->isProtected()) {
        if (ChatSeal::lockedForContentEdit($conversation)) {
            return LogicResult::render(['locked' => true, 'conversation_id' => (int)$conversation->key,
                'message' => 'Unlock your vault to edit this protected chat’s instructions.']);
        }
        $cols = ChatSeal::resealConversationColumn($conversation, 'aic_instructions', $stored);
    } else {
        $cols = [$column => $stored];
    }
    AiConversation::updateColumns((int)$conversation->key, $cols);

    return LogicResult::render(['field' => $field]);
}

function chat_set_capabilities_logic_descriptor() {
    return ['requires_session' => true,
            'description' => 'Set one AI chat control (model, temperature, thinking level, or a capability toggle) on an existing conversation.'];
}

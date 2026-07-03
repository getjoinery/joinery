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

    try {
        [$column, $stored] = ChatControls::validate($field, $value);
    } catch (InvalidArgumentException $e) {
        return LogicResult::error($e->getMessage());
    }

    $conversation->set($column, $stored);
    $conversation->save();

    return LogicResult::render(['field' => $field]);
}

function chat_set_capabilities_logic_api() {
    return ['requires_session' => true,
            'description' => 'Set one AI chat control (model, temperature, thinking level, or a capability toggle) on an existing conversation.'];
}

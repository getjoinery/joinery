<?php
/**
 * Joinery AI — converge one bounded batch of a chat's stored content to the
 * level it now promises (API action).
 * POST /api/v1/action/joinery_ai/chat_level_batch  { conversation_id }
 *
 * A level change (chat_set_capabilities, field=security_level) flips the
 * chat's promise at once and converts one batch; the page calls this until
 * `remaining` is 0. The target is the chat's CURRENT level, never a parameter.
 * Raising needs only the owner's public key; lowering decrypts, so it needs the
 * owner's window.
 */
function chat_level_batch_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

    $uid = (int)SessionControl::get_instance()->get_user_id();
    $conversation = new AiConversation((int)($input['conversation_id'] ?? 0), true);
    if (!$conversation->key
            || (int)$conversation->get('aic_owner_user_id') !== $uid
            || $conversation->get('aic_delete_time')) {
        return LogicResult::error('Conversation not found.');
    }

    $scope = new ChatConversationLevel($conversation);
    $pass = ProtectionLevelChange::convergeBatch($scope);
    if ($pass['locked']) {
        return LogicResult::error('Unlock your vault to keep converting this chat.');
    }
    return LogicResult::render([
        'security_level' => $scope->currentLevel(),
        'converted'      => $pass['converted'],
        'failed'         => $pass['failed'],
        'remaining'      => $pass['remaining'],
    ]);
}

function chat_level_batch_logic_descriptor() {
    return ['requires_session' => true,
            'mutates' => true,
            'description' => 'Converge one bounded batch of an AI chat\'s stored content to its current privacy level (owner only). Call repeatedly until `remaining` is 0; a pass that converts nothing while rows remain means those rows cannot be converted now.',
            'input' => [
                'conversation_id' => ['type' => 'int', 'required' => true, 'label' => 'Conversation ID'],
            ]];
}

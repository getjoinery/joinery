<?php
/**
 * Joinery AI — confirm / cancel a proposed action (API action).
 * POST /api/v1/action/joinery_ai/chat_confirm  { conversation_id, message_id, decision }
 *
 * Resolves a pending mutating call the assistant proposed. Confirm executes the
 * approved call and continues the turn; Cancel feeds a "declined" result back so
 * the model adapts. Either way the pending assistant message is finalized in
 * place (one assistant row per turn). Like chat_send the resume runs after the
 * response flushes; the client re-polls chat_poll on the same message_id. On a
 * non-fpm SAPI the resume runs synchronously and rides back in this response.
 */
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurn.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatWorkerSpawner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRender.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSerializer.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/CostGuard.php'));

function chat_confirm_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));

    $session = SessionControl::get_instance();
    $uid = (int)$session->get_user_id();
    if (!$uid) return LogicResult::error('Sign in required.');

    $conversation_id = (int)($input['conversation_id'] ?? 0);
    $message_id      = (int)($input['message_id'] ?? 0);
    $decision        = (string)($input['decision'] ?? '');
    if (!in_array($decision, ['confirm', 'cancel'], true)) {
        return LogicResult::error('Invalid decision.');
    }

    $conversation = new AiConversation($conversation_id, true);
    if (!$conversation->key
            || (int)$conversation->get('aic_owner_user_id') !== $uid
            || $conversation->get('aic_delete_time')) {
        return LogicResult::error('Conversation not found.');
    }

    $msg = new AiConversationMessage($message_id, true);
    if (!$msg->key
            || (int)$msg->get('aim_aic_conversation_id') !== (int)$conversation->key
            || $msg->get('aim_role') !== AiConversationMessage::ROLE_ASSISTANT) {
        return LogicResult::error('Message not found.');
    }

    $pending = $msg->get('aim_pending_action');
    if (is_string($pending)) $pending = json_decode($pending, true);
    if (empty($pending) || !is_array($pending)) {
        return LogicResult::error('There is no pending action to resolve (it may already have been handled).');
    }

    $lead_text = (string)$msg->get('aim_content');

    if ($decision === 'confirm') {
        try {
            CostGuard::enforceGlobalCap();
        } catch (CapExceededException $e) {
            return LogicResult::error('Joinery AI has reached its monthly token cap. Raise the cap in settings to continue.');
        }
    }

    // Flip the pending row to RUNNING so it can be polled (and a duplicate
    // confirm sees no pending action to resolve).
    $msg->set('aim_status', AiConversationMessage::STATUS_RUNNING);
    $msg->save();

    $payload = [
        'conversation_id' => (int)$conversation->key,
        'message_id'      => (int)$msg->key,
    ];

    // Resume in a detached CLI worker; the client re-polls chat_poll on the same
    // message_id. The worker re-reads the pending action and lead text from the
    // row, so only the message id and the decision need to cross the boundary.
    if (ChatWorkerSpawner::spawn((int)$msg->key, $decision)) {
        $payload['status'] = AiConversationMessage::STATUS_RUNNING;
        return LogicResult::render($payload);
    }

    // No background execution available — resume synchronously, return the turn.
    ChatTurn::resumeAndFinalize($conversation, $uid, $msg, $pending, $lead_text, $decision);
    $msg->load();
    $payload['status'] = (string)$msg->get('aim_status');
    if ($payload['status'] === AiConversationMessage::STATUS_FAILED) {
        $payload['error'] = (string)$msg->get('aim_error');
    } else {
        $model = ChatRender::conversationModel($conversation);
        $payload['assistant_message'] = ChatSerializer::message($msg, $model);
        $payload['usage_label']       = ChatSerializer::usageLabel($conversation);
    }
    return LogicResult::render($payload);
}

function chat_confirm_logic_api() {
    return ['requires_session' => true,
            'description' => 'Confirm or cancel a pending mutating action the assistant proposed; resumes the turn.'];
}

<?php
/**
 * Joinery AI — poll a running turn (API action).
 * POST /api/v1/action/joinery_ai/chat_poll  { message_id }
 *
 * The delivery channel for an asynchronous turn. While the row is RUNNING it
 * returns the answer text so far (partial_text); once COMPLETE it returns the
 * finished turn and the conversation usage label; FAILED returns the error. A
 * row left running past the stale ceiling (its worker died) is reaped to FAILED
 * here so the client shows an error instead of spinning forever. Owner-scoped.
 */
function chat_poll_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatAsync.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRender.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSerializer.php'));

    $session = SessionControl::get_instance();
    $uid = (int)$session->get_user_id();
    if (!$uid) return LogicResult::error('Sign in required.');

    $message_id = (int)($input['message_id'] ?? 0);
    if ($message_id <= 0) return LogicResult::error('Missing message id.');

    $msg = new AiConversationMessage($message_id, true);
    if (!$msg->key
            || $msg->get('aim_role') !== AiConversationMessage::ROLE_ASSISTANT
            || $msg->get('aim_delete_time')) {
        return LogicResult::error('Message not found.');
    }

    // Owner-scope through the parent conversation.
    $conversation = new AiConversation((int)$msg->get('aim_aic_conversation_id'), true);
    if (!$conversation->key
            || (int)$conversation->get('aic_owner_user_id') !== $uid
            || $conversation->get('aic_delete_time')) {
        return LogicResult::error('Message not found.');
    }

    // Reap a stale running row before reading its status.
    if (ChatAsync::sweepMessage($msg)) $msg->load();

    $status = (string)$msg->get('aim_status');
    $out = ['status' => $status];

    if ($status === AiConversationMessage::STATUS_COMPLETE) {
        $model = ChatRender::conversationModel($conversation);
        $out['message']     = ChatSerializer::message($msg, $model);
        $out['usage_label'] = ChatSerializer::usageLabel($conversation);
    } elseif ($status === AiConversationMessage::STATUS_FAILED) {
        $out['error'] = (string)$msg->get('aim_error') ?: 'The assistant could not complete this turn.';
    } else {
        // Still running — the answer text written so far, for a live view.
        $out['partial_text'] = (string)$msg->get('aim_content');
    }

    return LogicResult::render($out);
}

function chat_poll_logic_api() {
    return ['requires_session' => true,
            'description' => 'Poll a running AI chat turn; returns partial text while running, the finished turn when complete, or an error.'];
}

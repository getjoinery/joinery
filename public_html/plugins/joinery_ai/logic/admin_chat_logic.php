<?php

function admin_joinery_ai_chat_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));

    $session = SessionControl::get_instance();
    if (!$session->is_logged_in() || $session->get_permission() < 5) {
        return LogicResult::redirect('/login?return=/admin/joinery_ai/chat');
    }

    $uid = (int)$session->get_user_id();
    $settings = Globalvars::get_instance();

    $conversations = new MultiAiConversation(
        ['owner_user_id' => $uid, 'deleted' => false],
        ['aic_update_time' => 'DESC']
    );
    $conversations->load();

    // Resolve the selected thread. Explicit id wins; otherwise default to the
    // most recent thread so the page opens on something useful. id=0 with no
    // threads is the empty "new chat" state.
    $selected_id = (int)($input['aic_conversation_id'] ?? 0);
    $selected = null;
    if ($selected_id > 0) {
        $candidate = new AiConversation($selected_id, true);
        if ($candidate->key
                && (int)$candidate->get('aic_owner_user_id') === $uid
                && !$candidate->get('aic_delete_time')) {
            $selected = $candidate;
        }
    } elseif (count($conversations)) {
        $selected = $conversations->get(0);
    }

    $messages = [];
    if ($selected) {
        $rows = new MultiAiConversationMessage(
            ['conversation_id' => (int)$selected->key, 'deleted' => false],
            ['aim_message_id' => 'ASC']
        );
        $rows->load();
        foreach ($rows as $row) $messages[] = $row;
    }

    $active_model = $selected ? (string)$selected->get('aic_model') : '';
    if ($active_model === '') {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
        $active_model = ChatRunner::defaultModel();
    }

    return LogicResult::render([
        'session'        => $session,
        'conversations'  => $conversations,
        'selected'       => $selected,
        'messages'       => $messages,
        'active_model'   => $active_model,
        'web_enabled'    => (string)$settings->get_setting('joinery_ai_brave_search_api_key') !== '',
        'chat_enabled'   => (bool)$settings->get_setting('joinery_ai_chat_enabled'),
    ]);
}

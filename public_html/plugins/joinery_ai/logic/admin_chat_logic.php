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

    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));

    $active_model = $selected ? (string)$selected->get('aic_model') : '';
    if ($active_model === '') {
        $active_model = ChatRunner::defaultModel();
    }

    // Per-chat model controls. For an existing chat, show its own stored value
    // (blank = "inherit the default"); for a new chat, all blank. The resolved
    // defaults drive the placeholder text so the inherited value is visible.
    $g = function ($col) use ($selected) {
        if (!$selected) return '';
        $v = $selected->get($col);
        return ($v === null) ? '' : (string)$v;
    };
    $thinking_level = $selected ? (string)$selected->get('aic_thinking_level') : '';
    if ($thinking_level === '') {
        $thinking_level = (string)$settings->get_setting('joinery_ai_default_thinking_level') ?: 'off';
    }

    return LogicResult::render([
        'session'        => $session,
        'conversations'  => $conversations,
        'selected'       => $selected,
        'messages'       => $messages,
        'active_model'   => $active_model,
        'models'         => LlmProviderFactory::allModels(),
        'data_access'    => $selected ? (bool)$selected->get('aic_data_access') : false,
        'web_search'     => $selected ? (bool)$selected->get('aic_web_search') : false,
        'temperature'    => $g('aic_temperature'),
        'top_p'          => $g('aic_top_p'),
        'max_tokens'     => $g('aic_max_tokens'),
        'instructions'   => $g('aic_instructions'),
        'thinking_level' => $thinking_level,
        'def_temperature'=> (string)$settings->get_setting('joinery_ai_default_temperature'),
        'def_top_p'      => (string)$settings->get_setting('joinery_ai_default_top_p'),
        'def_max_tokens' => (string)$settings->get_setting('joinery_ai_chat_max_tokens'),
        'brave_key_set'  => (string)$settings->get_setting('joinery_ai_brave_search_api_key') !== '',
        'chat_enabled'   => (bool)$settings->get_setting('joinery_ai_chat_enabled'),
    ]);
}

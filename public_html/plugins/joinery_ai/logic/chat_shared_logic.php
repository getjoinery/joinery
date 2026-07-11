<?php

/**
 * Shared chat page logic for both surfaces. The admin page
 * (admin_joinery_ai_chat_logic) and the member page
 * (profile_joinery_ai_chat_logic) differ only in the access gate and the login
 * return path; everything the view needs — the conversation list, the selected
 * thread + its messages, the model catalog and privacy map, and the per-chat /
 * default control values — is identical and built here once.
 *
 * Owner-scoping is enforced downstream at read time (ChatTurnContext /
 * ModelQueryExecutor), not here: this function only loads the caller's OWN
 * conversations (owner_user_id = the session user) regardless of surface.
 */
function joinery_ai_chat_page_logic(array $input, int $min_permission, string $login_return): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));

    $session = SessionControl::get_instance();
    if (!$session->is_logged_in() || $session->get_permission() < $min_permission) {
        return LogicResult::redirect('/login?return=' . $login_return);
    }

    $uid = (int)$session->get_user_id();
    $settings = Globalvars::get_instance();

    $conversations = new MultiAiConversation(
        ['owner_user_id' => $uid, 'deleted' => false],
        ['aic_pinned' => 'DESC', 'aic_update_time' => 'DESC']
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

    // A locked protected conversation renders metadata only — its turns (and
    // sealed controls like instructions) stay withheld until the user unlocks in
    // the page. Skip loading messages so no sealed read throws on page load.
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSeal.php'));
    $selected_locked = $selected ? ChatSeal::isLocked($selected) : false;

    $messages = [];
    if ($selected && !$selected_locked) {
        $rows = new MultiAiConversationMessage(
            ['conversation_id' => (int)$selected->key, 'deleted' => false],
            ['aim_message_id' => 'ASC']
        );
        $rows->load();
        foreach ($rows as $row) $messages[] = $row;
    }

    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AiAttachment.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatLevel.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));

    $active_model = $selected ? (string)$selected->get('aic_model') : '';
    if ($active_model === '') {
        $active_model = ChatRunner::defaultModel();
    }

    // Per-model privacy, sourced from each model's provider. The chat composer
    // uses this to warn (only) before sending sensitive-looking text to a
    // non-private model. Every model here belongs to a configured provider, so
    // forModel() resolves without throwing; treat any surprise as non-private.
    $models = LlmProviderFactory::allModels();
    $model_privacy = [];
    foreach ($models as $mid => $_label) {
        try {
            $model_privacy[(string)$mid] = LlmProviderFactory::forModel((string)$mid)->isPrivate();
        } catch (Throwable $e) {
            $model_privacy[(string)$mid] = false;
        }
    }

    // Per-chat model controls. For an existing chat, show its own stored value
    // (blank = "inherit the default"); for a new chat, all blank. The resolved
    // defaults drive the placeholder text so the inherited value is visible.
    $g = function ($col) use ($selected) {
        if (!$selected) return '';
        $v = $selected->get($col);
        return ($v === null) ? '' : (string)$v;
    };
    $default_thinking_level = (string)$settings->get_setting('joinery_ai_default_thinking_level') ?: 'off';
    $thinking_level = $selected ? (string)$selected->get('aic_thinking_level') : '';
    if ($thinking_level === '') {
        $thinking_level = $default_thinking_level;
    }

    // New-chat defaults the composer resets to. Model resolves to the active
    // provider's default; web search defaults on when a search key is configured.
    $brave_key_set = (string)$settings->get_setting('joinery_ai_brave_search_api_key') !== '';
    $default_model = ChatRunner::defaultModel();
    $default_web_search = $brave_key_set
        && (string)$settings->get_setting('joinery_ai_default_web_search') === '1';

    return LogicResult::render([
        'session'        => $session,
        'conversations'  => $conversations,
        'selected'       => $selected,
        'messages'       => $messages,
        'active_model'   => $active_model,
        'models'         => $models,
        'model_privacy'  => $model_privacy,
        'data_access'    => $selected ? (bool)$selected->get('aic_data_access') : false,
        'web_search'     => $selected ? (bool)$selected->get('aic_web_search') : $default_web_search,
        'temperature'    => $g('aic_temperature'),
        'top_p'          => $g('aic_top_p'),
        'max_tokens'     => $g('aic_max_tokens'),
        // aic_instructions is sealed — withheld (empty) while the chat is locked.
        'instructions'   => $selected_locked ? '' : $g('aic_instructions'),
        'thinking_level' => $thinking_level,
        // Per-conversation encryption level (cleartext) + the locked flag, and the
        // levels the composer may offer for a NEW chat (gated by vault / local model).
        'selected_locked'  => $selected_locked,
        'security_level'   => $selected
            ? ((string)$selected->get('aic_security_level') ?: AiConversation::LEVEL_STANDARD)
            : ChatLevel::defaultLevel(),
        'private_available'  => ChatLevel::privateAvailable($uid),
        'fortress_available' => ChatLevel::fortressAvailable($uid),
        'default_chat_level' => ChatLevel::defaultLevel(),
        'default_model'  => $default_model,
        'default_thinking_level' => $default_thinking_level,
        'default_web_search'     => $default_web_search,
        'def_temperature'=> (string)$settings->get_setting('joinery_ai_default_temperature'),
        'def_top_p'      => (string)$settings->get_setting('joinery_ai_default_top_p'),
        'def_max_tokens' => (string)$settings->get_setting('joinery_ai_chat_max_tokens'),
        'brave_key_set'  => $brave_key_set,
        'chat_enabled'   => (bool)$settings->get_setting('joinery_ai_chat_enabled'),
        // Attachments: the per-chat send mode (extract vs original) and the
        // accepted MIME types + count for the composer.
        'attachment_mode' => $selected
            ? ((string)$selected->get('aic_attachment_mode') ?: AiAttachment::MODE_EXTRACT)
            : AiAttachment::MODE_EXTRACT,
        'default_attachment_mode' => AiAttachment::MODE_EXTRACT,
        'attach_accept_types'     => implode(',', array_keys(AiAttachment::CATEGORY)),
        'attach_max_files'        => AiAttachment::maxPerMessage(),
    ]);
}

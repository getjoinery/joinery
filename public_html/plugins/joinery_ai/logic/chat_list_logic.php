<?php
/**
 * Joinery AI — list the signed-in user's chat conversations (API action).
 * POST /api/v1/action/joinery_ai/chat_list  { search? }
 *
 * The native app's thread pane. Owner-scoped, pinned-first then most-recent,
 * optionally filtered by a term matched against the title OR any message body.
 */
function chat_list_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatSerializer.php'));

    $session = SessionControl::get_instance();
    $uid = (int)$session->get_user_id();
    if (!$uid) return LogicResult::error('Sign in required.');

    $search = isset($input['search']) ? trim((string)$input['search']) : '';

    $options = ['owner_user_id' => $uid, 'deleted' => false];
    if ($search !== '') $options['search'] = $search;

    $conversations = new MultiAiConversation(
        $options,
        ['aic_pinned' => 'DESC', 'aic_update_time' => 'DESC']
    );
    $conversations->load();

    $out = [];
    foreach ($conversations as $c) $out[] = ChatSerializer::conversationSummary($c);

    $payload = ['conversations' => $out];
    // Searching while locked can't reach protected chats — flag it so the native
    // client offers to unlock and re-search (identical discipline to mail).
    if ($search !== '' && MultiAiConversation::ownerHasLockedProtected($uid)) {
        $payload['search_locked'] = true;
    }

    return LogicResult::render($payload);
}

function chat_list_logic_descriptor() {
    return ['requires_session' => true,
            'description' => 'List the signed-in user\'s AI chat conversations (pinned first, newest first); optional title/body search.'];
}

<?php

require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/chat_shared_logic.php'));

/**
 * Member chat page: any logged-in user (permission floor 0). A member's reads
 * are owner-scoped and the action surface is withheld downstream
 * (ChatTurnContext); this only gates page access and loads the caller's own
 * conversations. Delegates to the shared loader.
 */
function profile_joinery_ai_chat_logic(array $input): LogicResult {
    return joinery_ai_chat_page_logic($input, 0, '/profile/joinery_ai/chat');
}

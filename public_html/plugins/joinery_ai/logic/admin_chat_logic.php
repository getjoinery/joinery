<?php

require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/chat_shared_logic.php'));

/**
 * Admin chat page: requires admin permission (5). The /admin/* route is also
 * permission-gated, so this is the inner check. Delegates to the shared loader.
 */
function admin_joinery_ai_chat_logic(array $input): LogicResult {
    return joinery_ai_chat_page_logic($input, 5, '/admin/joinery_ai/chat');
}

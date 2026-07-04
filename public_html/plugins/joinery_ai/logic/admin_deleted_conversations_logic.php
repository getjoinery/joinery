<?php
/**
 * Joinery AI — admin "Deleted Chats" purge list.
 * URL: /admin/joinery_ai/deleted_conversations
 *
 * Lists every soft-deleted AiConversation across all owners (a superadmin
 * cleanup tool, not per-user trash) and offers a bulk "Empty Trash" action
 * that permanently deletes all of them — cascading through their messages,
 * attachment links, and uploaded Files. Per-row purge lives at
 * admin_deleted_conversation_purge (dry-run + confirm).
 */
function admin_joinery_ai_deleted_conversations_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));

    $session = SessionControl::get_instance();
    $session->check_permission(10);
    $session->set_return();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && ($input['action'] ?? '') === 'empty_trash' && !empty($input['confirm'])) {
        $deleted = new MultiAiConversation(['deleted' => true], ['aic_conversation_id' => 'ASC']);
        $deleted->load();
        foreach ($deleted as $conversation) {
            $conversation->permanent_delete();
        }
        return LogicResult::redirect('/admin/joinery_ai/deleted_conversations');
    }

    $numperpage = 30;
    $offset = (int)LibraryFunctions::fetch_variable_local($_GET, 'offset', 0);

    $conversations = new MultiAiConversation(
        ['deleted' => true],
        ['aic_delete_time' => 'DESC'],
        $numperpage,
        $offset
    );
    $numrecords = $conversations->count_all();
    $conversations->load();

    return LogicResult::render([
        'session' => $session,
        'conversations' => $conversations,
        'numrecords' => $numrecords,
        'numperpage' => $numperpage,
    ]);
}
?>

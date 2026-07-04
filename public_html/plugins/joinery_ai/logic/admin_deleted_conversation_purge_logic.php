<?php
/**
 * Joinery AI — permanently delete one soft-deleted conversation.
 * URL: /admin/joinery_ai/deleted_conversation_purge?aic_conversation_id=N
 *
 * GET shows a dry-run preview (permanent_delete_dry_run()) of the cascade —
 * messages, attachment links, and the uploaded Files they point at — with a
 * confirm form. POST executes AiConversation::permanent_delete(). Only ever
 * operates on a conversation that is already soft-deleted (aic_delete_time
 * set) — this purges trash, it does not delete a live chat.
 */
function admin_joinery_ai_deleted_conversation_purge_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));

    $session = SessionControl::get_instance();
    $session->check_permission(10);

    $aic_conversation_id = LibraryFunctions::fetch_variable(
        'aic_conversation_id', NULL, 1, 'You must provide a conversation to purge.', $input
    );

    $conversation = new AiConversation($aic_conversation_id, TRUE);
    if (!$conversation->key || !$conversation->get('aic_delete_time')) {
        return LogicResult::redirect('/admin/joinery_ai/deleted_conversations');
    }

    // POST - execute
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($input['confirm'])) {
        $conversation->permanent_delete();
        return LogicResult::redirect('/admin/joinery_ai/deleted_conversations');
    }

    // GET - preview
    $dry_run = $conversation->permanent_delete_dry_run();

    return LogicResult::render([
        'session' => $session,
        'conversation' => $conversation,
        'aic_conversation_id' => $aic_conversation_id,
        'dry_run' => $dry_run,
    ]);
}
?>

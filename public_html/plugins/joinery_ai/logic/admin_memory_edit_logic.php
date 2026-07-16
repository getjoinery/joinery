<?php

/**
 * Joinery AI — admin memory edit (specs/joinery_ai_memory.md § human entry).
 * New rows created here are always SHARED (source='admin', created_by=the
 * acting admin) — the admin page is the only writer of the shared pool. An
 * existing row of either scope may be edited or soft-deleted (support), but
 * its scope/owner/source never change on edit: provenance stays truthful.
 */
function admin_joinery_ai_memory_edit_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_memories_class.php'));

    $session = SessionControl::get_instance();
    $session->check_permission(10);

    if (isset($input['edit_primary_key_value']) && $input['edit_primary_key_value']) {
        $memory = new AiMemory($input['edit_primary_key_value'], TRUE);
    } elseif (isset($input['mem_memory_id']) && $input['mem_memory_id']) {
        $memory = new AiMemory($input['mem_memory_id'], TRUE);
    } else {
        $memory = new AiMemory(NULL);
        $memory->set('mem_scope', AiMemory::SCOPE_SHARED);
        $memory->set('mem_source', AiMemory::SOURCE_ADMIN);
        $memory->set('mem_created_by_user_id', $session->get_user_id());
    }

    if ($memory->key && !$memory->get('mem_memory_id')) {
        return LogicResult::redirect('/admin/joinery_ai/memory');
    }

    if (LibraryFunctions::isFormSubmission()) {
        if (isset($input['btn_delete']) && $memory->key) {
            $memory->soft_delete();
            return LogicResult::redirect('/admin/joinery_ai/memory');
        }

        $memory->set('mem_title', trim((string)($input['mem_title'] ?? '')));
        $memory->set('mem_content', (string)($input['mem_content'] ?? ''));

        $tags_raw = trim((string)($input['mem_tags_text'] ?? ''));
        $tags = $tags_raw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $tags_raw)), 'strlen'));
        $memory->set('mem_tags', $tags);

        $memory->set('mem_update_time', gmdate('Y-m-d H:i:s'));
        $memory->prepare();
        $memory->save();
        $memory->load();

        return LogicResult::redirect('/admin/joinery_ai/memory_edit?mem_memory_id=' . $memory->key . '&saved=1');
    }

    return LogicResult::render([
        'memory' => $memory,
        'session' => $session,
        'saved' => !empty($input['saved']),
    ]);
}

<?php

/**
 * Joinery AI — member memory page logic (specs/joinery_ai_memory.md § human
 * entry). A member manages only their OWN user-scope memories: list, add,
 * edit, delete — including rows the AI wrote (badged by mem_source so they can
 * correct or delete them). Shared org memories are admin-managed and never
 * editable here; a memory id that isn't the caller's own simply falls back to
 * the list (no existence signal).
 */
function profile_joinery_ai_memory_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('includes/Pager.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_memories_class.php'));

    $session = SessionControl::get_instance();
    if (!$session->is_logged_in()) {
        return LogicResult::redirect('/login?return=/profile/joinery_ai/memory');
    }
    $uid = (int)$session->get_user_id();

    // Resolve the row being edited — only ever the caller's own user-scope row.
    $memory = new AiMemory(NULL);
    $edit_id = (int)($input['edit_primary_key_value'] ?? $input['mem_memory_id'] ?? 0);
    if ($edit_id > 0) {
        $candidate = new AiMemory($edit_id, TRUE);
        if (!$candidate->get('mem_memory_id')
                || (string)$candidate->get('mem_scope') !== AiMemory::SCOPE_USER
                || (int)$candidate->get('mem_owner_user_id') !== $uid
                || $candidate->get('mem_delete_time')) {
            return LogicResult::redirect('/profile/joinery_ai/memory');
        }
        $memory = $candidate;
    }

    if (LibraryFunctions::isFormSubmission()) {
        if (isset($input['btn_delete']) && $memory->key) {
            $memory->soft_delete();
            return LogicResult::redirect('/profile/joinery_ai/memory');
        }

        if (!$memory->key) {
            $memory->set('mem_scope', AiMemory::SCOPE_USER);
            $memory->set('mem_owner_user_id', $uid);
            $memory->set('mem_created_by_user_id', $uid);
            $memory->set('mem_source', AiMemory::SOURCE_USER);
        }
        // An AI-written row keeps its 'ai' source on edit — the badge means
        // "the AI first wrote this", which stays true.

        $memory->set('mem_title', trim((string)($input['mem_title'] ?? '')));
        $memory->set('mem_content', (string)($input['mem_content'] ?? ''));

        $tags_raw = trim((string)($input['mem_tags_text'] ?? ''));
        $tags = $tags_raw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $tags_raw)), 'strlen'));
        $memory->set('mem_tags', $tags);

        $memory->set('mem_update_time', gmdate('Y-m-d H:i:s'));
        try {
            $memory->prepare();
            $memory->save();
        } catch (AiMemoryException $e) {
            return LogicResult::error($e->getMessage());
        }

        return LogicResult::redirect('/profile/joinery_ai/memory?saved=1');
    }

    $numperpage = 30;
    $offset = (int)($input['offset'] ?? 0);
    $memories = new MultiAiMemory(
        ['scope' => AiMemory::SCOPE_USER, 'owner_user_id' => $uid, 'deleted' => false],
        ['mem_update_time' => 'DESC', 'mem_create_time' => 'DESC'],
        $numperpage, $offset
    );
    $numrecords = $memories->count_all();
    $memories->load();

    return LogicResult::render([
        'session'    => $session,
        'memory'     => $memory,
        'memories'   => $memories,
        'numrecords' => $numrecords,
        'numperpage' => $numperpage,
        'offset'     => $offset,
        'pager'      => new Pager(['numrecords' => $numrecords, 'numperpage' => $numperpage]),
        'saved'      => !empty($input['saved']),
    ]);
}

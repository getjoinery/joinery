<?php

function admin_joinery_ai_note_logic($get_vars, $post_vars) {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_notes_class.php'));

    $session = SessionControl::get_instance();
    $session->check_permission(10);

    if (isset($post_vars['edit_primary_key_value']) && $post_vars['edit_primary_key_value']) {
        $note = new RecipeNote($post_vars['edit_primary_key_value'], TRUE);
    } elseif (isset($get_vars['rcn_note_id']) && $get_vars['rcn_note_id']) {
        $note = new RecipeNote($get_vars['rcn_note_id'], TRUE);
    } else {
        $note = new RecipeNote(NULL);
        $note->set('rcn_owner_user_id', $session->get_user_id());
    }

    if ($post_vars && isset($post_vars['btn_submit'])) {
        if (isset($post_vars['btn_delete']) && $note->key) {
            $note->soft_delete();
            return LogicResult::redirect('/admin/joinery_ai/notes');
        }

        $note->set('rcn_title', trim((string)($post_vars['rcn_title'] ?? '')));
        $note->set('rcn_content', (string)($post_vars['rcn_content'] ?? ''));

        // Tags posted as comma-separated string
        $tags_raw = trim((string)($post_vars['rcn_tags_text'] ?? ''));
        $tags = $tags_raw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $tags_raw)), 'strlen'));
        $note->set('rcn_tags', $tags);

        if (!$note->get('rcn_owner_user_id')) {
            $note->set('rcn_owner_user_id', $session->get_user_id());
        }
        $note->set('rcn_update_time', gmdate('Y-m-d H:i:s'));
        $note->prepare();
        $note->save();
        $note->load();

        return LogicResult::redirect('/admin/joinery_ai/note?rcn_note_id=' . $note->key . '&saved=1');
    }

    return LogicResult::render([
        'note' => $note,
        'session' => $session,
        'saved' => !empty($get_vars['saved']),
    ]);
}

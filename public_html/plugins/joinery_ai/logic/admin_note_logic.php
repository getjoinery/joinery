<?php

function admin_joinery_ai_note_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_notes_class.php'));

    $session = SessionControl::get_instance();
    $session->check_permission(10);

    if (isset($input['edit_primary_key_value']) && $input['edit_primary_key_value']) {
        $note = new RecipeNote($input['edit_primary_key_value'], TRUE);
    } elseif (isset($input['rcn_note_id']) && $input['rcn_note_id']) {
        $note = new RecipeNote($input['rcn_note_id'], TRUE);
    } else {
        $note = new RecipeNote(NULL);
        $note->set('rcn_owner_user_id', $session->get_user_id());
    }

    // Gate on the POST method, not a specific button name: the Delete button
    // posts only btn_delete (not btn_submit), so an isset($input['btn_submit'])
    // gate would skip deletes. See specs/formwriter_submitter_preservation.md.
    if (LibraryFunctions::isFormSubmission()) {
        if (isset($input['btn_delete']) && $note->key) {
            $note->soft_delete();
            return LogicResult::redirect('/admin/joinery_ai/notes');
        }

        $note->set('rcn_title', trim((string)($input['rcn_title'] ?? '')));
        $note->set('rcn_content', (string)($input['rcn_content'] ?? ''));

        // Tags posted as comma-separated string
        $tags_raw = trim((string)($input['rcn_tags_text'] ?? ''));
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
        'saved' => !empty($input['saved']),
    ]);
}

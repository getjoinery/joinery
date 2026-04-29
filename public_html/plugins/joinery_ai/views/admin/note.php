<?php
/**
 * Joinery AI - Note Edit
 * URL: /admin/joinery_ai/note?rcn_note_id=N
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/admin_note_logic.php'));

$page_vars = process_logic(admin_joinery_ai_note_logic($_GET, $_POST));
extract($page_vars);

$is_new = !$note->key;

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'joinery-ai-notes',
    'page_title' => $is_new ? 'New Note' : 'Edit Note',
    'readable_title' => $is_new ? 'New Note' : 'Edit Note',
    'breadcrumbs' => [
        'Joinery AI' => '/admin/joinery_ai',
        'Notes' => '/admin/joinery_ai/notes',
        ($is_new ? 'New' : ($note->get('rcn_title') ?: 'Untitled')) => '',
    ],
    'session' => $session,
]);

if (!empty($saved)) {
    echo '<div class="alert alert-success">Saved.</div>';
}

$page->begin_box(['title' => $is_new ? 'Create Note' : 'Edit Note']);

$formwriter = $page->getFormWriter('form1', [
    'model' => $note,
    'edit_primary_key_value' => $note->key,
]);

echo $formwriter->begin_form();

$formwriter->textinput('rcn_title', 'Title', ['required' => true, 'maxlength' => 255]);

$formwriter->textarea('rcn_content', 'Content (Markdown)', [
    'rows' => 16,
    'placeholder' => "Free-form notes. Recipes can read these via get_my_notes "
                  . "and write new ones via save_note.",
]);

$tags = $note->get('rcn_tags');
if (is_string($tags)) {
    $decoded = json_decode($tags, true);
    $tags = is_array($decoded) ? $decoded : [];
}
if (!is_array($tags)) $tags = [];
$tags_text = implode(', ', $tags);

$formwriter->textinput('rcn_tags_text', 'Tags (comma-separated)', [
    'value' => $tags_text,
    'placeholder' => 'e.g. stocks, watchlist',
]);

$formwriter->submitbutton('btn_submit', $is_new ? 'Create' : 'Save');

if (!$is_new) {
    echo '<button type="submit" name="btn_delete" value="1" class="btn btn-outline-danger ms-2" '
       . 'onclick="return confirm(\'Soft-delete this note?\');">Delete</button>';
}

echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();

<?php
/**
 * Joinery AI - Memory Edit
 * URL: /admin/joinery_ai/memory_edit?mem_memory_id=N   (no id = new shared memory)
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/admin_memory_edit_logic.php'));

$page_vars = process_logic(admin_joinery_ai_memory_edit_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$is_new = !$memory->key;
$is_shared = (string)$memory->get('mem_scope') === AiMemory::SCOPE_SHARED;

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'joinery-ai-memory',
    'page_title' => $is_new ? 'New Shared Memory' : 'Edit Memory',
    'readable_title' => $is_new ? 'New Shared Memory' : 'Edit Memory',
    'breadcrumbs' => [
        'Joinery AI' => '/admin/joinery_ai',
        'Memory' => '/admin/joinery_ai/memory',
        ($is_new ? 'New' : ($memory->get('mem_title') ?: 'Untitled')) => '',
    ],
    'session' => $session,
]);

if (!empty($saved)) {
    echo '<div class="alert alert-success">Saved.</div>';
}

$box_title = $is_new ? 'Create Shared Memory'
    : 'Edit ' . ($is_shared ? 'Shared' : 'Private') . ' Memory'
      . ' (saved by ' . strtoupper((string)$memory->get('mem_source')) . ')';
$page->begin_box(['title' => $box_title]);

$formwriter = $page->getFormWriter('form1', [
    'model' => $memory,
    'edit_primary_key_value' => $memory->key,
]);

echo $formwriter->begin_form();

$formwriter->textinput('mem_title', 'Title', [
    'maxlength' => 255,
    'helptext' => 'Shown in the AI\'s memory index — make it descriptive.',
]);

$formwriter->textarea('mem_content', 'Content', [
    'rows' => 10,
    'required' => true,
]);

$tags = $memory->get('mem_tags');
if (is_string($tags)) {
    $decoded = json_decode($tags, true);
    $tags = is_array($decoded) ? $decoded : [];
}
if (!is_array($tags)) $tags = [];
$tags_text = implode(', ', $tags);

$formwriter->textinput('mem_tags_text', 'Tags (comma-separated)', [
    'value' => $tags_text,
    'placeholder' => 'e.g. policy, style',
]);

$formwriter->submitbutton('btn_submit', $is_new ? 'Create' : 'Save');

if (!$is_new) {
    $formwriter->submitbutton('btn_delete', 'Delete', [
        'class'          => 'btn btn-outline-danger ms-2',
        'onclick'        => "return confirm('Delete this memory?');",
        'formnovalidate' => true,
    ]);
}

echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();

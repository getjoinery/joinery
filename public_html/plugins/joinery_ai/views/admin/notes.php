<?php
/**
 * Joinery AI - Notes List
 * URL: /admin/joinery_ai/notes
 *
 * The owner's notes — written by save_note from recipes, editable here so
 * the agent ↔ human feedback loop is operable. Subsequent runs read back
 * via get_my_notes.
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_notes_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$numperpage = 30;
$offset = (int)LibraryFunctions::fetch_variable_local($_GET, 'offset', 0);

$notes = new MultiRecipeNote(
    ['owner_user_id' => $session->get_user_id(), 'deleted' => false],
    ['rcn_update_time' => 'DESC'],
    $numperpage,
    $offset
);
$numrecords = $notes->count_all();
$notes->load();

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'joinery-ai-notes',
    'page_title' => 'Notes',
    'readable_title' => 'Notes',
    'breadcrumbs' => [
        'Joinery AI' => '/admin/joinery_ai',
        'Notes' => '',
    ],
    'session' => $session,
]);

$pager = new Pager(['numrecords' => $numrecords, 'numperpage' => $numperpage]);
$headers = ['Title', 'Updated', 'Tags', 'Actions'];
$page->tableheader($headers, [
    'title' => 'Notes (' . $numrecords . ')',
    'altlinks' => ['New Note' => '/admin/joinery_ai/note'],
], $pager);

foreach ($notes as $note) {
    $row = [];
    $row[] = '<a href="/admin/joinery_ai/note?rcn_note_id=' . (int)$note->key . '">'
           . htmlspecialchars($note->get('rcn_title')) . '</a>';

    $when = $note->get('rcn_update_time')
        ? LibraryFunctions::convert_time(
            $note->get('rcn_update_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A'
          )
        : 'never';
    $row[] = htmlspecialchars($when);

    $tags = $note->get('rcn_tags');
    if (is_string($tags)) {
        $decoded = json_decode($tags, true);
        $tags = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($tags)) $tags = [];
    $row[] = $tags ? htmlspecialchars(implode(', ', $tags)) : '<em class="text-muted">—</em>';

    $row[] = '<a class="btn btn-sm btn-outline-primary" href="/admin/joinery_ai/note?rcn_note_id='
           . (int)$note->key . '">Edit</a>';

    $page->disprow($row);
}

if (!count($notes)) {
    echo '<tr><td colspan="4" class="text-center text-muted py-4">'
       . 'No notes yet. Recipes can write here via the <code>save_note</code> tool, or '
       . '<a href="/admin/joinery_ai/note">create one manually</a>.</td></tr>';
}

$page->endtable($pager);
$page->admin_footer();

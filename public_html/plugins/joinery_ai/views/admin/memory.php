<?php
/**
 * Joinery AI - Memory List
 * URL: /admin/joinery_ai/memory
 *
 * The shared memory pool — org facts the AI recalls for EVERY user
 * (specs/joinery_ai_memory.md). Admin-curated only: the AI can never write a
 * shared memory. A view filter also lets an admin browse one user's private
 * memories for support (read-mostly; rows link to the same edit page).
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_memories_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$numperpage = 30;
$offset = (int)LibraryFunctions::fetch_variable_local($_GET, 'offset', 0);
$view = (string)LibraryFunctions::fetch_variable_local($_GET, 'view', 'shared');
$browse_user_id = (int)LibraryFunctions::fetch_variable_local($_GET, 'usr_user_id', 0);
$is_user_view = ($view === 'user' && $browse_user_id > 0);

$options = $is_user_view
    ? ['scope' => AiMemory::SCOPE_USER, 'owner_user_id' => $browse_user_id, 'deleted' => false]
    : ['scope' => AiMemory::SCOPE_SHARED, 'deleted' => false];

$memories = new MultiAiMemory($options, ['mem_update_time' => 'DESC', 'mem_create_time' => 'DESC'],
    $numperpage, $offset);
$numrecords = $memories->count_all();
$memories->load();

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'joinery-ai-memory',
    'page_title' => 'Memory',
    'readable_title' => 'Memory',
    'breadcrumbs' => [
        'Joinery AI' => '/admin/joinery_ai',
        'Memory' => '',
    ],
    'session' => $session,
]);

// View filter: the shared pool (default) or one user's private memories.
$filter = $page->getFormWriter('memory_filter', ['method' => 'get', 'action' => '/admin/joinery_ai/memory']);
echo $filter->begin_form();
$filter->dropinput('view', 'View', [
    'value' => $is_user_view ? 'user' : 'shared',
    'options' => ['shared' => 'Shared pool (all users)', 'user' => "One user's private memories"],
    'visibility_rules' => [
        'shared' => ['hide' => ['usr_user_id']],
        'user'   => ['show' => ['usr_user_id']],
    ],
]);
$filter->numberinput('usr_user_id', 'User ID', [
    'value' => $browse_user_id ?: '',
    'min' => 1,
    'helptext' => 'Find the id on the Users page.',
]);
$filter->submitbutton('btn_filter', 'View');
echo $filter->end_form();

$pager = new Pager(['numrecords' => $numrecords, 'numperpage' => $numperpage]);
$headers = ['Title', 'Content', 'Source', 'Updated', 'Tags', 'Actions'];
$title = $is_user_view
    ? 'Private memories of user ' . $browse_user_id . ' (' . $numrecords . ')'
    : 'Shared memories (' . $numrecords . ')';
$page->tableheader($headers, [
    'title' => $title,
    'altlinks' => ['New Shared Memory' => '/admin/joinery_ai/memory_edit'],
], $pager);

foreach ($memories as $memory) {
    $row = [];
    $mtitle = trim((string)$memory->get('mem_title'));
    $row[] = '<a href="/admin/joinery_ai/memory_edit?mem_memory_id=' . (int)$memory->key . '">'
           . ($mtitle !== '' ? htmlspecialchars($mtitle) : '<em>(untitled)</em>') . '</a>';

    $preview = trim((string)preg_replace('/\s+/', ' ', (string)$memory->get('mem_content')));
    if (mb_strlen($preview) > 90) $preview = mb_substr($preview, 0, 90) . '…';
    $row[] = htmlspecialchars($preview);

    $row[] = htmlspecialchars(strtoupper((string)$memory->get('mem_source')));

    $when = $memory->get('mem_update_time') ?: $memory->get('mem_create_time');
    $row[] = $when
        ? htmlspecialchars(LibraryFunctions::convert_time($when, 'UTC', $session->get_timezone(), 'M j, Y g:i A'))
        : '';

    $tags = $memory->get('mem_tags');
    if (is_string($tags)) {
        $decoded = json_decode($tags, true);
        $tags = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($tags)) $tags = [];
    $row[] = $tags ? htmlspecialchars(implode(', ', $tags)) : '<em class="text-muted">—</em>';

    $row[] = '<a class="btn btn-sm btn-outline-primary" href="/admin/joinery_ai/memory_edit?mem_memory_id='
           . (int)$memory->key . '">Edit</a>';

    $page->disprow($row);
}

if (!count($memories)) {
    echo '<tr><td colspan="6" class="text-center text-muted py-4">'
       . ($is_user_view
            ? 'This user has no private memories.'
            : 'No shared memories yet. <a href="/admin/joinery_ai/memory_edit">Add one</a> — '
              . 'the AI recalls shared memories for every user.')
       . '</td></tr>';
}

$page->endtable($pager);
$page->admin_footer();

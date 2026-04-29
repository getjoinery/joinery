<?php
/**
 * Joinery AI - Recipe List
 * URL: /admin/joinery_ai
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$numperpage = 30;
$offset = LibraryFunctions::fetch_variable_local($_GET, 'offset', 0);
$sort = LibraryFunctions::fetch_variable_local($_GET, 'sort', 'rcp_recipe_id');
$sdirection = LibraryFunctions::fetch_variable_local($_GET, 'sdirection', 'DESC');

$recipes = new MultiRecipe(
    ['deleted' => false],
    [$sort => $sdirection],
    $numperpage,
    $offset
);
$numrecords = $recipes->count_all();
$recipes->load();

// Latest run per recipe (one query rather than per-recipe lookups)
$latest_runs = [];
if (count($recipes)) {
    $db = DbConnector::get_instance()->get_db_link();
    $ids = [];
    foreach ($recipes as $r) {
        $ids[] = (int)$r->key;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT DISTINCT ON (rcr_rcp_recipe_id) rcr_rcp_recipe_id, rcr_status, rcr_started_time
            FROM rcr_recipe_runs
            WHERE rcr_rcp_recipe_id IN ($placeholders)
              AND rcr_delete_time IS NULL
            ORDER BY rcr_rcp_recipe_id, rcr_started_time DESC";
    $q = $db->prepare($sql);
    $q->execute($ids);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $latest_runs[$row['rcr_rcp_recipe_id']] = $row;
    }
}

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'joinery-ai-recipes',
    'page_title' => 'Joinery AI Recipes',
    'readable_title' => 'Recipes',
    'breadcrumbs' => [
        'Joinery AI' => '/admin/joinery_ai',
        'Recipes' => '',
    ],
    'session' => $session,
]);

$pager = new Pager(['numrecords' => $numrecords, 'numperpage' => $numperpage]);
$table_options = [
    'title' => 'Recipes',
    'altlinks' => ['New Recipe' => '/admin/joinery_ai/edit'],
];
$headers = ['Name', 'Schedule', 'Model', 'Last Run', 'Enabled', 'Actions'];
$page->tableheader($headers, $table_options, $pager);

foreach ($recipes as $recipe) {
    $row = [];

    $row[] = '<a href="/admin/joinery_ai/edit?rcp_recipe_id=' . (int)$recipe->key . '">'
           . htmlspecialchars($recipe->get('rcp_name')) . '</a>';

    $freq = $recipe->get('rcp_schedule_frequency');
    $sched_label = ucfirst($freq);
    if ($freq === 'weekly') {
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $dow = $recipe->get('rcp_schedule_day_of_week');
        if ($dow !== null && $dow !== '') {
            $sched_label .= ' ' . ($days[(int)$dow] ?? '?');
        }
    }
    if (in_array($freq, ['daily', 'weekly'])) {
        $time = $recipe->get('rcp_schedule_time');
        if (is_object($time) && method_exists($time, 'format')) {
            $time = $time->format('H:i:s');
        }
        if ($time) {
            // Stored as UTC; convert to admin's timezone for display, using
            // today's date as the DST-reference (matches save path).
            $today = gmdate('Y-m-d');
            $local = LibraryFunctions::convert_time(
                $today . ' ' . $time, 'UTC', $session->get_timezone(), 'H:i'
            );
            $sched_label .= ' ' . $local;
        }
    }
    $row[] = htmlspecialchars($sched_label);

    $row[] = htmlspecialchars($recipe->get('rcp_model'));

    $latest = $latest_runs[$recipe->key] ?? null;
    if ($latest) {
        $when = LibraryFunctions::convert_time(
            $latest['rcr_started_time'], 'UTC', $session->get_timezone(), 'M j g:i A'
        );
        $status = $latest['rcr_status'];
        $badge = 'secondary';
        if ($status === 'success')      $badge = 'success';
        elseif ($status === 'running')  $badge = 'info';
        elseif ($status === 'pending')  $badge = 'warning';
        elseif (in_array($status, ['failed', 'timeout'])) $badge = 'danger';
        $row[] = '<span class="badge bg-' . $badge . '">' . htmlspecialchars($status) . '</span> '
               . htmlspecialchars($when);
    } else {
        $row[] = '<em class="text-muted">never</em>';
    }

    $row[] = $recipe->get('rcp_enabled')
        ? '<span class="badge bg-success">Yes</span>'
        : '<span class="badge bg-secondary">No</span>';

    $actions = '<a class="btn btn-sm btn-outline-primary" href="/admin/joinery_ai/edit?rcp_recipe_id='
             . (int)$recipe->key . '">Edit</a> '
             . '<form method="post" action="/admin/joinery_ai/run_now" class="d-inline">'
             . '<input type="hidden" name="rcp_recipe_id" value="' . (int)$recipe->key . '">'
             . '<button type="submit" class="btn btn-sm btn-outline-success" '
             . 'onclick="this.disabled=true;this.innerHTML=\'Running…\';this.form.submit();">'
             . 'Run Now</button></form>';
    $row[] = $actions;

    $page->disprow($row);
}

if (!count($recipes)) {
    echo '<tr><td colspan="6" class="text-center text-muted py-4">'
       . 'No recipes yet. <a href="/admin/joinery_ai/edit">Create one</a>.</td></tr>';
}

$page->endtable($pager);
$page->admin_footer();

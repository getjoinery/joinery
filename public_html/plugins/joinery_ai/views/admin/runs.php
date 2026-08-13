<?php
/**
 * Joinery AI - Run History
 * URL: /admin/joinery_ai/runs[?rcp_recipe_id=N]
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
$offset = (int)LibraryFunctions::fetch_variable_local($_GET, 'offset', 0);
$filter_recipe_id = (int)LibraryFunctions::fetch_variable_local($_GET, 'filter', 0);

$search = ['deleted' => false];
if ($filter_recipe_id > 0) {
    $search['recipe_id'] = $filter_recipe_id;
}

$runs = new MultiRecipeRun(
    $search,
    ['rcr_started_time' => 'DESC'],
    $numperpage,
    $offset
);
$numrecords = $runs->count_all();
$runs->load();

// Recipe names for the filter dropdown and the rows in the table.
$all_recipes = new MultiRecipe(['deleted' => false], ['rcp_name' => 'ASC']);
$all_recipes->load();
$recipe_names = [];
foreach ($all_recipes as $r) {
    $recipe_names[(int)$r->key] = $r->get('rcp_name');
}

// Auto-refresh while any visible run is still in flight.
$any_in_flight = false;
foreach ($runs as $r) {
    if (in_array($r->get('rcr_status'), ['pending', 'running'], true)) {
        $any_in_flight = true;
        break;
    }
}
if ($any_in_flight) {
    header('Refresh: 5');
}

$page = new AdminPage();
$breadcrumbs = ['Joinery AI' => '/admin/joinery_ai'];
if ($filter_recipe_id && isset($recipe_names[$filter_recipe_id])) {
    $breadcrumbs[$recipe_names[$filter_recipe_id]] = '/admin/joinery_ai/edit?rcp_recipe_id=' . $filter_recipe_id;
}
$breadcrumbs['Run History'] = '';

$page->admin_header([
    'menu-id' => 'joinery-ai-runs',
    'page_title' => 'Run History',
    'readable_title' => 'Run History',
    'breadcrumbs' => $breadcrumbs,
    'session' => $session,
]);

$pager = new Pager(['numrecords' => $numrecords, 'numperpage' => $numperpage]);

$filter_options = ['All recipes' => 0];
foreach ($all_recipes as $r) {
    $filter_options[$r->get('rcp_name')] = (int)$r->key;
}

$headers = ['Run', 'Recipe', 'Status', 'Trigger', 'Started', 'Duration', 'Tokens', 'Cost'];
$page->tableheader($headers, [
    'title'         => 'Runs',
    'filteroptions' => $filter_options,
], $pager);

foreach ($runs as $run) {
    $row = [];
    $rid = (int)$run->key;
    $row[] = '<a href="/admin/joinery_ai/run?rcr_run_id=' . $rid . '">#' . $rid . '</a>';

    $rcp_id = (int)$run->get('rcr_rcp_recipe_id');
    $rname = $recipe_names[$rcp_id] ?? '(deleted)';
    $row[] = htmlspecialchars($rname);

    $status = $run->get('rcr_status');
    $badge = 'secondary';
    if ($status === 'success')      $badge = 'success';
    elseif ($status === 'running')  $badge = 'info';
    elseif ($status === 'pending')  $badge = 'warning';
    elseif (in_array($status, ['failed', 'timeout', 'skipped'])) $badge = 'danger';
    $row[] = '<span class="badge bg-' . $badge . '">' . htmlspecialchars($status) . '</span>';

    $row[] = htmlspecialchars($run->get('rcr_trigger') ?: '');

    $row[] = htmlspecialchars($run->get_local('rcr_started_time', 'M j g:i A'));

    $duration = '';
    if ($run->get('rcr_completed_time')) {
        $a = strtotime($run->get('rcr_started_time'));
        $b = strtotime($run->get('rcr_completed_time'));
        if ($a && $b && $b >= $a) $duration = ($b - $a) . 's';
    }
    $row[] = htmlspecialchars($duration);

    $row[] = (int)$run->get('rcr_input_tokens') . ' / ' . (int)$run->get('rcr_output_tokens');

    $row[] = '$' . number_format((float)$run->get('rcr_cost_estimate'), 4);

    $page->disprow($row);
}

if (!count($runs)) {
    echo '<tr><td colspan="8" class="text-center text-muted py-4">No runs yet.</td></tr>';
}

$page->endtable($pager);
$page->admin_footer();

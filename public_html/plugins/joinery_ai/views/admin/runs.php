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
$filter_recipe_id = (int)LibraryFunctions::fetch_variable_local($_GET, 'rcp_recipe_id', 0);

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

// Eager-load recipe names to avoid N+1.
$recipe_names = [];
if (count($runs)) {
    $ids = [];
    foreach ($runs as $r) $ids[(int)$r->get('rcr_rcp_recipe_id')] = true;
    if ($ids) {
        $db = DbConnector::get_instance()->get_db_link();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $q = $db->prepare("SELECT rcp_recipe_id, rcp_name FROM rcp_recipes WHERE rcp_recipe_id IN ($placeholders)");
        $q->execute(array_keys($ids));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recipe_names[(int)$row['rcp_recipe_id']] = $row['rcp_name'];
        }
    }
}

// Filter dropdown options.
$all_recipes = new MultiRecipe(['deleted' => false], ['rcp_name' => 'ASC']);
$all_recipes->load();

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

// Filter bar
?>
<div class="card mb-3"><div class="card-body">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="form-label">Recipe</label>
            <select name="rcp_recipe_id" class="form-select form-select-sm">
                <option value="">All recipes</option>
                <?php foreach ($all_recipes as $r): ?>
                    <option value="<?php echo (int)$r->key; ?>"<?php echo $filter_recipe_id == $r->key ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars($r->get('rcp_name')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <?php if ($filter_recipe_id): ?>
                <a href="/admin/joinery_ai/runs" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div></div>
<?php

$pager = new Pager(['numrecords' => $numrecords, 'numperpage' => $numperpage]);
$headers = ['Run', 'Recipe', 'Status', 'Trigger', 'Started', 'Duration', 'Tokens', 'Cost'];
$page->tableheader($headers, ['title' => 'Runs (' . $numrecords . ')'], $pager);

foreach ($runs as $run) {
    $row = [];
    $rid = (int)$run->key;
    $row[] = '<a href="/admin/joinery_ai/run?rcr_run_id=' . $rid . '">#' . $rid . '</a>';

    $rcp_id = (int)$run->get('rcr_rcp_recipe_id');
    $rname = $recipe_names[$rcp_id] ?? '(deleted)';
    $row[] = '<a href="/admin/joinery_ai/runs?rcp_recipe_id=' . $rcp_id . '">' . htmlspecialchars($rname) . '</a>';

    $status = $run->get('rcr_status');
    $badge = 'secondary';
    if ($status === 'success')      $badge = 'success';
    elseif ($status === 'running')  $badge = 'info';
    elseif ($status === 'pending')  $badge = 'warning';
    elseif (in_array($status, ['failed', 'timeout', 'skipped'])) $badge = 'danger';
    $row[] = '<span class="badge bg-' . $badge . '">' . htmlspecialchars($status) . '</span>';

    $row[] = htmlspecialchars($run->get('rcr_trigger') ?: '');

    $row[] = htmlspecialchars(LibraryFunctions::convert_time(
        $run->get('rcr_started_time'), 'UTC', $session->get_timezone(), 'M j g:i A'
    ));

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

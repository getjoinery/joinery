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

// Latest run per recipe (one query rather than per-recipe lookups). Also
// pull the run id so we can render a Stop button on in-flight rows.
$latest_runs = [];
$owner_status = []; // owner_user_id => 'active' | 'inactive' | 'missing'
$owner_names  = []; // owner_user_id => display label
if (count($recipes)) {
    $db = DbConnector::get_instance()->get_db_link();
    $ids = [];
    $owner_ids = [];
    foreach ($recipes as $r) {
        $ids[] = (int)$r->key;
        $oid = (int)$r->get('rcp_owner_user_id');
        if ($oid > 0) $owner_ids[$oid] = true;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT DISTINCT ON (rcr_rcp_recipe_id) rcr_rcp_recipe_id, rcr_run_id,
                   rcr_status, rcr_started_time
            FROM rcr_recipe_runs
            WHERE rcr_rcp_recipe_id IN ($placeholders)
              AND rcr_delete_time IS NULL
            ORDER BY rcr_rcp_recipe_id, rcr_started_time DESC";
    $q = $db->prepare($sql);
    $q->execute($ids);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $latest_runs[$row['rcr_rcp_recipe_id']] = $row;
    }

    // Owner lookup: name + active status. Same predicate the runner uses
    // (exists, not soft-deleted, permission >= 10) so the badge here matches
    // what the runner will accept.
    if (!empty($owner_ids)) {
        $oid_list = array_keys($owner_ids);
        $oph = implode(',', array_fill(0, count($oid_list), '?'));
        $oq = $db->prepare(
            "SELECT usr_user_id, usr_first_name, usr_last_name, usr_email,
                    usr_permission, usr_delete_time
             FROM usr_users WHERE usr_user_id IN ($oph)"
        );
        $oq->execute($oid_list);
        foreach ($oq->fetchAll(PDO::FETCH_ASSOC) as $orow) {
            $oid = (int)$orow['usr_user_id'];
            $name = trim(($orow['usr_first_name'] ?? '') . ' ' . ($orow['usr_last_name'] ?? ''));
            $owner_names[$oid] = $name !== '' ? $name : $orow['usr_email'];
            $active = !$orow['usr_delete_time'] && (int)$orow['usr_permission'] >= 10;
            $owner_status[$oid] = $active ? 'active' : 'inactive';
        }
        // Owners that exist in rcp_owner_user_id but not in usr_users (deleted
        // permanently) are missing.
        foreach ($oid_list as $oid) {
            if (!isset($owner_status[$oid])) {
                $owner_status[$oid] = 'missing';
                $owner_names[$oid] = '(user #' . $oid . ', deleted)';
            }
        }
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
$headers = ['Name', 'Owner', 'Schedule', 'Model', 'Last Run', 'Enabled', 'Actions'];
$page->tableheader($headers, $table_options, $pager);

foreach ($recipes as $recipe) {
    $row = [];

    $row[] = '<a href="/admin/joinery_ai/edit?rcp_recipe_id=' . (int)$recipe->key . '">'
           . htmlspecialchars($recipe->get('rcp_name')) . '</a>';

    // Owner column. Active = user exists, not deleted, permission >= 10.
    // Inactive (deleted/demoted/missing) is the visible signal that the
    // recipe is broken and needs ownership transferred via the edit page.
    $oid = (int)$recipe->get('rcp_owner_user_id');
    if ($oid > 0 && isset($owner_names[$oid])) {
        $stat = $owner_status[$oid];
        $badge = $stat === 'active' ? 'success' : 'danger';
        $row[] = htmlspecialchars($owner_names[$oid])
               . ' <span class="badge bg-' . $badge . '">' . $stat . '</span>';
    } else {
        $row[] = '<span class="badge bg-danger">no owner</span>';
    }

    $freq = $recipe->get('rcp_schedule_frequency');
    $sched_label = $freq === 'none' ? 'No Schedule' : ucfirst($freq);
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

    $model_label = (string)$recipe->get('rcp_model');
    $row[] = $model_label !== ''
        ? htmlspecialchars($model_label)
        : '<em class="text-muted">not set</em>';

    $latest = $latest_runs[$recipe->key] ?? null;
    if ($latest) {
        $when = LibraryFunctions::convert_time(
            $latest['rcr_started_time'], 'UTC', $session->get_timezone(), 'M j g:i A'
        );
        $status = $latest['rcr_status'];
        $badge = 'secondary';
        if ($status === 'success')                          $badge = 'success';
        elseif ($status === 'running')                      $badge = 'info';
        elseif ($status === 'pending')                      $badge = 'warning';
        elseif (in_array($status, ['failed', 'timeout']))   $badge = 'danger';
        elseif ($status === 'incomplete')                   $badge = 'warning';
        elseif ($status === 'cancelled')                    $badge = 'dark';
        $row[] = '<span class="badge bg-' . $badge . '">' . htmlspecialchars($status) . '</span> '
               . htmlspecialchars($when);
    } else {
        $row[] = '<em class="text-muted">never</em>';
    }

    // "Shipped with your install, not set up yet" and "a recipe someone turned
    // off" both render as disabled otherwise, and they mean opposite things.
    $awaiting_setup = (string)$recipe->get('rcp_declared_key') !== ''
        && !$recipe->get('rcp_enabled')
        && empty(Recipe::decodeSourceConfig($recipe));
    if ($recipe->get('rcp_enabled')) {
        $row[] = '<span class="badge bg-success">Yes</span>';
    } elseif ($awaiting_setup) {
        $row[] = '<span class="badge bg-info text-dark">Awaiting setup</span>';
    } else {
        $row[] = '<span class="badge bg-secondary">No</span>';
    }

    $actions = '<a class="btn btn-sm btn-outline-primary" href="/admin/joinery_ai/edit?rcp_recipe_id='
             . (int)$recipe->key . '">Edit</a> '
             . '<form method="post" action="/admin/joinery_ai/run_now" class="d-inline">'
             . '<input type="hidden" name="rcp_recipe_id" value="' . (int)$recipe->key . '">'
             . '<button type="submit" class="btn btn-sm btn-outline-success" '
             . 'onclick="this.disabled=true;this.innerHTML=\'Running…\';this.form.submit();">'
             . 'Run Now</button></form>';

    // Stop button — shown only when the latest run is in-flight. Sets
    // rcr_kill_requested so the runner exits at the next iteration boundary
    // (or, for pending rows, marks them cancelled directly).
    if ($latest && in_array($latest['rcr_status'], ['pending', 'running'], true)) {
        $actions .= ' <form method="post" action="/admin/joinery_ai/stop_run" class="d-inline">'
                  . '<input type="hidden" name="rcr_run_id" value="' . (int)$latest['rcr_run_id'] . '">'
                  . '<input type="hidden" name="rcp_recipe_id" value="' . (int)$recipe->key . '">'
                  . '<button type="submit" class="btn btn-sm btn-outline-danger" '
                  . 'onclick="return confirm(\'Stop this run?\');">Stop</button>'
                  . '</form>';
    }

    $row[] = $actions;

    $page->disprow($row);
}

if (!count($recipes)) {
    echo '<tr><td colspan="7" class="text-center text-muted py-4">'
       . 'No recipes yet. <a href="/admin/joinery_ai/edit">Create one</a>.</td></tr>';
}

$page->endtable($pager);
$page->admin_footer();

<?php
/**
 * Joinery AI - Run Detail
 * URL: /admin/joinery_ai/run?rcr_run_id=N
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$run_id = (int)LibraryFunctions::fetch_variable_local($_GET, 'rcr_run_id', 0);
if ($run_id <= 0) {
    header('Location: /admin/joinery_ai');
    exit;
}

$run = new RecipeRun($run_id, true);
if (!$run->key) {
    header('Location: /admin/joinery_ai');
    exit;
}

$recipe = new Recipe($run->get('rcr_rcp_recipe_id'), true);

// Auto-refresh while the run is still in flight, so manual triggers from
// the previous page transition smoothly to the final state.
$is_in_flight = in_array($run->get('rcr_status'), ['pending', 'running'], true);
if ($is_in_flight) {
    header('Refresh: 5');
}

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'joinery-ai-runs',
    'page_title' => 'Run #' . (int)$run->key,
    'readable_title' => 'Run #' . (int)$run->key,
    'breadcrumbs' => [
        'Joinery AI' => '/admin/joinery_ai',
        ($recipe->key ? $recipe->get('rcp_name') : 'Unknown') => '/admin/joinery_ai/edit?rcp_recipe_id=' . (int)$recipe->key,
        'Run #' . (int)$run->key => '',
    ],
    'session' => $session,
]);

if ($is_in_flight) {
    echo '<div class="alert alert-info">'
       . 'Run is in progress — this page will refresh every 5 seconds until it completes.'
       . '</div>';
}

$status = $run->get('rcr_status');
$badge = 'secondary';
if ($status === 'success')      $badge = 'success';
elseif ($status === 'running')  $badge = 'info';
elseif ($status === 'pending')  $badge = 'warning';
elseif (in_array($status, ['failed', 'timeout', 'skipped'])) $badge = 'danger';

$started = LibraryFunctions::convert_time(
    $run->get('rcr_started_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i:s A T'
);
$completed = $run->get('rcr_completed_time')
    ? LibraryFunctions::convert_time(
        $run->get('rcr_completed_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i:s A T'
      )
    : '—';

$duration = '';
if ($run->get('rcr_completed_time') && $run->get('rcr_started_time')) {
    $a = strtotime($run->get('rcr_started_time'));
    $b = strtotime($run->get('rcr_completed_time'));
    if ($a && $b && $b >= $a) {
        $duration = ($b - $a) . 's';
    }
}

$cost = (float)$run->get('rcr_cost_estimate');

$page->begin_box(['title' => 'Run summary']);
?>
<dl class="row mb-0">
    <dt class="col-sm-3">Recipe</dt>
    <dd class="col-sm-9">
        <?php if ($recipe->key): ?>
            <a href="/admin/joinery_ai/edit?rcp_recipe_id=<?php echo (int)$recipe->key; ?>">
                <?php echo htmlspecialchars($recipe->get('rcp_name')); ?>
            </a>
        <?php else: ?>
            <em>(deleted)</em>
        <?php endif; ?>
    </dd>

    <dt class="col-sm-3">Status</dt>
    <dd class="col-sm-9">
        <span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($status); ?></span>
        <?php if ($run->get('rcr_trigger') === 'manual'): ?>
            <span class="badge bg-light text-dark">manual</span>
        <?php endif; ?>
    </dd>

    <dt class="col-sm-3">Started</dt>
    <dd class="col-sm-9"><?php echo htmlspecialchars($started); ?></dd>

    <dt class="col-sm-3">Completed</dt>
    <dd class="col-sm-9"><?php echo htmlspecialchars($completed); ?></dd>

    <?php if ($duration): ?>
        <dt class="col-sm-3">Duration</dt>
        <dd class="col-sm-9"><?php echo htmlspecialchars($duration); ?></dd>
    <?php endif; ?>

    <dt class="col-sm-3">Tokens (in / out)</dt>
    <dd class="col-sm-9">
        <?php echo (int)$run->get('rcr_input_tokens'); ?> in /
        <?php echo (int)$run->get('rcr_output_tokens'); ?> out
    </dd>

    <dt class="col-sm-3">Estimated cost</dt>
    <dd class="col-sm-9">$<?php echo number_format($cost, 4); ?></dd>
</dl>
<?php
$page->end_box();

if ($run->get('rcr_error')) {
    $page->begin_box(['title' => 'Error']);
    echo '<pre class="mb-0" style="white-space: pre-wrap;">'
       . htmlspecialchars($run->get('rcr_error')) . '</pre>';
    $page->end_box();
}

if ($run->get('rcr_output')) {
    $page->begin_box(['title' => 'Output']);
    echo '<div style="white-space: pre-wrap;">'
       . htmlspecialchars($run->get('rcr_output')) . '</div>';
    $page->end_box();
}

$tool_calls = $run->get('rcr_tool_calls');
if (is_string($tool_calls)) {
    $decoded = json_decode($tool_calls, true);
    $tool_calls = is_array($decoded) ? $decoded : null;
}
if (is_array($tool_calls) && count($tool_calls)) {
    $page->begin_box(['title' => 'Tool call trace (' . count($tool_calls) . ')']);
    foreach ($tool_calls as $i => $call) {
        $err = !empty($call['is_error']);
        echo '<div class="border-start ps-3 mb-3" style="border-color: '
           . ($err ? '#dc3545' : '#0d6efd') . ' !important;">';
        echo '<strong>' . ($i + 1) . '. ' . htmlspecialchars($call['name'] ?? '(unknown)') . '</strong>';
        if ($err) echo ' <span class="badge bg-danger">error</span>';
        if (isset($call['duration_ms'])) {
            echo ' <span class="text-muted">' . (int)$call['duration_ms'] . 'ms</span>';
        }
        if (isset($call['input'])) {
            echo '<pre class="mb-1 mt-1" style="font-size:0.85em;">'
               . htmlspecialchars(json_encode($call['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
               . '</pre>';
        }
        if (isset($call['output'])) {
            $out = (string)$call['output'];
            if (mb_strlen($out) > 2000) $out = mb_substr($out, 0, 2000) . "\n…(truncated)";
            echo '<pre class="mb-0" style="font-size:0.85em; white-space: pre-wrap;">'
               . htmlspecialchars($out) . '</pre>';
        }
        echo '</div>';
    }
    $page->end_box();
}

$page->admin_footer();

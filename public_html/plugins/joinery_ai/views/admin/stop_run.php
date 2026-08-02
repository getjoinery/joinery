<?php
/**
 * Joinery AI - Stop Run
 * URL: /admin/joinery_ai/stop_run
 *
 * Sets rcr_kill_requested = TRUE on a pending or running run row. The
 * runner picks up the flag at the next iteration boundary and exits
 * cleanly, marking the run cancelled. Pending rows that haven't been
 * picked up yet are also cancelled directly so the dispatcher doesn't
 * spawn a worker for them.
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);

$run_id = (int)LibraryFunctions::fetch_variable_local($_REQUEST, 'rcr_run_id', 0);
$recipe_id = (int)LibraryFunctions::fetch_variable_local($_REQUEST, 'rcp_recipe_id', 0);

if ($run_id <= 0) {
    header('Location: /admin/joinery_ai');
    exit;
}

$db = DbConnector::get_instance()->get_db_link();

// Pending rows haven't been picked up; mark them cancelled directly so the
// dispatcher won't dispatch them. Running rows get the kill flag and the
// runner exits at the next iteration.
$q = $db->prepare(
    "UPDATE rcr_recipe_runs
     SET rcr_kill_requested = TRUE,
         rcr_status = CASE
             WHEN rcr_status = ? THEN ?
             ELSE rcr_status
         END,
         rcr_completed_time = CASE
             WHEN rcr_status = ? THEN NOW() AT TIME ZONE 'UTC'
             ELSE rcr_completed_time
         END,
         rcr_status_note = CASE
             WHEN rcr_status = ? THEN 'cancelled by admin (before dispatch)'
             ELSE rcr_status_note
         END
     WHERE rcr_run_id = ?
       AND rcr_status IN (?, ?)
       AND rcr_delete_time IS NULL"
);
$q->execute([
    RecipeRun::STATUS_PENDING, RecipeRun::STATUS_CANCELLED,
    RecipeRun::STATUS_PENDING,
    RecipeRun::STATUS_PENDING,
    $run_id,
    RecipeRun::STATUS_PENDING, RecipeRun::STATUS_RUNNING,
]);

if ($recipe_id > 0) {
    header('Location: /admin/joinery_ai');
} else {
    header('Location: /admin/joinery_ai/run?rcr_run_id=' . $run_id);
}
exit;

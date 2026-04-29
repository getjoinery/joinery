<?php
/**
 * Joinery AI - Run Recipe Now (async)
 * URL: /admin/joinery_ai/run_now
 *
 * Inserts a pending RecipeRun row, kicks off a background worker if there's
 * concurrency slack, then redirects immediately. The run page auto-refreshes
 * until the row hits a terminal status.
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeWorkerSpawner.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);

$recipe_id = (int)LibraryFunctions::fetch_variable_local($_REQUEST, 'rcp_recipe_id', 0);
if ($recipe_id <= 0) {
    header('Location: /admin/joinery_ai');
    exit;
}

$recipe = new Recipe($recipe_id, true);
if (!$recipe->key) {
    header('Location: /admin/joinery_ai');
    exit;
}

$run = new RecipeRun(NULL);
$run->set('rcr_rcp_recipe_id', $recipe_id);
$run->set('rcr_status', RecipeRun::STATUS_PENDING);
$run->set('rcr_trigger', RecipeRun::TRIGGER_MANUAL);
$run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
$run->prepare();
$run->save();
$run->load();

// Try to spawn immediately. If we're at concurrency cap, the row stays
// pending until the dispatcher tick or another worker's self-chain picks
// it up — in that case the run page will say "Queued, N runs ahead."
RecipeWorkerSpawner::spawnIfUnderCap($run);

header('Location: /admin/joinery_ai/run?rcr_run_id=' . (int)$run->key);
exit;

<?php
/**
 * CLI worker for Joinery AI recipe runs.
 *
 * Spawned via `php run_recipe.php <run_id>` by:
 *   - run_now admin handler (manual trigger)
 *   - RecipeDispatcher scheduled task (scheduled trigger)
 *   - this script itself on exit (worker self-chain)
 *
 * Loads the run row, calls RecipeRunner::run() (which manages its own
 * status transitions), then attempts to spawn the next pending row before
 * exiting. CLI-only — bails immediately if invoked over HTTP.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php run_recipe.php <run_id>\n");
    exit(2);
}

$run_id = (int)$argv[1];
if ($run_id <= 0) {
    fwrite(STDERR, "Invalid run_id: '$argv[1]'\n");
    exit(2);
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeWorkerSpawner.php'));

$run = new RecipeRun($run_id, true);
if (!$run->key) {
    fwrite(STDERR, "RecipeRun #$run_id not found.\n");
    exit(3);
}

// Treat this as a long-running task; the runner has its own internal timeout.
set_time_limit(0);
ignore_user_abort(true);

try {
    RecipeRunner::run($run);
} catch (Throwable $e) {
    error_log('[joinery_ai cli] Uncaught while running run #' . $run_id . ': ' . $e->getMessage());
}

// Worker self-chain: pull the next pending row off the queue if we have slack.
try {
    RecipeWorkerSpawner::spawnNextPending();
} catch (Throwable $e) {
    error_log('[joinery_ai cli] Self-chain spawn failed after run #' . $run_id . ': ' . $e->getMessage());
}

exit(0);

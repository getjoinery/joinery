<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));

/**
 * Spawns CLI workers for queued RecipeRun rows, subject to a concurrency cap.
 *
 * Used by three callers:
 *   - manual trigger (admin run_now): inserts a pending row, then calls
 *     spawnIfUnderCap() to kick off immediately when there's slack.
 *   - RecipeDispatcher (cron): same pattern for due-by-schedule recipes,
 *     plus drainPendingQueue() to pick up anything left waiting.
 *   - CLI worker on exit (self-chain): spawnNextPending() to keep the
 *     queue moving without waiting for the next cron tick.
 *
 * The race between count_running() and the spawned worker actually starting
 * (and flipping its row to running) is benign at the v1 cap of 3 — at-cap-
 * plus-one is fine. If precision becomes important, wrap the check + spawn
 * in pg_advisory_lock.
 */
class RecipeWorkerSpawner {

    const DEFAULT_CONCURRENCY = 3;

    /**
     * Spawn a worker for $run if we're under the concurrency cap and the run
     * is in a spawnable state. Returns true if a worker was kicked off.
     */
    public static function spawnIfUnderCap(RecipeRun $run): bool {
        if (!self::isSpawnable($run)) return false;
        if (self::countActive() >= self::cap()) return false;
        return self::spawn((int)$run->key);
    }

    /**
     * Find the oldest pending row and spawn a worker for it (if under cap).
     * Returns true if a worker was kicked off, false if there was nothing to
     * do or no slack.
     */
    public static function spawnNextPending(): bool {
        if (self::countActive() >= self::cap()) return false;

        $db = DbConnector::get_instance()->get_db_link();
        $sql = "SELECT rcr_run_id FROM rcr_recipe_runs
                WHERE rcr_status = ? AND rcr_delete_time IS NULL
                ORDER BY rcr_started_time ASC
                LIMIT 1";
        $q = $db->prepare($sql);
        $q->execute([RecipeRun::STATUS_PENDING]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        return self::spawn((int)$row['rcr_run_id']);
    }

    /**
     * Drain the pending queue oldest-first up to the concurrency cap. Used by
     * the dispatcher tick. Returns the number of workers spawned.
     */
    public static function drainPendingQueue(): int {
        $spawned = 0;
        $cap = self::cap();
        for ($i = 0; $i < $cap; $i++) {
            if (self::countActive() >= $cap) break;
            if (!self::spawnNextPending()) break;
            $spawned++;
        }
        return $spawned;
    }

    /** Number of currently in-flight runs (pending or running). */
    public static function countActive(): int {
        $db = DbConnector::get_instance()->get_db_link();
        $sql = "SELECT count(*) FROM rcr_recipe_runs
                WHERE rcr_status IN (?, ?) AND rcr_delete_time IS NULL";
        $q = $db->prepare($sql);
        $q->execute([RecipeRun::STATUS_PENDING, RecipeRun::STATUS_RUNNING]);
        return (int)$q->fetchColumn();
    }

    public static function cap(): int {
        $settings = Globalvars::get_instance();
        $cap = (int)$settings->get_setting('joinery_ai_max_concurrent_workers');
        return $cap > 0 ? $cap : self::DEFAULT_CONCURRENCY;
    }

    private static function isSpawnable(RecipeRun $run): bool {
        $status = $run->get('rcr_status');
        return in_array($status, [RecipeRun::STATUS_PENDING, ''], true);
    }

    /**
     * Detached background spawn. Output is redirected so the spawning request
     * doesn't wait on the worker's stdio.
     */
    private static function spawn(int $run_id): bool {
        $script = PathHelper::getIncludePath('plugins/joinery_ai/cli/run_recipe.php');
        // Site logs live at site-root/logs/, not under public_html.
        $log = PathHelper::getSiteRoot() . '/logs/joinery_ai_worker.log';
        // The trailing & detaches; redirecting stdout/stderr to the log keeps
        // an audit trail without blocking. Errors inside the worker still
        // surface via rcr_error on the run row.
        $cmd = 'php ' . escapeshellarg($script) . ' ' . $run_id
             . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
        // No exit code check — exec returns immediately due to & detach.
        exec($cmd);
        return true;
    }

}

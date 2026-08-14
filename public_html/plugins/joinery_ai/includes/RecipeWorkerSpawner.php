<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));

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
 * The concurrency cap gates on the number of workers actually running, not on
 * the number of rows queued: a pending row occupies no process, and counting
 * it would let a backlog of undispatched rows starve the drain (the queue can
 * never shrink if being queued is itself what blocks dispatch). A row is
 * claimed for a worker by flipping it pending -> running in a single
 * conditional UPDATE, so two concurrent drainers can never launch two workers
 * for the same row; the loser's UPDATE simply matches nothing.
 */
class RecipeWorkerSpawner {

    const DEFAULT_CONCURRENCY = 3;

    /**
     * Spawn a worker for $run if we're under the concurrency cap and the run
     * is in a spawnable state. Returns true if a worker was kicked off.
     */
    public static function spawnIfUnderCap(RecipeRun $run): bool {
        if (!self::isSpawnable($run)) return false;
        if (self::countRunning() >= self::cap()) return false;
        // Claim before spawning so a concurrent drainer can't also take it.
        if (!self::claim((int)$run->key)) return false;
        return self::spawn((int)$run->key);
    }

    /**
     * Claim the oldest runnable pending row and spawn a worker for it (if under
     * cap). Returns true if a worker was kicked off, false if there was nothing
     * to do or no slack.
     */
    public static function spawnNextPending(): bool {
        if (self::countRunning() >= self::cap()) return false;
        $run_id = self::claimNextRunnablePending();
        if ($run_id === null) return false;
        return self::spawn($run_id);
    }

    /**
     * Drain the pending queue oldest-first up to the concurrency cap. Used by
     * the dispatcher tick. Returns the number of workers spawned. Each claimed
     * row flips to running, so countRunning() rises as we go and the loop stops
     * exactly at the cap.
     */
    public static function drainPendingQueue(): int {
        $spawned = 0;
        $cap = self::cap();
        while (self::countRunning() < $cap) {
            $run_id = self::claimNextRunnablePending();
            if ($run_id === null) break;
            self::spawn($run_id);
            $spawned++;
        }
        return $spawned;
    }

    /** Number of workers currently occupying a slot (rows in 'running'). */
    public static function countRunning(): int {
        $db = DbConnector::get_instance()->get_db_link();
        $sql = "SELECT count(*) FROM rcr_recipe_runs
                WHERE rcr_status = ? AND rcr_delete_time IS NULL";
        $q = $db->prepare($sql);
        $q->execute([RecipeRun::STATUS_RUNNING]);
        return (int)$q->fetchColumn();
    }

    /**
     * Atomically claim the oldest pending row that a worker can actually make
     * progress on, flipping it pending -> running. Returns the claimed run id,
     * or null if nothing is claimable.
     *
     * In-window (fully-sealed) recipes are skipped: their pending rows wait for
     * the owner's browser session, never a worker (specs/in_window_deferred_work.md).
     * A row whose recipe is gone is left for the reaper, but still claimable so
     * a stray never wedges the scan.
     */
    private static function claimNextRunnablePending(): ?int {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
        $db = DbConnector::get_instance()->get_db_link();
        $sql = "SELECT rcr_run_id, rcr_rcp_recipe_id FROM rcr_recipe_runs
                WHERE rcr_status = ? AND rcr_delete_time IS NULL
                ORDER BY rcr_started_time ASC";
        $q = $db->prepare($sql);
        $q->execute([RecipeRun::STATUS_PENDING]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $recipe = new Recipe((int)$row['rcr_rcp_recipe_id'], true);
            if ($recipe->key && !RecipeVaultScope::cronRunnable($recipe)) {
                continue;   // in-window: not a worker's to run
            }
            if (self::claim((int)$row['rcr_run_id'])) {
                return (int)$row['rcr_run_id'];
            }
            // Lost the claim race to another drainer; try the next candidate.
        }
        return null;
    }

    /**
     * Flip a specific row pending -> running. Returns true only for the caller
     * that actually claimed it (the row was still pending); a concurrent claim
     * of the same row matches nothing and returns false.
     */
    private static function claim(int $run_id): bool {
        $db = DbConnector::get_instance()->get_db_link();
        $sql = "UPDATE rcr_recipe_runs
                SET rcr_status = ?, rcr_started_time = (NOW() AT TIME ZONE 'UTC')
                WHERE rcr_run_id = ? AND rcr_status = ? AND rcr_delete_time IS NULL";
        $q = $db->prepare($sql);
        $q->execute([RecipeRun::STATUS_RUNNING, $run_id, RecipeRun::STATUS_PENDING]);
        return $q->rowCount() === 1;
    }

    public static function cap(): int {
        $settings = Globalvars::get_instance();
        $cap = (int)$settings->get_setting('joinery_ai_max_concurrent_workers');
        return $cap > 0 ? $cap : self::DEFAULT_CONCURRENCY;
    }

    private static function isSpawnable(RecipeRun $run): bool {
        $status = $run->get('rcr_status');
        if (!in_array($status, [RecipeRun::STATUS_PENDING, ''], true)) {
            return false;
        }
        // A worker is a command-line process, and a command-line process can
        // never hold a vault unlock window — the secret lives in APCu keyed to
        // the browser session. A recipe whose WHOLE binding is sealed is
        // therefore unspawnable by construction; it runs in slices inside its
        // owner's own request instead (specs/in_window_deferred_work.md). A
        // mixed binding spawns fine: the worker drains its standard mailboxes
        // and the sealed remainder waits for the window. Refusing here as well
        // as in the dispatcher covers Run Now and the worker self-chain, not
        // just the scheduled path.
        return self::cronRunnable($run);
    }

    /** Can a CLI worker make progress on this run's recipe at all? */
    private static function cronRunnable(RecipeRun $run): bool {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
        $recipe = new Recipe((int)$run->get('rcr_rcp_recipe_id'), true);
        if (!$recipe->key) {
            return true;   // a dangling run fails its own way in the runner
        }
        return RecipeVaultScope::cronRunnable($recipe);
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

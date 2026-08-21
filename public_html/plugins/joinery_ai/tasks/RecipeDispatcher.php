<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeWorkerSpawner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeSchedule.php'));

/**
 * Recipe dispatcher — runs every cron tick.
 *
 * Per tick, in order:
 *   1. Reap running — sweep stuck rcr_status='running' rows whose started_time
 *      is older than the runner's wall-clock budget. Mark them 'timeout'.
 *      Without this, a crashed worker would leave its row pinned forever.
 *   2. Reap pending — cancel pending rows no worker will ever pick up: the
 *      recipe was deleted or disabled after queueing, or the row has sat
 *      pending far past any healthy dispatch. Without this a jammed queue
 *      never clears, and (because a pending row is a queued run) a recurring
 *      recipe can't be re-scheduled while a dead one lingers. In-window
 *      (fully-sealed) rows are left alone — they wait for the owner's browser
 *      session, not a worker.
 *   3. Schedule — for each enabled recipe, ask RecipeSchedule whether it is
 *      due from the STANDARD posture (this process holds no vault window, so
 *      the sealed subset of a binding is never cron's to answer for). If due
 *      and the recipe doesn't already have an active run, insert a pending row.
 *   4. Drain — spawn workers for pending rows up to the concurrency cap.
 *
 * Dueness lives in RecipeSchedule and not here, because the in-window drain
 * has to give the same answer for the sealed half of the same recipe
 * (specs/recipe_run_scheduling.md).
 *
 * @version 1.1.0
 */
class RecipeDispatcher implements ScheduledTaskInterface {

    /** Reap rows older than this many seconds in 'running'. Should be larger
     *  than RecipeRunner::WALL_CLOCK_SECONDS (90s) plus a safety margin. */
    const STUCK_RUN_SECONDS = 600;

    /** Cancel a cron-runnable row still pending after this many seconds. A
     *  healthy run is claimed within a tick or two; an hour means it was never
     *  going to be dispatched. Well clear of any legitimate queue wait. */
    const STUCK_PENDING_SECONDS = 3600;

    public function run(array $config) {
        $reaped_running = $this->reapStuckRuns();
        $reaped_pending = $this->reapStuckPending();
        $scheduled      = $this->scheduleDueRecipes();
        $drained        = RecipeWorkerSpawner::drainPendingQueue();

        $msg = "Reaped: {$reaped_running} running / {$reaped_pending} pending, "
             . "scheduled: {$scheduled}, drained: {$drained}.";
        return ['status' => 'success', 'message' => $msg];
    }

    /**
     * Mark stuck running rows as timed out. A row is "stuck" if its started
     * time is older than STUCK_RUN_SECONDS — anything past that is almost
     * certainly a crashed worker.
     */
    private function reapStuckRuns(): int {
        $db = DbConnector::get_instance()->get_db_link();
        // The verdict goes in rcr_status_note, not rcr_error: the reaper runs
        // from cron, holds nobody's vault key, and rcr_error is a sealed column
        // on a protected run. This is the platform's own message anyway, not
        // anything the run read.
        $sql = "UPDATE rcr_recipe_runs
                SET rcr_status = ?,
                    rcr_status_note = COALESCE(NULLIF(rcr_status_note,''), 'reaper: worker process did not complete'),
                    rcr_completed_time = NOW() AT TIME ZONE 'UTC'
                WHERE rcr_status = ?
                  AND rcr_started_time < (NOW() AT TIME ZONE 'UTC' - INTERVAL '" . self::STUCK_RUN_SECONDS . " seconds')
                  AND rcr_delete_time IS NULL";
        $q = $db->prepare($sql);
        $q->execute([RecipeRun::STATUS_TIMEOUT, RecipeRun::STATUS_RUNNING]);
        return $q->rowCount();
    }

    /**
     * Cancel pending rows that no worker will ever run, so they stop counting
     * against the queue and a recurring recipe can be re-scheduled. Three ways
     * a pending row goes dead:
     *   - its recipe was deleted after the row was queued;
     *   - its recipe has no automatic trigger any more (set to Manually only,
     *     i.e. rcp_enabled false) AND the row was queued by the schedule or a
     *     window rather than by a person. A MANUAL row on such a recipe is not
     *     dead at all — Run Now is precisely what "manually only" leaves, and
     *     the spawner will still give it a worker;
     *   - it is cron-runnable but has sat pending past STUCK_PENDING_SECONDS —
     *     a worker was never going to start.
     * A cron-runnable row still inside the window is left to drain normally.
     * An in-window (fully-sealed) recipe's row is left untouched at any age: it
     * runs in slices inside the owner's browser session, not from a worker
     * (specs/in_window_deferred_work.md), so pending is its normal resting state.
     *
     * The verdict goes in rcr_status_note, never rcr_error: like the running
     * reaper this runs from cron holding nobody's vault key, and it never quotes
     * what the run read.
     */
    private function reapStuckPending(): int {
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
        $db = DbConnector::get_instance()->get_db_link();
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::STUCK_PENDING_SECONDS);

        $q = $db->prepare("SELECT rcr_run_id, rcr_rcp_recipe_id, rcr_started_time, rcr_trigger
                           FROM rcr_recipe_runs
                           WHERE rcr_status = ? AND rcr_delete_time IS NULL");
        $q->execute([RecipeRun::STATUS_PENDING]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return 0;

        // Prefetch live recipe state so a deleted recipe is recognised without
        // constructing a model for a missing row (which would emit a "does not
        // exist" notice per orphan — noisy on a task that runs every tick).
        $enabled_by_id = [];
        $er = $db->query("SELECT rcp_recipe_id, rcp_enabled FROM rcp_recipes
                          WHERE rcp_delete_time IS NULL");
        foreach ($er->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $enabled_by_id[(int)$e['rcp_recipe_id']] =
                !($e['rcp_enabled'] === false || $e['rcp_enabled'] === 'f' || $e['rcp_enabled'] === '0');
        }

        $upd = $db->prepare("UPDATE rcr_recipe_runs
            SET rcr_status = ?,
                rcr_status_note = COALESCE(NULLIF(rcr_status_note, ''), ?),
                rcr_completed_time = NOW() AT TIME ZONE 'UTC'
            WHERE rcr_run_id = ? AND rcr_status = ? AND rcr_delete_time IS NULL");

        $cancelled = 0;
        foreach ($rows as $row) {
            $rid = (int)$row['rcr_rcp_recipe_id'];
            if (!array_key_exists($rid, $enabled_by_id)) {
                $reason = 'reaper: recipe no longer exists';
            } elseif (!$enabled_by_id[$rid]
                    && (string)$row['rcr_trigger'] !== RecipeRun::TRIGGER_MANUAL) {
                $reason = 'reaper: recipe set to manual only while run was queued';
            } elseif (!RecipeVaultScope::cronRunnable(new Recipe($rid, true))) {
                continue;   // in-window: waits for the owner's session, not stuck
            } elseif ((string)$row['rcr_started_time'] < $cutoff) {
                $reason = 'reaper: queued too long without dispatch';
            } else {
                continue;   // healthy, still waiting its turn under the cap
            }
            $upd->execute([RecipeRun::STATUS_CANCELLED, $reason,
                (int)$row['rcr_run_id'], RecipeRun::STATUS_PENDING]);
            $cancelled += $upd->rowCount();
        }
        return $cancelled;
    }

    /**
     * For each enabled recipe, insert a pending RecipeRun if it's due now
     * and doesn't already have an active (pending/running) run.
     * Returns the number of pending rows inserted.
     */
    private function scheduleDueRecipes(): int {
        $recipes = new MultiRecipe(['enabled' => true, 'deleted' => false]);
        $recipes->load();

        $now_utc = gmdate('Y-m-d H:i:s');
        $inserted = 0;

        foreach ($recipes as $recipe) {
            // A recipe whose WHOLE binding is sealed cannot run from cron at
            // all — this process holds no unlock window and never will
            // (specs/in_window_deferred_work.md). It runs in slices inside its
            // owner's browser session instead, so queueing it here would only
            // create rows no worker could ever complete. A mixed binding IS
            // scheduled: on the worker its sealed mailboxes fail closed out of
            // the candidate set and the standard remainder drains.
            if (!RecipeVaultScope::cronRunnable($recipe)) continue;
            // The active-run check comes first because it is the cheaper of the
            // two: dueness on an arrival recipe asks its job whether anything
            // is unhandled, and there is no point asking about a recipe that is
            // already queued or running.
            if ($this->hasActiveRun((int)$recipe->key)) continue;
            // POSTURE_STANDARD: an arrival-scheduled recipe is due when the
            // part of its binding a worker can actually read has something
            // unhandled. Answering for the sealed subset here would queue runs
            // whose only possible outcome is "caught up".
            if (!RecipeSchedule::isDue($recipe, PipelineJobInterface::POSTURE_STANDARD, $now_utc)) continue;

            $run = new RecipeRun(NULL);
            $run->set('rcr_rcp_recipe_id', (int)$recipe->key);
            $run->set('rcr_status', RecipeRun::STATUS_PENDING);
            $run->set('rcr_trigger', RecipeRun::TRIGGER_SCHEDULE);
            $run->set('rcr_started_time', $now_utc);
            $run->prepare();
            $run->save();
            $inserted++;
        }

        return $inserted;
    }

    private function hasActiveRun(int $recipe_id): bool {
        $db = DbConnector::get_instance()->get_db_link();
        $sql = "SELECT 1 FROM rcr_recipe_runs
                WHERE rcr_rcp_recipe_id = ?
                  AND rcr_status IN (?, ?)
                  AND rcr_delete_time IS NULL
                LIMIT 1";
        $q = $db->prepare($sql);
        $q->execute([$recipe_id, RecipeRun::STATUS_PENDING, RecipeRun::STATUS_RUNNING]);
        return (bool)$q->fetchColumn();
    }

}

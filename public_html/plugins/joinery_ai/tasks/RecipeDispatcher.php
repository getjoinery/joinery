<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeWorkerSpawner.php'));

/**
 * Recipe dispatcher — runs every cron tick.
 *
 * Per tick, in order:
 *   1. Reaper — sweep stuck rcr_status='running' rows whose started_time is
 *      older than the runner's wall-clock budget. Mark them 'timeout'.
 *      Without this, a crashed worker would leave its row pinned forever.
 *   2. Schedule — for each enabled recipe, decide whether it's due based
 *      on rcp_schedule_frequency / day_of_week / time vs current UTC. If
 *      due and the recipe doesn't already have an active run, insert a
 *      pending row.
 *   3. Drain — spawn workers for pending rows up to the concurrency cap.
 *
 * Schedule comparison is done in UTC — rcp_schedule_time is stored as the
 * UTC equivalent of the user's chosen local time. DST flips will shift the
 * actual local fire-time by ±1 hour twice a year; this is a known v1 trade-
 * off documented in the spec.
 */
class RecipeDispatcher implements ScheduledTaskInterface {

    /** Reap rows older than this many seconds in 'running'. Should be larger
     *  than RecipeRunner::WALL_CLOCK_SECONDS (90s) plus a safety margin. */
    const STUCK_RUN_SECONDS = 600;

    public function run(array $config) {
        $reaped     = $this->reapStuckRuns();
        $scheduled  = $this->scheduleDueRecipes();
        $drained    = RecipeWorkerSpawner::drainPendingQueue();

        $msg = "Reaped: $reaped, scheduled: $scheduled, drained: $drained.";
        return ['status' => 'success', 'message' => $msg];
    }

    /**
     * Mark stuck running rows as timed out. A row is "stuck" if its started
     * time is older than STUCK_RUN_SECONDS — anything past that is almost
     * certainly a crashed worker.
     */
    private function reapStuckRuns(): int {
        $db = DbConnector::get_instance()->get_db_link();
        $sql = "UPDATE rcr_recipe_runs
                SET rcr_status = ?,
                    rcr_error = COALESCE(NULLIF(rcr_error,''), 'reaper: worker process did not complete'),
                    rcr_completed_time = NOW() AT TIME ZONE 'UTC'
                WHERE rcr_status = ?
                  AND rcr_started_time < (NOW() AT TIME ZONE 'UTC' - INTERVAL '" . self::STUCK_RUN_SECONDS . " seconds')
                  AND rcr_delete_time IS NULL";
        $q = $db->prepare($sql);
        $q->execute([RecipeRun::STATUS_TIMEOUT, RecipeRun::STATUS_RUNNING]);
        return $q->rowCount();
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
            if (!$this->isDue($recipe, $now_utc)) continue;
            if ($this->hasActiveRun((int)$recipe->key)) continue;

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

    /**
     * Has this recipe been started since $cutoff_utc?
     */
    private function lastStartedAfter(int $recipe_id, string $cutoff_utc): bool {
        $db = DbConnector::get_instance()->get_db_link();
        $sql = "SELECT 1 FROM rcr_recipe_runs
                WHERE rcr_rcp_recipe_id = ?
                  AND rcr_started_time >= ?
                  AND rcr_delete_time IS NULL
                LIMIT 1";
        $q = $db->prepare($sql);
        $q->execute([$recipe_id, $cutoff_utc]);
        return (bool)$q->fetchColumn();
    }

    /**
     * Is this recipe due to fire now?
     *
     * Times are compared in UTC. Edge cases:
     *   - hourly: due if it hasn't run in the current UTC clock hour
     *   - daily:  due if past schedule_time today (UTC) and no run today
     *   - weekly: due if today is the correct day_of_week (UTC), past
     *             schedule_time, and no run today
     *   - schedule_time NULL: treat as 00:00 (midnight UTC)
     */
    private function isDue(Recipe $recipe, string $now_utc): bool {
        $freq = (string)$recipe->get('rcp_schedule_frequency');
        $rid = (int)$recipe->key;

        if ($freq === 'hourly') {
            $hour_start = gmdate('Y-m-d H:00:00', strtotime($now_utc));
            return !$this->lastStartedAfter($rid, $hour_start);
        }

        $sched_time = (string)$recipe->get('rcp_schedule_time');
        if ($sched_time === '' || $sched_time === null) $sched_time = '00:00:00';

        $today_at_sched = gmdate('Y-m-d', strtotime($now_utc)) . ' ' . $sched_time;

        if ($freq === 'daily') {
            if ($now_utc < $today_at_sched) return false;
            $today_start = gmdate('Y-m-d 00:00:00', strtotime($now_utc));
            return !$this->lastStartedAfter($rid, $today_start);
        }

        if ($freq === 'weekly') {
            $dow_target = (int)$recipe->get('rcp_schedule_day_of_week');
            $dow_today = (int)gmdate('w', strtotime($now_utc)); // 0 = Sunday
            if ($dow_target !== $dow_today) return false;
            if ($now_utc < $today_at_sched) return false;
            $today_start = gmdate('Y-m-d 00:00:00', strtotime($now_utc));
            return !$this->lastStartedAfter($rid, $today_start);
        }

        return false;
    }

}

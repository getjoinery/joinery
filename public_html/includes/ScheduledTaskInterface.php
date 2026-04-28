<?php
/**
 * ScheduledTaskInterface
 *
 * All scheduled tasks must implement this interface.
 * Task classes are paired with a JSON config file that declares
 * metadata and configurable parameters.
 *
 * @version 1.2
 */
interface ScheduledTaskInterface {
    /**
     * Execute the scheduled task.
     *
     * May return a simple status string (backward-compatible) or an
     * array with 'status' and 'message' keys for richer reporting.
     *
     * Status meanings:
     * - 'success'  — Task ran and completed (with or without work to do)
     * - 'skipped'  — Task could not run (misconfigured, missing prerequisite)
     * - 'error'    — Task attempted to run but failed
     *
     * Optional 'deactivate' => true in the result array tells the runner
     * to flip sct_is_active to false after this run. Use this for
     * one-shot drain tasks that should stop scheduling themselves once
     * their queue is empty. (Setting sct_is_active from inside the task
     * via the model does NOT work — the runner's post-run save would
     * overwrite it; the flag is the supported mechanism.)
     *
     * @param array $config  Task-specific configuration from sct_task_config
     * @return string|array  Status string, or array('status'=>'...', 'message'=>'...', 'deactivate'=>bool)
     */
    public function run(array $config);
}

/**
 * ScheduledTaskDryRunnable
 *
 * Optional interface for tasks that support dry run / preview mode.
 * Tasks implementing this will get a "Dry Run" button in the admin UI.
 *
 * The dryRun() method should perform all read/computation logic but
 * skip any side effects (sending emails, deleting records, calling APIs).
 *
 * Return array keys:
 * - 'status'  (string)  Required. Same as run(): 'success', 'skipped', 'error'.
 * - 'message' (string)  Required. Human-readable summary (e.g., "Would send 5 events to 42 recipients").
 * - 'html'    (string)  Optional. HTML preview to display in the admin UI (e.g., email body).
 */
interface ScheduledTaskDryRunnable {
    /**
     * @param array $config  Task-specific configuration from sct_task_config
     * @return array  Array with 'status', 'message', and optionally 'html'
     */
    public function dryRun(array $config);
}

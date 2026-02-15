<?php
/**
 * ScheduledTaskInterface
 *
 * All scheduled tasks must implement this interface.
 * Task classes are paired with a JSON config file that declares
 * metadata and configurable parameters.
 *
 * @version 1.1
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
     * @param array $config  Task-specific configuration from sct_task_config
     * @return string|array  Status string, or array('status'=>'...', 'message'=>'...')
     */
    public function run(array $config);
}

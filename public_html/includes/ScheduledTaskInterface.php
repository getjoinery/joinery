<?php
/**
 * ScheduledTaskInterface
 *
 * All scheduled tasks must implement this interface.
 * Task classes are paired with a JSON config file that declares
 * metadata and configurable parameters.
 *
 * @version 1.0
 */
interface ScheduledTaskInterface {
    /**
     * Execute the scheduled task.
     *
     * @param array $config  Task-specific configuration from sct_task_config
     * @return string  'success', 'error', or 'skipped'
     */
    public function run(array $config): string;
}

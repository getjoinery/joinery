<?php
/**
 * CloudOffloadRun — the one offload task for the whole platform (shim).
 *
 * Replaces the per-store forward/reverse task pairs. A single every_run task
 * walks every declared StorageProfile and drives each store by its mode
 * (offload → push local→cloud, drain → pull cloud→local, idle → skip), so a new
 * offload consumer adds a StorageProfile and zero tasks. The orchestration lives
 * in CloudStorageLifecycle::runOffloadTick(); this is the scheduler entry point.
 * Self-deactivates (via the 'deactivate' result key) when no store is offloading
 * or draining and no offloaded file remains: while any does, the tick keeps
 * running so the daily file-store check can see it.
 *
 * @version 1.1 - stays active while any offloaded file exists (the daily file-store check)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageLifecycle.php'));

class CloudOffloadRun implements ScheduledTaskInterface {
	public function run(array $config) {
		return CloudStorageLifecycle::runOffloadTick();
	}
}

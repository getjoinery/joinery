<?php
/**
 * CloudStorageSync — forward sync task (shim)
 *
 * The public-files offload. Its orchestration now lives in the shared,
 * table-agnostic CloudOffloadEngine, driven by FileStorageProfile
 * (visibility = public). This class keeps its name, JSON, and scheduler
 * registration; the per-run work is one engine call.
 *
 * @version 2.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/FileStorageProfile.php'));

class CloudStorageSync implements ScheduledTaskInterface {
	public function run(array $config) {
		return CloudOffloadEngine::syncBatch(new FileStorageProfile());
	}
}

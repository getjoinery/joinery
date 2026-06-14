<?php
/**
 * CloudStorageReverseSync — pull-back task (shim)
 *
 * Activated only when an admin clicks "Disable and Pull Files Back to Local".
 * Its orchestration now lives in the shared CloudOffloadEngine, driven by
 * FileStorageProfile (visibility = public). The engine self-deactivates this
 * task (via the 'deactivate' result key) once no cloud rows remain.
 *
 * @version 2.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/FileStorageProfile.php'));

class CloudStorageReverseSync implements ScheduledTaskInterface {
	public function run(array $config) {
		return CloudOffloadEngine::reverseBatch(new FileStorageProfile());
	}
}

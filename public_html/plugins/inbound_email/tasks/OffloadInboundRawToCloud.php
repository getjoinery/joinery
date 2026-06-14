<?php
/**
 * OffloadInboundRawToCloud — forward offload task (shim).
 *
 * Pushes 'local' inbound-mail raw .eml files to the verified-private store. All
 * orchestration — the PUT→reload→flip→delete ordering, the failure cap, batching,
 * the per-row lock — is the shared CloudOffloadEngine's, driven by RawMessageStore
 * (visibility = private). This shim exists only so the scheduler tracks mail
 * offload status distinctly from the public-files task.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/RawMessageStore.php'));

class OffloadInboundRawToCloud implements ScheduledTaskInterface {
	public function run(array $config) {
		return CloudOffloadEngine::syncBatch(new RawMessageStore());
	}
}

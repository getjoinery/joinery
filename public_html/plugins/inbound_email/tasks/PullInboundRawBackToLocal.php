<?php
/**
 * PullInboundRawBackToLocal — reverse offload task (shim).
 *
 * Pulls cloud-resident inbound-mail raw .eml objects back to local disk and clears
 * the bucket objects. All orchestration is the shared CloudOffloadEngine's, driven
 * by RawMessageStore (visibility = private). Activated only when an admin disables
 * the private store and pulls objects back; self-deactivates when no cloud rows
 * remain.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/RawMessageStore.php'));

class PullInboundRawBackToLocal implements ScheduledTaskInterface {
	public function run(array $config) {
		return CloudOffloadEngine::reverseBatch(new RawMessageStore());
	}
}

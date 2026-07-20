<?php
/**
 * AdvanceRelayCloudProvisions - works the relay cloud-provision state machine
 * each cron tick (specs/mailbox_relay_cloud_provisioning.md).
 *
 * ready -> create instance -> booting -> (running + IP) -> provisioning
 * (the SSH relay build, run synchronously here — it is the long step) ->
 * done | failed. Destroy-kind runs delete their target instance. Credentials
 * are erased at every terminal state by the run model.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class AdvanceRelayCloudProvisions implements ScheduledTaskInterface {

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayCloudProvisioner.php'));

		$actionable = new MultiRelayCloudProvision(array('live' => true, 'deleted' => false));
		$actionable->load();

		$work = array();
		foreach ($actionable as $run) {
			if ((string)$run->get('rcp_status') !== 'awaiting_grant') {
				$work[] = $run;
			}
		}
		if (count($work) === 0) {
			return ['status' => 'success', 'message' => 'No relay cloud provisions to advance.'];
		}

		// The SSH build step can take many minutes; this tick is allowed to.
		set_time_limit(2400);

		$provisioner = new RelayCloudProvisioner();
		$lines = array();
		$errors = 0;
		foreach ($work as $run) {
			try {
				$lines[] = 'run #' . intval($run->key) . ': ' . $provisioner->advance($run);
			} catch (\Throwable $e) {
				$errors++;
				$lines[] = 'run #' . intval($run->key) . ': ERROR ' . $e->getMessage();
				error_log('AdvanceRelayCloudProvisions run #' . intval($run->key) . ': ' . $e->getMessage());
			}
		}

		return [
			'status'  => $errors ? 'error' : 'success',
			'message' => implode('; ', $lines),
		];
	}
}
?>

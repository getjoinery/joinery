<?php
/**
 * ServerManagerAdvanceProvisioning - Scheduled Task
 *
 * The customer provisioning pipeline, start to finish, in one pass.
 *
 * The three phases are stages of one journey and have a real order:
 *
 *  1. Poll orders   ask getjoinery for newly paid hosting orders and start an
 *                   install job (or a customer-cloud provision row) for each.
 *  2. Customer cloud  work the customer-cloud provision state machine.
 *  3. SSL           provision certificates for hosts that are ready for them.
 *
 * Running them together means one place to look when a customer's site is
 * stuck, instead of three tasks where a stalled stage is invisible from the
 * others. A phase that throws is caught and recorded; the later phases still
 * run, so a getjoinery API outage cannot stop SSL from being issued for a site
 * provisioned an hour ago.
 *
 * RunNodeUptimeChecks stays a SEPARATE task deliberately: it is monitoring, not
 * provisioning, and its up/down alerting must not sit behind a provisioning
 * call that hangs.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class ServerManagerAdvanceProvisioning implements ScheduledTaskInterface {

	public function run(array $config) {
		$base = 'plugins/server_manager/includes/provisioning/';
		$phases = array(
			'Orders'         => array($base . 'PollHostingOrders.php', 'PollHostingOrders'),
			'Customer cloud' => array($base . 'ProvisionCustomerCloud.php', 'ProvisionCustomerCloud'),
			'SSL'            => array($base . 'ProvisionPendingSsl.php', 'ProvisionPendingSsl'),
		);

		$parts = array();
		$failed = 0;
		$active = 0;

		foreach ($phases as $label => $phase) {
			list($file, $class) = $phase;
			try {
				require_once(PathHelper::getIncludePath($file));
				$runner = new $class();
				$result = $runner->run($config);

				$status = $result['status'] ?? 'error';
				if ($status === 'skipped') {
					continue; // not configured, or nothing to do
				}
				$active++;
				if ($status === 'error') {
					$failed++;
				}
				$parts[] = $label . ': ' . ($result['message'] ?? $status);
			} catch (Throwable $e) {
				// Isolated deliberately — one stalled stage must not strand the
				// customers already waiting at a later one.
				$failed++;
				$active++;
				$parts[] = $label . ': FAILED — ' . $e->getMessage();
				error_log('ServerManagerAdvanceProvisioning: ' . $label . ' failed: ' . $e->getMessage());
			}
		}

		if ($active === 0) {
			return array('status' => 'skipped', 'message' => 'Nothing to provision');
		}

		return array(
			'status' => $failed ? 'error' : 'success',
			'message' => implode('; ', $parts),
		);
	}
}

<?php
/**
 * ServerManagerAdvanceProvisioning - Scheduled Task
 *
 * The customer provisioning pipeline, start to finish, in one pass.
 *
 * The phases are stages of one journey and have a real order:
 *
 *  1. Poll orders   ask getjoinery for newly paid hosting orders and start an
 *                   install job (or a customer-cloud provision row) for each.
 *  2. Customer cloud  work the customer-cloud provision state machine.
 *  3. SSL           provision certificates for hosts that are ready for them.
 *  4. Domains       register managed domains, wire their DNS to the box, set PTR.
 *  5. Domain watch  keep expiry current and move custody toward the buyer.
 *  6. Hosted mail   build outbound mail for a site this operator hosts: the
 *                   customer's own subaccount, its sending domain and records,
 *                   the one SMTP credential that reaches their box.
 *  7. Hosted watch  the commercial half of a hosted site: the trial clock, the
 *                   allowance banners, and what falls due when a payment fails.
 *
 * The hosted phases come last on purpose. Both act on a site that is already
 * up, so a slow provider or an unconfigured account there must not sit in front
 * of a customer waiting for their machine to be created.
 *
 * Running them together means one place to look when a customer's site is
 * stuck, instead of separate tasks where a stalled stage is invisible from the
 * others. A phase that throws is caught and recorded; the later phases still
 * run, so a getjoinery API outage cannot stop SSL from being issued for a site
 * provisioned an hour ago.
 *
 * RunNodeUptimeChecks stays a SEPARATE task deliberately: it is monitoring, not
 * provisioning, and its up/down alerting must not sit behind a provisioning
 * call that hangs.
 *
 * @version 1.2 - the hosted tier runs as two more phases, last: mail for a site this operator hosts,
 *                then the trial clock and allowance banners (specs/hosted_trial_provisioning.md)
 * @version 1.1 - the managed-domain leg runs as two more phases
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class ServerManagerAdvanceProvisioning implements ScheduledTaskInterface {

	public function run(array $config) {
		$base = 'plugins/server_manager/includes/provisioning/';
		$phases = array(
			'Orders'         => array($base . 'PollHostingOrders.php', 'PollHostingOrders'),
			'Customer cloud' => array($base . 'ProvisionCustomerCloud.php', 'ProvisionCustomerCloud'),
			'SSL'            => array($base . 'ProvisionPendingSsl.php', 'ProvisionPendingSsl'),
			'Domains'        => array($base . 'ProvisionManagedDomains.php', 'ProvisionManagedDomains'),
			'Domain watch'   => array($base . 'ManagedDomainWatch.php', 'ManagedDomainWatch'),
			'Hosted mail'    => array($base . 'ProvisionHostedMail.php', 'ProvisionHostedMail'),
			'Hosted watch'   => array($base . 'HostedTrialWatch.php', 'HostedTrialWatch'),
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

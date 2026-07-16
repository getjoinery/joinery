<?php
/**
 * FleetReconcile - Scheduled Task (shared relay fleet, operator side).
 *
 * The dispatch-and-reconcile half of the fleet service
 * (specs/mailbox_relay_shared_fleet.md). The fleet_* API actions run as the
 * enrolled customer and only flag work on their own slot rows; this task runs
 * in the operator's cron context and:
 *
 *   1. Reconciles finished lifecycle jobs into slot statuses.
 *   2. Dispatches the flagged work as server_manager jobs against shard nodes:
 *      provisioning slots with no job yet → relay_add_tenant; slots flagged
 *      needs_domain_sync → relay_set_domains; released slots → relay_remove_tenant
 *      (which the shard refuses while the tenant's spool is undrained, so a
 *      releasing tenant drains cleanly).
 *   3. Re-checks entitlement for active slots: a lapse starts the grace window
 *      (mailbox_fleet_grace_days); past it the slot is SUSPENDED — its shard
 *      allowlist is emptied, so the merge drops its domains and the shard
 *      stops accepting its mail (senders queue/bounce per their MTAs; the
 *      tenant falls back to colocated MX or its own relay — nothing stored is
 *      lost, and the spool stays pullable).
 *
 * No-op unless this deployment runs the fleet service
 * (mailbox_fleet_service_enabled + mailbox_fleet_mx_zone).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));

class FleetReconcile implements ScheduledTaskInterface {

	public function run(array $config) {
		if (!FleetService::enabled()) {
			return array('status' => 'skipped', 'message' => 'Fleet service is not enabled on this deployment.');
		}

		$dispatched = 0; $reconciled = 0; $suspended = 0; $notes = array();

		$slots = new MultiMailboxFleetSlot(array('deleted' => false));
		$slots->load();

		foreach ($slots as $slot) {
			$status = (string)$slot->get('mft_status');
			if ($status === MailboxFleetSlot::STATUS_EVICTED) {
				continue;
			}

			// 1. Fold finished jobs into slot status.
			FleetService::reconcile($slot);
			$status = (string)$slot->get('mft_status');
			$reconciled++;

			$job = FleetService::lastJobState($slot);
			$job_running = $job !== null
				&& !in_array((string)$job['mjb_status'], array('completed', 'failed'), true);
			if ($job_running) {
				continue; // one lifecycle job at a time per slot
			}

			// 2. Dispatch pending work.
			if ($status === MailboxFleetSlot::STATUS_PROVISIONING) {
				// No job yet, or the last add-tenant failed — (re)dispatch.
				if (FleetService::dispatchJob($slot, 'add_tenant') !== null) {
					$dispatched++;
				}
				continue;
			}
			if ($status === MailboxFleetSlot::STATUS_RELEASED) {
				if (FleetService::dispatchJob($slot, 'remove_tenant') !== null) {
					$dispatched++;
				}
				continue;
			}
			if ((bool)$slot->get('mft_needs_domain_sync')) {
				if (FleetService::dispatchJob($slot, 'set_domains') !== null) {
					$dispatched++;
				}
				continue;
			}

			// 3. Entitlement re-check (active slots only).
			if ($status === MailboxFleetSlot::STATUS_ACTIVE) {
				$user_id = intval($slot->get('mft_usr_user_id'));
				$now = gmdate('Y-m-d H:i:s');
				if ($user_id > 0 && FleetService::entitled($user_id)) {
					$slot->set('mft_entitlement_check_time', $now);
					if ($slot->get('mft_entitlement_lapse_time') !== null) {
						$slot->set('mft_entitlement_lapse_time', null); // re-subscribed inside the window
					}
					$slot->save();
				} else {
					if ($slot->get('mft_entitlement_lapse_time') === null) {
						$slot->set('mft_entitlement_lapse_time', $now);
						$slot->save();
						$notes[] = 'slot ' . $slot->key . ' entitlement lapsed — grace window started';
					} else {
						$grace_days = max(1, intval(Globalvars::get_instance()->get_setting('mailbox_fleet_grace_days')) ?: 14);
						$deadline = LibraryFunctions::time_shift(
							(string)$slot->get('mft_entitlement_lapse_time'), $grace_days . ' days', 'Y-m-d H:i:s');
						if ($now > $deadline) {
							$slot->set('mft_status', MailboxFleetSlot::STATUS_SUSPENDED);
							$slot->set('mft_needs_domain_sync', true); // empties the shard allowlist
							$slot->save();
							FleetService::dispatchJob($slot, 'set_domains');
							$dispatched++;
							$suspended++;
						}
					}
				}
			}

			// A suspended slot whose owner re-subscribes reactivates on the next
			// pass: restore the allowlist and the tenant is back.
			if ($status === MailboxFleetSlot::STATUS_SUSPENDED) {
				$user_id = intval($slot->get('mft_usr_user_id'));
				if ($user_id > 0 && FleetService::entitled($user_id)) {
					$slot->set('mft_status', MailboxFleetSlot::STATUS_ACTIVE);
					$slot->set('mft_entitlement_lapse_time', null);
					$slot->set('mft_needs_domain_sync', true);
					$slot->save();
					FleetService::dispatchJob($slot, 'set_domains');
					$dispatched++;
					$notes[] = 'slot ' . $slot->key . ' reactivated';
				}
			}
		}

		$message = sprintf('Fleet: %d slot(s) reconciled, %d job(s) dispatched, %d suspended.',
			$reconciled, $dispatched, $suspended);
		if ($notes) {
			$message .= ' ' . implode('; ', $notes) . '.';
		}
		return array('status' => 'success', 'message' => $message);
	}
}

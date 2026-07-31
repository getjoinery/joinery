<?php
/**
 * MailboxRelayReconcile - Scheduled Task
 *
 * Everything that keeps a hardened ingest relay in step with this deployment,
 * in one pass with one place to look when mail stops arriving.
 *
 * The four phases have a real order, which is why they belong together:
 *
 *  1. Alias map      push the routing map BEFORE pulling spool, so a newly
 *                    created alias is valid on the relay and reject_unmatched
 *                    cannot bounce mail addressed to it. Routing edits push
 *                    immediately (RelayMapSync::onChange); this is the
 *                    reconcile floor behind that, and an unchanged map costs
 *                    one DB read and no SSH.
 *  2. Spool          rsync sealed blobs off the relay spool (copy-only), store
 *                    each durably, then delete what was stored — the
 *                    delete-after-store is the ack.
 *  3. Cloud provision  advance the relay cloud-provision state machine.
 *  4. Fleet          reconcile the hosted relay fleet (operator side only).
 *
 * Phases 1 and 2 no-op on a colocated deployment, phase 3 when nothing is
 * provisioning, phase 4 unless this deployment runs the fleet service — so the
 * pass is cheap on the deployments that use none of it.
 *
 * A phase that throws is caught and recorded, and the later phases still run.
 * Phase 1 failing must not strand mail already sitting on the relay's spool.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));

class MailboxRelayReconcile implements ScheduledTaskInterface {

	public function run(array $config) {
		$phases = array(
			'Relay map'   => function () use ($config) { return $this->syncAliasMap($config); },
			'Relay spool' => function () use ($config) { return $this->pullSpool($config); },
			'Cloud'       => function () { return $this->advanceCloudProvisions(); },
			'Fleet'       => function () { return $this->reconcileFleet(); },
		);

		$parts = array();
		$failed = 0;
		$active = 0;

		foreach ($phases as $label => $phase) {
			try {
				$result = $phase();
				$status = $result['status'] ?? 'error';
				if ($status === 'skipped') {
					continue; // does not apply to this deployment
				}
				$active++;
				if ($status === 'error') {
					$failed++;
				}
				$parts[] = $label . ': ' . ($result['message'] ?? $status);
			} catch (Throwable $e) {
				// Isolated deliberately — a failing phase must not cost the
				// later phases their run.
				$failed++;
				$active++;
				$parts[] = $label . ': FAILED — ' . $e->getMessage();
				error_log('MailboxRelayReconcile: ' . $label . ' failed: ' . $e->getMessage());
			}
		}

		if ($active === 0) {
			return array('status' => 'skipped', 'message' => 'Nothing to reconcile (colocated deployment)');
		}

		return array(
			'status' => $failed ? 'error' : 'success',
			'message' => implode('; ', $parts),
		);
	}

	/** Phase 1 — rebuild the relay's DB-free routing map and push it if changed. */
	private function syncAliasMap(array $config) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapSync.php'));

		$relay = MailboxRelay::active();
		if ($relay === null) {
			return array('status' => 'skipped', 'message' => '');
		}

		$force = array_key_exists('force_map_push', $config) && $this->truthy($config['force_map_push']);
		$result = RelayMapSync::push($relay, $force);

		return array(
			'status' => ($result['status'] === 'error') ? 'error' : 'success',
			'message' => $result['message'],
		);
	}

	/** Phase 2 — pull sealed blobs off the relay spool and store them durably. */
	private function pullSpool(array $config) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySpoolConsumer.php'));

		$relay = MailboxRelay::active();
		if ($relay === null) {
			return array('status' => 'skipped', 'message' => '');
		}

		$max = isset($config['spool_max_per_run']) ? (int)$config['spool_max_per_run'] : 0;
		if ($max <= 0) {
			$max = RelaySpoolConsumer::DEFAULT_MAX;
		}

		$consumer = new RelaySpoolConsumer($relay);
		$result = $consumer->pull($max);

		return array(
			'status' => ($result['status'] === 'error') ? 'error' : 'success',
			'message' => $result['message'],
		);
	}

	/**
	 * Phase 3 — advance the relay cloud-provision state machine.
	 *
	 * ready -> create instance -> booting -> (running + IP) -> provisioning
	 * (the SSH relay build, run synchronously here — it is the long step) ->
	 * done | failed. Destroy-kind runs delete their target instance.
	 * Credentials are erased at every terminal state by the run model.
	 */
	private function advanceCloudProvisions() {
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
			return array('status' => 'skipped', 'message' => '');
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
				error_log('MailboxRelayReconcile cloud run #' . intval($run->key) . ': ' . $e->getMessage());
			}
		}

		return array(
			'status' => $errors ? 'error' : 'success',
			'message' => implode('; ', $lines),
		);
	}

	/**
	 * Phase 4 — operator-side brain of the shared relay fleet.
	 *
	 * Reconciles finished shard lifecycle jobs into slot statuses, dispatches
	 * flagged work as server_manager jobs, and re-checks each active slot's
	 * entitlement with the grace-window suspension.
	 */
	private function reconcileFleet() {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_slot_class.php'));

		if (!FleetService::enabled()) {
			return array('status' => 'skipped', 'message' => '');
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

		$message = sprintf('%d slot(s) reconciled, %d job(s) dispatched, %d suspended',
			$reconciled, $dispatched, $suspended);
		if ($notes) {
			$message .= '. ' . implode('; ', $notes);
		}
		return array('status' => 'success', 'message' => $message);
	}

	private function truthy($value): bool {
		if (is_bool($value)) { return $value; }
		$v = strtolower(trim((string)$value));
		return in_array($v, array('1', 'true', 'yes', 'on'), true);
	}
}

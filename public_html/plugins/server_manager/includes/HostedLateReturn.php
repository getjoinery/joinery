<?php
/**
 * HostedLateReturn - bring a held Starter site back after a payment that came
 * late (specs/hosting_paid_launch.md O3).
 *
 * A Starter site whose grace ran out is held stopped on its host and its
 * hosting row is shut down. A payment that arrives after that only writes a
 * note on the row (HostedTrialSignals::clear_grace): bringing a site back is
 * a person's act, because the site may since have been removed, its buyer
 * may have bought again instead, or the payment may be on another
 * subscription. This is that act, from the node page, at level 10:
 *
 *   - the subscription that pays for it is found: its own line when that is
 *     active again, else the buyer's one active, uncancelled subscription to
 *     the same product that no other live site is on, which the site is then
 *     moved to (its old line was cancelled when the processor gave up);
 *   - the host is asked to start the container (hold_container start, the
 *     job marked late_return);
 *   - once the host answers that the container runs and is no longer held
 *     (settle(), from the hosting watch), the hosting row goes back to
 *     subscribed, every date and the ready-to-remove mark cleared, and the
 *     node's backups go back to the fleet's defaults and its uptime checks
 *     on. A start that fails changes nothing: the site stays shut down, its
 *     page says the start did not take, and Bring back is offered again.
 *
 * The relay slot needs nothing: its entitlement reads the hosting row, so the
 * relay reconcile's ladder reactivates it in place, and the banner is pushed
 * on the next watch. A site still suspended is refused: Unsuspend first; so
 * is one whose removal is under way on its host, and one already starting.
 *
 * @version 1.1 - reads the paying line through CustomerCloudProvision::local_order_item(), never another store's id (hosting_after_purchase B11)
 * @version 1.0
 */

class HostedLateReturn {

	/** Why it cannot be brought back now, or '' when it can; with the line that pays for it. */
	public static function refusal($node, ?OrderItem &$paying = null): string {
		$paying = null;
		$provision = $node && $node->key ? CustomerCloudProvision::latest_for_node($node->key) : null;
		if ($provision === null || !$provision->is_container() || !$provision->is_sold()) {
			return 'Only a sold Starter site is brought back this way.';
		}
		$trial = HostedTrial::for_provision($provision->key);
		if ($trial === null || (string)$trial->get('htr_state') !== HostedTrial::STATE_SHUTDOWN) {
			return 'Its hosting is not shut down, so there is nothing to bring back.';
		}
		if (HostedSuspension::open_for($provision->key) !== null) {
			return 'It is suspended. Unsuspend it first.';
		}
		if (self::open_start_job($provision) !== null) {
			return 'Its host is starting it already.';
		}
		$host_node = null;
		try {
			$host_node = JobCommandBuilder::decommission_host_node_for($node);
		} catch (Throwable $e) {
			return 'Its host cannot be reached from here: ' . $e->getMessage();
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT mjb_management_job_id FROM mjb_management_jobs
			WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'decommission_node' AND mjb_delete_time IS NULL
			  AND mjb_parameters->>'victim_node_id' = ? AND mjb_status IN ('pending', 'queued', 'running') LIMIT 1");
		$q->execute(array((int)$host_node->key, (string)(int)$node->key));
		if ($q->fetchColumn()) {
			return 'Its removal is under way on its host.';
		}
		$paying = self::paying_line($provision);
		if ($paying === null) {
			return 'No active subscription pays for it: its own is not active again, and the buyer has no other active '
				. 'subscription to the same product free to take it. Once the buyer pays, this can be done.';
		}
		return '';
	}

	/** The line that pays for the site now, or null. */
	public static function paying_line(CustomerCloudProvision $provision): ?OrderItem {
		$own = $provision->local_order_item();
		$own_id = $own !== null ? (int)$own->key : 0;
		if ($own && $own->key && self::pays($own)) {
			return $own;
		}
		if (!$own || !$own->key) {
			return null;
		}
		$found = array();
		foreach (new MultiOrderItem(array('user_id' => (int)$own->get('odi_usr_user_id'), 'is_active_subscription' => true,
				'odi_pro_product_id' => (int)$own->get('odi_pro_product_id'))) as $line) {
			if ((int)$line->key === $own_id || !self::pays($line)) {
				continue;
			}
			$taken = new MultiCustomerCloudProvision(array('cvp_external_order_item_id' => (int)$line->key, 'deleted' => false));
			if (count($taken) === 0) {
				$found[] = $line;
			}
		}
		return count($found) === 1 ? $found[0] : null;
	}

	/** The newest Bring back start job for the site, with its status; null when none. */
	public static function last_start_job(CustomerCloudProvision $provision): ?array {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT mjb_management_job_id, mjb_status, mjb_create_time FROM mjb_management_jobs
			WHERE mjb_job_type = 'hold_container' AND mjb_delete_time IS NULL
			  AND mjb_parameters->>'provision_id' = ? AND mjb_parameters->>'late_return' = '1'
			ORDER BY mjb_management_job_id DESC LIMIT 1");
		$q->execute(array((string)(int)$provision->key));
		$row = $q->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	/** The Bring back start job still waiting on its host, or null. */
	public static function open_start_job(CustomerCloudProvision $provision): ?array {
		$last = self::last_start_job($provision);
		return ($last && in_array((string)$last['mjb_status'], array('pending', 'queued', 'running'), true)) ? $last : null;
	}

	/**
	 * Did the newest Bring back start take? True when the host answered that
	 * the container was started and runs (hold_container's done on a start),
	 * false when it answered otherwise or the job failed, null while it waits
	 * or when there is none.
	 */
	public static function start_outcome(CustomerCloudProvision $provision): ?bool {
		$last = self::last_start_job($provision);
		if ($last === null || in_array((string)$last['mjb_status'], array('pending', 'queued', 'running'), true)) {
			return null;
		}
		if ((string)$last['mjb_status'] !== 'completed') {
			return false;
		}
		$job = new ManagementJob((int)$last['mjb_management_job_id'], TRUE);
		if (!$job->get('mjb_result')) {
			JobResultProcessor::process($job);
			$job->load();
		}
		$result = $job->get('mjb_result');
		$result = is_string($result) ? json_decode($result, true) : $result;
		return is_array($result) && ($result['action'] ?? '') === 'start' && ($result['done'] ?? false) === true;
	}

	/**
	 * From the hosting watch, on a shut-down row: once the host has started
	 * the container a person brought back, the row is subscribed again and
	 * the node's backups and uptime checks come back. Returns 1 when it did.
	 */
	public static function settle(CustomerCloudProvision $provision, $trial): int {
		if ((string)$trial->get('htr_state') !== HostedTrial::STATE_SHUTDOWN) {
			return 0;
		}
		// Only a start asked since this shutdown: an earlier Bring back's
		// finished job says nothing about the container now.
		$last = self::last_start_job($provision);
		$shut = trim((string)$trial->get('htr_shutdown_time'));
		if ($last === null || ($shut !== '' && strtotime((string)$last['mjb_create_time'] . ' UTC') < strtotime($shut . ' UTC'))
				|| self::start_outcome($provision) !== true) {
			return 0;
		}
		$node = new ManagedNode((int)$provision->get('cvp_mgn_managed_node_id'), TRUE);
		if (!$node->key || $node->get('mgn_delete_time')) {
			return 0;
		}
		foreach (array('htr_payment_failed_time', 'htr_grace_ends_time', 'htr_shelf_ends_time', 'htr_shutdown_time', 'htr_remove_ready_time') as $col) {
			$trial->set($col, null);
		}
		$trial->set('htr_state', HostedTrial::STATE_SUBSCRIBED);
		$trial->set('htr_note', 'Brought back after a late payment; its host started it on ' . gmdate('Y-m-d') . '.');
		$trial->save();
		$node->set('mgn_backup_policy', null);
		$node->set('mgn_uptime_enabled', true);
		$node->set('mgn_notes', trim((string)$node->get('mgn_notes') . "\n"
			. 'Brought back after a late payment on ' . gmdate('Y-m-d') . '; backups and uptime checks on again.'));
		$node->save();
		error_log('HostedLateReturn: ' . $provision->get('cvp_domain') . ' started by its host; hosting subscribed again.');
		return 1;
	}

	private static function pays(OrderItem $line): bool {
		return $line->get('odi_is_subscription') && !$line->get('odi_subscription_cancelled_time')
			&& in_array((string)$line->get('odi_subscription_status'), array('active', 'trialing'), true);
	}

	/**
	 * Bring it back. Throws DisplayableUserException, having done nothing,
	 * when refusal() refuses.
	 *
	 * @param ?callable $start asks the host to start the container, for tests: fn(ManagedNode $host, string $name)
	 * @return string[] one plain sentence per step
	 */
	public static function bring_back($node, int $by_user_id, ?callable $start = null): array {
		$why = self::refusal($node, $paying);
		if ($why !== '') {
			throw new DisplayableUserException($why);
		}
		$provision = CustomerCloudProvision::latest_for_node($node->key);
		$trial = HostedTrial::for_provision($provision->key);
		$name = trim((string)$node->get('mgn_container_name'));
		$host_node = JobCommandBuilder::decommission_host_node_for($node);
		$steps = array();

		if ((int)$paying->key !== (int)$provision->get('cvp_external_order_item_id')) {
			$steps[] = 'Moved it from order line #' . (int)$provision->get('cvp_external_order_item_id')
				. ' to the subscription that pays for it now (order ' . (int)$paying->get('odi_ord_order_id') . ').';
			$provision->set('cvp_external_order_item_id', (int)$paying->key);
			$provision->save();
			$trial->set('htr_external_order_item_id', (int)$paying->key);
		}

		if ($start !== null) {
			$start($host_node, $name, $provision);
			$steps[] = 'Asked its host to start the container.';
		} else {
			$built = JobCommandBuilder::build_hold_container($host_node, $name, 'start');
			$job = ManagementJob::createFromBuild($host_node->key, 'hold_container', $built, array(
				'reason'       => 'Brought back after a late payment on ' . gmdate('Y-m-d') . '.',
				'provision_id' => (int)$provision->key,
				'late_return'  => '1',
			), $by_user_id ?: null);
			$steps[] = 'Asked its host to start the container (job #' . (int)$job->key . ').';
		}
		$trial->save();
		$steps[] = 'Once the host says it runs, its hosting is subscribed again, its backups and uptime checks come back on, '
			. 'and its relay slot follows on the next relay pass.';
		error_log('HostedLateReturn: ' . $provision->get('cvp_domain') . ' brought back by user #' . $by_user_id);
		return $steps;
	}
}

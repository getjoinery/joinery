<?php
/**
 * HostedSuspensions — Suspend and Unsuspend for a hosted site, and what a
 * suspension or a disputed payment means for the buyer's next order
 * (specs/hosted_abuse_response.md §4, specs/hosting_launch.md S6).
 *
 * Suspend stops the site: a Starter site's container is stopped and held by its
 * host's agent; a Managed site's instance is powered off. Visitors reach
 * nothing, and the customer is told why by email. It also closes the site's
 * SMTP2GO subaccount and switches off the node's backups and uptime check, so
 * a site stopped on purpose raises no alerts. Nothing is deleted: the data,
 * the backups and the record stay. Unsuspend reverses each step.
 *
 * THE STOP COMES FIRST. A Managed power-off the provider refuses throws, and
 * nothing is recorded. A Starter stop is a request to the host: the record is
 * saved once it is queued, and hold_outcome() reads the host's answer (its
 * `done`, not the job's status), so the node page says "stop asked, not
 * confirmed" or "the host has not stopped it" until the container is held. A
 * stop that failed or did not take is asked again once an hour
 * (recheck_hold(), from the billing watch's tick). The record is saved straight
 * after the stop, before the steps that follow it (mail, monitoring, the email),
 * so a later failure never leaves a stopped site with nothing on record. One
 * suspend or unsuspend at a time per site, under an advisory lock.
 *
 * A HOLD FROM ELSEWHERE does not lift it: the host page's Start, Replace and a
 * site copy are refused for a suspended site (open_for_container(),
 * SiteCopyRunner::source_refusals()).
 *
 * A site whose hosting ended unpaid is already stopped by its grace's shutdown.
 * Suspend does not stop it again, and Unsuspend closes the record and reopens
 * the mail but does not start it: bringing such a site back is a person's
 * manual step (hosted_tier.md).
 *
 * THE BUYER, AFTERWARDS. An open suspension, or a disputed payment, on any of
 * a buyer's sites (by account or by the paying card) holds their next paid
 * hosted order for a person, the same hold the per-buyer limit uses
 * (CustomerCloudFulfillment::over_limit_reason()). A dispute is the store's
 * order.disputed signal; handle_signal() keeps each dispute by id and marks the
 * site while any is not won (order.dispute_closed carries the outcome).
 *
 * @version 1.1 - a Starter stop is confirmed from the host's answer and asked again hourly; the record is saved
 *                straight after the stop; one suspend at a time per site; a site whose hosting ended is not stopped
 *                or started, and its suspension can still be lifted; a won dispute clears the mark (reviewer2 B1-B4,
 *                B6, B7)
 * @version 1.2 - disputes kept by id, so a won one clears the mark only when no other is open; the hourly re-ask
 *                runs under the suspend lock (reviewer2 N1, N2)
 * @version 1.0
 */

class HostedSuspensions {

	/** How long after a stop that did not take before the host is asked again. */
	const HOLD_RETRY_SECONDS = 3600;

	/** The advisory lock class one site's suspend and unsuspend share (the provision id is the key). */
	const LOCK_CLASS = 74101;

	/** Tests: replaces Smtp2GoClient::fromSettings() (false = no key held). */
	public static $mail_client = null;

	/** Tests: callable($provision) returning the instance's cloud driver. */
	public static $driver_stand_in = null;

	/** Tests: callable($to, $subject, $body) in place of the customer email. */
	public static $mailer = null;

	/**
	 * Suspend a hosted site. Returns the open record. Throws, with nothing
	 * recorded, when the site cannot be suspended or the stop is refused.
	 */
	public static function suspend(CustomerCloudProvision $provision, string $reason, int $by_user_id): HostedSuspension {
		$reason = trim(preg_replace('/\s+/', ' ', $reason));
		if ($reason === '') {
			throw new RuntimeException('Say why the site is suspended; the customer is told the reason.');
		}
		$reason = mb_substr($reason, 0, 500);
		self::assert_hosted($provision);
		return self::locked($provision, function () use ($provision, $reason, $by_user_id) {
			if (HostedSuspension::open_for($provision->key) !== null) {
				throw new RuntimeException($provision->get('cvp_domain') . ' is already suspended.');
			}
			$node = self::node_of($provision);
			$ended = self::hosting_ended($provision);

			$row = new HostedSuspension(NULL);
			$row->set('hsu_cvp_customer_cloud_provision_id', (int)$provision->key);
			$row->set('hsu_reason', $reason);
			$row->set('hsu_suspend_usr_user_id', $by_user_id ?: null);
			$row->set('hsu_saved', json_encode(array(
				'backup_policy'  => $node->get('mgn_backup_policy'),
				'uptime_enabled' => (bool)$node->get('mgn_uptime_enabled'),
			)));

			if ($ended) {
				$first = 'Not stopped again: it was stopped when its hosting ended unpaid.';
			} elseif ($provision->is_container()) {
				$job = self::hold($node, 'stop', 'Suspended: ' . $reason, $provision, $by_user_id);
				$row->set('hsu_hold_job_id', (int)$job->key);
				$first = 'Asked the host to stop the container and keep it stopped (job #' . (int)$job->key . ').';
			} else {
				self::driver($provision)->shutdownInstance(self::instance_id($provision));
				$first = 'Powered off instance ' . self::instance_id($provision) . '.';
			}
			// On record from here: whatever fails below, Unsuspend can still lift it.
			$row->add_steps(array($first));
			$row->save();

			$steps = array(self::mail($provision, 'close'));
			try {
				$node->set('mgn_backup_policy', json_encode(array('enabled' => false)));
				$node->set('mgn_uptime_enabled', false);
				$node->set('mgn_notes', trim((string)$node->get('mgn_notes') . "\n"
					. 'Suspended on ' . gmdate('Y-m-d') . ': ' . $reason . '. Backups and uptime checks switched off until Unsuspend.'));
				$node->save();
				$steps[] = 'Switched off backups and uptime checks for the node.';
			} catch (Throwable $e) {
				$steps[] = 'Could not switch off backups and uptime checks: ' . $e->getMessage();
			}
			$steps[] = self::tell_customer($provision, 'Your site ' . $provision->get('cvp_domain') . ' is suspended',
				"Your site " . $provision->get('cvp_domain') . " has been suspended and is being taken offline.\n\n"
				. "The reason: " . $reason . "\n\n"
				. "Nothing has been deleted. Your site, its data and its backups are kept as they are.\n"
				. "If you think this is a mistake, or once the problem is fixed, reply to this email.\n");

			$row->add_steps($steps);
			$row->save();
			error_log('HostedSuspensions: ' . $provision->get('cvp_domain') . ' suspended by user #' . $by_user_id . ': ' . $reason);
			return $row;
		});
	}

	/** Lift a site's open suspension. Throws, leaving it suspended, when the start is refused. */
	public static function unsuspend(CustomerCloudProvision $provision, int $by_user_id): HostedSuspension {
		return self::locked($provision, function () use ($provision, $by_user_id) {
			$row = HostedSuspension::open_for($provision->key);
			if ($row === null) {
				throw new RuntimeException($provision->get('cvp_domain') . ' is not suspended.');
			}
			$node = self::node_of($provision);
			$ended = self::hosting_ended($provision);

			$steps = array();
			if ($ended) {
				$steps[] = 'Not started: its hosting ended unpaid, so it stays off. Bringing it back is a manual step.';
			} elseif ($provision->is_container()) {
				$job = self::hold($node, 'start', '', $provision, $by_user_id);
				$steps[] = 'Asked the host to start the container again (job #' . (int)$job->key . ').';
			} else {
				self::driver($provision)->bootInstance(self::instance_id($provision));
				$steps[] = 'Powered on instance ' . self::instance_id($provision) . '.';
			}

			$steps[] = self::mail($provision, 'reopen');

			if ($ended) {
				// The shutdown switched these off for its own reason; that stands.
				$steps[] = 'Left backups and uptime checks off, as the end of its hosting set them.';
			} else {
				$saved = $row->saved();
				try {
					if (array_key_exists('backup_policy', $saved)) {
						$node->set('mgn_backup_policy', $saved['backup_policy']);
					}
					if (array_key_exists('uptime_enabled', $saved)) {
						$node->set('mgn_uptime_enabled', (bool)$saved['uptime_enabled']);
					}
					$node->set('mgn_notes', trim((string)$node->get('mgn_notes') . "\n"
						. 'Unsuspended on ' . gmdate('Y-m-d') . '; backups and uptime checks put back as they were.'));
					$node->save();
					$steps[] = 'Put back the node\'s backups and uptime checks as they were.';
				} catch (Throwable $e) {
					$steps[] = 'Could not put back backups and uptime checks: ' . $e->getMessage();
				}
			}

			$steps[] = self::tell_customer($provision, 'The suspension of ' . $provision->get('cvp_domain') . ' is lifted',
				"The suspension of your site " . $provision->get('cvp_domain') . " has been lifted.\n"
				. ($ended ? "Its hosting has ended, so it stays off. Reply to this email if you want it back.\n"
					: "It is starting again and should be reachable within a few minutes.\n"));

			$row->set('hsu_unsuspend_usr_user_id', $by_user_id ?: null);
			$row->set('hsu_unsuspend_time', gmdate('Y-m-d H:i:s'));
			$row->add_steps($steps);
			$row->save();
			error_log('HostedSuspensions: ' . $provision->get('cvp_domain') . ' unsuspended by user #' . $by_user_id);
			return $row;
		});
	}

	/**
	 * What became of a Starter suspension's stop: 'held' (the host's answer says
	 * stopped and held), 'asked' (no answer yet), 'refused' (failed, lost,
	 * cancelled, or answered without holding it), or '' for a suspension with
	 * no stop job (a Managed site, or one whose hosting had already ended).
	 */
	public static function hold_outcome(HostedSuspension $row): string {
		$job_id = (int)$row->get('hsu_hold_job_id');
		if (!$job_id) {
			return '';
		}
		$job = new ManagementJob($job_id, TRUE);
		if (!$job->key) {
			return 'refused';
		}
		$status = (string)$job->get('mjb_status');
		if (in_array($status, array('pending', 'queued', 'running'), true)) {
			return 'asked';
		}
		if ($status !== 'completed') {
			return 'refused';
		}
		if (!$job->get('mjb_result')) {
			JobResultProcessor::process($job);
			$job->load();
		}
		$result = $job->get('mjb_result');
		$result = is_string($result) ? json_decode($result, true) : $result;
		return (is_array($result) && ($result['action'] ?? '') === 'stop' && ($result['done'] ?? false) === true) ? 'held' : 'refused';
	}

	/**
	 * The billing watch's tick: a suspended Starter site whose stop did not
	 * take is asked again, once an hour. Returns 1 when it asked.
	 */
	public static function recheck_hold(CustomerCloudProvision $provision): int {
		// Under the suspend lock, so a stop asked again cannot land after an
		// Unsuspend's start; a press under way wins and this tick skips.
		try {
			return self::locked($provision, function () use ($provision) {
				return self::recheck_hold_locked($provision);
			});
		} catch (RuntimeException $e) {
			return 0;
		}
	}

	private static function recheck_hold_locked(CustomerCloudProvision $provision): int {
		$row = HostedSuspension::open_for($provision->key);
		if ($row === null || !$provision->is_container() || self::hold_outcome($row) !== 'refused') {
			return 0;
		}
		$job = new ManagementJob((int)$row->get('hsu_hold_job_id'), TRUE);
		$at = $job->key ? (string)($job->get('mjb_completed_time') ?: $job->get('mjb_create_time')) : '';
		if ($at !== '' && time() - strtotime($at . ' UTC') < self::HOLD_RETRY_SECONDS) {
			return 0;
		}
		try {
			$again = self::hold(self::node_of($provision), 'stop', 'Suspended: ' . $row->get('hsu_reason'), $provision, 0);
		} catch (Throwable $e) {
			error_log('HostedSuspensions: ' . $provision->get('cvp_domain') . ': could not ask its host again: ' . $e->getMessage());
			return 0;
		}
		$row->set('hsu_hold_job_id', (int)$again->key);
		$row->add_steps(array('Asked the host again to stop the container (job #' . (int)$again->key
			. '): the last ask did not hold it.'));
		$row->save();
		return 1;
	}

	/**
	 * The open suspension of the site in container $name on this host's node,
	 * or null. What the host page's Start and Replace refuse on.
	 */
	public static function open_for_container($host_node, string $name): ?HostedSuspension {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT s.hsu_hosted_suspension_id
			FROM hsu_hosted_suspensions s
			JOIN cvp_customer_cloud_provisions p ON p.cvp_customer_cloud_provision_id = s.hsu_cvp_customer_cloud_provision_id
			JOIN mgn_managed_nodes n ON n.mgn_managed_node_id = p.cvp_mgn_managed_node_id
			JOIN mgh_managed_hosts h ON h.mgh_managed_host_id = n.mgn_mgh_managed_host_id
			WHERE h.mgh_mgn_managed_node_id = ? AND n.mgn_container_name = ?
			  AND s.hsu_unsuspend_time IS NULL AND s.hsu_delete_time IS NULL
			ORDER BY s.hsu_hosted_suspension_id DESC LIMIT 1");
		$q->execute(array((int)$host_node->key, $name));
		$id = (int)$q->fetchColumn();
		return $id ? new HostedSuspension($id, TRUE) : null;
	}

	/** The site's open suspension, for a node page or a copy's preflight. */
	public static function open_for_node($node): ?HostedSuspension {
		$provision = CustomerCloudProvision::latest_for_node((int)$node->key);
		return $provision ? HostedSuspension::open_for($provision->key) : null;
	}

	/**
	 * Why a buyer's next hosted order is held for a person, or null: an open
	 * suspension or a disputed payment on any site of the account or the card.
	 * Deleted sites count: a site removed after a dispute still says who the
	 * buyer is.
	 */
	public static function buyer_flag(int $user_id, string $fingerprint): ?string {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT cvp_domain, cvp_disputed_time IS NOT NULL AS disputed
			FROM cvp_customer_cloud_provisions p
			WHERE (cvp_usr_user_id = ? OR (? <> '' AND cvp_card_fingerprint = ?))
			  AND (cvp_disputed_time IS NOT NULL OR EXISTS (
			        SELECT 1 FROM hsu_hosted_suspensions s
			        WHERE s.hsu_cvp_customer_cloud_provision_id = p.cvp_customer_cloud_provision_id
			          AND s.hsu_unsuspend_time IS NULL AND s.hsu_delete_time IS NULL))
			ORDER BY cvp_customer_cloud_provision_id DESC LIMIT 1");
		$q->execute(array($user_id, $fingerprint, $fingerprint));
		$hit = $q->fetch(PDO::FETCH_ASSOC);
		if (!$hit) {
			return null;
		}
		return 'the buyer (by account or card) has ' . ($hit['disputed'] ? 'a disputed payment' : 'a suspended site')
			. ' on ' . $hit['cvp_domain'];
	}

	/**
	 * order.disputed and order.dispute_closed: each hosted site the disputed
	 * order paid for keeps its disputes by id (cvp_disputes, id => open, won,
	 * lost or warning_closed) and is marked (cvp_disputed_time) while any of
	 * them is not won. Two disputed renewals on one line are two entries, so
	 * winning one does not clear the other. The operator's alert is the
	 * signal's own notice; the mark is what the next order is held on.
	 */
	public static function handle_signal($signal, array $payload): void {
		if (!in_array($signal, array('order.disputed', 'order.dispute_closed'), true)) {
			return;
		}
		$dispute_id = (string)($payload['dispute_id'] ?? '');
		$status = $signal === 'order.disputed' ? 'open' : ((string)($payload['status'] ?? '') ?: 'closed');
		$ids = array_filter(array_map('intval', explode(',', (string)($payload['order_item_ids'] ?? ''))));
		foreach ($ids as $order_item_id) {
			foreach (new MultiCustomerCloudProvision(array('external_order_item_id' => $order_item_id)) as $provision) {
				$disputes = $provision->get('cvp_disputes');
				$disputes = is_string($disputes) ? json_decode($disputes, true) : $disputes;
				$disputes = is_array($disputes) ? $disputes : array();
				if ($signal === 'order.dispute_closed' && !array_key_exists($dispute_id, $disputes)) {
					continue;   // a close for a dispute this site never had open
				}
				$disputes[$dispute_id] = $status;
				$marked = count(array_filter($disputes, function ($st) { return $st !== 'won'; })) > 0;
				$provision->set('cvp_disputes', json_encode($disputes));
				if ($marked && !$provision->get('cvp_disputed_time')) {
					$provision->set('cvp_disputed_time', gmdate('Y-m-d H:i:s'));
				} elseif (!$marked) {
					$provision->set('cvp_disputed_time', null);
				}
				$provision->save();
				error_log('HostedSuspensions: dispute ' . $dispute_id . ' on ' . $provision->get('cvp_domain') . ' is ' . $status
					. ($marked ? '; the buyer\'s next hosted order is held for a person.' : '; no dispute holds the buyer now.'));
			}
		}
	}

	// ── The pieces ───────────────────────────────────────────────────────────

	/** Run $fn holding this site's suspend lock; a second press while one runs is refused. */
	private static function locked(CustomerCloudProvision $provision, callable $fn) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare('SELECT pg_try_advisory_lock(?, ?)');
		$q->execute(array(self::LOCK_CLASS, (int)$provision->key));
		if (!$q->fetchColumn()) {
			throw new RuntimeException($provision->get('cvp_domain') . ' is being suspended or unsuspended right now; try again in a moment.');
		}
		try {
			return $fn();
		} finally {
			$u = $db->prepare('SELECT pg_advisory_unlock(?, ?)');
			$u->execute(array(self::LOCK_CLASS, (int)$provision->key));
		}
	}

	/** Whether the grace already ran out and its shutdown stopped the site. */
	private static function hosting_ended(CustomerCloudProvision $provision): bool {
		$trial = HostedTrial::for_provision($provision->key);
		return $trial !== null && (string)$trial->get('htr_state') === HostedTrial::STATE_SHUTDOWN;
	}


	private static function assert_hosted(CustomerCloudProvision $provision): void {
		if (!$provision->key || $provision->get('cvp_delete_time')) {
			throw new RuntimeException('No such hosted site.');
		}
		if (!$provision->is_sold() || !$provision->is_operator_hosted()) {
			throw new RuntimeException('Only a hosted site a customer bought is suspended here.');
		}
		if ($provision->is_pre_payment()) {
			throw new RuntimeException($provision->get('cvp_domain') . ' was never paid for; there is nothing to suspend.');
		}
	}

	private static function node_of(CustomerCloudProvision $provision): ManagedNode {
		$id = (int)$provision->get('cvp_mgn_managed_node_id');
		$node = $id ? new ManagedNode($id, TRUE) : null;
		if (!$node || !$node->key || $node->get('mgn_delete_time')) {
			throw new RuntimeException($provision->get('cvp_domain') . ' has no node on record.');
		}
		return $node;
	}

	/** A hold_container job on the site's host: stop (with the reason on the job) or start. */
	private static function hold(ManagedNode $node, string $op, string $reason, CustomerCloudProvision $provision, int $by_user_id) {
		$name = trim((string)$node->get('mgn_container_name'));
		if ($name === '') {
			throw new RuntimeException($provision->get('cvp_domain') . ' has no container on record.');
		}
		$host_node = JobCommandBuilder::decommission_host_node_for($node);
		$built = JobCommandBuilder::build_hold_container($host_node, $name, $op);
		$params = array('provision_id' => (int)$provision->key, 'suspension' => '1');
		if ($reason !== '') {
			$params['reason'] = mb_substr($reason, 0, 200);
		}
		return ManagementJob::createFromBuild($host_node->key, 'hold_container', $built, $params, $by_user_id ?: null);
	}

	private static function instance_id(CustomerCloudProvision $provision): string {
		$id = trim((string)$provision->get('cvp_instance_id'));
		if ($id === '') {
			throw new RuntimeException($provision->get('cvp_domain') . ' has no instance on record.');
		}
		return $id;
	}

	private static function driver(CustomerCloudProvision $provision) {
		if (is_callable(self::$driver_stand_in)) {
			return (self::$driver_stand_in)($provision);
		}
		$d = HeldCloudAccounts::provision_driver($provision);
		if ($d['driver'] === null) {
			throw new RuntimeException($d['reason']);
		}
		return $d['driver'];
	}

	/** Close or reopen the site's SMTP2GO subaccount; returns the step's line. */
	private static function mail(CustomerCloudProvision $provision, string $op): string {
		$sub = trim((string)$provision->get('cvp_smtp2go_subaccount_id'));
		if ($sub === '') {
			return 'No SMTP2GO subaccount on record; its mail is not ours to ' . ($op === 'close' ? 'stop' : 'start') . '.';
		}
		$client = self::$mail_client ?? (Smtp2GoClient::fromSettings() ?? false);
		if ($client === false) {
			return 'Mail subaccount ' . $sub . ' was not ' . ($op === 'close' ? 'closed' : 'reopened')
				. ': this management node holds no SMTP2GO key.';
		}
		try {
			if ($op === 'close') {
				$client->closeSubaccount($sub);
				return 'Closed mail subaccount ' . $sub . ' at SMTP2GO.';
			}
			$client->reopenSubaccount($sub);
			return 'Reopened mail subaccount ' . $sub . ' at SMTP2GO.';
		} catch (Throwable $e) {
			return 'Mail subaccount ' . $sub . ' was not ' . ($op === 'close' ? 'closed' : 'reopened') . ': ' . $e->getMessage();
		}
	}

	private static function tell_customer(CustomerCloudProvision $provision, string $subject, string $body): string {
		$to = trim((string)$provision->get('cvp_buyer_email'));
		if ($to === '') {
			$user = new User((int)$provision->get('cvp_usr_user_id'), TRUE);
			$to = $user->key ? trim((string)$user->get('usr_email')) : '';
		}
		if ($to === '') {
			return 'The customer was not emailed: no address on record.';
		}
		try {
			if (is_callable(self::$mailer)) {
				(self::$mailer)($to, $subject, $body);
			} else {
				EmailSender::quickSend($to, $subject, $body);
			}
			return 'Emailed the customer at ' . $to . '.';
		} catch (Throwable $e) {
			return 'The customer email to ' . $to . ' failed: ' . $e->getMessage();
		}
	}
}

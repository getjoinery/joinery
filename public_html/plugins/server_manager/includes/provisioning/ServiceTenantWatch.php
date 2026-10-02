<?php
/**
 * ServiceTenantWatch — the reconcile and the meter for the services a
 * self-hosted site rents from this plane, worked each tick
 * (specs/services_phase2_platform.md §3, §5, §10 item 3).
 *
 * One phase of ServerManagerAdvanceProvisioning, after the hosted watch. For
 * every tenant row that has ever been entitled:
 *
 *   1. The allowance is re-read from the plan setting and, for mail, the
 *      subaccount's limit is re-set when it changed — one number in one place
 *      governs every tenant.
 *   2. The ladder (core ServiceTenantLadder): the date is compared every pass,
 *      so no signal is needed. A passed date starts the grace window; past it
 *      the row is suspended — mail's subaccount closed, the backup storage broker
 *      refusing — and the retention clock starts. A new date at any point
 *      before the prune reactivates in place.
 *   3. The meter. Mail: the provider's own month-to-date count, read hourly,
 *      so a missed webhook cannot make the banner wrong. Shelf: the ledger,
 *      reconciled daily against a real listing — an object the listing does
 *      not have is dropped, one the ledger does not have is adopted at its
 *      listed size — so the figure is what is actually there.
 *   4. Abandoned runs: a run left open past ShelfBroker::STALE_RUN_HOURS is
 *      aborted on the plane's side (its open multipart cancelled with the
 *      plane's credential, its unfinished ledger rows dropped).
 *   5. Retention. Every active tenant's backup storage is pruned to the newest
 *      server_manager_services_shelf_keep_chains chains per profile, chains
 *      whole, a chain with an open run never touched. A suspended or released
 *      tenant whose prune-after day has come loses its whole prefix, once,
 *      and the row says so.
 *
 * The plane never deletes anything a box asked it to: every delete here is
 * the plane's own act under the service's retention promise.
 *
 * A NODE-LINKED ROW is a Managed site that moved to its customer's own cloud
 * account (specs/managed_to_self_hosted_transfer.md §5a). It has no key, so
 * nothing on the box ever polls its status: the banner is composed here from
 * the node's rows and pushed when it changes, and the customer gets one email
 * when grace starts and one at suspension. Its backup storage is the node's
 * fleet backups — the figure is what they occupy, the allowance pauses them,
 * and a prune empties the node's prefix — so the broker's ledger, its stale
 * runs and its chain retention do not apply.
 *
 * @version 1.2 - node-linked rows (a site moved off Managed): fleet-backup figure, allowance and prune;
 *                the banner pushed over the agent; grace and suspension emails
 * @version 1.1 - the reconcile aborts an open multipart at the provider before dropping its row
 */
class ServiceTenantWatch {

	/** How often the mail provider is asked for a tenant's month-to-date count. */
	const MAIL_FIGURE_SECONDS = 3600;

	/** How often a tenant's ledger is reconciled against a real listing. */
	const SHELF_RECONCILE_SECONDS = 86400;

	/** @var Smtp2GoClient|null|false Resolved once per tick; false = not configured. */
	public $client = false;

	/** @var array Human-readable problems for the run summary. */
	private $errors = array();

	/** @var array node id => ['lapsed' => [service…], 'suspended' => [service…]] this tick. */
	private $node_transitions = array();

	/** The job type carrying a site's banner to it (the same primitive the hosted watch uses). */
	const BANNER_JOB_TYPE = 'hosted_plan_notice';

	public function run(array $config): array {
		$rows = new MultiServiceTenant(array(
			'states'  => array(ServiceTenant::STATE_PROVISIONING, ServiceTenant::STATE_ACTIVE,
				ServiceTenant::STATE_SUSPENDED, ServiceTenant::STATE_RELEASED),
			'deleted' => false,
		), array('svt_service_tenant_id' => 'ASC'));
		$rows->load();
		if (count($rows) === 0) {
			return array('status' => 'skipped', 'message' => 'No service tenants to watch.');
		}

		$acted = 0;
		$linked_nodes = array();
		foreach ($rows as $row) {
			try {
				$acted += $this->watch($row);
			} catch (Throwable $e) {
				$this->errors[] = $this->label($row) . ': ' . $e->getMessage();
				error_log('ServiceTenantWatch: ' . $this->label($row) . ': ' . $e->getMessage());
			}
			if ($row->is_node_linked()) {
				$linked_nodes[(int)$row->get('svt_mgn_managed_node_id')] = true;
			}
		}

		// A node-linked site hears about its services from here, not from a
		// status poll of its own: the transitions by email, the standing on its
		// banner.
		foreach (array_keys($linked_nodes) as $node_id) {
			try {
				$acted += $this->tell_node_customer($node_id);
				$node = new ManagedNode($node_id, TRUE);
				if ($node->key && !$node->get('mgn_delete_time') && $node->get('mgn_enabled')) {
					$acted += (self::push_node_banner($node) === 'filed') ? 1 : 0;
				}
			} catch (Throwable $e) {
				$this->errors[] = 'node #' . $node_id . ': ' . $e->getMessage();
				error_log('ServiceTenantWatch: node #' . $node_id . ': ' . $e->getMessage());
			}
		}

		$message = 'Service tenants: ' . $acted . ' change(s) across ' . count($rows) . ' row(s).';
		if ($this->errors) {
			$message .= ' ' . count($this->errors) . ' problem(s): ' . implode('; ', array_slice($this->errors, 0, 3));
			if (count($this->errors) > 3) { $message .= ' …'; }
			return array('status' => 'error', 'message' => $message);
		}
		return array('status' => 'success', 'message' => $message);
	}

	/** One row, one tick. Returns how many things changed. */
	public function watch(ServiceTenant $row, ?string $now = null): int {
		$now = $now ?? gmdate('Y-m-d H:i:s');
		$acted = 0;
		$service = (string)$row->get('svt_service');

		$acted += $this->refresh_allowance($row);

		// The ladder. The two acts are the service's own (JoineryServices).
		$client = $this->mail_client();
		$state = (string)$row->get('svt_state');
		if ($state === ServiceTenant::STATE_ACTIVE || $state === ServiceTenant::STATE_SUSPENDED) {
			$step = ServiceTenantLadder::advance($row, array(
				'state'      => 'svt_state',
				'lapse_time' => 'svt_lapse_time',
				'check_time' => 'svt_checked_time',
			), $row->entitled($now), JoineryServices::graceDays(),
				function (ServiceTenant $r) use ($client, $now) { JoineryServices::suspend($r, $client, $now); },
				function (ServiceTenant $r) use ($client) { JoineryServices::reactivate($r, $client); },
				$now);
			if ($step === ServiceTenantLadder::STEP_SUSPENDED || $step === ServiceTenantLadder::STEP_REACTIVATED
					|| $step === ServiceTenantLadder::STEP_LAPSED) {
				$acted++;
			}
			if ($row->is_node_linked() && ($step === ServiceTenantLadder::STEP_LAPSED
					|| $step === ServiceTenantLadder::STEP_SUSPENDED)) {
				$kind = $step === ServiceTenantLadder::STEP_LAPSED ? 'lapsed' : 'suspended';
				$this->node_transitions[(int)$row->get('svt_mgn_managed_node_id')][$kind][] = $service;
			}
		} else {
			$row->set('svt_checked_time', $now);
			$row->save();
		}

		// A rung the ladder wrote whose provider act did not land (the mail
		// provider was unreachable): the row says so — suspended with no
		// revoked time, or active with one still set — and the act is retried
		// here until it lands.
		$state = (string)$row->get('svt_state');
		if ($state === ServiceTenant::STATE_SUSPENDED && $row->get('svt_revoked_time') === null) {
			JoineryServices::suspend($row, $client, $now);
			$acted++;
		} elseif ($state === ServiceTenant::STATE_ACTIVE && $row->get('svt_revoked_time') !== null) {
			JoineryServices::reactivate($row, $client);
			$acted++;
		}

		if ($service === ServiceTenant::SERVICE_MAIL) {
			$acted += $this->meter_mail($row, $client, $now);
		} elseif ($row->is_node_linked()) {
			$acted += $this->watch_node_shelf($row, $now);
		} else {
			$acted += $this->abort_stale_runs($row, $now);
			$acted += $this->prune_if_due($row, $now);
			if ((string)$row->get('svt_state') === ServiceTenant::STATE_ACTIVE) {
				$acted += $this->reconcile_shelf($row, $now);
				$acted += $this->retain_chains($row);
			}
		}
		return $acted;
	}

	// ── 1. The allowance ─────────────────────────────────────────────────────

	private function refresh_allowance(ServiceTenant $row): int {
		$service = (string)$row->get('svt_service');
		$allowance = JoineryServices::allowance($service);
		if ((int)$row->get('svt_allowance') === $allowance) {
			return 0;
		}
		if ($service === ServiceTenant::SERVICE_MAIL) {
			$subaccount = trim((string)$row->get('svt_provider_subaccount_id'));
			$client = $this->mail_client();
			if ($subaccount !== '' && $client !== null) {
				Smtp2GoLeg::setLimit($client, $subaccount, $allowance);
			}
		}
		$row->set('svt_allowance', $allowance);
		$row->save();
		return 1;
	}

	// ── Node-linked backup storage ───────────────────────────────────────────

	/**
	 * A site moved off Managed keeps its fleet backups: the figure is what
	 * they occupy, the allowance pauses them (and lifts its own pause), and
	 * once the retention day comes after a stop, the node's prefix is emptied.
	 */
	private function watch_node_shelf(ServiceTenant $row, string $now): int {
		$node = $row->linked_node();
		if ($node === null) {
			return 0;
		}
		$acted = 0;
		$bytes = NodeBackupShelf::bytes($node);
		if ((int)$row->get('svt_figure') !== $bytes) {
			$row->set('svt_figure', $bytes);
			$row->set('svt_figure_time', $now);
			$row->save();
		}
		if ((string)$row->get('svt_state') === ServiceTenant::STATE_ACTIVE) {
			$was = (string)$row->get('svt_notice');
			$acted_on = NodeBackupShelf::apply_allowance($node, (int)$row->get('svt_allowance'));
			if ($acted_on === 'paused') {
				$row->set('svt_notice', 'Offsite backups of this site are paused: it is using its whole '
					. JoineryServices::formatFigure(ServiceTenant::SERVICE_SHELF, (int)$row->get('svt_allowance'))
					. ' backup allowance. They start again on their own once backup storage comes back under it.');
				$row->save();
				$acted++;
			} elseif ($acted_on === 'resumed' && $was !== '') {
				$row->set('svt_notice', null);
				$row->save();
				$acted++;
			}
			return $acted;
		}

		$after = trim((string)$row->get('svt_prune_after_time'));
		if ($after === '' || $after > $now || $row->get('svt_pruned_time') !== null) {
			return $acted;
		}
		try {
			$deleted = NodeBackupShelf::prune($node);
		} catch (RuntimeException $e) {
			$this->errors[] = $this->label($row) . ': ' . $e->getMessage() . '.';
			return $acted;
		}
		if ($deleted === null) {
			return $acted;   // no target to reach yet; tried again next tick
		}
		$row->set('svt_figure', 0);
		$row->set('svt_figure_time', $now);
		$row->set('svt_pruned_time', $now);
		$row->set('svt_notice', 'The getjoinery backup storage copies of this site were pruned on ' . substr($now, 0, 10)
			. ', ' . ServiceTenant::RETENTION_DAYS . ' days after the service stopped.');
		$row->save();
		error_log('ServiceTenantWatch: pruned ' . $deleted . ' object(s) from backup storage of ' . $this->label($row) . '.');
		return $acted + 1;
	}

	// ── Node-linked sites: the banner and the two emails ─────────────────────

	/**
	 * The services banner a node-linked site shows, as the five values the
	 * node script writes: the same shape a self-hosted site composes for
	 * itself from its status poll (ServicesStatusPoll::writeBanner). No row
	 * still in service means the banner clears.
	 */
	public static function node_banner_settings(int $node_id): array {
		$rows = array();
		$until = '';
		$notices = array();
		$tenants = new MultiServiceTenant(array('node_id' => $node_id, 'deleted' => false),
			array('svt_service_tenant_id' => 'ASC'));
		foreach ($tenants as $tenant) {
			$s = JoineryServices::statusOf($tenant);
			if (in_array((string)$s['state'], array(ServiceTenant::STATE_UNPAID, ServiceTenant::STATE_RELEASED), true)) {
				continue;
			}
			$rows[] = array(
				'label'        => (string)$s['label'],
				'used'         => (string)$s['used_label'],
				'allowance'    => (string)$s['allowance_label'],
				'percent'      => (int)$s['percent'],
				'action_label' => (string)$s['action_label'],
				'action_url'   => (string)$s['action_url'],
			);
			$paid = trim((string)$s['paid_until']);
			if ($paid !== '' && ($until === '' || $paid < $until)) {
				$until = $paid;
			}
			$notice = trim((string)$s['notice']);
			if ($notice !== '' && !in_array($notice, $notices, true)) {
				$notices[] = $notice;
			}
		}
		if (!$rows) {
			return array('state' => '', 'until_time' => '', 'notice' => '', 'allowances' => '', 'manage_url' => '');
		}
		return array(
			'state'      => 'services',
			'until_time' => $until,
			'notice'     => implode(' ', $notices),
			'allowances' => json_encode($rows),
			'manage_url' => JoineryServices::manageUrl(),
		);
	}

	/**
	 * File the banner for a node-linked site when what it should show has
	 * changed. Returns 'filed', 'same' (the node has it, or it is on its way),
	 * 'busy' (another push is in flight) or 'unable: <why>' — an agent without
	 * the primitive, or an older release that does not render the services
	 * state, is a note on the rows, not a failure.
	 *
	 * The digest rides on the job, and a job that completed with this digest
	 * is the node showing it; a failed one is an attempt, so it is filed again.
	 */
	public static function push_node_banner(ManagedNode $node): string {
		$settings = self::node_banner_settings((int)$node->key);
		$digest = hash('sha256', json_encode($settings));
		$latest = ManagementJob::latestForNode($node->key, self::BANNER_JOB_TYPE);
		if ($latest) {
			$params = $latest->get('mjb_parameters');
			if (is_string($params)) { $params = json_decode($params, true); }
			$status = (string)$latest->get('mjb_status');
			$same = is_array($params) && (string)($params['digest'] ?? '') === $digest;
			if ($same && in_array($status, array('completed', 'queued', 'pending', 'running'), true)) {
				return 'same';
			}
			if (in_array($status, array('queued', 'pending', 'running'), true)) {
				return 'busy';
			}
		}
		try {
			$built = JobCommandBuilder::build_hosted_plan_notice($node, $settings);
		} catch (Throwable $e) {
			$note = 'The site cannot show its services banner yet — ' . $e->getMessage();
			foreach (new MultiServiceTenant(array('node_id' => (int)$node->key, 'deleted' => false)) as $tenant) {
				if ((string)$tenant->get('svt_note') !== $note) {
					$tenant->set('svt_note', $note);
					$tenant->save();
				}
			}
			return 'unable: ' . $e->getMessage();
		}
		ManagementJob::createFromBuild($node->key, self::BANNER_JOB_TYPE, $built,
			array('digest' => $digest, 'services' => true), null);
		return 'filed';
	}

	/**
	 * One email per node per transition this tick: grace started, or the
	 * service stopped. Both rows of a site lapse on the same date, so they
	 * arrive together and are told together.
	 */
	private function tell_node_customer(int $node_id): int {
		$told = 0;
		foreach (array('lapsed', 'suspended') as $kind) {
			$services = $this->node_transitions[$node_id][$kind] ?? array();
			if (!$services) {
				continue;
			}
			$provision = CustomerCloudProvision::latest_for_node($node_id);
			if ($provision === null) {
				continue;
			}
			$to = trim((string)$provision->get('cvp_buyer_email'));
			if ($to === '') {
				continue;
			}
			$names = array();
			foreach (array_unique($services) as $service) {
				$names[] = $service === ServiceTenant::SERVICE_MAIL ? 'outbound email' : 'backup storage';
			}
			$grace_ends = '';
			if ($kind === 'lapsed') {
				$row = ServiceTenant::forNode($node_id, $services[0]);
				$grace_ends = $row ? (string)ServiceTenantLadder::graceEnds($row, 'svt_lapse_time', JoineryServices::graceDays()) : '';
			}
			try {
				EmailSender::sendTemplate($kind === 'lapsed' ? 'services_moved_grace' : 'services_moved_suspended', $to, array(
					'recipient'  => InstanceTransfers::recipient_for($provision),
					'domain'     => (string)$provision->get('cvp_domain'),
					'buyer_name' => (string)($provision->get('cvp_buyer_name') ?: 'there'),
					'services'   => implode(' and ', $names),
					'grace_ends' => $grace_ends !== '' ? gmdate('F j, Y', strtotime($grace_ends . ' UTC')) : '',
					'retention_days' => ServiceTenant::RETENTION_DAYS,
					'manage_url' => JoineryServices::manageUrl(),
				));
				$told++;
			} catch (Throwable $e) {
				$this->errors[] = 'node #' . $node_id . ': the ' . $kind . ' email could not be sent: ' . $e->getMessage();
			}
		}
		return $told;
	}

	// ── 3. The meter ─────────────────────────────────────────────────────────

	/** The provider's own count, hourly; the webhook only nudges between reads. */
	private function meter_mail(ServiceTenant $row, ?Smtp2GoClient $client, string $now): int {
		$subaccount = trim((string)$row->get('svt_provider_subaccount_id'));
		if ($subaccount === '' || $client === null) {
			return 0;
		}
		$measured = trim((string)$row->get('svt_figure_time'));
		if ($measured !== '' && substr($measured, 0, 7) === substr($now, 0, 7)
				&& (strtotime($now . ' UTC') - strtotime($measured . ' UTC')) < self::MAIL_FIGURE_SECONDS) {
			return 0;
		}
		$sent = $client->monthToDateSends($subaccount);
		$row->set('svt_figure', $sent);
		$row->set('svt_figure_time', $now);
		$row->save();
		return 1;
	}

	/**
	 * The ledger against a listing, daily. What is in backup storage is the truth;
	 * the ledger is brought to it in both directions, and the figure follows.
	 */
	private function reconcile_shelf(ServiceTenant $row, string $now): int {
		$last = trim((string)$row->get('svt_reconciled_time'));
		if ($last !== '' && (strtotime($now . ' UTC') - strtotime($last . ' UTC')) < self::SHELF_RECONCILE_SECONDS) {
			return 0;
		}
		list($target, $creds, $bucket, $base) = $this->shelf_of($row);
		if ($target === null) {
			return 0;
		}
		$row->set('svt_reconciled_time', $now);
		$row->save();
		$listed = array();
		foreach (S3Signer::list($creds, $bucket, $base) as $object) {
			$key = (string)($object['key'] ?? '');
			if ($key !== '' && strpos($key, $base) === 0) {
				$listed[$key] = (int)($object['size'] ?? 0);
			}
		}

		// Keys with an open run are in flight: neither dropped nor adopted.
		$in_flight = array();
		$open = new MultiShelfRun(array('tenant_id' => (int)$row->key, 'state' => ShelfRun::STATE_OPEN, 'deleted' => false));
		foreach ($open as $run) {
			$in_flight[(int)$run->key] = true;
		}

		$changed = 0;
		$ledger = new MultiShelfObject(array('tenant_id' => (int)$row->key, 'deleted' => false));
		$known = array();
		foreach ($ledger as $object) {
			$key = (string)$object->get('svo_key');
			$known[$key] = true;
			if (isset($in_flight[(int)$object->get('svo_svr_shelf_run_id')])) {
				continue;
			}
			if (!isset($listed[$key])) {
				// Signed (or once completed) and not in backup storage: nothing to
				// count. A multipart still open at the provider is aborted
				// there before the row goes.
				ShelfBroker::abortObject($object);
				$changed++;
				continue;
			}
			if ($object->get('svo_completed_time') === null || (int)$object->get('svo_bytes') !== $listed[$key]) {
				$object->set('svo_bytes', $listed[$key]);
				$object->set('svo_completed_time', $object->get('svo_completed_time') ?? $now);
				$object->set('svo_upload_id', null);
				$object->save();
				$changed++;
			}
		}
		foreach ($listed as $key => $size) {
			if (isset($known[$key])) {
				continue;
			}
			// In backup storage and unknown to the ledger: it occupies the tenant's
			// allowance whoever wrote it, so it is counted.
			$object = new ShelfObject(NULL);
			$object->set('svo_svt_service_tenant_id', (int)$row->key);
			$object->set('svo_key', $key);
			$object->set('svo_bytes', $size);
			$object->set('svo_chain', self::chain_of_key($key, $base));
			$object->set('svo_signed_time', $now);
			$object->set('svo_completed_time', $now);
			$object->save();
			$changed++;
		}
		ShelfBroker::refreshFigure($row);
		return $changed ? 1 : 0;
	}

	// ── 4. Abandoned runs ────────────────────────────────────────────────────

	private function abort_stale_runs(ServiceTenant $row, string $now): int {
		$aborted = 0;
		$stale = new MultiShelfRun(array('tenant_id' => (int)$row->key, 'state' => ShelfRun::STATE_OPEN,
			'stale_hours' => ShelfBroker::STALE_RUN_HOURS, 'deleted' => false));
		foreach ($stale as $run) {
			ShelfBroker::abortRun($run, 'The site never finished this run within ' . ShelfBroker::STALE_RUN_HOURS
				. ' hours; the plane cancelled what it had signed.');
			$aborted++;
		}
		return $aborted;
	}

	// ── 5. Retention ─────────────────────────────────────────────────────────

	/** The newest N chains per profile stay; older ones go whole. */
	private function retain_chains(ServiceTenant $row): int {
		$keep = self::keep_chains();
		list($target, $creds, $bucket, $base) = $this->shelf_of($row);
		if ($target === null) {
			return 0;
		}
		$objects = new MultiShelfObject(array('tenant_id' => (int)$row->key, 'completed' => true, 'deleted' => false));
		$families = array();   // profile => chain => [ShelfObject]
		foreach ($objects as $object) {
			$key = (string)$object->get('svo_key');
			$rel = substr($key, strlen($base));
			$parts = explode('/', $rel);
			if (count($parts) < 3) {
				continue; // not {profile}/{chain}/{object}: not a chain's
			}
			$families[$parts[0]][$parts[1]][] = $object;
		}
		$busy = array();
		$open = new MultiShelfRun(array('tenant_id' => (int)$row->key, 'state' => ShelfRun::STATE_OPEN, 'deleted' => false));
		foreach ($open as $run) {
			$busy[(string)$run->get('svr_chain')] = true;
		}

		$pruned = 0;
		foreach ($families as $profile => $chains) {
			krsort($chains, SORT_STRING);   // chain-YYYYMMDD_HHMMSS: newest first
			$surplus = array_slice(array_keys($chains), $keep);
			foreach ($surplus as $chain) {
				if (isset($busy[$chain])) {
					continue;
				}
				try {
					foreach ($chains[$chain] as $object) {
						$this->delete_object($creds, $bucket, (string)$object->get('svo_key'));
					}
					// Only once every object is gone do the rows go: a half-deleted
					// chain must keep looking like one that still needs deleting.
					foreach ($chains[$chain] as $object) {
						$object->permanent_delete();
					}
					$pruned++;
				} catch (\Throwable $e) {
					$this->errors[] = $this->label($row) . ': retention of ' . $profile . '/' . $chain . ' failed: ' . $e->getMessage();
					error_log('ServiceTenantWatch: retention of ' . $chain . ' for ' . $this->label($row) . ' failed: ' . $e->getMessage());
				}
			}
		}
		if ($pruned) {
			ShelfBroker::refreshFigure($row);
		}
		return $pruned;
	}

	/** The prune-after day has come for a stopped tenant: the whole prefix goes, once. */
	private function prune_if_due(ServiceTenant $row, string $now): int {
		$after = trim((string)$row->get('svt_prune_after_time'));
		if ($after === '' || $after > $now || $row->get('svt_pruned_time') !== null) {
			return 0;
		}
		$state = (string)$row->get('svt_state');
		if ($state !== ServiceTenant::STATE_SUSPENDED && $state !== ServiceTenant::STATE_RELEASED) {
			return 0;
		}
		list($target, $creds, $bucket, $base) = $this->shelf_of($row);
		if ($target === null) {
			return 0;
		}
		$deleted = 0;
		foreach (S3Signer::list($creds, $bucket, $base) as $object) {
			$key = (string)($object['key'] ?? '');
			if ($key === '' || strpos($key, $base) !== 0) {
				continue;
			}
			$this->delete_object($creds, $bucket, $key);
			$deleted++;
		}
		$ledger = new MultiShelfObject(array('tenant_id' => (int)$row->key, 'deleted' => false));
		foreach ($ledger as $object) {
			$object->permanent_delete();
		}
		$row->set('svt_figure', 0);
		$row->set('svt_figure_time', $now);
		$row->set('svt_pruned_time', $now);
		$row->set('svt_notice', 'The getjoinery backup storage copies of this site were pruned on ' . substr($now, 0, 10)
			. ', ' . ServiceTenant::RETENTION_DAYS . ' days after the service stopped.');
		$row->save();
		error_log('ServiceTenantWatch: pruned ' . $deleted . ' object(s) from backup storage of ' . $this->label($row) . '.');
		return 1;
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/** The chains kept per profile on every active tenant's backup storage. */
	public static function keep_chains(): int {
		$raw = trim((string)Globalvars::get_instance()->get_setting('server_manager_services_shelf_keep_chains', false, true));
		return $raw === '' ? 4 : max(1, intval($raw));
	}

	/** The chain segment of a key under the tenant prefix, or ''. */
	public static function chain_of_key(string $key, string $base): string {
		$parts = explode('/', substr($key, strlen($base)));
		return count($parts) >= 3 ? (string)$parts[1] : '';
	}

	private function delete_object(array $creds, string $bucket, string $key): void {
		$resp = S3Signer::delete($creds, $bucket, '/' . ltrim($key, '/'));
		$status = (int)($resp['status'] ?? 0);
		if (($status < 200 || $status >= 300) && $status !== 404) {
			throw new RuntimeException('HTTP ' . $status . ' deleting ' . $key);
		}
	}

	/** [target, creds, bucket, tenant prefix], or [null, …] when the plane has no usable shelf. */
	private function shelf_of(ServiceTenant $row): array {
		$target = JoineryServices::shelfTarget();
		if ($target === null) {
			return array(null, array(), '', '');
		}
		try {
			$creds = (array)$target->get_credentials();
		} catch (\Throwable $e) {
			$this->errors[] = 'the backup storage credential cannot be read: ' . $e->getMessage();
			return array(null, array(), '', '');
		}
		$bucket = trim((string)$target->get('bkt_bucket'));
		if ($bucket === '' || empty($creds['access_key'])) {
			return array(null, array(), '', '');
		}
		return array($target, $creds, $bucket, ShelfBroker::tenantPrefix($row, $target));
	}

	private function mail_client(): ?Smtp2GoClient {
		if ($this->client === false) {
			$this->client = Smtp2GoClient::fromSettings();
		}
		return $this->client;
	}

	private function label(ServiceTenant $row): string {
		return (string)$row->get('svt_service') . ' tenant ' . (string)$row->get('svt_slug')
			. ' (' . (string)$row->get('svt_host') . ')';
	}
}

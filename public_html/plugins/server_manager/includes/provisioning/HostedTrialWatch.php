<?php
/**
 * HostedTrialWatch — the commercial half of a hosted site, worked each tick.
 *
 * The mail leg builds a hosted site's sending. This watches what happens to it
 * afterwards: whether a trial is running out, whether an allowance is nearly
 * gone, whether a payment failed and what falls due if it stays failed
 * (specs/hosted_trial_provisioning.md §4.6, §6, §7).
 *
 * Five things happen here, and nothing else:
 *
 *   1. A hosted provision that finished gets its billing row — on trial if a
 *      trial is configured, subscribed from day one if not.
 *   2. Every live row's banner is composed and, WHEN IT CHANGES, pushed to the
 *      site as managed settings. When it changes, not every tick: a banner that
 *      dispatched a job every fifteen minutes forever would be a load, a job
 *      log nobody can read, and no better a banner.
 *   3. A grace period that ran out shuts the instance down and raises a
 *      deletion task for a person.
 *   4. A shelf whose keep-period ran out is pruned.
 *   5. Once per billing period, the operator is told if the ACCOUNT's outbound
 *      transfer pool is nearly spent.
 *
 * THE PLATFORM NEVER DELETES A CLOUD INSTANCE. Shutdown is the strongest
 * automatic action there is; the deletion that actually stops the bill is a
 * person at the provider, and this raises a signal asking for it. A billing
 * fact should not be able to destroy somebody's data unattended, and this is
 * where that rule is either kept or quietly lost.
 *
 * THE GRACE CLOCK ITSELF IS NOT SET HERE. It is set by the store's own signals
 * (HostedTrialSignals), because whether a subscription is paying is the store's
 * fact. This only acts on the dates that clock wrote.
 *
 * WHAT IS NOT DONE HERE, ON PURPOSE: nothing about sending health. The mail
 * provider counts a customer's sends against their subaccount's monthly limit
 * and refuses past it, and its own abuse controls act on bounces and
 * complaints. Its webhook events reach this plane only to move the banner's
 * "sent this month" figure. A second enforcement here would be built on
 * unsigned events and would duplicate a decision the provider already makes
 * with better information.
 *
 * @version 1.1
 */

class HostedTrialWatch {

	/** Percentage of an allowance at which the customer's banner warns. */
	const WARN_PERCENT = 80;

	/** Percentage of the account transfer pool at which the OPERATOR is told. */
	const TRANSFER_WARN_PERCENT = 80;

	/** The job type carrying a site's hosting banner to it. */
	const JOB_TYPE = 'hosted_plan_notice';

	/** @var array Human-readable problems for the run summary. */
	private $errors = array();

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/hosted_trial_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionHostedMail.php'));
		// Named explicitly: classes under a plugin's includes/provisioning/ are
		// not on the name-resolution path, and the operator token this watch
		// shuts an instance down with lives on that class.
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionCustomerCloud.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/HostedTrialSignals.php'));
		require_once(PathHelper::getIncludePath('includes/SignalBus.php'));

		$provisions = new MultiCustomerCloudProvision(array(
			'hosting_mode' => 'operator',
			'status'       => 'done',
			'deleted'      => false,
		));
		$provisions->load();
		if (count($provisions) === 0) {
			return array('status' => 'skipped', 'message' => 'No hosted sites to watch.');
		}

		$acted = 0;
		foreach ($provisions as $provision) {
			try {
				$acted += $this->watch($provision);
			} catch (Throwable $e) {
				$this->errors[] = $provision->get('cvp_domain') . ': ' . $e->getMessage();
				error_log('HostedTrialWatch: ' . $provision->get('cvp_domain') . ': ' . $e->getMessage());
			}
		}

		try {
			$acted += $this->watch_transfer_pool();
		} catch (Throwable $e) {
			$this->errors[] = 'transfer pool: ' . $e->getMessage();
		}

		$message = 'Hosted sites: ' . $acted . ' change(s) across ' . count($provisions) . ' site(s).';
		if ($this->errors) {
			$message .= ' ' . count($this->errors) . ' problem(s): ' . implode('; ', array_slice($this->errors, 0, 3));
			if (count($this->errors) > 3) { $message .= ' …'; }
			return array('status' => 'error', 'message' => $message);
		}
		return array('status' => 'success', 'message' => $message);
	}

	/** One site, one tick. Returns how many things changed. */
	private function watch($provision): int {
		$trial = HostedTrial::for_provision($provision->key);
		if ($trial === null) {
			$this->open_row($provision, self::trial_days());
			return 1;
		}
		if ((string)$trial->get('htr_state') === HostedTrial::STATE_SHUTDOWN) {
			return $this->prune_shelf_if_due($provision, $trial);
		}

		$changed = 0;
		// Before anything reads it: a month rolls whether or not a webhook
		// arrives to notice it, and a banner showing last month's sends against
		// this month's allowance is wrong for the whole of a quiet first week.
		$changed += $this->roll_count($trial);
		$changed += $this->convert_if_trial_ended($trial);
		$changed += $this->enforce_storage_allowance($provision, $trial);
		$changed += $this->shut_down_if_due($provision, $trial);
		$changed += $this->push_banner($provision, $trial);
		return $changed;
	}

	// ── 1. The billing row ────────────────────────────────────────────────────

	/**
	 * A hosted site's first day.
	 *
	 * With a trial configured the row opens on trial, ending when the store
	 * says the subscription's first period does or, failing a date from the
	 * store, after the configured length — a date that is roughly right beats
	 * no date on a banner whose whole job is to say when something happens.
	 * With no trial the subscription has been charging since checkout and the
	 * row opens subscribed: there is nothing to count down to, so nothing is
	 * invented.
	 */
	private function open_row($provision, int $trial_days): void {
		$trial = new HostedTrial(NULL);
		$trial->set('htr_cvp_provision_id', (int)$provision->key);
		$trial->set('htr_external_order_item_id', $provision->get('cvp_external_order_item_id'));
		if ($trial_days > 0) {
			$trial->set('htr_state', HostedTrial::STATE_TRIAL);
			$trial->set('htr_trial_ends_time', $this->trial_end_for($provision, $trial_days));
		} else {
			$trial->set('htr_state', HostedTrial::STATE_SUBSCRIBED);
		}
		$trial->set('htr_counts_reset_time', gmdate('Y-m-d H:i:s'));
		$trial->prepare();
		$trial->save();
		error_log('HostedTrialWatch: hosting started for ' . $provision->get('cvp_domain')
			. ($trial_days > 0
				? '; the trial ends ' . $trial->get('htr_trial_ends_time') . ' UTC.'
				: '; billed from the first day.'));
	}

	/** The configured trial length in days. Zero means hosting is billed from checkout. */
	public static function trial_days(): int {
		return max(0, (int)Globalvars::get_instance()->get_setting('server_manager_hosted_trial_days', true, true));
	}

	/** When the free period ends, from the store's own dates where there are any. */
	private function trial_end_for($provision, int $days): string {
		$order_item_id = (int)$provision->get('cvp_external_order_item_id');
		if ($order_item_id && class_exists('OrderItem')) {
			try {
				$item = new OrderItem($order_item_id, TRUE);
				$end = trim((string)$item->get('odi_subscription_period_end'));
				if ($item->key && $end !== '') {
					return $end;
				}
			} catch (Throwable $e) {
				// The store lives on another site in some deployments. The
				// configured length is the answer then, and it is a good one.
			}
		}
		return gmdate('Y-m-d H:i:s', time() + ($days * 86400));
	}

	/**
	 * Roll the month's sending count when the calendar has moved on.
	 *
	 * The webhook resets it too, on the first event of a new month — but a
	 * customer who sends nothing in the first week of a month would otherwise
	 * carry last month's total on their banner. Both places reset; whichever
	 * gets there first is right, and neither depends on the other having run.
	 */
	private function roll_count($trial): int {
		$reset = trim((string)$trial->get('htr_counts_reset_time'));
		if ($reset !== '' && gmdate('Y-m', strtotime($reset . ' UTC')) === gmdate('Y-m')) {
			return 0;
		}
		$trial->set('htr_sent_count', 0);
		$trial->set('htr_counts_reset_time', gmdate('Y-m-d H:i:s'));
		$trial->save();
		return 1;
	}

	/**
	 * The free period ended and nothing went wrong: the customer is a
	 * subscriber.
	 *
	 * Nothing else in the platform says so. The store dispatches
	 * subscription.payment_recovered only for an item that was actually in
	 * trouble, and a first successful charge at the end of a trial is not that
	 * — so a site left to itself would sit at `trial` forever with a banner
	 * counting down to a date that has passed. The failure path is unaffected:
	 * a charge that failed has already moved this row to `grace`, and grace is
	 * not a state this converts out of.
	 */
	private function convert_if_trial_ended($trial): int {
		if ((string)$trial->get('htr_state') !== HostedTrial::STATE_TRIAL) {
			return 0;
		}
		$ends = trim((string)$trial->get('htr_trial_ends_time'));
		if ($ends === '' || strtotime($ends . ' UTC') > time()) {
			return 0;
		}
		$trial->set('htr_state', HostedTrial::STATE_SUBSCRIBED);
		$trial->save();
		return 1;
	}

	// ── 2. The banner ─────────────────────────────────────────────────────────

	/**
	 * Compose what this site's admins should see, and push it if it differs
	 * from what they are already seeing.
	 *
	 * The digest is over the composed VALUES, so a change of a single
	 * percentage point does dispatch a job — which is right: the numbers are
	 * the reason anyone reads the thing. What it stops is the identical banner
	 * being re-sent every quarter of an hour for the life of the site.
	 */
	private function push_banner($provision, $trial): int {
		$node = $this->node_of($provision);
		if ($node === null) {
			return 0;
		}
		$settings = $this->banner_settings($provision, $trial);
		$digest = hash('sha256', json_encode($settings));

		// THE DIGEST RECORDS WHAT THE SITE IS ACTUALLY SHOWING, so it is stamped
		// when the node answers — not when the job is filed. Stamped at dispatch,
		// a refused push would be remembered as delivered and nothing would ever
		// retry it: for a subscribed site with steady allowances the values never
		// change again, so "never" is the honest length of that wait.
		$mine = ManagementJob::latestForNode($node->key, self::JOB_TYPE);
		if ($mine && (string)$mine->get('mjb_status') === 'completed'
				&& (string)$trial->get('htr_pushed_digest') !== $digest
				&& (string)$this->job_digest($mine) === $digest) {
			// The node has this exact banner. Record it and stop re-sending.
			$trial->set('htr_pushed_digest', $digest);
			$trial->set('htr_pushed_time', gmdate('Y-m-d H:i:s'));
			$trial->save();
			return 1;
		}
		if ($digest === (string)$trial->get('htr_pushed_digest')) {
			return 0;
		}
		// One in flight at a time: two banner pushes queued together would
		// arrive in an order nobody chose, and the older one would land last.
		if ($mine && in_array((string)$mine->get('mjb_status'),
				array('queued', 'pending', 'running'), true)) {
			return 0;
		}
		// Anything else — a failed push, or none at all — means the site is not
		// showing this banner, so one is filed. A failure is an attempt, not an
		// outcome.
		try {
			$built = JobCommandBuilder::build_hosted_plan_notice($node, $settings);
		} catch (Throwable $e) {
			// An agent too old to carry the primitive. Worth saying once on the
			// row, never worth an alert per tick: the site works, its owner
			// simply is not being told about their hosting.
			$trial->set('htr_note', 'The site cannot show its hosting banner yet — ' . $e->getMessage());
			$trial->save();
			return 0;
		}
		ManagementJob::createFromBuild($node->key, self::JOB_TYPE, $built,
			array('provision_id' => (int)$provision->key, 'digest' => $digest), null);
		return 1;
	}

	/** The banner digest a filed notice job carries, or ''. */
	private function job_digest($job): string {
		$params = $job->get('mjb_parameters');
		if (is_string($params)) { $params = json_decode($params, true); }
		return is_array($params) ? (string)($params['digest'] ?? '') : '';
	}

	/**
	 * The five VALUES a hosted site's banner renders from.
	 *
	 * Values, not setting names: which settings they land in is decided by
	 * utils/hosted_plan_notice.php on the node.
	 */
	public function banner_settings($provision, $trial): array {
		$state = (string)$trial->get('htr_state');
		$until = '';
		switch ($state) {
			case HostedTrial::STATE_TRIAL: $until = (string)$trial->get('htr_trial_ends_time'); break;
			case HostedTrial::STATE_GRACE: $until = (string)$trial->get('htr_grace_ends_time'); break;
		}
		$settings = Globalvars::get_instance();
		// One sentence, carrying whatever has actually been done to this site.
		// A paused shelf is not a billing state, and a pause the customer is
		// never told about is a backup that silently stops.
		return array(
			'state'      => $state,
			'until_time' => $until,
			'notice'     => $this->storage_pause_notice($provision),
			'allowances' => json_encode($this->allowances($provision, $trial)),
			'manage_url' => trim((string)$settings->get_setting('server_manager_hosted_manage_url')),
		);
	}

	/**
	 * Each allowance, what is used of it, and the ONE action for that service.
	 *
	 * The action is always "open your own account", never a bigger plan —
	 * there is no bigger plan, and a customer who has outgrown the hosting is
	 * better served by their own provider. Each figure comes from the party
	 * that actually counts it: the mail provider counts sends, the retention
	 * pass sizes the shelf, the node reports its own disk. None of them is
	 * copied into a meter here that could disagree with the thing that decides.
	 */
	public function allowances($provision, $trial): array {
		$settings = Globalvars::get_instance();
		$out = array();

		$send_allowance = ProvisionHostedMail::send_allowance();
		$sent = (int)$trial->get('htr_sent_count');
		$out[] = array(
			'label'        => 'Email sent this month',
			'used'         => number_format($sent),
			'allowance'    => number_format($send_allowance),
			'percent'      => $send_allowance > 0 ? (int)round(100 * $sent / $send_allowance) : 0,
			'action_label' => 'Use your own email account',
			'action_url'   => trim((string)$settings->get_setting('server_manager_smtp2go_referral_url')),
		);

		$node = $this->node_of($provision);
		$shelf_allowance_gb = max(1, (int)$settings->get_setting('server_manager_hosted_shelf_allowance_gb', true, true));
		$shelf_bytes = $node ? (int)$node->get('mgn_backup_shelf_bytes') : 0;
		$shelf_gb = $shelf_bytes / 1073741824;
		$out[] = array(
			'label'        => 'Backups stored',
			'used'         => self::gb($shelf_gb),
			'allowance'    => $shelf_allowance_gb . ' GB',
			'percent'      => (int)round(100 * $shelf_gb / $shelf_allowance_gb),
			'action_label' => 'Use your own storage',
			'action_url'   => trim((string)$settings->get_setting('server_manager_storage_referral_url')),
		);

		// Disk is the node's own figure, already badged on its overview. There
		// is no action here that is not "move to your own server", and a full
		// disk stops the site on its own long before anything we could do.
		$disk = $node ? self::disk_percent($node) : null;
		if ($disk !== null) {
			$out[] = array(
				'label'        => 'Disk used',
				'used'         => $disk . '%',
				'allowance'    => '100%',
				'percent'      => $disk,
				'action_label' => 'Move to your own server',
				'action_url'   => trim((string)$settings->get_setting('server_manager_linode_referral_url')),
			);
		}
		return $out;
	}

	// ── 3. The storage allowance ──────────────────────────────────────────────

	/**
	 * A shelf over its allowance stops being extended.
	 *
	 * This is the one limit with no provider backstop — no storage provider
	 * caps a prefix — so it is enforced here, and enforced by switching the
	 * node's fleet policy off with the reason recorded. Not by deleting
	 * anything: the customer's existing backups are their backups, and the
	 * answer to "you are using too much" is never "so we removed some".
	 */
	private function enforce_storage_allowance($provision, $trial): int {
		$node = $this->node_of($provision);
		if ($node === null) {
			return 0;
		}
		$allowance_gb = max(1, (int)Globalvars::get_instance()->get_setting(
			'server_manager_hosted_shelf_allowance_gb', true, true));
		$used_gb = ((int)$node->get('mgn_backup_shelf_bytes')) / 1073741824;

		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetBackupPolicy.php'));
		$stored_mode = FleetBackupPolicy::stored_mode($node);

		if ($used_gb >= $allowance_gb && $stored_mode !== 'off') {
			$node->set('mgn_backup_policy', json_encode(array('enabled' => false, 'paused_for_shelf' => true)));
			$node->save();
			$trial->set('htr_note', 'Backups paused on ' . gmdate('Y-m-d') . ': shelf at '
				. self::gb($used_gb) . ' of ' . $allowance_gb . ' GB.');
			$trial->save();
			error_log('HostedTrialWatch: fleet backups paused for ' . $provision->get('cvp_domain')
				. ' — shelf at ' . self::gb($used_gb) . ' of ' . $allowance_gb . ' GB.');
			return 1;
		}

		// And back on when the shelf comes down — retention prunes it every
		// cycle, so a customer who deletes a large upload gets their backups
		// back without asking. Only a pause THIS put there is lifted: a policy
		// somebody switched off deliberately stays off.
		if ($used_gb < $allowance_gb && self::paused_for_shelf($node)) {
			$node->set('mgn_backup_policy', null);
			$node->save();
			$trial->set('htr_note', null);
			$trial->save();
			error_log('HostedTrialWatch: fleet backups resumed for ' . $provision->get('cvp_domain')
				. ' — shelf back to ' . self::gb($used_gb) . '.');
			return 1;
		}
		return 0;
	}

	/**
	 * Did THIS pause a node's backups, rather than a person?
	 *
	 * The marker is inside the stored policy, which FleetBackupPolicy ignores —
	 * it copies only keys it recognises — so it rides along without changing
	 * what the policy means. Without it, resuming would override an operator
	 * who switched a node off on purpose.
	 */
	public static function paused_for_shelf($node): bool {
		$stored = $node->get('mgn_backup_policy');
		if (is_string($stored)) { $stored = json_decode($stored, true); }
		return is_array($stored) && !empty($stored['paused_for_shelf']);
	}

	/**
	 * The sentence a customer's banner carries while their backups are paused
	 * for the shelf allowance, or ''.
	 */
	private function storage_pause_notice($provision): string {
		$node = $this->node_of($provision);
		if ($node === null || !self::paused_for_shelf($node)) {
			return '';
		}
		$allowance_gb = max(1, (int)Globalvars::get_instance()->get_setting(
			'server_manager_hosted_shelf_allowance_gb', true, true));
		return 'Offsite backups of this site are paused: it is using its whole '
			. $allowance_gb . ' GB backup allowance. They start again on their own once the '
			. 'shelf comes back under it, or move to your own storage to lift the limit.';
	}

	// ── 4. The end of a grace period ──────────────────────────────────────────

	/**
	 * The grace ran out. Power the instance off and ask a person to delete it.
	 *
	 * Powering off is the whole of what happens automatically. The instance
	 * keeps billing until somebody deletes it at the provider, and that cost is
	 * the price of the rule — a subscription lapsing must not be able to
	 * destroy a customer's machine without a person looking at it.
	 */
	private function shut_down_if_due($provision, $trial): int {
		if ((string)$trial->get('htr_state') !== HostedTrial::STATE_GRACE) {
			return 0;
		}
		$ends = trim((string)$trial->get('htr_grace_ends_time'));
		if ($ends === '' || strtotime($ends . ' UTC') > time()) {
			return 0;
		}

		$instance_id = trim((string)$provision->get('cvp_instance_id'));
		if ($instance_id !== '') {
			try {
				$token = ProvisionCustomerCloud::operator_compute_token();
				if ($token === '') {
					throw new Exception('no operator cloud token is configured');
				}
				require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));
				(new LinodeComputeDriver($token))->shutdownInstance($instance_id);
			} catch (Throwable $e) {
				// The date has passed and the machine is still on. Say so and
				// try again next tick — the deletion task below would be
				// misleading if it went out while the instance still ran.
				$this->errors[] = $provision->get('cvp_domain')
					. ': could not shut the instance down (' . $e->getMessage() . ').';
				return 0;
			}
		}

		$trial->set('htr_state', HostedTrial::STATE_SHUTDOWN);
		$trial->set('htr_shutdown_time', gmdate('Y-m-d H:i:s'));
		$trial->save();

		// A powered-off machine is not a broken one, and everything that watches
		// nodes has to be told the difference. Left as it was, this node would
		// fail its fleet backup every cycle, hold a pending run forever, and
		// trip a down-alert every few minutes — burying the one thing that IS
		// actionable (the deletion below) under noise about a machine somebody
		// turned off on purpose.
		$node = $this->node_of($provision);
		if ($node !== null) {
			try {
				$node->set('mgn_backup_policy', json_encode(array('enabled' => false)));
				$node->set('mgn_uptime_enabled', false);
				$node->set('mgn_notes', trim((string)$node->get('mgn_notes') . "\n"
					. 'Hosting ended unpaid on ' . gmdate('Y-m-d') . '; instance powered off and '
					. 'awaiting deletion at the provider. Backups and uptime checks switched off here.'));
				$node->save();
			} catch (Throwable $e) {
				$this->errors[] = $provision->get('cvp_domain')
					. ': the instance is off but its monitoring could not be quietened (' . $e->getMessage() . ').';
			}
		}

		SignalBus::dispatch('hosted.deletion_required', array(
			'provision_id'    => (int)$provision->key,
			'domain'          => (string)$provision->get('cvp_domain'),
			'instance_id'     => $instance_id,
			'buyer_email'     => (string)$provision->get('cvp_buyer_email'),
			'shelf_ends_time' => (string)$trial->get('htr_shelf_ends_time'),
		));
		error_log('HostedTrialWatch: ' . $provision->get('cvp_domain')
			. ' shut down after its grace period; deletion is owed to a person at the provider.');
		return 1;
	}

	// ── 5. The shelf, afterwards ──────────────────────────────────────────────

	/**
	 * Prune a shut-down customer's shelf once the keep-period is up.
	 *
	 * Between the shutdown and this, a returning customer is recoverable: a
	 * fresh install plus restore-over-agent, which needs THEIR recovery key.
	 * After it they are not, which is why the date is on their banner from the
	 * day the payment failed rather than announced when it arrives.
	 */
	private function prune_shelf_if_due($provision, $trial): int {
		$ends = trim((string)$trial->get('htr_shelf_ends_time'));
		if ($ends === '' || strtotime($ends . ' UTC') > time()) {
			return 0;
		}
		$node = $this->node_of($provision);
		if ($node === null) {
			return 0;
		}
		$target = JobCommandBuilder::get_target($node);
		if (!$target) {
			return 0;
		}
		// keep = 0 is not expressible through prune(), which floors at one on
		// purpose. Emptying a shelf is a different act from trimming one, and
		// it is done here, explicitly, with the plane's own credential.
		require_once(PathHelper::getIncludePath('includes/S3Signer.php'));
		// The WHOLE slug prefix, both profiles. Everything under it is on this
		// operator's shelf and was kept under this operator's retention promise;
		// a customer who pointed their own backups at their own bucket has
		// nothing here to lose.
		$creds  = $target->get_credentials();
		$bucket = trim((string)$target->get('bkt_bucket'));
		$prefix = rtrim(trim((string)$target->get('bkt_path_prefix')) ?: 'joinery-backups', '/');
		$base   = $prefix . '/' . trim((string)$node->get('mgn_slug')) . '/';
		if ($bucket === '' || empty($creds)) {
			return 0;
		}
		$objects = S3Signer::list($creds, $bucket, $base);
		if (!is_array($objects)) {
			$this->errors[] = $provision->get('cvp_domain') . ': the shelf could not be listed for pruning.';
			return 0;
		}
		$deleted = 0;
		foreach ($objects as $object) {
			$key = is_array($object) ? (string)($object['key'] ?? '') : (string)$object;
			if ($key === '' || strpos($key, $base) !== 0) { continue; }
			$resp = S3Signer::delete($creds, $bucket, '/' . ltrim($key, '/'));
			$status = (int)($resp['status'] ?? 0);
			if (($status >= 200 && $status < 300) || $status === 404) {
				$deleted++;
			}
		}
		$node->set('mgn_backup_shelf_bytes', 0);
		$node->set('mgn_backup_shelf_checked_time', gmdate('Y-m-d H:i:s'));
		$node->save();
		$trial->set('htr_shelf_ends_time', null);
		$trial->set('htr_note', 'The backup shelf was pruned on ' . gmdate('Y-m-d') . '.');
		$trial->save();
		error_log('HostedTrialWatch: pruned ' . $deleted . ' object(s) from the shelf of '
			. $provision->get('cvp_domain') . ' after its keep-period ended.');
		return 1;
	}

	// ── 6. The account's transfer pool ────────────────────────────────────────

	/**
	 * ONE alert, for the operator, when the ACCOUNT's outbound transfer is
	 * nearly spent.
	 *
	 * Account-wide because that is how it is billed: instances draw on one pool
	 * and overage is charged against the pool, so a per-customer figure would be
	 * a number with no bill behind it and an alarm per site would be the same
	 * fact said forty times. Once per billing period, because that is how often
	 * the fact changes in a way anyone can act on.
	 */
	private function watch_transfer_pool(): int {
		$settings = Globalvars::get_instance();
		$last = trim((string)$settings->get_setting('server_manager_hosted_transfer_alerted_time', false, true));
		if ($last !== '' && gmdate('Y-m', strtotime($last . ' UTC')) === gmdate('Y-m')) {
			return 0;   // already said, this period
		}
		$token = ProvisionCustomerCloud::operator_compute_token();
		if ($token === '') {
			return 0;
		}
		require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));
		$transfer = (new LinodeComputeDriver($token))->getTransfer();
		$quota = (float)($transfer['quota_gb'] ?? 0);
		$used  = (float)($transfer['used_gb'] ?? 0);
		if ($quota <= 0) {
			return 0;
		}
		$percent = (int)round(100 * $used / $quota);
		if ($percent < self::TRANSFER_WARN_PERCENT) {
			return 0;
		}
		SignalBus::dispatch('hosted.transfer_pool_high', array(
			'used_gb'  => round($used, 1),
			'quota_gb' => round($quota, 1),
			'percent'  => $percent,
		));
		Setting::put('server_manager_hosted_transfer_alerted_time', gmdate('Y-m-d H:i:s'));
		return 1;
	}

	// ── Shared ────────────────────────────────────────────────────────────────

	/** The provision's node, or null. */
	private function node_of($provision) {
		$id = (int)$provision->get('cvp_mgn_node_id');
		if (!$id) { return null; }
		$node = new ManagedNode($id, TRUE);
		return ($node->key && !$node->get('mgn_delete_time')) ? $node : null;
	}

	/** The node's own disk figure, from its last status check, or null. */
	public static function disk_percent($node) {
		$status = json_decode((string)$node->get('mgn_last_status_data'), true);
		if (!is_array($status)) {
			return null;
		}
		foreach (array('disk_usage_percent', 'disk_percent') as $key) {
			if (isset($status[$key]) && is_numeric($status[$key])) {
				return (int)round((float)$status[$key]);
			}
		}
		return null;
	}

	/** A gigabyte figure, said the way a person would. */
	private static function gb(float $gb): string {
		if ($gb < 0.1) {
			return round($gb * 1024) . ' MB';
		}
		return round($gb, 1) . ' GB';
	}
}

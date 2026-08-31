<?php
/**
 * ManagedDomainWatch - counts the domain down, and hands it over.
 *
 * The buyer legally owns their domain from the moment it is registered. What
 * still has to move is management and billing: while the name sits in the
 * operator's registrar account, its renewal bills the OPERATOR, and the
 * platform never fronts a renewal. So the domain has to reach the buyer's own
 * account before its first expiry, or it lapses.
 *
 * That deadline is the only reason this class exists, and it shapes every
 * decision in it:
 *
 *  - **Nothing is said for the first six months.** A buyer who just bought
 *    hosting does not need a chore. The first mention of ownership transfer is
 *    a notice on their own box at expiry minus six months, escalating as the
 *    date approaches.
 *  - **The prompts are in-product, not one email.** A single email can be
 *    missed; a notice on the site they administer cannot.
 *  - **There is no renewal call to make.** The registrar seam does not even
 *    expose one. This class watches and informs; the buyer renews in their own
 *    account, on their own card, once custody is theirs.
 *
 * Custody moves by the registrar's own account push, which at Namecheap has no
 * API — so the pipeline queues it as an operator task and this class watches
 * for the domain to leave the account. inAccount() returning false IS the
 * success signal.
 *
 * **The box is converged on, not pushed at.** The four notice settings have one
 * owner and one desired state: compute what the box should be holding, compare
 * it against the last notice job that actually completed, and dispatch only
 * when they differ. That is one check rather than a push fired from each of the
 * four places something changes, and it is what makes a failed push self-heal
 * on the next tick instead of leaving stale values on a customer's site until
 * the next expiry change happens to trigger another one.
 *
 * The prompt timestamp follows the same rule: rdm_prompt_pushed_time is stamped
 * from a notice job that COMPLETED, never from one that was dispatched. A
 * dispatch that then failed — the agent down that hour, the node missing the
 * primitive — would otherwise record as shown a prompt the buyer never saw, and
 * that prompt is their first mention of a deadline that takes their site and
 * their email with it if they miss it.
 *
 * @version 1.3 - the notice travels the agent channel as a job, and the watcher converges
 *                on desired state instead of firing four separate pushes
 * @version 1.2 - the sweep mark never steps past an order nobody was told about
 * @version 1.1 - sweeps for domain years paid for but never registered
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/DomainRegistrarRegistry.php'));

class ManagedDomainWatch {

	/** How often an expiry date is worth re-reading from the registrar. */
	const EXPIRY_REFRESH_DAYS = 7;

	/** The job type carrying the four notice values to a node. */
	const JOB_NOTICE = 'managed_domain_notice';

	/**
	 * How long after a notice job finished before another is dispatched.
	 *
	 * The converge check re-dispatches whenever the box does not match, which on
	 * a node that keeps failing the job would be one job per tick forever. The
	 * gap turns that into a slow retry: the values are not urgent — the earliest
	 * one matters six months before an expiry — and a node that cannot take them
	 * this minute will not be able to a minute later.
	 */
	const NOTICE_RETRY_GAP_MINUTES = 10;

	/** How many recent notice jobs one converge check reads. */
	const NOTICE_JOB_LOOKBACK = 5;

	/**
	 * How long a paid domain line is given to produce its registration row
	 * before it counts as unclaimed. The row is written in the same request as
	 * the charge, so this only has to outlast a slow one.
	 */
	const ORPHAN_SETTLE_MINUTES = 15;

	/** How many unclaimed lines one tick will look at. */
	const ORPHAN_SWEEP_LIMIT = 100;

	/** @var array Human-readable notes for the run summary. */
	private $notes = array();

	/** @var DomainRegistrarProvider|null */
	private $registrar = null;

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/registered_domains_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

		if ($this->get_registrar() === null) {
			return array('status' => 'skipped', 'message' => 'No registrar configured');
		}

		$worked = $this->sweep_unclaimed_domain_lines();

		$rows = new MultiRegisteredDomain(array(
			'status'  => RegisteredDomain::STATUS_ACTIVE,
			'deleted' => false,
		));
		$rows->load();
		if (count($rows) === 0 && $worked === 0) {
			return array('status' => 'skipped', 'message' => 'No active managed domains.');
		}

		foreach ($rows as $row) {
			try {
				$worked += $this->watch($row);
			} catch (Throwable $e) {
				$this->notes[] = $row->get('rdm_domain') . ': ' . $e->getMessage();
				error_log('ManagedDomainWatch: ' . $row->get('rdm_domain') . ': ' . $e->getMessage());
			}
		}

		if ($worked === 0 && empty($this->notes)) {
			return array('status' => 'skipped', 'message' => 'Nothing due.');
		}
		return array(
			'status'  => 'success',
			'message' => 'Domain watch: ' . $worked . ' update(s). ' . implode('; ', array_slice($this->notes, 0, 3)),
		);
	}

	/**
	 * One row. Returns the number of things that changed.
	 *
	 * A domain in the buyer's own account is finished as far as the REGISTRAR is
	 * concerned — no expiry to re-read, no custody to ask about, and not one API
	 * call spent on either. It is not finished as far as the BOX is concerned:
	 * self_custody is the value that makes the notice stop rendering, and if the
	 * job carrying it failed, the buyer is still being told to do something they
	 * have already done. So the converge check runs for every row, including
	 * this one, and costs a query when the box already matches.
	 */
	private function watch($row): int {
		$changed = 0;
		if ((string)$row->get('rdm_graduation_state') !== RegisteredDomain::GRAD_SELF) {
			$changed += $this->refresh_expiry($row);
			$changed += $this->check_custody($row);
		}
		$changed += $this->converge_notice($row);
		return $changed;
	}

	// ------------------------------------------------------------------

	/**
	 * Keep the expiry current, at most weekly.
	 *
	 * Weekly rather than every tick because the number moves once a year and
	 * every read spends the operator's registrar API quota — but often enough
	 * that a renewal made in the buyer's own account is noticed long before
	 * the countdown would have expired.
	 */
	private function refresh_expiry($row): int {
		$checked = trim((string)$row->get('rdm_expiry_checked_time'));
		if ($checked !== '') {
			$age = time() - (int)strtotime($checked . ' UTC');
			if ($age < self::EXPIRY_REFRESH_DAYS * 86400) {
				return 0;
			}
		}

		try {
			$expiry = $this->get_registrar()->getExpiry((string)$row->get('rdm_domain'));
		} catch (DomainRegistrarException $e) {
			$this->notes[] = $row->get('rdm_domain') . ': expiry read failed, will retry.';
			return 0;
		}

		$row->set('rdm_expiry_checked_time', gmdate('Y-m-d H:i:s'));
		if ($expiry !== null && $expiry !== (string)$row->get('rdm_expiry_time')) {
			$row->set('rdm_expiry_time', $expiry);
			$row->save();
			// The box is not told here. The converge check later in this same
			// tick sees the new date and dispatches — one author for the value,
			// and a push that failed is retried rather than lost.
			return 1;
		}
		$row->save();
		return 0;
	}

	/**
	 * Has the domain left the operator's account?
	 *
	 * Only asked once a push is in flight — before that the answer is always
	 * yes and the question costs API quota for nothing.
	 */
	private function check_custody($row): int {
		$state = (string)$row->get('rdm_graduation_state');
		if ($state !== RegisteredDomain::GRAD_REQUESTED && $state !== RegisteredDomain::GRAD_SENT) {
			return 0;
		}

		try {
			$still_ours = $this->get_registrar()->inAccount((string)$row->get('rdm_domain'));
		} catch (DomainRegistrarException $e) {
			$this->notes[] = $row->get('rdm_domain') . ': custody check failed, will retry.';
			return 0;
		}
		if ($still_ours) {
			return 0;
		}

		$row->set('rdm_graduation_state', RegisteredDomain::GRAD_SELF);
		$row->save();
		$this->send_self_custody_email($row);
		$this->notes[] = $row->get('rdm_domain') . ' is now in the buyer\'s own account.';
		return 1;
	}

	// ------------------------------------------------------------------
	// The other direction: paid for, never queued
	// ------------------------------------------------------------------

	/**
	 * Find domain years a buyer paid for that produced no registration.
	 *
	 * The pipeline's other guard runs from the domain row outward and refuses
	 * to register a name the order did not pay for. This is the mirror image,
	 * and it needs its own sweep because there is nothing to run from: a buyer
	 * who removes the HOSTING line from their cart and keeps the domain line
	 * pays for a domain year whose intake never fires, so no row is ever
	 * written and no queue ever shows it. Left alone that is money taken for
	 * nothing, invisible to everyone.
	 *
	 * The signature is arithmetic on the order: more paid domain-year lines
	 * than registration rows. A high-water mark over order-item ids makes the
	 * alert once-only, and the sweep stops at the first line too young to have
	 * settled, so nothing is skipped past.
	 *
	 * Every non-deleted registration row counts, whatever its status — the
	 * question here is only whether the intake ever fired. A row sitting at
	 * pending or parked at failed has already been seen by the pipeline and
	 * has its own queue entry; reporting it again from this side would be a
	 * second voice on a problem somebody is already looking at.
	 *
	 * @return int alerts sent.
	 */
	private function sweep_unclaimed_domain_lines(): int {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ProvisioningSetup.php'));
		require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));

		$settings = Globalvars::get_instance();
		$product_id = (int)$settings->get_setting('store_domain_registration_product_id', false, true);
		if ($product_id <= 0) {
			return 0;   // the store sells no domain years here
		}

		// Straight from the table, not the request-cached singleton: the mark is
		// advanced by this same method, and a cached read would let a second
		// sweep in one process re-report everything the first one just did.
		$watermark = (int)ProvisioningSetup::readSetting('server_manager_domain_orphan_swept_id');

		try {
			$db = DbConnector::get_instance()->get_db_link();
			// One order at a time, because the question is per-order arithmetic:
			// a cart with two hosting items pays for two domains and must file
			// two rows. Expressed as a join because no model spans the store's
			// order items and this plugin's registrations.
			//
			// The arithmetic assumes an order's domain lines are paid together
			// and therefore age together: paid_lines counts only lines past the
			// watermark, while filed_rows counts every registration on the
			// order, so lines of one order straddling the settle boundary on
			// different ticks would undercount and hide an orphan. True today —
			// one charge, one timestamp — but a partial-payment feature would
			// break it silently, so it is written down rather than assumed.
			$settle = (int)self::ORPHAN_SETTLE_MINUTES;
			$q = $db->prepare(
				"SELECT d.odi_ord_order_id AS order_id,
				        count(*) AS paid_lines,
				        max(d.odi_order_item_id) AS max_item_id,
				        (SELECT count(*) FROM rdm_registered_domains r
				         JOIN odi_order_items p ON p.odi_order_item_id = r.rdm_external_order_item_id
				         WHERE p.odi_ord_order_id = d.odi_ord_order_id
				           AND r.rdm_delete_time IS NULL) AS filed_rows
				 FROM odi_order_items d
				 WHERE d.odi_pro_product_id = :product_id
				   AND d.odi_status = :paid
				   AND d.odi_order_item_id > :watermark
				   AND d.odi_status_change_time < now() - interval '{$settle} minutes'
				 GROUP BY d.odi_ord_order_id
				 ORDER BY max_item_id ASC
				 LIMIT :sweep_limit");
			$q->bindValue(':product_id', $product_id, PDO::PARAM_INT);
			$q->bindValue(':paid', OrderItem::STATUS_PAID, PDO::PARAM_INT);
			$q->bindValue(':watermark', $watermark, PDO::PARAM_INT);
			$q->bindValue(':sweep_limit', self::ORPHAN_SWEEP_LIMIT, PDO::PARAM_INT);
			$q->execute();
			$orders = $q->fetchAll(PDO::FETCH_ASSOC);
		} catch (Throwable $e) {
			error_log('ManagedDomainWatch: unclaimed-line sweep failed: ' . $e->getMessage());
			return 0;
		}

		$alerts = 0;
		$highest = $watermark;
		foreach ($orders as $order) {
			$unclaimed = (int)$order['paid_lines'] - (int)$order['filed_rows'];
			if ($unclaimed > 0 && !$this->alert_unclaimed_order((int)$order['order_id'], $unclaimed)) {
				// The alert did not go — no recipient configured, or the send
				// failed. Stop here WITHOUT advancing: the mark is what makes
				// this report once-only, so stepping over an order nobody was
				// told about would lose it permanently, which is the exact
				// failure this whole sweep exists to prevent. Rows are handled
				// in ascending id order, so stopping leaves this order and
				// everything after it for the next tick, with no duplicates.
				break;
			}
			if ($unclaimed > 0) {
				$alerts++;
			}
			$highest = max($highest, (int)$order['max_item_id']);
		}

		// Advance only past orders that were fully handled. Anything newer was
		// too young to judge, beyond this tick's limit, or left behind by a
		// failed alert — each gets its turn next time.
		if ($highest > $watermark) {
			try {
				Setting::put('server_manager_domain_orphan_swept_id', (string)$highest);
			} catch (Throwable $e) {
				error_log('ManagedDomainWatch: could not advance the sweep watermark: ' . $e->getMessage());
			}
		}
		if ($alerts > 0) {
			$this->notes[] = $alerts . ' order(s) paid for a domain that was never registered.';
		}
		return $alerts;
	}

	/** Tell the operator about one order that paid for a domain it never got. */
	protected function alert_unclaimed_order(int $order_id, int $unclaimed): bool {
		$to = self::resolve_alert_recipient();
		if ($to === '') {
			error_log('ManagedDomainWatch: order ' . $order_id . ' paid for ' . $unclaimed
				. ' unregistered domain(s) and no alert recipient is configured.');
			return false;
		}

		// The name the buyer asked for, if any line on the order recorded one.
		$names = array();
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare(
				'SELECT DISTINCT r.oir_answer
				 FROM oir_order_item_requirements r
				 JOIN odi_order_items i ON i.odi_order_item_id = r.oir_odi_order_item_id
				 WHERE i.odi_ord_order_id = ? AND r.oir_label = ?');
			$q->execute(array($order_id, 'Registered domain'));
			$names = $q->fetchAll(PDO::FETCH_COLUMN) ?: array();
		} catch (Throwable $e) {
			error_log('ManagedDomainWatch: could not read the domain name for order '
				. $order_id . ': ' . $e->getMessage());
		}

		$body = "An order paid for a domain registration that never reached the queue.\n\n"
			. 'Order: ' . $order_id . "\n"
			. 'Unregistered domain years paid for: ' . $unclaimed . "\n"
			. 'Name(s) asked for: ' . ($names ? implode(', ', $names) : 'none recorded') . "\n\n"
			. "The usual cause is the hosting line being removed from the cart while the domain\n"
			. "line stayed in it, so nothing ever asked for the registration. Nothing is registered\n"
			. "and nothing will retry on its own: refund the domain line, or register the name and\n"
			. "file the row by hand.\n\n"
			. "This is reported once per order.\n";
		try {
			EmailSender::quickSend($to, '[managed-domain] Paid but never registered: order ' . $order_id, $body);
			return true;
		} catch (Throwable $e) {
			error_log('ManagedDomainWatch: unclaimed-order alert send failed: ' . $e->getMessage());
			return false;
		}
	}

	/** provisioning_admin_alert_email -> webmaster_email -> first superadmin. */
	private static function resolve_alert_recipient(): string {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionManagedDomains.php'));
		return ProvisionManagedDomains::resolve_alert_recipient();
	}

	// ------------------------------------------------------------------
	// The node notice
	// ------------------------------------------------------------------

	/**
	 * Make the box hold the four facts it should be holding.
	 *
	 * A desired-state check, not a push. It computes what the notice on this
	 * buyer's own site ought to be saying, compares that against the last notice
	 * job that actually COMPLETED for this node and this domain, and dispatches
	 * one only when they differ or none exists.
	 *
	 * That single check replaced four push sites — activation, an expiry
	 * refresh, custody moving, and the six-month prompt. Each of them fired at
	 * the moment something changed and had no idea whether it landed, so a push
	 * that failed left stale values on a customer's site until the next change
	 * happened to trigger another one. Here a failure just means the box still
	 * does not match, and the next tick tries again.
	 *
	 * @return int 1 when something moved: the prompt was recorded as seen, or a
	 *             fresh notice was dispatched.
	 */
	protected function converge_notice($row): int {
		$node_id = (int)$row->get('rdm_mgn_node_id');
		if ($node_id <= 0) {
			return 0;   // nothing built yet; there is no box to tell
		}
		$node = new ManagedNode($node_id, TRUE);
		if (!$node->key) {
			return 0;
		}

		$domain = (string)$row->get('rdm_domain');
		$jobs   = $this->notice_jobs($node_id, $domain);
		$latest = $jobs ? $jobs[0] : null;
		$landed = null;
		foreach ($jobs as $job) {
			if ($job['mjb_status'] === 'completed') { $landed = $job; break; }
		}

		$changed = 0;

		// THE PROMPT IS RECORDED FROM A JOB THAT COMPLETED. A dispatch means
		// queued, and a queued job that then failed would record as shown a
		// prompt the buyer never saw — of a deadline that takes their site and
		// their email with it.
		//
		// And only from a job carrying a state that RENDERS one. self_custody is
		// a real state the box is told, and it is the state that makes the
		// notice stop: a buyer whose domain moved into their own account before
		// the six-month mark was never prompted, and recording one would be
		// recording a thing that never happened.
		if ($landed && !trim((string)$row->get('rdm_prompt_pushed_time'))
				&& self::state_prompts((string)(self::job_params($landed)['state'] ?? ''))) {
			$row->set('rdm_prompt_pushed_time',
				(string)($landed['mjb_completed_time'] ?: gmdate('Y-m-d H:i:s')));
			$row->save();
			$this->notes[] = $domain . ': take-ownership prompt is now live on the box.';
			$changed = 1;
		}

		// Computed after the stamp: the stamp is one of its inputs — a prompt
		// already shown keeps showing even once the window arithmetic would say
		// otherwise.
		$desired = self::desired_notice($row);

		if ($latest && in_array($latest['mjb_status'], array('pending', 'running'), true)) {
			return $changed;   // asked; waiting
		}
		if ($landed && self::notice_matches($landed, $desired)) {
			return $changed;   // the box already holds it
		}
		if ($latest) {
			$since = strtotime((string)($latest['mjb_completed_time'] ?: $latest['mjb_create_time']));
			if ($since && (time() - $since) < self::NOTICE_RETRY_GAP_MINUTES * 60) {
				return $changed;   // tried recently; a slow retry, not a per-tick one
			}
		}

		return $changed + $this->dispatch_notice($node, $desired);
	}

	/**
	 * What this box should be holding, from the row.
	 *
	 * Before the six-month threshold the custody state is deliberately EMPTY:
	 * the box holds the domain and its expiry and says nothing. That is the
	 * whole restraint of this feature — a buyer who just bought hosting does not
	 * need a chore — and empty is a real value the node writes, not an omission,
	 * so a state pushed early can be taken back.
	 *
	 * A prompt already shown stays shown. Once a buyer has been told, the notice
	 * does not disappear because a renewal moved the date back out of the
	 * window; the thing they were asked to do is still outstanding.
	 */
	public static function desired_notice($row): array {
		$state = ($row->get('rdm_prompt_pushed_time') || $row->in_prompt_window())
			? (string)$row->get('rdm_graduation_state') : '';
		return array(
			'domain'      => (string)$row->get('rdm_domain'),
			'expiry_time' => self::notice_expiry($row),
			'state'       => $state,
			'manage_url'  => self::manageUrl(),
		);
	}

	/**
	 * The expiry in the shape the notice carries: a date, optionally with a
	 * time.
	 *
	 * Trimmed by pattern rather than reparsed. rdm_expiry_time is a
	 * timestamp(6), so it can come back carrying fractional seconds that the
	 * node's parameter spec refuses — and running it through strtotime to
	 * reformat would read a UTC-stored naive string in whatever timezone this
	 * process happens to be in, which moves a date the buyer is counting down
	 * to.
	 */
	private static function notice_expiry($row): string {
		$raw = trim((string)$row->get('rdm_expiry_time'));
		if ($raw === '' || !preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}:\d{2}))?/', $raw, $m)) {
			return '';
		}
		return $m[1] . (isset($m[2]) && $m[2] !== '' ? ' ' . $m[2] : '');
	}

	/**
	 * Does this custody state put a take-ownership notice on the owner's site?
	 *
	 * Empty says nothing, and self_custody says the job is done — neither is a
	 * prompt. The three in between are.
	 */
	public static function state_prompts(string $state): bool {
		return in_array($state, array(RegisteredDomain::GRAD_OPERATOR,
			RegisteredDomain::GRAD_REQUESTED, RegisteredDomain::GRAD_SENT), true);
	}

	/** Does a completed notice job carry exactly these four values? */
	public static function notice_matches(array $job, array $desired): bool {
		$params = self::job_params($job);
		foreach (array('domain', 'expiry_time', 'state', 'manage_url') as $key) {
			if ((string)($params[$key] ?? '') !== (string)($desired[$key] ?? '')) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Send one notice job to a node.
	 *
	 * A builder exception — the node's agent does not offer the primitive — is
	 * a note rather than a thrown error: the row is fine, the box is behind, and
	 * an operator reading the run summary is who can do something about it.
	 *
	 * @return int 1 when a job was queued.
	 */
	protected function dispatch_notice($node, array $desired): int {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

		try {
			$built = JobCommandBuilder::build_managed_domain_notice($node, $desired);
		} catch (Throwable $e) {
			$this->notes[] = $desired['domain'] . ': ' . $e->getMessage();
			return 0;
		}

		ManagementJob::createFromBuild($node->key, self::JOB_NOTICE, $built, $desired, null);
		$this->notes[] = $desired['domain'] . ': the box is being told what it holds.';
		return 1;
	}

	/**
	 * The recent notice jobs for this node AND this domain, newest first.
	 *
	 * Scoped by domain because a shared host carries many managed domains, and
	 * by node because that is who the job is addressed to. createPrimitiveJob
	 * writes mjb_parameters as well as the envelope, so both are already there
	 * to filter on.
	 */
	protected function notice_jobs(int $node_id, string $domain): array {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT mjb_id, mjb_status, mjb_create_time, mjb_completed_time, mjb_parameters
			 FROM mjb_management_jobs
			 WHERE mjb_mgn_node_id = ? AND mjb_job_type = ? AND mjb_delete_time IS NULL
			   AND mjb_parameters->>'domain' = ?
			 ORDER BY mjb_create_time DESC, mjb_id DESC
			 LIMIT " . (int)self::NOTICE_JOB_LOOKBACK);
		$q->execute(array($node_id, self::JOB_NOTICE, $domain));
		return $q->fetchAll(PDO::FETCH_ASSOC) ?: array();
	}

	/** A job row's parameters, decoded. */
	protected static function job_params(array $job): array {
		$params = json_decode((string)($job['mjb_parameters'] ?? ''), true);
		return is_array($params) ? $params : array();
	}

	/** Where the take-ownership flow lives, on this management node. */
	public static function manageUrl(): string {
		require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
		return LibraryFunctions::get_absolute_url('/profile/server_manager/domain');
	}

	// ------------------------------------------------------------------

	/** Tell the buyer the handover finished, and what they must do next. */
	private function send_self_custody_email($row): void {
		$to = trim((string)$row->get('rdm_buyer_email'));
		if ($to === '') {
			return;
		}
		$domain = (string)$row->get('rdm_domain');
		$expiry = $row->get_local('rdm_expiry_time', 'F j, Y');
		$body = "Your domain is now fully yours.\n\n"
			. $domain . " has moved into your own registrar account. You were always its legal owner;\n"
			. "now you manage and pay for it directly too, and we are out of the loop entirely.\n\n"
			. ($expiry ? "One thing still needs doing: it expires on " . $expiry . ".\n"
				. "Add a payment method in your registrar account and turn on auto-renew, or the name\n"
				. "will lapse on that date. We do not renew it for you.\n\n" : '')
			. "Your website and email keep working exactly as they are — the DNS records moved with\n"
			. "the domain and nothing needs changing.\n";
		try {
			EmailSender::quickSend($to, 'Your domain ' . $domain . ' is now fully yours', $body);
		} catch (Throwable $e) {
			error_log('ManagedDomainWatch: self-custody email to ' . $to . ' failed: ' . $e->getMessage());
		}
	}

	/** The configured registrar, or null. Overridable for tests. */
	protected function get_registrar() {
		if ($this->registrar === null) {
			$this->registrar = DomainRegistrarRegistry::firstConfigured();
		}
		return $this->registrar;
	}
}

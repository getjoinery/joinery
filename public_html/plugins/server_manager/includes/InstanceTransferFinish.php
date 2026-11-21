<?php
/**
 * InstanceTransferFinish — what happens on our side once the provider says a
 * Managed site's server has moved into its customer's own account
 * (specs/managed_to_self_hosted_transfer.md §5, §5a), and the customer's
 * later "Stop managing this site" (§6).
 *
 * The finish is a list of steps run one at a time, each recorded on the row
 * as it completes (itx_finish_step), so a crash resumes at the next one and
 * no step runs twice:
 *
 *   left_us       the instance is gone from our account (our token gets a 404)
 *   hosting_mode  the provision says what happened: transferred
 *   billing       the hosting row moves to transferred FIRST, then the
 *                 subscription is cancelled — immediately, no refund, no store
 *                 email — so the store's cancel signal finds a row that
 *                 ignores it. A remote store's subscription is a to-do.
 *   services      mail and backups carry on as a Services subscription,
 *                 paid to the end of the hosting period: node-linked rows
 *   revived       a site that had been shut down is back on our books as live
 *   banner        the site's banner switches to the services standing
 *   told          the customer's email, and a note on the node
 *
 * Nothing on the box changes and our agent stays (D2): the node carries on as
 * a bring-your-own-cloud node until its customer says otherwise.
 *
 * @version 1.5 - reads the paying line through CustomerCloudProvision::local_order_item(), never another store's id (hosting_after_purchase B11)
 * @version 1.4 - link_node_services(): the node-linked mail and backup rows, shared with an own-Linode site built with the
 *                email and backups bundle (specs/own_linode_we_install.md W5); a newly built site's mail row may be unverified ($mail_verified), and its backups link without its mail ($link_mail)
 * @version 1.3 - asks the account of ours the server was in (cloud_accounts_list)
 * @version 1.2 - a finished transfer marks the node hosted on the customer's account
 * @version 1.1 - the last step marks its email, note and alert as each happens, so a crash never repeats them
 * @version 1.0
 */
class InstanceTransferFinish {

	const STEPS = array('left_us', 'hosting_mode', 'billing', 'services', 'revived', 'banner', 'told');

	/** How long a provider "completed" may leave the instance visible to us before the operator hears. */
	const LEFT_US_PATIENCE_SECONDS = 21600;

	/**
	 * Work the finish as far as it goes. Returns true when the row reached done.
	 * A step that cannot finish yet (the instance still visible, a provider
	 * outage) leaves the row at the last completed step for the next tick.
	 */
	public static function run(InstanceTransfer $row): bool {
		if ($row->state() !== InstanceTransfer::STATE_FINISHING) {
			return false;
		}
		$provision = InstanceTransfers::provision_of($row);
		$done = (string)$row->get('itx_finish_step');
		$position = $done === '' ? 0 : array_search($done, self::STEPS, true) + 1;
		foreach (array_slice(self::STEPS, (int)$position) as $step) {
			$method = 'step_' . $step;
			if (!self::$method($row, $provision)) {
				$row->save();
				return false;
			}
			$row->set('itx_finish_step', $step);
			$row->save();
		}
		$row->set('itx_state', InstanceTransfer::STATE_DONE);
		$row->save();
		return true;
	}

	// ── The steps ─────────────────────────────────────────────────────────────

	/** 1. Our own token can no longer see the instance. */
	private static function step_left_us(InstanceTransfer $row, CustomerCloudProvision $provision): bool {
		$resolved = InstanceTransfers::driver($provision);
		if ($resolved['driver'] === null) {
			$row->set('itx_error', 'Waiting to confirm the move: ' . $resolved['reason']);
			return false;
		}
		try {
			$resolved['driver']->getInstance((string)$row->get('itx_instance_id'));
		} catch (CloudComputeException $e) {
			if ((int)$e->getCode() === 404) {
				$row->set('itx_error', null);
				return true;
			}
			$row->set('itx_error', 'Waiting to confirm the move: ' . $e->getMessage());
			return false;
		}
		$row->set('itx_error', 'Linode reports the transfer completed, but the instance is still visible on our account. Waiting.');
		$accepted = strtotime((string)$row->get('itx_accepted_time') . ' UTC');
		if ($accepted !== false && time() - $accepted > self::LEFT_US_PATIENCE_SECONDS && $row->email_time('stuck_alert') === '') {
			InstanceTransfers::alert($provision, 'A finished transfer still shows the instance on our account',
				'Linode reported the transfer completed, but our token still sees instance ' . $row->get('itx_instance_id')
				. '. Check it in Cloud Manager.', $row);
			$row->mark_emailed('stuck_alert');
		}
		return false;
	}

	/** 2. The provision says what happened. */
	private static function step_hosting_mode(InstanceTransfer $row, CustomerCloudProvision $provision): bool {
		if (!$provision->is_transferred()) {
			$provision->set('cvp_hosting_mode', 'transferred');
			$provision->save();
		}
		// The machine is the customer's now: removing its node no longer waits for it.
		$node_id = (int)$provision->get('cvp_mgn_managed_node_id');
		if ($node_id) {
			ManagedNode::updateColumns($node_id, array('mgn_cloud_account' => CloudAccounts::CUSTOMER));
		}
		if (!$row->get('itx_completed_time')) {
			$row->set('itx_completed_time', gmdate('Y-m-d H:i:s'));
		}
		return true;
	}

	/**
	 * 3. Hosting billing ends, state first. The paid-up date is read before
	 * the cancel touches the subscription, and carried to the Services rows.
	 */
	private static function step_billing(InstanceTransfer $row, CustomerCloudProvision $provision): bool {
		$trial = HostedTrial::for_provision((int)$provision->key);
		if (!$row->get('itx_paid_until')) {
			list($paid_until, $note) = self::paid_until($provision, $trial);
			$row->set('itx_paid_until', $paid_until);
			if ($note !== '') {
				self::add_todo($row, $note);
			}
			$row->save();
		}

		if ($trial !== null && (string)$trial->get('htr_state') !== HostedTrial::STATE_TRANSFERRED) {
			$trial->set('htr_state', HostedTrial::STATE_TRANSFERRED);
			$trial->set('htr_note', 'Moved to the customer\'s own Linode account on ' . gmdate('Y-m-d') . '.');
			$trial->save();
		}

		$item_id = (int)$provision->get('cvp_external_order_item_id');
		if ($item_id <= 0) {
			return true;
		}
		$item = $provision->local_order_item();
		if ($item === null) {
			self::add_todo($row, 'Cancel the hosting subscription for order item #' . $item_id
				. ' in the store that sold it (it is not on this site). Immediately, no refund.');
			return true;
		}
		if (!$item->get('odi_is_subscription') || $item->get('odi_subscription_cancelled_time')
				|| in_array((string)$item->get('odi_subscription_status'), array('canceled', 'cancelled'), true)) {
			return true;
		}
		try {
			$item->cancel_subscription_by_system('immediate');
		} catch (Throwable $e) {
			self::add_todo($row, 'The hosting subscription (order item #' . $item_id . ') could not be cancelled: '
				. $e->getMessage() . ' Cancel it by hand: immediately, no refund.');
		}
		return true;
	}

	/**
	 * The day hosting was paid up to, and a to-do when it had to be guessed.
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function paid_until(CustomerCloudProvision $provision, ?HostedTrial $trial): array {
		$state = $trial ? (string)$trial->get('htr_state') : '';
		if (in_array($state, array(HostedTrial::STATE_GRACE, HostedTrial::STATE_SHUTDOWN), true)) {
			// Unpaid since the failed charge: the services lapse into their own
			// grace straight away, which is the honest standing.
			$failed = trim((string)$trial->get('htr_payment_failed_time'));
			return array($failed !== '' ? $failed : gmdate('Y-m-d H:i:s'), '');
		}
		$item = $provision->local_order_item();
		if ($item !== null) {
			$end = trim((string)$item->get('odi_subscription_period_end'));
			if ($end !== '') {
				return array($end, '');
			}
		}
		if ($state === HostedTrial::STATE_TRIAL && trim((string)$trial->get('htr_trial_ends_time')) !== '') {
			return array((string)$trial->get('htr_trial_ends_time'), '');
		}
		$guess = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
		return array($guess, 'Confirm the paid-through date for this site\'s mail and backups on the Service Tenants page: '
			. 'the store did not say when its hosting was paid to, so it was set 30 days out.');
	}

	/**
	 * 4. Mail and backups carry on as node-linked Services rows (§5a).
	 *
	 * Mail is a row copy: the provider pieces the Managed leg built become the
	 * tenant's, and the provision lets go of them — the provider's webhook
	 * matches provisions first and would otherwise keep counting sends against
	 * the old row. Backups stay on the fleet path; the row's ladder acts on
	 * the node.
	 */
	private static function step_services(InstanceTransfer $row, CustomerCloudProvision $provision): bool {
		$node = InstanceTransfers::node_of($provision);
		if ($node === null) {
			$row->set('itx_error', 'The site has no node, so its mail and backups could not be carried on. Grant them by hand.');
			return true;
		}
		self::link_node_services($provision, $node, (string)$row->get('itx_paid_until'),
			'Moved from Managed on ' . gmdate('Y-m-d') . '.');
		return true;
	}

	/**
	 * A site's mail and backups as node-linked Services rows (§5a): what a
	 * Managed site keeps when it moves to its buyer's own account, and what an
	 * own-Linode site with the email and backups bundle is built into
	 * (specs/own_linode_we_install.md W5). Idempotent: a row the node already
	 * has is left as it is.
	 *
	 * Mail is a row copy: the provider pieces the hosted mail leg built become
	 * the tenant's, and the provision lets go of them — the provider's webhook
	 * matches provisions first and would otherwise keep counting sends against
	 * the old row. Backups stay on the fleet path; the row's ladder acts on
	 * the node. $order_item_id names the services line the rows follow, when
	 * one paid for them.
	 */
	public static function link_node_services(CustomerCloudProvision $provision, ManagedNode $node, string $paid_until,
			string $note, int $order_item_id = 0, bool $mail_verified = true, bool $link_mail = true): void {
		$user_id = (int)$provision->get('cvp_usr_user_id');
		$domain = strtolower(trim((string)$provision->get('cvp_domain')));

		$subaccount = trim((string)$provision->get('cvp_smtp2go_subaccount_id'));
		if ($link_mail && ($subaccount !== '' || ServiceTenant::forNode((int)$node->key, ServiceTenant::SERVICE_MAIL) !== null)) {
			$mail = ServiceTenant::forNode((int)$node->key, ServiceTenant::SERVICE_MAIL);
			if ($mail === null) {
				$mail = self::new_tenant($node, $user_id, ServiceTenant::SERVICE_MAIL, $domain, $paid_until, $note, $order_item_id);
				$mail->set('svt_provider_subaccount_id', $subaccount);
				$mail->set('svt_provider_user_id', trim((string)$provision->get('cvp_smtp2go_user_id')) ?: null);
				$mail->set('svt_provider_domain', Smtp2GoLeg::sendingDomain($domain));
				// A moved Managed site's domain was verified while hosted; a newly
				// built one may still be waiting, and the services reconcile
				// keeps asking the provider until it is.
				$mail->set('svt_mail_state', $mail_verified ? ServiceTenant::MAIL_DOMAIN_VERIFIED : ServiceTenant::MAIL_DOMAIN_ADDED);
				$records = $provision->get('cvp_mail_records');
				$mail->set('svt_mail_records', is_string($records) ? $records : json_encode($records ?: array()));
				// Left at 0 so the next reconcile re-sets the provider's limit
				// to the Services allowance, rather than trusting the Managed one.
				$mail->set('svt_allowance', 0);
				$mail->save();
				self::slug($mail);
			}
			if (trim((string)$provision->get('cvp_smtp2go_subaccount_id')) !== ''
					|| trim((string)$provision->get('cvp_smtp2go_user_id')) !== '') {
				$provision->set('cvp_smtp2go_user_id', null);
				$provision->set('cvp_smtp2go_subaccount_id', null);
				$provision->save();
			}
		}

		$shelf = ServiceTenant::forNode((int)$node->key, ServiceTenant::SERVICE_SHELF);
		if ($shelf === null) {
			$shelf = self::new_tenant($node, $user_id, ServiceTenant::SERVICE_SHELF, $domain, $paid_until, $note, $order_item_id);
			$shelf->set('svt_allowance', JoineryServices::allowance(ServiceTenant::SERVICE_SHELF));
			$shelf->set('svt_figure', NodeBackupShelf::bytes($node));
			$shelf->set('svt_figure_time', gmdate('Y-m-d H:i:s'));
			$shelf->save();
			self::slug($shelf);
		}
		// A pause the hosting allowance put there lifts when the node is under
		// the Services allowance (the same number); over it, it stays paused.
		if (NodeBackupShelf::paused_for_shelf($node)) {
			NodeBackupShelf::apply_allowance($node, JoineryServices::allowance(ServiceTenant::SERVICE_SHELF));
		}
	}

	private static function new_tenant(ManagedNode $node, int $user_id, string $service, string $host, string $paid_until,
			string $note, int $order_item_id = 0): ServiceTenant {
		$tenant = new ServiceTenant(NULL);
		$tenant->set('svt_usr_user_id', $user_id);
		$tenant->set('svt_mgn_managed_node_id', (int)$node->key);
		$tenant->set('svt_service', $service);
		$tenant->set('svt_host', $host);
		$tenant->set('svt_state', ServiceTenant::STATE_ACTIVE);
		$tenant->set('svt_paid_until', $paid_until !== '' ? $paid_until : null);
		$tenant->set('svt_note', $note);
		if ($order_item_id > 0) {
			$tenant->set('svt_odi_order_item_id', $order_item_id);
		}
		return $tenant;
	}

	private static function slug(ServiceTenant $tenant): void {
		if (trim((string)$tenant->get('svt_slug')) === '') {
			$tenant->set('svt_slug', 't' . (int)$tenant->key);
			$tenant->save();
		}
	}

	/** 5. A site that had been shut down comes back on our books as live. */
	private static function step_revived(InstanceTransfer $row, CustomerCloudProvision $provision): bool {
		if (!$row->get('itx_deletion_withdrawn')) {
			return true;
		}
		$node = InstanceTransfers::node_of($provision);
		if ($node === null) {
			return true;
		}
		$notes = array();
		foreach (preg_split('/\R/', (string)$node->get('mgn_notes')) as $line) {
			if (stripos($line, 'awaiting deletion') === false && stripos($line, 'Deletion withdrawn') === false) {
				$notes[] = $line;
			}
		}
		$notes[] = 'Shut down for non-payment, then moved to its customer\'s own Linode account on ' . gmdate('Y-m-d')
			. ': backups and uptime checks back on. It moved powered off; its customer boots it.';
		$node->set('mgn_notes', trim(implode("\n", $notes)));
		if (NodeBackupShelf::suspended_for_services($node) === false) {
			$node->set('mgn_backup_policy', null);
		}
		$node->set('mgn_uptime_enabled', true);
		$node->save();
		return true;
	}

	/**
	 * 6. The banner switches to the services standing. Ours to send: the
	 * hosted watch no longer reaches the row. A node that cannot take it yet is
	 * a note on the row, not a failure.
	 */
	private static function step_banner(InstanceTransfer $row, CustomerCloudProvision $provision): bool {
		$node = InstanceTransfers::node_of($provision);
		if ($node === null) {
			return true;
		}
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ServiceTenantWatch.php'));
		$result = ServiceTenantWatch::push_node_banner($node);
		if (strpos($result, 'unable: ') === 0) {
			$row->set('itx_error', 'The site\'s banner still shows its hosting: ' . substr($result, 8));
		}
		return true;
	}

	/**
	 * 7. The customer is told what is theirs now; the node carries a note.
	 * Each of the three is marked as it happens, so a crash part-way through
	 * resumes without a second email, note or alert.
	 */
	private static function step_told(InstanceTransfer $row, CustomerCloudProvision $provision): bool {
		if ($row->email_time('done') === '') {
			InstanceTransfers::email($row, $provision, 'done', array(
				'dns_records' => self::records_we_hold($provision),
			));
		}
		$node = InstanceTransfers::node_of($provision);
		if ($node !== null && $row->email_time('node_note') === '') {
			$node->set('mgn_notes', trim((string)$node->get('mgn_notes') . "\n"
				. 'Moved to its customer\'s own Linode account on ' . gmdate('Y-m-d') . ' (transfer #' . (int)$row->key
				. '). Still managed by us until its customer says otherwise.'));
			$node->save();
			$row->mark_emailed('node_note');
			$row->save();
		}
		if ($row->email_time('done_alert') === '') {
			InstanceTransfers::alert($provision, $provision->get('cvp_domain') . ' moved to its customer\'s Linode account',
				'The finish ran: hosting cancelled, mail and backups carried on as a Services subscription'
				. ($row->get('itx_operator_todo') ? '. There is a to-do on the queue.' : '.'), $row);
			$row->mark_emailed('done_alert');
			$row->save();
		}
		return true;
	}

	/**
	 * The DNS records we still hold for the site, one per line: the sender
	 * domain's records, a domain we registered for them, and a zone in our own
	 * Linode DNS Manager when the credential of the account it was in can read it.
	 */
	public static function records_we_hold(CustomerCloudProvision $provision): string {
		$lines = array();
		$node_id = (int)$provision->get('cvp_mgn_managed_node_id');
		$mail = $node_id ? ServiceTenant::forNode($node_id, ServiceTenant::SERVICE_MAIL) : null;
		$records = $mail !== null ? $mail->mailRecords() : array();
		foreach ($records as $r) {
			if (is_array($r) && !empty($r['type']) && !empty($r['name'])) {
				$lines[] = $r['type'] . ' ' . $r['name'] . ' → ' . (string)($r['value'] ?? '')
					. (!empty($r['purpose']) ? ' (' . $r['purpose'] . ')' : '');
			}
		}
		if ($node_id) {
			foreach (new MultiRegisteredDomain(array('rdm_mgn_managed_node_id' => $node_id, 'deleted' => false)) as $rdm) {
				$lines[] = 'Your domain ' . $rdm->get('rdm_domain') . ' was registered through us. It stays on its existing '
					. 'path to being fully yours, and its records stay where they are.';
			}
		}
		foreach (self::operator_zone_records($provision) as $line) {
			$lines[] = $line;
		}
		return implode("\n", $lines);
	}

	/** Records in a zone for the site's domain in the Linode DNS Manager of the account it was in, or none. Best effort. */
	private static function operator_zone_records(CustomerCloudProvision $provision): array {
		$domain = (string)$provision->get('cvp_domain');
		if (InstanceTransfers::$driver !== null || trim($domain) === '') {
			return array();
		}
		require_once(PathHelper::getIncludePath('includes/dns/drivers/LinodeDnsDriver.php'));
		$token = HeldCloudAccounts::provision_token($provision)['token'];
		if ($token === '') {
			return array();
		}
		try {
			$dns = new LinodeDnsDriver(array('access_token' => $token));
			$zone = $dns->zoneFor($domain);
			if ($zone === null) {
				return array();
			}
			$out = array('Our Linode DNS Manager holds the zone ' . $zone . '. It keeps answering; these are its records:');
			foreach ($dns->listRecords($zone) as $record) {
				$out[] = '  ' . $record->describe();
			}
			return $out;
		} catch (Throwable $e) {
			// A token without the Domains scope cannot see zones: nothing to list.
			return array();
		}
	}

	private static function add_todo(InstanceTransfer $row, string $todo): void {
		$current = trim((string)$row->get('itx_operator_todo'));
		if (strpos($current, $todo) !== false) {
			return;
		}
		$row->set('itx_operator_todo', $current === '' ? $todo : $current . "\n" . $todo);
	}

	// ── Stop managing this site (§6, D2) ──────────────────────────────────────

	/**
	 * The customer of a moved site tells us to stop. Unpairing the agent alone
	 * would leave a node that fails its backups nightly and raises silent-agent
	 * incidents, so all of it goes: the agent is forgotten, backups and uptime
	 * checks are switched off and the node disabled, its open incidents close
	 * (and stay closed — the reconciler does not watch a disabled node), and
	 * the backup storage row is released, which starts the 90-day retention
	 * clock. Mail carries on: it does not depend on the agent.
	 *
	 * @return array{incidents: int, backups_released: bool}
	 */
	public static function stop_managing(CustomerCloudProvision $provision): array {
		if (!$provision->is_transferred()) {
			throw new InstanceTransferFlowException('Only a site that has moved to its own Linode account can be let go this way.');
		}
		$node = InstanceTransfers::node_of($provision);
		if ($node === null) {
			throw new InstanceTransferFlowException('We are not managing this site any more.');
		}
		if (!$node->get('mgn_enabled') && !$node->get('mgn_agent_public_key')) {
			throw new InstanceTransferFlowException('We have already stopped managing this site.');
		}

		return self::let_go_node($node);
	}

	/**
	 * Stop managing one node at its owner's word: the agent is forgotten,
	 * backups and uptime checks are switched off, the node is disabled, its
	 * open incidents close, and its backup storage row is released, which
	 * starts the retention clock. Shared by a moved site's Stop managing and
	 * a buyer removing a site on their own server (SiteRemoval).
	 *
	 * @return array{incidents: int, backups_released: bool}
	 */
	public static function let_go_node(ManagedNode $node): array {
		AgentChannelEndpoint::forgetAgent($node);
		$node->set('mgn_backup_policy', json_encode(array('enabled' => false)));
		$node->set('mgn_uptime_enabled', false);
		$node->set('mgn_enabled', false);
		$node->set('mgn_notes', trim((string)$node->get('mgn_notes') . "\n"
			. 'Its customer stopped our management on ' . gmdate('Y-m-d') . ': agent forgotten, backups and uptime checks off, '
			. 'node disabled. Stored backups are pruned ' . ServiceTenant::RETENTION_DAYS . ' days after that.'));
		$node->save();

		$closed = IncidentReconciler::close_for_node((int)$node->key,
			'Its customer stopped our management of this site; nothing here watches it now.');

		$released = false;
		$shelf = ServiceTenant::forNode((int)$node->key, ServiceTenant::SERVICE_SHELF);
		if ($shelf !== null && (string)$shelf->get('svt_state') !== ServiceTenant::STATE_RELEASED) {
			JoineryServices::releaseRow($shelf);
			$released = true;
		}
		return array('incidents' => $closed, 'backups_released' => $released);
	}
}

<?php
/**
 * ProvisionHostedAi — AI credit for the sites we host and the bundle sites we
 * manage (specs/hosted_ai_credit.md §3.3, §3.5).
 *
 * Each site gets one TrustedRouter key in our account, capped at U0 + A + B
 * (HostedAiCap). TrustedRouter refuses past the cap with a 402, so the site's
 * own admin cannot spend more than the site has. The leg is the ProvisionHostedMail
 * pattern: the management key never leaves this machine, the one credential
 * that reaches a site is cut to its slice, it travels in an agent primitive
 * job whose node-side script holds the setting name, and it is blanked from
 * the job row once the node answers.
 *
 * The states, one step per site per tick:
 *
 *   pending          wait for the site's node to be able to take the key
 *                    (asked BEFORE anything is minted, as on the mail leg);
 *                    then create the key at this month's cap and push it in
 *                    the same step: the key is never stored, so it exists
 *                    only for as long as the push takes to file.
 *   key_created      minted, push not filed (a crash between the two). The
 *                    key is gone with the process, so the next tick deletes
 *                    it and mints again.
 *   settings_pushed  the push is travelling. Completed with
 *                    HOSTED_AI_SETTINGS=ok is done; a failed push mints a
 *                    fresh key and deletes the old, up to MAX_PUSH_ATTEMPTS,
 *                    then the leg fails and says so.
 *   done             the watch: usage, the month roll, refunds, the cap.
 *
 * A bundle site with no agent (connected by key only) takes its key in its
 * services enrol answer instead (JoineryServices::enrolAi); its account sits
 * in pending here with a note until then.
 *
 * THE WATCH, hourly: one key listing for every site's usage, the month roll
 * (free usage topped up to $1, never added to), refunds found on the store's
 * credit purchase lines, and a PATCH wherever the cap the figures call for
 * differs from the one last set. The cap sync runs every tick, so a purchase
 * whose fulfilment could not reach TrustedRouter is applied within a tick.
 * Once a day our own TrustedRouter balance is checked against the low-balance
 * figure: every site's cap draws on that one prepaid balance.
 *
 * A failure here leaves a WORKING SITE: AI is an add-on, so a failed leg is a
 * note on the node page and an operator alert, never a failed provision.
 *
 * @version 1.2 - a suspended bundle site is not minted a key (B16)
 * @version 1.1 - reviewer2's review: a replaced key is retired in two steps (B1, B2); a removal that failed is
 *   retried, and a Managed site no longer hosted is ended here too (B5); a bundle site is ended only when its
 *   services are released, switched off while suspended, and reopened when it pays again (B4); mint never
 *   overwrites a live key, waits while the site is suspended, and counts its attempts (B6); a push waiting
 *   a day for its node is cancelled and its key replaced (B10); the balance is checked once a day (B8)
 * @version 1.0
 */

class ProvisionHostedAi {

	const JOB_TYPE = 'hosted_ai_settings';

	/** Pushes (each a fresh key) before the leg gives up, as on the mail leg. */
	const MAX_PUSH_ATTEMPTS = 3;

	/** How old a usage read may be before the watch reads again. */
	const WATCH_SECONDS = 3300;

	/** @var string[] */
	private $errors = array();

	/** @var TrustedRouterClient|null */
	private $client = null;

	/** @var array|null hash => usage row, read once per tick when needed */
	private $usage = null;

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/hosted_trials_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/service_tenants_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/HostedTrialWatch.php'));

		$opened = $this->open_accounts();
		$accounts = array();
		foreach (new MultiHostedAiAccount(array('deleted' => false), array('hai_hosted_ai_account_id' => 'ASC')) as $account) {
			if ((string)$account->get('hai_state') !== HostedAiAccount::STATE_REMOVED) {
				$accounts[] = $account;
			}
		}
		if (!$accounts && count(new MultiHostedAiLedger(array('kind' => HostedAiLedger::KIND_PURCHASE, 'deleted' => false))) === 0) {
			return array('status' => 'skipped', 'message' => 'No AI credit to set up.');
		}

		$this->client = HostedAiCredit::client();
		if ($this->client === null) {
			return array('status' => 'error', 'message' => count($accounts)
				. ' site(s) are waiting for AI credit, and no TrustedRouter management key is configured. '
				. 'Set it on the Provisioning Setup page.');
		}

		$steps = $opened;
		foreach ($accounts as $i => $account) {
			// A replaced key's final usage, then its delete (two-step retirement).
			if ($account->retiring_keys()) {
				try {
					$steps += HostedAiCredit::finish_retiring($account, $this->client, $this->usage_list());
				} catch (Throwable $e) {
					$this->note($account, 'A replaced key could not be deleted yet: ' . $e->getMessage());
				}
			}
			try {
				if ($this->end_if_lapsed($account)) {
					unset($accounts[$i]);
					$steps++;
					continue;
				}
				$steps += $this->advance($account);
			} catch (Throwable $e) {
				$this->note($account, $e->getMessage());
				error_log('ProvisionHostedAi: account #' . $account->key . ': ' . $e->getMessage());
			}
		}

		// The hourly half: usage, the month, refunds. Refunds are found even
		// when no account needs a usage read, since a refund can land on a
		// removed site's purchase.
		if ($this->watch_due($accounts)) {
			try {
				$steps += $this->watch($accounts);
			} catch (Throwable $e) {
				$this->errors[] = 'watch: ' . $e->getMessage();
			}
			try {
				$steps += HostedAiCredit::apply_refunds();
			} catch (Throwable $e) {
				$this->errors[] = 'refunds: ' . $e->getMessage();
			}
		}

		// Every tick: wherever the figures call for another cap, set it.
		foreach ($accounts as $account) {
			try {
				if (HostedAiCredit::sync($account, $this->client)) {
					$steps++;
				}
			} catch (Throwable $e) {
				$this->note($account, 'The cap could not be moved: ' . $e->getMessage());
			}
		}

		try {
			$this->check_balance();
		} catch (Throwable $e) {
			$this->errors[] = 'balance: ' . $e->getMessage();
		}

		$message = 'Hosted AI: ' . $steps . ' step(s) across ' . count($accounts) . ' site(s).';
		if ($this->errors) {
			$message .= ' ' . count($this->errors) . ' problem(s): ' . implode('; ', array_slice($this->errors, 0, 3));
			if (count($this->errors) > 3) { $message .= ' …'; }
			return array('status' => 'error', 'message' => $message);
		}
		return array('status' => 'success', 'message' => $message);
	}

	// ── Which sites get an account ────────────────────────────────────────────

	/**
	 * Open an account for every entitled site that has none: a sold Managed
	 * site whose plan is in trial or subscribed (Q5: a trial counts), and a
	 * bundle site whose bundle is paid (Q4). Returns how many were opened.
	 */
	private function open_accounts(): int {
		$opened = 0;
		foreach (HostedTrialWatch::sold_sites() as $provision) {
			if (HostedAiAccount::for_provision($provision->key) !== null) {
				continue;
			}
			if (!self::provision_entitled($provision)) {
				continue;
			}
			HostedAiCredit::open((int)$provision->key, null);
			$opened++;
		}

		$seen = array();
		foreach (new MultiServiceTenant(array('deleted' => false), array('svt_service_tenant_id' => 'ASC')) as $tenant) {
			$anchor = self::anchor_of($tenant);
			if ($anchor === null || isset($seen[$anchor->key])) {
				continue;
			}
			$seen[$anchor->key] = true;
			if (!self::tenant_entitled($anchor)) {
				continue;
			}
			$existing = HostedAiAccount::for_tenant($anchor->key);
			if ($existing !== null) {
				// A bundle site that left and paid again comes back (B4).
				if ((string)$existing->get('hai_state') === HostedAiAccount::STATE_REMOVED) {
					HostedAiCredit::reopen($existing);
					$opened++;
				}
				continue;
			}
			// A Managed site that moved to its buyer's own account and kept
			// the bundle keeps its account, key and credit.
			$carried = self::account_on_node((int)$anchor->get('svt_mgn_managed_node_id'));
			if ($carried !== null) {
				$carried->set('hai_svt_service_tenant_id', (int)$anchor->key);
				$carried->save();
				continue;
			}
			HostedAiCredit::open(null, (int)$anchor->key);
			$opened++;
		}
		return $opened;
	}

	/** A site's first service row: the one its AI account hangs from. */
	public static function anchor_of(ServiceTenant $tenant): ?ServiceTenant {
		$rows = $tenant->siteRows();
		if (!$rows) { return null; }
		usort($rows, function ($a, $b) { return (int)$a->key <=> (int)$b->key; });
		return $rows[0];
	}

	/** The live Managed account whose provision is on this node, or null. */
	private static function account_on_node(int $node_id): ?HostedAiAccount {
		if ($node_id <= 0) { return null; }
		foreach (new MultiCustomerCloudProvision(array('node_id' => $node_id, 'deleted' => false)) as $provision) {
			$account = HostedAiAccount::for_provision($provision->key);
			if ($account !== null && (string)$account->get('hai_state') !== HostedAiAccount::STATE_REMOVED
					&& !(int)$account->get('hai_svt_service_tenant_id')) {
				return $account;
			}
		}
		return null;
	}

	public static function provision_entitled($provision): bool {
		$trial = HostedTrial::for_provision($provision->key);
		return $trial !== null && in_array((string)$trial->get('htr_state'),
			array(HostedTrial::STATE_TRIAL, HostedTrial::STATE_SUBSCRIBED), true);
	}

	public static function tenant_entitled(ServiceTenant $tenant): bool {
		foreach ($tenant->siteRows() as $row) {
			if ($row->entitled()) {
				return true;
			}
		}
		return false;
	}

	/** Does this account's site get free usage this month? */
	public static function entitled(HostedAiAccount $account): bool {
		$tenant_id = (int)$account->get('hai_svt_service_tenant_id');
		if ($tenant_id) {
			$tenant = new ServiceTenant($tenant_id, TRUE);
			return $tenant->key && !$tenant->get('svt_delete_time') && self::tenant_entitled($tenant);
		}
		$provision_id = (int)$account->get('hai_cvp_customer_cloud_provision_id');
		if (!$provision_id) { return false; }
		$provision = new CustomerCloudProvision($provision_id, TRUE);
		return $provision->key && !$provision->get('cvp_delete_time') && self::provision_entitled($provision);
	}

	/**
	 * The node a site's key is pushed to, or null (a bundle site connected by
	 * key only, or a node that is gone).
	 */
	public static function node_of(HostedAiAccount $account): ?ManagedNode {
		$tenant_id = (int)$account->get('hai_svt_service_tenant_id');
		if ($tenant_id) {
			$tenant = new ServiceTenant($tenant_id, TRUE);
			return $tenant->key ? $tenant->linked_node() : null;
		}
		$provision_id = (int)$account->get('hai_cvp_customer_cloud_provision_id');
		if (!$provision_id) { return null; }
		$provision = new CustomerCloudProvision($provision_id, TRUE);
		$id = $provision->key ? (int)$provision->get('cvp_mgn_managed_node_id') : 0;
		if (!$id) { return null; }
		$node = new ManagedNode($id, TRUE);
		return ($node->key && !$node->get('mgn_delete_time')) ? $node : null;
	}

	/** The site in words, for notes and alerts. */
	public static function label(HostedAiAccount $account): string {
		$tenant_id = (int)$account->get('hai_svt_service_tenant_id');
		if ($tenant_id) {
			$tenant = new ServiceTenant($tenant_id, TRUE);
			if ($tenant->key) { return trim((string)$tenant->get('svt_host')) ?: 'bundle site #' . $tenant_id; }
		}
		$provision_id = (int)$account->get('hai_cvp_customer_cloud_provision_id');
		if ($provision_id) {
			$provision = new CustomerCloudProvision($provision_id, TRUE);
			if ($provision->key) { return (string)$provision->get('cvp_domain'); }
		}
		return 'AI account #' . (int)$account->key;
	}

	/** The key's name at TrustedRouter: no domain or buyer goes there. */
	public static function key_name(HostedAiAccount $account): array {
		$tenant_id = (int)$account->get('hai_svt_service_tenant_id');
		$provision_id = (int)$account->get('hai_cvp_customer_cloud_provision_id');
		$prefix = '';
		if ($provision_id) {
			$provision = new CustomerCloudProvision($provision_id, TRUE);
			$prefix = $provision->key ? $provision->external_name_prefix() : '';
		} elseif ($tenant_id) {
			$tenant = new ServiceTenant($tenant_id, TRUE);
			$prefix = $tenant->key ? JoineryServices::externalNamePrefix($tenant) : '';
		}
		$name = $prefix . ($provision_id ? 'ai-' . $provision_id : 'ai-svt-' . $tenant_id);
		$tags = $provision_id ? array('provision' => (string)$provision_id) : array('tenant' => (string)$tenant_id);
		return array('name' => $name, 'tags' => $tags);
	}

	/**
	 * Whether a site has left us, and its account is ended here. Returns true
	 * when the account is closed this pass.
	 *
	 *  - A removal that could not reach TrustedRouter (`removing`) is asked
	 *    again (B5).
	 *  - A Managed account whose provision is gone, or whose hosting moved to
	 *    the buyer's own account without our email and backups, ends (B5: the
	 *    hooks ask once; this is the net under them).
	 *  - A bundle site whose service rows are all released (or gone) ends. One
	 *    whose rows are all suspended has its key switched off and kept: a
	 *    suspended bundle comes back with a new paid date, and its key and
	 *    bought credit with it (B4).
	 */
	private function end_if_lapsed(HostedAiAccount $account): bool {
		$state = (string)$account->get('hai_state');
		if ($state === HostedAiAccount::STATE_REMOVING) {
			$done = HostedAiCredit::remove($account, (string)($account->get('hai_note') ?: 'Removal retried.'));
			if (!$done['ok']) {
				$this->errors[] = self::label($account) . ': ' . $done['line'];
			}
			return $done['ok'];
		}
		$tenant_id = (int)$account->get('hai_svt_service_tenant_id');
		if (!$tenant_id) {
			$provision = new CustomerCloudProvision((int)$account->get('hai_cvp_customer_cloud_provision_id'), TRUE);
			$gone = !$provision->key || $provision->get('cvp_delete_time');
			if ($gone) {
				return $this->end($account, 'The site is no longer hosted by us.');
			}
			// Moved to its buyer's own account: only once the move has finished,
			// since its Services rows are made in the move's last steps. Then it
			// carries on with them, or ends (HostedAiCredit::carry_or_end).
			$trial = HostedTrial::for_provision($provision->key);
			if ($trial === null || (string)$trial->get('htr_state') !== HostedTrial::STATE_TRANSFERRED) {
				return false;
			}
			$finished = false;
			foreach (new MultiInstanceTransfer(array('provision_id' => (int)$provision->key, 'deleted' => false)) as $move) {
				if ((string)$move->get('itx_state') === InstanceTransfer::STATE_DONE) {
					$finished = true;
				}
			}
			if (!$finished) {
				return false;
			}
			HostedAiCredit::carry_or_end($provision, self::node_of($account));
			$account->load();
			return (string)$account->get('hai_state') === HostedAiAccount::STATE_REMOVED;
		}

		$tenant = new ServiceTenant($tenant_id, TRUE);
		$rows = ($tenant->key && !$tenant->get('svt_delete_time')) ? $tenant->siteRows() : array();
		$released = true;
		$stopped = true;
		foreach ($rows as $row) {
			$row_state = (string)$row->get('svt_state');
			if ($row_state !== ServiceTenant::STATE_RELEASED) {
				$released = false;
			}
			if (!in_array($row_state, array(ServiceTenant::STATE_SUSPENDED, ServiceTenant::STATE_RELEASED), true)) {
				$stopped = false;
			}
		}
		if ($released) {
			return $this->end($account, 'The site left our email and backups.');
		}
		$this->hold_for_suspension($account, $stopped);
		return false;
	}

	/** Switch a suspended bundle site's key off, and back on when it pays again. */
	private function hold_for_suspension(HostedAiAccount $account, bool $suspended): void {
		$hash = trim((string)$account->get('hai_key_hash'));
		if ($hash === '' || (bool)$account->get('hai_disabled_set') === $suspended) {
			return;
		}
		try {
			$this->client->setDisabled($hash, $suspended);
		} catch (Throwable $e) {
			$this->note($account, 'The key could not be switched ' . ($suspended ? 'off' : 'on') . ': ' . $e->getMessage());
			return;
		}
		$account->set('hai_disabled_set', $suspended);
		$state = (string)$account->get('hai_state');
		if ($suspended && $state === HostedAiAccount::STATE_DONE) {
			$account->set('hai_state', HostedAiAccount::STATE_DISABLED);
		} elseif (!$suspended && $state === HostedAiAccount::STATE_DISABLED) {
			$account->set('hai_state', HostedAiAccount::STATE_DONE);
		}
		$account->save();
	}

	/** Clear the key on the site where an agent reaches it, then remove the account. */
	private function end(HostedAiAccount $account, string $why): bool {
		$node = self::node_of($account);
		if ($node !== null && JobCommandBuilder::has_primitive($node, self::JOB_TYPE)) {
			try {
				ManagementJob::createFromBuild($node->key, self::JOB_TYPE,
					JobCommandBuilder::build_hosted_ai_settings($node, array('api_key' => '')),
					array('hai_account_id' => (int)$account->key, 'clear' => '1'), null);
			} catch (Throwable $e) {
				error_log('ProvisionHostedAi: clearing the AI key on ' . self::label($account) . ': ' . $e->getMessage());
			}
		}
		$done = HostedAiCredit::remove($account, $why);
		if (!$done['ok']) {
			$this->errors[] = self::label($account) . ': ' . $done['line'];
		}
		return $done['ok'];
	}

	// ── The states ────────────────────────────────────────────────────────────

	private function advance(HostedAiAccount $account): int {
		switch ((string)$account->get('hai_state')) {
			case HostedAiAccount::STATE_PENDING:         return $this->mint_and_push($account);
			case HostedAiAccount::STATE_KEY_CREATED:     return $this->replace_key($account, 'The key was created but never sent; a fresh one replaces it.');
			case HostedAiAccount::STATE_SETTINGS_PUSHED: return $this->follow_push($account);
		}
		return 0;
	}

	/** pending: the node must be able to take the key before one is minted. */
	private function mint_and_push(HostedAiAccount $account): int {
		$node = self::node_of($account);
		if ($node === null) {
			if ((int)$account->get('hai_svt_service_tenant_id')) {
				$this->note($account, 'This bundle site has no agent we reach; it collects its AI credit key when it next checks in for services.', false);
			} else {
				$this->note($account, 'This site has no node to send its AI credit key to yet.');
			}
			return 0;
		}
		if (!JobCommandBuilder::has_primitive($node, self::JOB_TYPE)) {
			$this->note($account, 'This site\'s agent does not offer hosted_ai_settings yet, so its AI credit key '
				. 'cannot be handed over. Apply an update to the node; setup resumes on its own. Nothing has been '
				. 'minted for it in the meantime.');
			return 0;
		}
		// A suspended site is not handed a working key (B6).
		if ((bool)$account->get('hai_disabled_set') || self::suspended($account)) {
			$this->note($account, 'The site is suspended; its AI credit key is made when it is unsuspended.', false);
			return 0;
		}
		// Never mint over a key still on record: retire it first, or the old
		// one would stand live at its old cap with nothing naming it (B6).
		if (trim((string)$account->get('hai_key_hash')) !== '') {
			HostedAiCredit::retire_key($account, $this->client, $this->usage_of((string)$account->get('hai_key_hash')));
		}
		$named = self::key_name($account);
		$cap = $account->wanted_cap();
		$minted = $this->client->createKey($named['name'], $cap, $named['tags']);
		// Stamped and saved before the push is filed: a crash between the two
		// leaves key_created, which the next tick cleans up, not an orphan key
		// this plane never names again.
		$account->set('hai_key_hash', $minted['hash']);
		$account->set('hai_key_time', gmdate('Y-m-d H:i:s'));
		$account->set('hai_limit_set', $cap);
		$account->set('hai_state', HostedAiAccount::STATE_KEY_CREATED);
		$account->save();
		$this->dispatch($account, $node, $minted['key']);
		return 1;
	}

	/**
	 * Replace the current key: retire it (switched off now, deleted on a later
	 * pass from its final usage) and mint and push a fresh one. Each
	 * replacement is an attempt, so a push that can never be filed stops at
	 * MAX_PUSH_ATTEMPTS rather than minting a key every tick (B6).
	 */
	private function replace_key(HostedAiAccount $account, string $why): int {
		$attempts = (int)$account->get('hai_attempts') + 1;
		if ($attempts > self::MAX_PUSH_ATTEMPTS) {
			$this->fail_leg($account, $why . ' This happened ' . ($attempts - 1) . ' times, so setup stopped.');
			return 1;
		}
		$hash = trim((string)$account->get('hai_key_hash'));
		if ($hash !== '') {
			HostedAiCredit::retire_key($account, $this->client, $this->usage_of($hash));
		}
		$account->set('hai_state', HostedAiAccount::STATE_PENDING);
		$account->set('hai_attempts', $attempts);
		$account->set('hai_note', mb_substr($why, 0, 4000));
		$account->save();
		return $this->mint_and_push($account) + 1;
	}

	/** A Managed site under an open suspension, or a bundle site whose services are all stopped. */
	private static function suspended(HostedAiAccount $account): bool {
		if ((int)$account->get('hai_svt_service_tenant_id')) {
			return self::site_stopped($account);
		}
		$provision_id = (int)$account->get('hai_cvp_customer_cloud_provision_id');
		return $provision_id > 0 && HostedSuspension::open_for($provision_id) !== null;
	}

	/** A bundle site whose service rows are all suspended or released (B16). */
	public static function site_stopped(HostedAiAccount $account): bool {
		$tenant_id = (int)$account->get('hai_svt_service_tenant_id');
		if (!$tenant_id) {
			return false;
		}
		$tenant = new ServiceTenant($tenant_id, TRUE);
		$rows = ($tenant->key && !$tenant->get('svt_delete_time')) ? $tenant->siteRows() : array();
		foreach ($rows as $row) {
			if (!in_array((string)$row->get('svt_state'), array(ServiceTenant::STATE_SUSPENDED, ServiceTenant::STATE_RELEASED), true)) {
				return false;
			}
		}
		return true;
	}

	/** How long a push may wait for its node before its key is replaced. */
	const PUSH_PATIENCE_HOURS = 24;

	/** settings_pushed: read the push's outcome from its job. */
	private function follow_push(HostedAiAccount $account): int {
		$node = self::node_of($account);
		if ($node === null) {
			$this->fail_leg($account, 'This site has no node to send its AI credit key to.');
			return 1;
		}
		$job = ManagementJob::latestForNode($node->key, self::JOB_TYPE);
		$status = $job ? (string)$job->get('mjb_status') : '';
		if (in_array($status, array('queued', 'pending'), true)) {
			$filed = strtotime((string)$job->get('mjb_create_time') . ' UTC');
			if ($filed === false || time() - $filed < self::PUSH_PATIENCE_HOURS * 3600) {
				return 0;
			}
			// The node has not collected it in a day: the job is cancelled and
			// its key blanked, so a live key does not sit in a job row while
			// the node is away (B10). The node gets a fresh one when it is back.
			$job->set('mjb_status', 'cancelled');
			$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
			$job->save();
			JobResultProcessor::process($job);
			return $this->replace_key($account, 'The site did not collect its AI credit key within '
				. self::PUSH_PATIENCE_HOURS . ' hours (job #' . $job->key . ' cancelled).');
		}
		if ($status === 'running') {
			return 0;
		}
		if ($status === 'completed' && self::configured($job)) {
			$account->set('hai_state', HostedAiAccount::STATE_DONE);
			$account->set('hai_note', null);
			$account->set('hai_attempts', 0);
			$account->save();
			return 1;
		}
		$attempts = $this->push_attempts($node, $account);
		if ($attempts >= self::MAX_PUSH_ATTEMPTS) {
			$this->fail_leg($account, 'The site would not take its AI credit key after ' . $attempts . ' attempts'
				. ($job ? ' (job #' . $job->key . ': ' . trim((string)$job->get('mjb_error_message')) . ')' : '')
				. '. Its release may not carry utils/hosted_ai_settings.php. The key was deleted.');
			return 1;
		}
		$account->set('hai_attempts', $attempts);
		$account->save();
		return $this->replace_key($account, 'The last push did not take' . ($job ? ' (job #' . $job->key . ')' : '') . '; a fresh key replaces it.');
	}

	private static function configured($job): bool {
		$result = $job->get('mjb_result');
		$result = is_string($result) ? json_decode($result, true) : $result;
		if (is_array($result) && array_key_exists('configured', $result)) {
			return (bool)$result['configured'];
		}
		return strpos((string)($job->get('mjb_output') ?: ''), 'HOSTED_AI_SETTINGS=ok') !== false;
	}

	/** File the push. VALUES ONLY: the setting name lives in the node's script. */
	private function dispatch(HostedAiAccount $account, ManagedNode $node, string $key): void {
		$built = JobCommandBuilder::build_hosted_ai_settings($node, array('api_key' => $key));
		ManagementJob::createFromBuild($node->key, self::JOB_TYPE, $built,
			array('hai_account_id' => (int)$account->key), null);
		$account->set('hai_state', HostedAiAccount::STATE_SETTINGS_PUSHED);
		$account->save();
	}

	/** Pushes to this node since it last took one, counted from the last Retry. */
	private function push_attempts(ManagedNode $node, HostedAiAccount $account): int {
		$retry = trim((string)$account->get('hai_retry_time'));
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT COUNT(*) FROM mjb_management_jobs j
			 WHERE j.mjb_mgn_managed_node_id = ? AND j.mjb_job_type = ? AND j.mjb_delete_time IS NULL
			   AND j.mjb_management_job_id > COALESCE((
				SELECT MAX(c.mjb_management_job_id) FROM mjb_management_jobs c
				 WHERE c.mjb_mgn_managed_node_id = j.mjb_mgn_managed_node_id
				   AND c.mjb_job_type = j.mjb_job_type AND c.mjb_delete_time IS NULL
				   AND c.mjb_status = 'completed'
				   AND COALESCE(c.mjb_result::text, '') LIKE '%\"configured\":true%'), 0)
			   AND (CAST(? AS timestamp) IS NULL OR j.mjb_create_time > CAST(? AS timestamp))");
		$q->execute(array((int)$node->key, self::JOB_TYPE, $retry !== '' ? $retry : null, $retry !== '' ? $retry : null));
		return (int)$q->fetchColumn();
	}

	// ── The watch ─────────────────────────────────────────────────────────────

	private function watch_due(array $accounts): bool {
		$period = HostedAiCredit::period();
		foreach ($accounts as $account) {
			if (!$account->has_key()) { continue; }
			if ((string)$account->get('hai_period_start') !== $period) { return true; }
			$at = trim((string)$account->get('hai_usage_time'));
			if ($at === '' || time() - strtotime($at . ' UTC') >= self::WATCH_SECONDS) { return true; }
		}
		// Refunds on removed sites still need finding, hourly.
		$last = (string)Globalvars::get_instance()->get_setting('server_manager_hosted_ai_watch_time', false, true);
		return $last === '' || time() - strtotime($last . ' UTC') >= self::WATCH_SECONDS;
	}

	/** Usage for every keyed account, then the month roll where it is due. */
	private function watch(array $accounts): int {
		$changed = 0;
		foreach ($accounts as $account) {
			$hash = trim((string)$account->get('hai_key_hash'));
			if ($hash === '' || !$account->has_key()) {
				// No key yet: a new month still tops up free usage.
				if (HostedAiCredit::roll($account, (int)$account->get('hai_usage'), self::entitled($account))) {
					$changed++;
				}
				continue;
			}
			$usage = $this->usage_of($hash);
			if (HostedAiCredit::roll($account, $usage, self::entitled($account))) {
				$changed++;
				continue;
			}
			$account->set('hai_usage', max($usage, (int)$account->get('hai_usage')));
			$account->set('hai_usage_time', gmdate('Y-m-d H:i:s'));
			$account->save();
		}
		Setting::put('server_manager_hosted_ai_watch_time', gmdate('Y-m-d H:i:s'));
		return $changed;
	}

	/** Every key's usage, listed once per tick. */
	private function usage_list(): array {
		if ($this->usage === null) {
			$this->usage = $this->client->listUsage();
		}
		return $this->usage;
	}

	/** A key's lifetime usage from one listing per tick. A key not listed reads its last figure. */
	private function usage_of(string $hash): int {
		$this->usage_list();
		if (isset($this->usage[$hash])) {
			return (int)$this->usage[$hash]['usage'];
		}
		$account = HostedAiAccount::for_key_hash($hash);
		return $account ? (int)$account->get('hai_usage') : 0;
	}

	/** Once a day, tell the operator when our own balance is low. */
	private function check_balance(): void {
		$settings = Globalvars::get_instance();
		$today = gmdate('Y-m-d');
		if ((string)$settings->get_setting('server_manager_hosted_ai_balance_alert_day', false, true) === $today) {
			return;
		}
		$threshold_raw = trim((string)$settings->get_setting('server_manager_hosted_ai_low_balance_usd', true, true));
		if ($threshold_raw === '' || !is_numeric($threshold_raw) || (float)$threshold_raw <= 0) {
			return;
		}
		// Stamped for every check, not only an alert: once a day either way (B8).
		Setting::put('server_manager_hosted_ai_balance_alert_day', $today);
		$remaining = $this->client->remainingBalance();
		if ($remaining >= HostedAiCap::micro($threshold_raw)) {
			return;
		}
		$this->errors[] = 'our TrustedRouter balance is ' . HostedAiCap::dollars($remaining);
		self::notify_ops('[hosted] TrustedRouter balance is low: ' . HostedAiCap::dollars($remaining),
			"Our TrustedRouter balance is " . HostedAiCap::dollars($remaining) . ", below the alert figure of $"
			. number_format((float)$threshold_raw, 2) . ".\n\n"
			. "Every hosted site's AI credit draws on this one prepaid balance, so when it runs out AI stops on "
			. "every site at once. Add credit or check auto-refill at TrustedRouter.\n");
	}

	// ── Shared ────────────────────────────────────────────────────────────────

	private function note(HostedAiAccount $account, string $reason, bool $is_problem = true): void {
		$account->set('hai_note', mb_substr($reason, 0, 4000));
		$account->save();
		if ($is_problem) {
			$this->errors[] = self::label($account) . ': ' . $reason;
		}
	}

	/**
	 * Stop this leg, once, and tell somebody. The site is unaffected: it is
	 * up, and only its AI credit is outstanding. Retry AI setup on the node
	 * page restarts it.
	 */
	private function fail_leg(HostedAiAccount $account, string $reason): void {
		$hash = trim((string)$account->get('hai_key_hash'));
		if ($hash !== '') {
			// Switched off now and deleted on a later pass; if even that fails
			// the key stays on record, and Retry retires it before minting.
			try {
				HostedAiCredit::retire_key($account, $this->client, $this->usage_of($hash));
			} catch (Throwable $e) {
				$reason .= ' (The key could not be switched off yet: ' . $e->getMessage() . ')';
			}
		}
		$account->set('hai_state', HostedAiAccount::STATE_FAILED);
		$account->set('hai_note', mb_substr($reason, 0, 4000));
		$account->save();
		$this->errors[] = self::label($account) . ': AI setup FAILED — ' . $reason;
		self::notify_ops('[hosted] AI credit not set up: ' . self::label($account),
			"This site is up, but its AI credit was not set up.\n\n"
			. 'Site: ' . self::label($account) . "\n"
			. 'Reason: ' . $reason . "\n\n"
			. "Once the cause is fixed, press Retry AI setup on the site's node page.\n");
	}

	/** The operator alert chain the other legs use. */
	public static function notify_ops(string $subject, string $body): void {
		$settings = Globalvars::get_instance();
		$to = trim((string)$settings->get_setting('server_manager_provisioning_admin_alert_email'));
		if ($to === '') {
			$to = trim((string)$settings->get_setting('webmaster_email'));
		}
		if ($to === '') {
			return;
		}
		try {
			EmailSender::quickSend($to, $subject, $body);
		} catch (Throwable $e) {
			error_log('ProvisionHostedAi: alert send failed: ' . $e->getMessage());
		}
	}
}

<?php
/**
 * ProvisionHostedMail — outbound mail for a site this operator hosts.
 *
 * A hosted customer never opens an email-provider account. Their site sends
 * through the operator's SMTP2GO account, inside a SUBACCOUNT of their own,
 * with an SMTP user minted for them and nothing else standing. This leg is how
 * that gets built, one step per tick, each step stamped on the provision so a
 * crash resumes rather than repeats (specs/hosted_trial_provisioning.md §4.3).
 *
 * The order, and why:
 *
 *   pending             create the subaccount and set its monthly limit. The
 *                       cap is the PROVIDER's — it counts month-to-date sends
 *                       and refuses past the limit — because a cap written on
 *                       the customer's own box is advisory: they are permission
 *                       10 there and can edit it.
 *   subaccount_created  register the sending domain inside that subaccount and
 *                       keep the DNS records it asks for. They are kept on the
 *                       row, not just published, because where this plane does
 *                       NOT hold the zone they are what somebody has to publish
 *                       by hand, and a leg that forgot them would leave a
 *                       customer with no way to finish.
 *   domain_added        publish those records, where we hold the zone. Where we
 *                       do not, say so on the row and move on: waiting for
 *                       something nobody here can do is not a state, it is a
 *                       stall.
 *   records_published   ask the provider to verify. A wait, not a failure — DNS
 *                       takes minutes to be visible. Where we never published,
 *                       this passes through with the note standing.
 *   domain_verified     mint the SMTP user. This is the only credential that
 *                       reaches the customer's machine, it lives inside their
 *                       own subaccount, and it can be removed on its own.
 *   smtp_user_created   push the site's mail settings over the agent channel
 *                       (hosted_mail_settings). The password travels in the job,
 *                       is blanked when the node answers, and never appears in
 *                       job output.
 *   done                nothing further. The banners and the trial clock are
 *                       HostedTrialWatch's.
 *
 * NOTHING HERE EVER PUTS THE MASTER KEY ON A BOX. Every provider call is made
 * from this machine with the master key and carries subaccount_id; what crosses
 * to the customer is one username and one password, cut to their slice.
 *
 * A failure in this leg leaves a WORKING SITE. The site is already up by the
 * time this runs; mail is a step of setup, and an operator alert plus an honest
 * amber state on the wizard is the right answer to a provider outage — not a
 * failed provision.
 *
 * @version 1.0
 */

class ProvisionHostedMail {

	/** The states this leg advances, in order. 'done' and 'failed' are terminal. */
	const WORKING_STATES = array(
		'pending', 'subaccount_created', 'domain_added', 'records_published',
		'domain_verified', 'smtp_user_created',
	);

	/**
	 * How long the leg waits for the provider to verify a domain whose records
	 * this plane published before it moves on regardless.
	 *
	 * Moving on is right: the SMTP user and the site's settings do not depend on
	 * verification, and a site that can send unsigned mail today and signed mail
	 * an hour later is strictly better than a site that cannot send at all. The
	 * watch keeps asking; the wizard's Email step reads the real answer.
	 */
	const VERIFY_PATIENCE_HOURS = 6;

	/** The job type carrying a site's mail credentials to it. */
	const JOB_TYPE = 'hosted_mail_settings';

	/** @var array Human-readable problems for the run summary. */
	private $errors = array();

	/** @var Smtp2GoClient|null Resolved once per tick. */
	private $client = null;

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/registered_domains_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/Smtp2GoClient.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));
		require_once(PathHelper::getIncludePath('includes/dns/DnsReconciler.php'));
		require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

		$rows = new MultiCustomerCloudProvision(array(
			'hosting_mode' => 'operator',
			'mail_states'  => self::WORKING_STATES,
			'deleted'      => false,
		));
		$rows->load();
		if (count($rows) === 0) {
			return array('status' => 'skipped', 'message' => 'No hosted mail to set up.');
		}

		$this->client = Smtp2GoClient::fromSettings();
		if ($this->client === null) {
			// Not a failure of any one provision: nobody has configured the
			// account yet, and every hosted site is waiting on the same thing.
			return array('status' => 'error', 'message' => count($rows)
				. ' hosted site(s) are waiting for outbound mail, and no SMTP2GO master key is '
				. 'configured. Set it on the Provisioning Setup page.');
		}

		$advanced = 0;
		foreach ($rows as $provision) {
			try {
				$advanced += $this->advance($provision);
			} catch (Smtp2GoException $e) {
				$this->note_transient($provision, $e->getMessage());
			} catch (Throwable $e) {
				$this->note_transient($provision, $e->getMessage());
				error_log('ProvisionHostedMail: ' . $provision->get('cvp_domain') . ': ' . $e->getMessage());
			}
		}

		$message = 'Hosted mail: ' . $advanced . ' step(s) taken across ' . count($rows) . ' site(s).';
		if ($this->errors) {
			$message .= ' ' . count($this->errors) . ' problem(s): ' . implode('; ', array_slice($this->errors, 0, 3));
			if (count($this->errors) > 3) { $message .= ' …'; }
			return array('status' => 'error', 'message' => $message);
		}
		return array('status' => 'success', 'message' => $message);
	}

	/** One step for one provision. Returns 1 when the row moved. */
	private function advance($provision): int {
		// The site has to exist before its mail can be set up: the settings push
		// travels to the node's own agent, and the sending identity is the
		// domain the site answers on.
		if ((string)$provision->get('cvp_status') !== 'done') {
			return 0;
		}

		switch ((string)$provision->get('cvp_mail_state')) {
			case 'pending':            return $this->create_subaccount($provision);
			case 'subaccount_created': return $this->add_domain($provision);
			case 'domain_added':       return $this->publish_records($provision);
			case 'records_published':  return $this->verify_domain($provision);
			case 'domain_verified':    return $this->create_smtp_user($provision);
			case 'smtp_user_created':  return $this->push_settings($provision);
		}
		return 0;
	}

	// ── Step 1: the subaccount ────────────────────────────────────────────────

	private function create_subaccount($provision): int {
		$domain = strtolower(trim((string)$provision->get('cvp_domain')));
		$id = trim((string)$provision->get('cvp_smtp2go_subaccount_id'));
		if ($id === '') {
			// Created before the id is stamped, and the row is saved
			// immediately: a crash between the two would otherwise leave an
			// orphan subaccount at the provider that this plane never names
			// again. It is one call, so a duplicate is recoverable by hand; a
			// forgotten one is not.
			$id = $this->client->addSubaccount(
				'Joinery hosted — ' . $domain,
				trim((string)$provision->get('cvp_buyer_email')));
			$provision->set('cvp_smtp2go_subaccount_id', $id);
			$provision->save();
		}
		$this->client->setSubaccountLimit($id, self::send_allowance());
		$provision->set('cvp_mail_state', 'subaccount_created');
		$provision->set('cvp_mail_error', null);
		$provision->save();
		return 1;
	}

	// ── Step 2: the sending domain ────────────────────────────────────────────

	/**
	 * The sending identity is mail.<domain>, not the apex.
	 *
	 * A subdomain keeps the customer's own apex SPF and DKIM out of the
	 * operator's reach: a customer who later moves to their own provider, or who
	 * already sends from the apex through something else, is not fighting a
	 * record this plane published.
	 */
	public static function sending_domain(string $domain): string {
		return 'mail.' . strtolower(trim($domain));
	}

	private function add_domain($provision): int {
		$sender = self::sending_domain((string)$provision->get('cvp_domain'));
		$result = $this->client->addDomain(
			(string)$provision->get('cvp_smtp2go_subaccount_id'), $sender);

		$provision->set('cvp_smtp2go_domain_id', $result['id']);
		$provision->set('cvp_mail_records', json_encode($result['records']));

		// NO RECORDS IS A FAILURE, not a note. A sending domain with nothing to
		// publish never verifies, so mail from this site would be unsigned for
		// ever — while the subaccount, the domain and the SMTP user all looked
		// set up and every dashboard read green. The likeliest cause is this
		// client not recognising the shape the provider answered in, which is
		// exactly the kind of thing that must stop the line rather than pass
		// quietly through it.
		if (!$result['records']) {
			$this->fail_leg($provision,
				'The provider registered the sending domain but this platform could not read any DNS '
				. 'records out of its answer. Nothing was published, so mail from this site would be '
				. 'unsigned. Capture the domain/add response and check Smtp2GoProvider::recordsOf '
				. 'against it before retrying.');
			return 1;
		}

		$provision->set('cvp_mail_state', 'domain_added');
		$provision->set('cvp_mail_error', null);
		$provision->save();
		return 1;
	}

	// ── Step 3: publish what the provider asked for ───────────────────────────

	private function publish_records($provision): int {
		$records = self::records_of($provision);
		$domain  = strtolower(trim((string)$provision->get('cvp_domain')));

		if (!$records) {
			// Unreachable in practice — add_domain fails the leg on zero records
			// — but a row hand-edited back to this state must not be read as
			// "nothing to do" and marched on to a domain that will never verify.
			$this->fail_leg($provision,
				'This provision has no mail records to publish, so its sending domain cannot verify.');
			return 1;
		}

		$row = $this->registered_domain($domain);
		if ($row === null) {
			// The buyer brought their own domain, so this plane holds no
			// credential for its zone and never will. Say what has to be
			// published and by whom, and carry on — the site can still send.
			$provision->set('cvp_mail_error',
				'This domain is not registered through us, so its mail records have to be published in '
				. 'whoever holds the zone. Until they are, mail from this site is unsigned and more '
				. 'likely to be filtered. The records are on this provision.');
			$provision->set('cvp_mail_state', 'records_published');
			$provision->save();
			return 1;
		}

		$plan = new DnsRecordPlan($domain, 'server_manager');
		foreach ($records as $record) {
			$plan->addRecord($record['type'], $record['name'], $record['value'], null,
				$record['priority'] ?? null, (string)($record['note'] ?? ''));
		}

		$driver = $this->dns_driver();
		if ($driver === null) {
			$this->note_transient($provision,
				'No DNS driver for the configured registrar; the sending domain\'s records were not published.');
			return 0;
		}
		try {
			$results = (new DnsReconciler())->apply($driver, $domain, $plan,
				array(), DnsReconciler::APPLY_ADDITIVE);
		} catch (Throwable $e) {
			$this->note_transient($provision, 'Publishing the sending domain\'s records: ' . $e->getMessage());
			return 0;
		}
		$failed = array();
		foreach ($results as $result) {
			if (empty($result['ok'])) {
				$failed[] = trim((string)($result['reason'] ?? 'unknown reason'));
			}
		}
		if ($failed) {
			$this->note_transient($provision,
				'Publishing the sending domain\'s records: ' . implode('; ', $failed));
			return 0;
		}

		$provision->set('cvp_mail_state', 'records_published');
		$provision->set('cvp_mail_error', null);
		$provision->save();
		return 1;
	}

	// ── Step 4: let the provider check them ───────────────────────────────────

	private function verify_domain($provision): int {
		$sender = self::sending_domain((string)$provision->get('cvp_domain'));
		$verified = $this->client->verifyDomain(
			(string)$provision->get('cvp_smtp2go_subaccount_id'), $sender);

		if ($verified) {
			$provision->set('cvp_mail_state', 'domain_verified');
			$provision->set('cvp_mail_error', null);
			$provision->save();
			return 1;
		}

		// Not yet. Wait a while — records take minutes to be visible — but not
		// forever: the SMTP user and the site's settings do not depend on this,
		// and a site that cannot send at all is worse than one whose mail is
		// unsigned for an afternoon. HostedTrialWatch keeps asking afterwards.
		$since = strtotime((string)($provision->get('cvp_update_time')
			?: $provision->get('cvp_create_time')) . ' UTC');
		if ($since !== false && (time() - $since) > self::VERIFY_PATIENCE_HOURS * 3600) {
			$provision->set('cvp_mail_state', 'domain_verified');
			$provision->set('cvp_mail_error',
				'The provider has not verified this sending domain yet, so mail from this site is '
				. 'unsigned and more likely to be filtered. Setup continued; verification is retried.');
			$provision->save();
			$this->errors[] = $provision->get('cvp_domain')
				. ': the sending domain is still unverified; setup continued without it.';
			return 1;
		}
		return 0;
	}

	// ── Step 5: the one credential that reaches the box ───────────────────────

	private function create_smtp_user($provision): int {
		// ASKED BEFORE ANYTHING IS MINTED. A node that cannot take the mail
		// settings cannot be handed the credential, and minting one anyway would create
		// an SMTP user per tick inside the customer's subaccount for as long as
		// its agent stayed old. The wait is transient by nature — an agent
		// update fixes it — so this notes and returns rather than failing.
		if (!$this->node_can_take_settings($provision)) {
			return 0;
		}
		$subaccount = (string)$provision->get('cvp_smtp2go_subaccount_id');
		$username = Smtp2GoClient::mintUsername((string)$provision->get('cvp_slug'));
		$password = Smtp2GoClient::mintPassword();

		$user = $this->client->addSmtpUser($subaccount, $username, $password);

		// The username is recorded so the credential can be revoked by name;
		// the password is NOT stored. It exists for exactly as long as it takes
		// to push it to the box, and a replacement is one call away — which is
		// a better property than a copy of it sitting in this database.
		$provision->set('cvp_smtp2go_user_id', $user['username'] !== '' ? $user['username'] : $user['id']);
		$provision->set('cvp_mail_state', 'smtp_user_created');
		$provision->save();

		return $this->dispatch_settings($provision, $user['username'], $user['password']) ? 1 : 0;
	}

	// ── Step 6: tell the site how to send ─────────────────────────────────────

	/**
	 * How many times the credential is re-minted before this leg gives up.
	 *
	 * A retry HAS to mint: the password was never stored, so there is nothing
	 * to re-send. That makes an unbounded retry a machine that creates one SMTP
	 * user every fifteen minutes, forever, inside the customer's subaccount —
	 * and the causes that would drive it are all permanent ones, not blips: an
	 * agent too old for the primitive, a site release without the pushable
	 * flags, a deleted node. Three attempts distinguish a transient failure
	 * from a broken configuration; past that a person is what is needed.
	 */
	const MAX_PUSH_ATTEMPTS = 3;

	/**
	 * smtp_user_created is where the row sits while the push travels. Each
	 * outcome is read from the job, not from a stamp: queued or running is a
	 * wait, completed is done, failed mints a fresh credential and pushes again
	 * — up to MAX_PUSH_ATTEMPTS, after which the leg FAILS and says so rather
	 * than minting for ever.
	 */
	private function push_settings($provision): int {
		$node = $this->node_of($provision);
		if ($node === null) {
			$this->fail_leg($provision, 'This provision has no node to push mail settings to.');
			return 1;
		}
		$job = ManagementJob::latestForNode($node->key, self::JOB_TYPE);
		if ($job && in_array((string)$job->get('mjb_status'), array('queued', 'pending', 'running'), true)) {
			return 0;
		}
		if ($job && (string)$job->get('mjb_status') === 'completed') {
			$provision->set('cvp_mail_state', 'done');
			$provision->set('cvp_mail_error', null);
			$provision->save();
			error_log('ProvisionHostedMail: outbound mail configured for ' . $provision->get('cvp_domain'));
			return 1;
		}

		if (!$this->node_can_take_settings($provision)) {
			return 0;
		}

		$attempts = $this->push_attempts($node);
		if ($attempts >= self::MAX_PUSH_ATTEMPTS) {
			$this->fail_leg($provision, 'The site would not take its mail settings after '
				. $attempts . ' attempts' . ($job ? ' (job #' . $job->key . ': '
					. trim((string)$job->get('mjb_error_message')) . ')' : '')
				. '. Its agent may predate the hosted_mail_settings primitive, or its release may not '
				. 'carry the node-side script that owns those setting names. The subaccount and '
				. 'sending domain are set up; only the handover to the box is outstanding.');
			return 1;
		}

		// Mint afresh: the previous password was never kept, so there is nothing
		// to re-send. A spare SMTP user inside the customer's own subaccount is
		// a cost worth paying for a site that can send — bounded by the attempt
		// count above so it stays a cost and not a leak.
		$username = Smtp2GoClient::mintUsername((string)$provision->get('cvp_slug'));
		$password = Smtp2GoClient::mintPassword();
		$user = $this->client->addSmtpUser(
			(string)$provision->get('cvp_smtp2go_subaccount_id'), $username, $password);
		$provision->set('cvp_smtp2go_user_id', $user['username'] !== '' ? $user['username'] : $user['id']);
		$provision->save();
		return $this->dispatch_settings($provision, $user['username'], $user['password']) ? 1 : 0;
	}

	/**
	 * Can this node be handed settings at all?
	 *
	 * The question is asked before a credential is minted rather than after,
	 * because the answer is stable — an agent below 1.20.0 does not start
	 * offering the primitive between two ticks — and every tick that asked it
	 * the other way round would leave a spent SMTP user behind.
	 */
	private function node_can_take_settings($provision): bool {
		$node = $this->node_of($provision);
		if ($node === null) {
			$this->note_transient($provision, 'This provision has no node to push mail settings to.');
			return false;
		}
		if (!JobCommandBuilder::has_primitive($node, self::JOB_TYPE)) {
			$this->note_transient($provision,
				'This site\'s agent does not offer the hosted_mail_settings primitive yet, so its mail '
				. 'credentials cannot be handed over. Apply an update to the node; setup resumes on '
				. 'its own. Nothing has been minted for it in the meantime.');
			return false;
		}
		return true;
	}

	/** How many mail-settings pushes this node has already been sent. */
	private function push_attempts($node): int {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT COUNT(*) FROM mjb_management_jobs
			 WHERE mjb_mgn_node_id = ? AND mjb_job_type = ? AND mjb_delete_time IS NULL");
		$q->execute(array((int)$node->key, self::JOB_TYPE));
		return (int)$q->fetchColumn();
	}

	/**
	 * The mail settings a hosted site runs on.
	 *
	 * VALUES ONLY. Which settings these land in is decided by
	 * utils/hosted_mail_settings.php on the node — this end cannot express a
	 * setting name, and that is what stops the same channel being able to
	 * redirect the mail of any other node this plane manages.
	 */
	private function dispatch_settings($provision, string $username, string $password): bool {
		$node = $this->node_of($provision);
		if ($node === null) {
			$this->note_transient($provision, 'The provision has no node to push mail settings to.');
			return false;
		}
		$sender = self::sending_domain((string)$provision->get('cvp_domain'));

		try {
			$built = JobCommandBuilder::build_hosted_mail_settings($node, array(
				'service'  => 'smtp',
				'host'     => 'mail.smtp2go.com',
				'port'     => 587,
				'username' => $username,
				'password' => $password,
				// The envelope sender and the HELO name are the SENDING
				// identity, which is the subdomain the provider verified — not
				// the apex the site answers on. Getting this wrong is the
				// difference between mail that authenticates and mail that
				// lands in spam.
				'sender'   => 'bounces@' . $sender,
				'helo'     => $sender,
				'hostname' => $sender,
			));
		} catch (Throwable $e) {
			$this->note_transient($provision, 'Cannot push mail settings — ' . $e->getMessage());
			return false;
		}
		// createFromBuild, not createJob: this is a primitive envelope, and only
		// that entry point stores one correctly.
		ManagementJob::createFromBuild($node->key, self::JOB_TYPE, $built,
			array('provision_id' => (int)$provision->key), null);
		return true;
	}

	// ── Shared ────────────────────────────────────────────────────────────────

	/** The monthly send allowance every hosted subaccount is capped at. */
	public static function send_allowance(): int {
		$value = (int)Globalvars::get_instance()->get_setting(
			'server_manager_hosted_send_allowance', true, true);
		return $value > 0 ? $value : 1000;
	}

	/** The DNS records the provider described, as stored. */
	public static function records_of($provision): array {
		$raw = $provision->get('cvp_mail_records');
		if (is_string($raw)) { $raw = json_decode($raw, true); }
		return is_array($raw) ? $raw : array();
	}

	/** The managed-domain row for this domain, or null when we do not hold it. */
	private function registered_domain(string $domain) {
		$rows = new MultiRegisteredDomain(array('domain' => $domain, 'deleted' => false));
		foreach ($rows as $row) {
			if (in_array((string)$row->get('rdm_status'),
					array(RegisteredDomain::STATUS_REGISTERED, RegisteredDomain::STATUS_ACTIVE), true)) {
				return $row;
			}
		}
		return null;
	}

	/** The provision's node, or null. */
	private function node_of($provision) {
		$id = (int)$provision->get('cvp_mgn_node_id');
		if (!$id) { return null; }
		$node = new ManagedNode($id, TRUE);
		return ($node->key && !$node->get('mgn_delete_time')) ? $node : null;
	}

	/** The registrar's DNS driver, or null. Overridable for tests. */
	protected function dns_driver() {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/DomainRegistrarRegistry.php'));
		$registrar = DomainRegistrarRegistry::firstConfigured();
		if ($registrar === null) { return null; }
		$class = DnsDriverRegistry::get($registrar->dnsDriverKey());
		return $class === null ? null : new $class($registrar->dnsCredential());
	}

	/** Record a problem and leave the row where it is, to be retried. */
	private function note_transient($provision, string $reason): void {
		$provision->set('cvp_mail_error', mb_substr($reason, 0, 4000));
		$provision->save();
		$this->errors[] = $provision->get('cvp_domain') . ': ' . $reason;
	}

	/**
	 * Stop this leg, once, and tell somebody.
	 *
	 * The alternative to a terminal state is a leg that reports the same error
	 * on every tick for ever, which is how an operator learns to stop reading
	 * the provisioning summary. The site itself is unaffected — it is up, and
	 * only its outbound mail is outstanding — so this is an alert, not a failed
	 * provision. Clearing cvp_mail_state back to 'pending' by hand is what
	 * restarts it once the cause is fixed.
	 */
	private function fail_leg($provision, string $reason): void {
		$provision->set('cvp_mail_state', 'failed');
		$provision->set('cvp_mail_error', mb_substr($reason, 0, 4000));
		$provision->save();
		$this->errors[] = $provision->get('cvp_domain') . ': mail setup FAILED — ' . $reason;
		error_log('ProvisionHostedMail: mail setup failed for ' . $provision->get('cvp_domain')
			. ': ' . $reason);
		$this->notify_ops('[hosted] Outbound mail not set up: ' . $provision->get('cvp_domain'),
			"This site is up, but its outbound mail was not finished.\n\n"
			. 'Domain: ' . $provision->get('cvp_domain') . "\n"
			. 'Buyer: ' . $provision->get('cvp_buyer_email') . "\n"
			. 'Reason: ' . $reason . "\n\n"
			. "Until it sends, this customer cannot reset their own password. Clear cvp_mail_state on "
			. "the provision row to start the leg again once the cause is fixed.\n");
	}

	/**
	 * Alert recipient chain, the same one the compute pipeline uses:
	 * provisioning_admin_alert_email -> webmaster_email -> first superadmin.
	 */
	private function notify_ops(string $subject, string $body): void {
		$settings = Globalvars::get_instance();
		$to = trim((string)$settings->get_setting('server_manager_provisioning_admin_alert_email'));
		if ($to === '') {
			$to = trim((string)$settings->get_setting('webmaster_email'));
		}
		if ($to === '') {
			$admins = new MultiUser(array('permission_range' => array(10, 10), 'deleted' => false,
				'not_system_users' => true), array('usr_user_id' => 'ASC'), 1);
			foreach ($admins as $admin) {
				$to = trim((string)$admin->get('usr_email'));
				break;
			}
		}
		if ($to === '') {
			return;
		}
		try {
			EmailSender::quickSend($to, $subject, $body);
		} catch (\Throwable $e) {
			error_log('ProvisionHostedMail: alert send failed: ' . $e->getMessage());
		}
	}
}

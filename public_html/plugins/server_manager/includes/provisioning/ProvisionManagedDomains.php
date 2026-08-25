<?php
/**
 * ProvisionManagedDomains - buy the name, wire it to the box, and get out.
 *
 * One phase of the provisioning umbrella task. It works every managed-domain
 * row that is not finished or parked, doing at most one step per row per tick
 * so a stuck step never blocks the others and a slow registrar never holds the
 * whole pipeline.
 *
 * The state machine, and what guards each step from repeating:
 *
 *   pending    -> register()             guarded by STATUS, not a timestamp:
 *                                        a stamp written after a charge is one
 *                                        crash away from a second charge, so
 *                                        only a pending row may buy.
 *   registered -> apex + www A records   guarded by rdm_dns_bootstrap_time
 *              -> the mail record set    guarded by rdm_dns_mail_time
 *              -> PTR                    guarded by rdm_ptr_time
 *   all three stamped -> active          and the node is told what it holds.
 *
 * Each null timestamp is an outstanding step retried next tick; a stamped one
 * is never redone. That is the whole idempotency story — there is no separate
 * ledger to keep in sync with reality.
 *
 * DNS is published through the shared reconciler in ADDITIVE mode, never by
 * calling a driver directly. Namecheap's setHosts replaces a zone's entire
 * host list, so the reconciler's read-diff-write is the only safe writer; and
 * additive means this phase can create records the zone lacks but will never
 * overwrite something a person put there.
 *
 * The mail records are NOT computed here. They are asked of the box itself,
 * over SSH, because the box is what knows its own topology, its SPF shape, its
 * DKIM key and whether it speaks Joinery Direct. A control plane that guessed
 * would publish a plausible record set the box does not actually match.
 *
 * @version 1.1 - send_failure_alert() is a protected seam, so tests intercept the mail edge
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/DomainRegistrarRegistry.php'));

class ProvisionManagedDomains {

	/** @var array Human-readable problems for the run summary. */
	private $errors = array();

	/** @var DomainRegistrarProvider|null Cached for the whole tick. */
	private $registrar = null;

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/registered_domains_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeDnsPlan.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeReverseDns.php'));
		require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));
		require_once(PathHelper::getIncludePath('includes/dns/DnsReconciler.php'));
		require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

		if ($this->get_registrar() === null) {
			return array('status' => 'skipped', 'message' => 'No registrar configured');
		}

		$rows = new MultiRegisteredDomain(array(
			'statuses' => array(RegisteredDomain::STATUS_PENDING, RegisteredDomain::STATUS_REGISTERED),
			'deleted'  => false,
		));
		$rows->load();
		if (count($rows) === 0) {
			return array('status' => 'skipped', 'message' => 'No managed domains to advance.');
		}

		$advanced = 0;
		foreach ($rows as $row) {
			try {
				$advanced += $this->advance($row);
			} catch (Throwable $e) {
				$this->errors[] = 'Domain ' . $row->get('rdm_domain') . ': ' . $e->getMessage();
				error_log('ProvisionManagedDomains: ' . $row->get('rdm_domain') . ': ' . $e->getMessage());
			}
		}

		$message = 'Managed domains: ' . $advanced . ' step(s) taken across ' . count($rows) . ' row(s).';
		if ($this->errors) {
			$message .= ' ' . count($this->errors) . ' error(s): ' . implode('; ', array_slice($this->errors, 0, 3));
			if (count($this->errors) > 3) { $message .= ' …'; }
			return array('status' => 'error', 'message' => $message);
		}
		return array('status' => 'success', 'message' => $message);
	}

	/** One row, at most one step. Returns 1 when something changed. */
	private function advance($row): int {
		// Every step needs the box: registration needs nothing from it, but the
		// DNS that follows points at its address, and buying a name we then
		// cannot wire up helps nobody. Waiting costs nothing — the compute leg
		// is working in parallel and the row is picked up again next tick.
		$node = $this->resolve_node($row);
		if ($node === null) {
			return 0;
		}
		$ip = NodeDnsPlan::publicIp($node);
		if ($ip === '') {
			return 0;
		}

		if ($row->get('rdm_status') === RegisteredDomain::STATUS_PENDING) {
			return $this->register_domain($row);
		}

		if (!$row->get('rdm_dns_bootstrap_time')) {
			return $this->bootstrap_dns($row, $node, $ip);
		}
		if (!$row->get('rdm_dns_mail_time')) {
			return $this->mail_dns($row, $node);
		}
		if (!$row->get('rdm_ptr_time')) {
			return $this->set_ptr($row, $node);
		}

		return $this->activate($row, $node);
	}

	// ==================================================================
	// Step 1 — find the box this domain belongs to
	// ==================================================================

	/**
	 * The node the compute leg built for the same order item, or null while it
	 * is still building. Both fulfillment modes are looked up by the order item
	 * they share, which is the only thing the two legs are guaranteed to agree
	 * on: customer-cloud carries it on the provision row, shared-host on the
	 * install job.
	 */
	private function resolve_node($row) {
		$node_id = (int)$row->get('rdm_mgn_node_id');
		if ($node_id > 0) {
			$node = new ManagedNode($node_id, TRUE);
			return $node->key ? $node : null;
		}

		$order_item_id = (int)$row->get('rdm_external_order_item_id');
		if ($order_item_id <= 0) {
			return null;
		}

		$found_id = 0;
		$provisions = new MultiCustomerCloudProvision(array(
			'external_order_item_id' => $order_item_id, 'deleted' => false));
		$provisions->load();
		foreach ($provisions as $provision) {
			$found_id = (int)$provision->get('cvp_mgn_node_id');
			break;
		}

		if ($found_id <= 0) {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT mjb_mgn_node_id FROM mjb_management_jobs '
				. 'WHERE mjb_external_order_item_id = ? AND mjb_delete_time IS NULL '
				. 'ORDER BY mjb_id DESC LIMIT 1');
			$q->execute(array($order_item_id));
			$found_id = (int)$q->fetchColumn();
		}

		if ($found_id <= 0) {
			return null;   // compute leg still working
		}

		$node = new ManagedNode($found_id, TRUE);
		if (!$node->key) {
			return null;
		}
		$row->set('rdm_mgn_node_id', $node->key);
		$row->save();
		return $node;
	}

	// ==================================================================
	// Step 2 — buy the name
	// ==================================================================

	private function register_domain($row): int {
		$registrar = $this->get_registrar();
		$domain = (string)$row->get('rdm_domain');

		$unpaid = $this->unpaid_reason($row);
		if ($unpaid !== '') {
			$this->fail_and_alert($row, $unpaid);
			return 1;
		}

		$registrant = $row->open_registrant();
		if (!is_array($registrant) || trim((string)($registrant['email'] ?? '')) === '') {
			$this->fail_and_alert($row, 'The registrant contact block is missing or unreadable, so '
				. $domain . ' cannot be registered with the buyer as its owner.');
			return 1;
		}

		try {
			$answers = $registrar->checkAvailability(array($domain));
		} catch (DomainRegistrarException $e) {
			return $this->note_transient($row, 'availability', $e);
		}
		$answer = $answers[$domain] ?? array();

		if (empty($answer['available'])) {
			// "Unavailable" from a registrar we may already have bought it from
			// is ambiguous, and the ambiguity is expensive: a create that
			// succeeded and then failed to record itself looks exactly like a
			// name someone else took. Asking whether WE hold it settles it
			// without risking a second charge.
			$owned_expiry = null;
			try {
				$owned_expiry = $registrar->getExpiry($domain);
			} catch (DomainRegistrarException $e) {
				return $this->note_transient($row, 'ownership recheck', $e);
			}
			if ($owned_expiry !== null) {
				$this->mark_registered($row, $owned_expiry);
				return 1;
			}
			$this->fail_and_alert($row, 'The registrar reports ' . $domain . ' is no longer available'
				. (empty($answer['message']) ? '.' : ': ' . $answer['message'])
				. ' Nothing was charged by us for it — resolve with the buyer (refund, or an alternate name).');
			return 1;
		}

		try {
			$result = $registrar->register($domain, $registrant, 1);
		} catch (DomainRegistrarException $e) {
			if ($e->transient) {
				return $this->note_transient($row, 'register', $e);
			}
			$this->fail_and_alert($row, 'Registration refused: ' . $e->getMessage());
			return 1;
		}

		$this->mark_registered($row, (string)($result['expiry'] ?? ''));

		// Privacy is asked for in the create call; this only verifies it stuck.
		// A failure here is worth an operator's attention but is not worth
		// undoing a successful registration over.
		try {
			$registrar->applyWhoisPrivacy($domain);
		} catch (DomainRegistrarException $e) {
			$this->errors[] = 'WHOIS privacy for ' . $domain . ' could not be confirmed: ' . $e->getMessage();
			error_log('ProvisionManagedDomains: WHOIS privacy for ' . $domain . ': ' . $e->getMessage());
		}
		return 1;
	}

	/**
	 * Why this domain must not be bought, or '' when the order paid for it.
	 *
	 * The checkout answers and the money are two different objects, and the
	 * cart lets a buyer separate them: every line carries its own Edit and
	 * Remove, so the domain-year line can be deleted, or repriced through its
	 * own product page, while the hosting line it came from is submitted
	 * unchanged. The intake reads the hosting line's answers, so without this
	 * check a domain removed from the cart would still be registered — bought
	 * on the operator's card, for free, silently.
	 *
	 * The rule is simply that the order has to contain a paid domain-year line
	 * worth at least the quote, and that each such line backs at most one
	 * registration. Anything else parks for a person rather than proceeding:
	 * "the buyer did not pay for this" is a decision, not an error.
	 */
	private function unpaid_reason($row): string {
		require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));

		$order_item_id = (int)$row->get('rdm_external_order_item_id');
		$quote = (float)$row->get('rdm_price_paid');
		if ($order_item_id <= 0 || $quote <= 0) {
			// An admin-created or hand-repaired row carries no order to check
			// against; the operator who made it is the authorization.
			return '';
		}

		$settings = Globalvars::get_instance();
		$product_id = (int)$settings->get_setting('store_domain_registration_product_id', false, true);
		if ($product_id <= 0) {
			return 'No domain-year product is configured, so there is no way to confirm '
				. $row->get('rdm_domain') . ' was actually paid for. Nothing was registered.';
		}

		$parent = new OrderItem($order_item_id, TRUE);
		$order_id = $parent->key ? (int)$parent->get('odi_ord_order_id') : 0;
		if ($order_id <= 0) {
			return 'The order behind ' . $row->get('rdm_domain') . ' could not be read, so payment '
				. 'for it could not be confirmed. Nothing was registered.';
		}

		$paid_lines = 0;
		$lines = new MultiOrderItem(array('order_id' => $order_id, 'product_id' => $product_id));
		$lines->load();
		foreach ($lines as $line) {
			if ((int)$line->get('odi_status') === OrderItem::STATUS_PAID
					&& (float)$line->get('odi_price') + 0.001 >= $quote) {
				$paid_lines++;
			}
		}
		if ($paid_lines === 0) {
			return 'The order contains no paid domain-registration line worth ' . $quote . ' for '
				. $row->get('rdm_domain') . ' — it was most likely removed from the cart or '
				. 'repriced before checkout. Nothing was registered; resolve with the buyer.';
		}

		// One line, one domain: a cart with two hosting items and two domains
		// pays for two, and must not register three. Counted with one join
		// rather than by walking every registration on the deployment — the
		// question is about this order, not about the estate.
		$claimed = $this->claimed_registrations($order_id, (int)$row->key);
		if ($claimed >= $paid_lines) {
			return 'The order paid for ' . $paid_lines . ' domain registration(s) and that many are '
				. 'already registered, so ' . $row->get('rdm_domain') . ' has nothing backing it. '
				. 'Nothing was registered; resolve with the buyer.';
		}
		return '';
	}

	/**
	 * How many domains on this order are already bought, excluding this row.
	 *
	 * A join because no model spans the store's order items and this plugin's
	 * registrations, and because the alternative — loading every registration
	 * and asking each one which order it belongs to — is a query per row.
	 */
	private function claimed_registrations(int $order_id, int $except_rdm_id): int {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare(
				'SELECT count(*) FROM rdm_registered_domains r
				 JOIN odi_order_items p ON p.odi_order_item_id = r.rdm_external_order_item_id
				 WHERE p.odi_ord_order_id = ?
				   AND r.rdm_delete_time IS NULL
				   AND r.rdm_id <> ?
				   AND r.rdm_status IN (?, ?)');
			$q->execute(array($order_id, $except_rdm_id,
				RegisteredDomain::STATUS_REGISTERED, RegisteredDomain::STATUS_ACTIVE));
			return (int)$q->fetchColumn();
		} catch (Throwable $e) {
			error_log('ProvisionManagedDomains: could not count registrations on order '
				. $order_id . ': ' . $e->getMessage());
			// Counting failed, so the "is it already claimed" question is
			// unanswered. Report it as claimed: refusing to buy is recoverable,
			// buying a domain twice is not.
			return PHP_INT_MAX;
		}
	}

	private function mark_registered($row, string $expiry): void {
		$row->set('rdm_status', RegisteredDomain::STATUS_REGISTERED);
		$row->set('rdm_registered_time', gmdate('Y-m-d H:i:s'));
		if ($expiry !== '') {
			$row->set('rdm_expiry_time', $expiry);
			$row->set('rdm_expiry_checked_time', gmdate('Y-m-d H:i:s'));
		}
		$row->set('rdm_error', null);
		$row->save();
	}

	// ==================================================================
	// Step 3 — point the name at the box
	// ==================================================================

	/**
	 * Apex and www. This is the record certificate issuance waits on, so
	 * publishing it is also what unblocks ProvisionPendingSsl — the buyer
	 * never touches DNS and never sees a certificate error.
	 */
	private function bootstrap_dns($row, $node, string $ip): int {
		$domain = (string)$row->get('rdm_domain');
		$type = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';

		$plan = new DnsRecordPlan($domain, 'server_manager');
		$plan->addRecord($type, $domain, $ip, null, null,
			'Points ' . $domain . ' at this site\'s server.');
		$plan->addRecord($type, 'www.' . $domain, $ip, null, null,
			'Points www.' . $domain . ' at the same server.');

		// The node's own site-domain record, when the box was named for this
		// same domain — merged rather than duplicated.
		$node_plan = NodeDnsPlan::forNode($node);
		if ($node_plan !== null && $node_plan->getDomain() === $domain) {
			$plan->merge($node_plan);
		}

		$result = $this->publish($row, $plan, 'apex and www');
		if (!$result) {
			return 0;
		}
		$row->set('rdm_dns_bootstrap_time', gmdate('Y-m-d H:i:s'));
		$row->set('rdm_error', null);
		$row->save();
		return 1;
	}

	// ==================================================================
	// Step 4 — turn email on for the domain
	// ==================================================================

	/**
	 * Ask the box to make itself mail-ready for the domain and hand back the
	 * record set that describes the result, then publish it.
	 *
	 * Waits for the install to finish first: a box mid-install has no mailbox
	 * plugin state to answer from, and its answer would be wrong rather than
	 * absent.
	 */
	private function mail_dns($row, $node): int {
		if (trim((string)$node->get('mgn_install_state')) !== '') {
			return 0;   // still installing
		}

		$domain = (string)$row->get('rdm_domain');
		$payload = $this->prepare_on_node($node, $domain);
		if ($payload === null) {
			// Treated as transient in every case, including a node too old to
			// carry the utility: a control plane that gave up here would leave
			// a paid-for domain with no mail and no path back.
			$row->set('rdm_error', mb_substr('Transient (mail DNS): the node could not prepare '
				. $domain . ' for mail.', 0, 4000));
			$row->save();
			$this->errors[] = 'Mail preparation for ' . $domain . ' did not answer; will retry.';
			return 0;
		}
		if (empty($payload['ok'])) {
			$row->set('rdm_error', mb_substr('Transient (mail DNS): ' . (string)($payload['error']
				?? 'the node refused to prepare the domain'), 0, 4000));
			$row->save();
			$this->errors[] = 'Mail preparation for ' . $domain . ': '
				. (string)($payload['error'] ?? 'refused');
			return 0;
		}

		$plan = new DnsRecordPlan($domain, 'mailbox');
		foreach ((array)($payload['records'] ?? array()) as $record) {
			$type = strtoupper(trim((string)($record['type'] ?? '')));
			$name = trim((string)($record['name'] ?? ''));
			$value = (string)($record['value'] ?? '');
			if ($type === '' || $name === '' || $value === '') {
				continue;
			}
			$priority = isset($record['priority']) && $record['priority'] !== null
				? (int)$record['priority'] : null;
			$plan->addRecord($type, $name, $value, null, $priority,
				(string)($record['note'] ?? ''));
		}

		if (count($plan) === 0) {
			$this->errors[] = 'The node returned no mail records for ' . $domain . '; will retry.';
			return 0;
		}

		if (!$this->publish($row, $plan, 'mail records')) {
			return 0;
		}

		// A record set without DKIM is publishable and useful — MX and SPF are
		// what make mail arrive — but it is not finished, and stamping it would
		// mean the signing key never gets published at all. So publish, do not
		// stamp, and come back for it.
		if (empty($payload['dkim_ready'])) {
			$this->errors[] = 'Mail records for ' . $domain
				. ' published without DKIM; waiting for the signing key.';
			return 0;
		}

		$row->set('rdm_dns_mail_time', gmdate('Y-m-d H:i:s'));
		$row->set('rdm_error', null);
		$row->save();
		return 1;
	}

	// ==================================================================
	// Step 5 — reverse DNS
	// ==================================================================

	/**
	 * Make the box's address answer with its mail hostname. Receiving mail
	 * servers check this, and a mismatch is one of the quieter reasons
	 * legitimate mail lands in spam.
	 */
	private function set_ptr($row, $node): int {
		$domain = (string)$row->get('rdm_domain');

		if (NodeReverseDns::provisionForNode($node) === null) {
			// A shared host's address serves many domains, so no per-domain PTR
			// is even meaningful — and the host's own PTR already stands. This
			// is a finished step, not a skipped one.
			$row->set('rdm_ptr_time', gmdate('Y-m-d H:i:s'));
			$row->set('rdm_error', null);
			$row->save();
			return 1;
		}

		$result = NodeReverseDns::setQuietly($node, 'mail.' . $domain);
		if (empty($result['ok'])) {
			// Its forward-check gate needs the mail A record to have propagated
			// first, so "not yet" is the ordinary answer for a minute or two.
			$row->set('rdm_error', mb_substr('Transient (PTR): ' . (string)$result['message'], 0, 4000));
			$row->save();
			return 0;
		}
		$row->set('rdm_ptr_time', gmdate('Y-m-d H:i:s'));
		$row->set('rdm_error', null);
		$row->save();
		return 1;
	}

	// ==================================================================
	// Step 6 — done
	// ==================================================================

	private function activate($row, $node): int {
		$row->set('rdm_status', RegisteredDomain::STATUS_ACTIVE);
		$row->set('rdm_error', null);
		$row->save();

		// The box is told its domain and expiry now, with no custody state —
		// so it holds the facts but says nothing. The take-ownership notice
		// only appears when the watcher pushes a state, six months out.
		$this->push_banner_state($row, $node, '');
		return 1;
	}

	/**
	 * Write the managed-domain settings onto the node.
	 *
	 * The command is built by ManagedDomainWatch, which owns that shape, but
	 * sent through this phase's own SSH seam so there is one overridable edge
	 * per class rather than a second connection path nothing can intercept.
	 */
	protected function push_banner_state($row, $node, string $state): bool {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ManagedDomainWatch.php'));
		$command = ManagedDomainWatch::buildBannerCommand($row, $node, $state);
		if ($command === '') {
			return false;
		}
		$run = $this->run_on_node($node, $command);
		if (!$run['ok']) {
			error_log('ProvisionManagedDomains: banner push to ' . $node->get('mgn_name')
				. ' failed (' . $run['code'] . '): ' . $run['output']);
			return false;
		}
		return true;
	}

	// ==================================================================
	// Shared plumbing
	// ==================================================================

	/** The configured registrar, or null. Overridable for tests. */
	protected function get_registrar() {
		if ($this->registrar === null) {
			$this->registrar = DomainRegistrarRegistry::firstConfigured();
		}
		return $this->registrar;
	}

	/** The DNS driver that serves this registrar's zones. Overridable for tests. */
	protected function get_dns_driver() {
		$registrar = $this->get_registrar();
		if ($registrar === null) {
			return null;
		}
		$class = DnsDriverRegistry::get($registrar->dnsDriverKey());
		if ($class === null) {
			return null;
		}
		return new $class($registrar->dnsCredential());
	}

	/** The reconciler. Overridable for tests. */
	protected function get_reconciler() {
		return new DnsReconciler();
	}

	/**
	 * Publish a plan additively. Returns true when every record either landed
	 * or was already right.
	 */
	private function publish($row, DnsRecordPlan $plan, string $what): bool {
		$driver = $this->get_dns_driver();
		if ($driver === null) {
			$this->errors[] = 'No DNS driver for the configured registrar; ' . $what . ' not published.';
			return false;
		}

		try {
			$results = $this->get_reconciler()->apply($driver, $plan->getDomain(), $plan,
				array(), DnsReconciler::APPLY_ADDITIVE);
		} catch (Throwable $e) {
			$row->set('rdm_error', mb_substr('Transient (' . $what . '): ' . $e->getMessage(), 0, 4000));
			$row->save();
			$this->errors[] = $plan->getDomain() . ' ' . $what . ': ' . $e->getMessage();
			return false;
		}

		$failed = array();
		foreach ($results as $result) {
			if (empty($result['ok'])) {
				$failed[] = trim((string)($result['reason'] ?? 'unknown reason'));
			}
		}
		if (!empty($failed)) {
			$row->set('rdm_error', mb_substr('Transient (' . $what . '): ' . implode('; ', $failed), 0, 4000));
			$row->save();
			$this->errors[] = $plan->getDomain() . ' ' . $what . ': ' . implode('; ', $failed);
			return false;
		}
		return true;
	}

	/**
	 * Run the mailbox prepare utility on the node and read its JSON answer.
	 * Returns null when the utility could not be reached or did not answer.
	 */
	protected function prepare_on_node($node, string $domain): ?array {
		$utility = 'public_html/plugins/mailbox/utils/managed_domain_prepare.php';
		$inner = 'php ' . escapeshellarg($utility) . ' ' . escapeshellarg($domain);

		$container = trim((string)$node->get('mgn_container_name'));
		$ssh_user = (string)$node->get('mgn_ssh_user') ?: 'root';
		$sudo = ($ssh_user !== 'root') ? 'sudo ' : '';
		if ($container !== '') {
			$remote = $sudo . 'docker exec -i ' . escapeshellarg($container) . ' bash -c ' . escapeshellarg($inner);
		} else {
			$web_root = trim((string)$node->get('mgn_web_root'));
			$site_dir = $web_root !== '' ? dirname($web_root) : '';
			$remote = $sudo . 'bash -c ' . escapeshellarg(
				($site_dir !== '' ? 'cd ' . escapeshellarg($site_dir) . ' && ' : '') . $inner);
		}

		$run = $this->run_on_node($node, $remote);
		if (!$run['ok']) {
			error_log('ProvisionManagedDomains: prepare on ' . $node->get('mgn_name') . ' for '
				. $domain . ' exited ' . $run['code'] . ': ' . $run['output']);
			return null;
		}

		// The utility prints one JSON line last; anything before it is noise
		// from the shell or the site's own bootstrap.
		$lines = array_values(array_filter(array_map('trim', explode("\n", $run['output'])), 'strlen'));
		for ($i = count($lines) - 1; $i >= 0; $i--) {
			$decoded = json_decode($lines[$i], true);
			if (is_array($decoded) && array_key_exists('ok', $decoded)) {
				return $decoded;
			}
		}
		error_log('ProvisionManagedDomains: prepare on ' . $node->get('mgn_name') . ' for ' . $domain
			. ' printed no JSON: ' . $run['output']);
		return null;
	}

	/** One SSH command against a node. Overridable for tests. */
	protected function run_on_node($node, string $remote_command): array {
		$key_path = (string)$node->get('mgn_ssh_key_path');
		$host = (string)$node->get('mgn_host');
		$user = (string)$node->get('mgn_ssh_user') ?: 'root';
		$port = intval($node->get('mgn_ssh_port')) ?: 22;
		if ($key_path === '' || !is_readable($key_path) || $host === '') {
			return array('ok' => false, 'code' => -1,
				'output' => 'Node SSH coordinates incomplete (key: ' . $key_path . ', host: ' . $host . ').');
		}

		$cmd = array('ssh', '-i', $key_path, '-p', (string)$port,
			'-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new',
			'-o', 'ConnectTimeout=15', $user . '@' . $host, $remote_command);
		$proc = proc_open($cmd, array(
			0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		if (!is_resource($proc)) {
			return array('ok' => false, 'code' => -1, 'output' => 'Could not start ssh.');
		}
		fclose($pipes[0]);
		$out = (string)stream_get_contents($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		return array('ok' => ($code === 0), 'code' => $code, 'output' => trim($out . "\n" . $err));
	}

	/** Record a transient failure and leave the row where it is. */
	private function note_transient($row, string $phase, DomainRegistrarException $e): int {
		$row->set('rdm_error', mb_substr('Transient (' . $phase . '): ' . $e->getMessage(), 0, 4000));
		$row->save();
		$this->errors[] = $row->get('rdm_domain') . ': transient registrar error (' . $phase . '), will retry.';
		return 0;
	}

	/**
	 * Terminal failure: park the row and tell a person. Never auto-retried —
	 * the operator clears it from the Domains page once they have decided what
	 * to do with the buyer.
	 */
	private function fail_and_alert($row, string $reason): void {
		$row->fail($reason);
		$this->errors[] = $row->get('rdm_domain') . ': FAILED — ' . $reason;
		$this->send_failure_alert($row, $reason);
	}

	/**
	 * The operator email for a parked row. Protected as this class's fourth
	 * outside edge (registrar, DNS, SSH, mail): a test double must be able to
	 * intercept it — this class's suite once sent sixty real alerts through
	 * dev's live Postfix because it could not — and asserting the alert's
	 * content is stronger than letting it fire.
	 */
	protected function send_failure_alert($row, string $reason): void {
		$to = $this->resolve_alert_recipient();
		if ($to === '') {
			error_log('ProvisionManagedDomains: no alert recipient for ' . $row->get('rdm_domain'));
			return;
		}
		$body = "A managed domain registration failed.\n\n"
			. 'Domain: ' . $row->get('rdm_domain') . "\n"
			. 'Order item: ' . $row->get('rdm_external_order_item_id') . "\n"
			. 'Buyer: ' . $row->get('rdm_buyer_email') . "\n"
			. 'Reason: ' . $reason . "\n\n"
			. "It is parked on /admin/server_manager/domains, where Retry puts it back in the queue.\n";
		try {
			EmailSender::quickSend($to, '[managed-domain] Registration failed: ' . $row->get('rdm_domain'), $body);
		} catch (Throwable $e) {
			error_log('ProvisionManagedDomains: alert send failed: ' . $e->getMessage());
		}
	}

	/** provisioning_admin_alert_email -> webmaster_email -> first superadmin. */
	public static function resolve_alert_recipient(): string {
		$settings = Globalvars::get_instance();
		$email = trim((string)$settings->get_setting('server_manager_provisioning_admin_alert_email', false, true));
		if ($email !== '') { return $email; }

		$email = trim((string)$settings->get_setting('webmaster_email', false, true));
		if ($email !== '') { return $email; }

		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$admins = new MultiUser(array(
			'permission_range' => array(10, 10),
			'deleted'          => false,
			'not_system_users' => true,
		), array('usr_user_id' => 'ASC'), 1);
		$admins->load();
		if (count($admins) > 0) {
			$email = trim((string)$admins->get(0)->get('usr_email'));
			if ($email !== '') { return $email; }
		}
		return '';
	}
}

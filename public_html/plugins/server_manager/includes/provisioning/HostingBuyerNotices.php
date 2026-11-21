<?php
/**
 * HostingBuyerNotices — tells a buyer, once, each state their paid site
 * reaches that needs them to know or act (specs/hosting_after_purchase.md WP2).
 *
 * One provisioning phase reads each paid site's state and emails what it has
 * not told yet, rather than an email sent from each place a state is set:
 * a state reached by any path (an event, a retry, an operator's action, a
 * missed webhook caught up later) is told the same way, and a state told once
 * is never told again while it lasts.
 *
 * What is told, each from its own email template (editable on the site's
 * email templates page):
 *
 *  - waiting to connect (an own-Linode site paid for, no Linode connection)
 *  - held for review (over a limit, or an open suspension or dispute)
 *  - setup failed
 *  - cancel scheduled (the subscription ends at the end of the paid period)
 *  - payment needed (grace after a failed payment) / hosting ended (grace
 *    after the subscription ended)
 *  - shuts down soon (seven days before the grace ends)
 *  - shut down
 *
 * Only a site this site's store sold is told (CustomerCloudProvision::
 * local_order_item()): a site sold by another store is that store's to tell.
 *
 * What was told is kept on the site's row (cvp_buyer_notices, a JSON list of
 * keys). A setup state's key is dropped when the site leaves that state, so a
 * site that fails, is retried, and fails again is told again; a plan state's
 * key carries its date, so a second grace period is a new key.
 *
 * @version 1.4 - tell_refused(): a held order that was refused, with what was refunded
 * @version 1.3 - provider_refused: a buyer whose own account would not make the server is sent the provider's words
 * @version 1.2 - told only when sent (B20); a cancel is told while it is still to come, and forgotten when taken
 *                back (B22) (hosting_after_purchase)
 * @version 1.1 - tell_domain_expiry(): a domain whose site is gone or shut down is told by email (hosting_after_purchase D2)
 * @version 1.0 - specs/hosting_after_purchase.md WP2
 */
class HostingBuyerNotices {

	/** Template per notice kind. */
	const TEMPLATES = array(
		'waiting_connect'  => 'hosting_waiting_connect',
		'provider_refused' => 'hosting_provider_refused',
		'held'             => 'hosting_held',
		'failed'           => 'hosting_setup_failed',
		'refused'          => 'hosting_order_refused',
		'cancel_scheduled' => 'hosting_cancel_scheduled',
		'grace_payment'    => 'hosting_grace_payment',
		'grace_ended'      => 'hosting_grace_ended',
		'shutdown_soon'    => 'hosting_shutdown_soon',
		'shut_down'        => 'hosting_shut_down',
		'domain_expiring'  => 'hosting_domain_expiring',
	);

	/**
	 * Days before a domain's expiry that its buyer is emailed when no site of
	 * theirs is live to show the take-ownership notice (hosting_after_purchase
	 * D2): the notice's own window start, a month, a week.
	 */
	const DOMAIN_STAGES = array(RegisteredDomain::PROMPT_LEAD_DAYS, 30, 7);

	/** Setup states told to the buyer, by cvp_status. */
	const SETUP_KINDS = array(
		'pending_connect' => 'waiting_connect',
		'held'            => 'held',
		'failed'          => 'failed',
	);

	/** Days before the grace ends that the last warning goes out. */
	const SOON_DAYS = 7;

	/** Tests: callable($template, $to, $vars) in place of EmailSender. */
	public static $mailer = null;

	/** Tests: a fixed "now" (Y-m-d H:i:s UTC). */
	public static $now = null;

	public function run(array $config): array {
		$rows = new MultiCustomerCloudProvision(array(
			'origin'   => 'buyer',
			'statuses' => array('pending_connect', 'held', 'failed', 'ready', 'booting', 'installing', 'done'),
			'deleted'  => false,
		));
		$sent = 0;
		$errors = array();
		foreach ($rows as $provision) {
			try {
				$sent += self::tell($provision);
			} catch (Throwable $e) {
				$errors[] = $provision->get('cvp_domain') . ': ' . $e->getMessage();
				error_log('HostingBuyerNotices: ' . $provision->get('cvp_domain') . ': ' . $e->getMessage());
			}
		}
		if ($sent === 0 && !$errors) {
			return array('status' => 'skipped', 'message' => 'Nothing new to tell buyers.');
		}
		$message = $sent . ' buyer email(s) sent.';
		if ($errors) {
			return array('status' => 'error', 'message' => $message . ' ' . implode('; ', array_slice($errors, 0, 3)));
		}
		return array('status' => 'success', 'message' => $message);
	}

	/**
	 * Tell this site's buyer what they have not been told. Returns the number
	 * of emails sent.
	 */
	public static function tell(CustomerCloudProvision $provision): int {
		$item = $provision->local_order_item();
		$to = trim((string)$provision->get('cvp_buyer_email'));
		if ($item === null || $to === '') {
			return 0;
		}
		$told = self::told($provision);
		$due = self::due($provision, $item);

		// A setup state no longer current is forgotten, so reaching it again
		// is told again.
		// A cancel taken back is forgotten too, so cancelling again is told.
		$keep = array();
		foreach ($told as $key) {
			$fleeting = strpos($key, 'setup:') === 0 || strpos($key, 'plan:cancel:') === 0;
			if (!$fleeting || isset($due[$key])) {
				$keep[] = $key;
			}
		}
		$sent = 0;
		foreach ($due as $key => $notice) {
			if (in_array($key, $keep, true)) {
				continue;
			}
			if (self::send($provision, $notice['kind'], $notice['vars'], $to)) {
				$keep[] = $key;
				$sent++;
			}
		}
		if ($keep !== $told) {
			$provision->set('cvp_buyer_notices', json_encode(array_values($keep)));
			$provision->save();
		}
		return $sent;
	}

	/**
	 * What this site's state calls for now: key => [kind, vars]. At most one
	 * setup notice and one plan notice.
	 */
	public static function due(CustomerCloudProvision $provision, OrderItem $item): array {
		$due = array();
		$status = (string)$provision->get('cvp_status');
		$refusal = $provision->provider_refusal();
		if ($refusal !== '') {
			// Their own account would not make the server: told with the
			// provider's words, once per refusal.
			$due['setup:refused:' . substr(sha1($refusal), 0, 12)] = array('kind' => 'provider_refused',
				// Another party's words, into an HTML template that does not escape.
				'vars' => array('provider_reason' => htmlspecialchars($refusal, ENT_NOQUOTES, 'UTF-8')));
		} elseif (isset(self::SETUP_KINDS[$status])) {
			$due['setup:' . $status] = array('kind' => self::SETUP_KINDS[$status], 'vars' => array());
		}

		$trial = HostedTrial::for_provision((int)$provision->key);
		if ($trial === null) {
			return $due;
		}
		$state = (string)$trial->get('htr_state');
		$now = self::now();
		$grace_ends = trim((string)$trial->get('htr_grace_ends_time'));
		$plan_vars = array(
			'grace_ends' => self::day($grace_ends),
			'shelf_ends' => self::day((string)$trial->get('htr_shelf_ends_time')),
			'period_end' => self::day((string)$item->get('odi_subscription_period_end')),
			'grace_days' => (string)HostedTrialSignals::grace_days(),
			'shelf_days' => (string)HostedTrialSignals::shelf_days(),
		);

		if (in_array($state, array(HostedTrial::STATE_TRIAL, HostedTrial::STATE_SUBSCRIBED), true)) {
			$cancelled = trim((string)$item->get('odi_subscription_cancelled_time'));
			$end = trim((string)$item->get('odi_subscription_period_end'));
			// Only a cancel still to take effect: an immediate one (cancelled
			// now, the period end still ahead) is told as the grace it starts.
			if ($cancelled !== '' && $cancelled > $now && $end !== '' && $end > $now) {
				$due['plan:cancel:' . $cancelled] = array('kind' => 'cancel_scheduled', 'vars' => $plan_vars);
			}
		}
		elseif ($state === HostedTrial::STATE_GRACE && $grace_ends !== '') {
			// The grace a cancel ends in reads differently from the one a
			// failed payment starts: there is no card to fix.
			$ended = trim((string)$item->get('odi_subscription_cancelled_time')) !== '';
			$due['plan:grace:' . $grace_ends] = array('kind' => $ended ? 'grace_ended' : 'grace_payment', 'vars' => $plan_vars);
			$left = (int)ceil((strtotime($grace_ends . ' UTC') - strtotime($now . ' UTC')) / 86400);
			if ($left <= self::SOON_DAYS && $left > 0) {
				$due['plan:soon:' . $grace_ends] = array('kind' => 'shutdown_soon',
					'vars' => $plan_vars + array('days_left' => (string)$left));
			}
		}
		elseif ($state === HostedTrial::STATE_SHUTDOWN) {
			$due['plan:shutdown:' . (string)$trial->get('htr_shutdown_time')] = array('kind' => 'shut_down', 'vars' => $plan_vars);
		}
		return $due;
	}

	/**
	 * A domain registered for a site whose site is gone or shut down: the
	 * take-ownership notice lives on the site, so it reaches nobody, and a
	 * name left in our registrar account lapses at its expiry. The buyer is
	 * emailed instead, at each stage it has reached. The told keys go on the
	 * site's row (removed rows included), and carry the expiry date, so a
	 * renewal starts the stages again. Returns the number of emails sent.
	 */
	public static function tell_domain_expiry(RegisteredDomain $row): int {
		if (!ManagedDomainWatch::state_prompts((string)$row->get('rdm_graduation_state'))) {
			return 0;
		}
		$days = $row->days_to_expiry();
		$to = trim((string)$row->get('rdm_buyer_email'));
		if ($days === null || $days < 0 || $to === '') {
			return 0;
		}
		// The site it was bought with, by the paying line; or by its node, for
		// a site whose paying line moved since (a late return) (B27).
		$provision = null;
		foreach (new MultiCustomerCloudProvision(array('external_order_item_id' => (int)$row->get('rdm_external_order_item_id'))) as $p) {
			$provision = $p;
		}
		if ($provision === null && (int)$row->get('rdm_mgn_managed_node_id') > 0) {
			foreach (new MultiCustomerCloudProvision(array('cvp_mgn_managed_node_id' => (int)$row->get('rdm_mgn_managed_node_id'))) as $p) {
				$provision = $p;
			}
		}
		if ($provision === null) {
			return 0;
		}
		$stage = null;
		foreach (self::DOMAIN_STAGES as $lead) {
			if ($days <= $lead) {
				$stage = $lead;
			}
		}
		if ($stage === null) {
			return 0;
		}
		$key = 'domain:' . substr((string)$row->get('rdm_expiry_time'), 0, 10) . ':' . $stage;
		$told = self::told($provision);
		if (in_array($key, $told, true)) {
			return 0;
		}
		$vars = array(
			'domain'      => (string)$row->get('rdm_domain'),
			'expiry_date' => self::day((string)$row->get('rdm_expiry_time')),
			'days_left'   => (string)$days,
			'domain_url'  => LibraryFunctions::get_absolute_url('/profile/server_manager/domain'),
		);
		if (!self::send($provision, 'domain_expiring', $vars, $to)) {
			return 0;
		}
		$told[] = $key;
		$provision->set('cvp_buyer_notices', json_encode(array_values($told)));
		$provision->save();
		return 1;
	}

	/** The keys already told. */
	/**
	 * Tell the buyer of a held order that it was not accepted and what was
	 * given back. Sent once, by the refusal itself (the row is gone after it,
	 * so the pass never sees it). Returns whether the email went.
	 */
	public static function tell_refused(CustomerCloudProvision $provision, float $refunded): bool {
		$to = trim((string)$provision->get('cvp_buyer_email'));
		if ($to === '' || $provision->local_order_item() === null) {
			return false;
		}
		return self::send($provision, 'refused', array(
			'refund_amount' => $refunded > 0 ? ManagedSiteDraft::money(number_format($refunded, 2, '.', '')) : '',
		), $to);
	}

	public static function told(CustomerCloudProvision $provision): array {
		$list = json_decode((string)$provision->get('cvp_buyer_notices'), true);
		return is_array($list) ? array_values(array_filter($list, 'is_string')) : array();
	}

	private static function send(CustomerCloudProvision $provision, string $kind, array $vars, string $to): bool {
		$template = self::TEMPLATES[$kind] ?? '';
		if ($template === '') {
			return false;
		}
		$vars = array_merge(self::common_vars($provision), $vars);
		// Told only when it went: a send that returned false or threw is tried
		// again on the next pass (hosting_after_purchase B20).
		try {
			$ok = self::$mailer !== null
				? call_user_func(self::$mailer, $template, $to, $vars) !== false
				: EmailSender::sendTemplate($template, $to, $vars) !== false;
		} catch (Throwable $e) {
			error_log('HostingBuyerNotices: the ' . $kind . ' email for ' . $provision->get('cvp_domain')
				. ' could not be sent: ' . $e->getMessage());
			return false;
		}
		if (!$ok) {
			error_log('HostingBuyerNotices: the ' . $kind . ' email for ' . $provision->get('cvp_domain')
				. ' was not sent; it is tried again next pass.');
		}
		return $ok;
	}

	/** What every notice may say. */
	public static function common_vars(CustomerCloudProvision $provision): array {
		$user = new User((int)$provision->get('cvp_usr_user_id'), TRUE);
		return array(
			'recipient'      => $user->key ? $user->export_as_array() : array('usr_email' => (string)$provision->get('cvp_buyer_email')),
			'buyer_name'     => (string)($provision->get('cvp_buyer_name') ?: 'there'),
			'domain'         => (string)$provision->get('cvp_domain'),
			'sites_url'      => LibraryFunctions::get_absolute_url('/profile/server_manager'),
			'billing_url'    => LibraryFunctions::get_absolute_url('/profile/billing'),
			'subscriptions_url' => LibraryFunctions::get_absolute_url('/profile/subscriptions'),
			'support_email'  => SmHosting::support_email(),
		);
	}

	private static function now(): string {
		return self::$now ?? gmdate('Y-m-d H:i:s');
	}

	/** "December 3, 2026", or '' for no date. */
	private static function day(string $when): string {
		$when = trim($when);
		return $when === '' ? '' : gmdate('F j, Y', strtotime($when . ' UTC'));
	}
}

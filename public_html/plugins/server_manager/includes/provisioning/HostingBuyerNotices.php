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
 *  - still waiting for its DNS records, a day and again a week after the
 *    records were listed for the buyer (a built site nobody can open)
 *
 * Only a site this site's store sold is told (CustomerCloudProvision::
 * local_order_item()): a site sold by another store is that store's to tell.
 *
 * Each notice is a send-once email on the outbox (EmailSender::sendTemplateOnce(),
 * specs/email_outbox.md), about the site's row, keyed by what it tells: the
 * outbox row is the record of what was told. A setup state's key is
 * forgotten when the site leaves that state, so a site that fails, is
 * retried, and fails again is told again; a plan state's key carries its
 * date, so a second grace period is a new key. A notice that fails for good
 * is tried again on the next pass.
 *
 * @version 1.6 - each notice is a send-once email on the outbox; cvp_buyer_notices and told() are gone (specs/email_outbox.md)
 * @version 1.5 - dns_reminder: a built site still waiting for its DNS records a day, then a week, after they were listed (walkthrough Q41)
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
		'dns_reminder'     => 'hosting_dns_reminder',
	);

	/**
	 * Days after a built site's DNS list was shown to its buyer that they are
	 * reminded, while the name still does not reach the site. The ready email
	 * told them first; only the latest stage reached is sent.
	 */
	const DNS_REMINDER_DAYS = array(1, 7);

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
		$due = self::due($provision, $item);

		// A setup state no longer current is forgotten, so reaching it again
		// is told again; one still due is kept, so the same state on two
		// passes is told once. A cancel taken back is forgotten too: made
		// again to the same period end, it has the same key (B22).
		foreach (array('setup:', 'plan:cancel:') as $fleeting) {
			// With the keys that failed for good: a passed state is neither
			// sent again nor held as a failure.
			foreach (EmailSender::onceKeys($provision, $fleeting, true) as $key) {
				if (!isset($due[$key])) {
					EmailSender::forgetOnce($provision, $key);
				}
			}
		}
		$sent = 0;
		foreach ($due as $key => $notice) {
			if (self::send($provision, $key, $notice['kind'], $notice['vars'], $to)) {
				$sent++;
			}
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
		$due += self::dns_reminder($provision, $item, $trial);
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
	 * A built site whose name still does not reach it, a day and then a week
	 * after its DNS list was shown (cvp_site_dns_time): the buyer pays for a
	 * site nobody can open. Not while its hosting is ending (a cancel, a grace
	 * or a shutdown is told on its own), and only the latest stage reached, so
	 * a site found late gets one email. The key carries the list's date.
	 */
	private static function dns_reminder(CustomerCloudProvision $provision, OrderItem $item, ?HostedTrial $trial): array {
		$listed = trim((string)$provision->get('cvp_site_dns_time'));
		if ($listed === '' || !SmHosting::awaits_address($provision)) {
			return array();
		}
		if ($trial !== null && !in_array((string)$trial->get('htr_state'),
				array(HostedTrial::STATE_TRIAL, HostedTrial::STATE_SUBSCRIBED), true)) {
			return array();
		}
		if (trim((string)$item->get('odi_subscription_cancelled_time')) !== '') {
			return array();
		}
		$days = (int)floor((strtotime(self::now() . ' UTC') - strtotime($listed . ' UTC')) / 86400);
		$stage = null;
		foreach (self::DNS_REMINDER_DAYS as $lead) {
			if ($days >= $lead) {
				$stage = $lead;
			}
		}
		if ($stage === null) {
			return array();
		}
		return array('dns:' . substr($listed, 0, 10) . ':' . $stage => array('kind' => 'dns_reminder',
			'vars' => array('listed_date' => self::day($listed), 'days_waiting' => (string)$days)));
	}

	/**
	 * A domain registered for a site whose site is gone or shut down: the
	 * take-ownership notice lives on the site, so it reaches nobody, and a
	 * name left in our registrar account lapses at its expiry. The buyer is
	 * emailed instead, at each stage it has reached, about the site's row
	 * (removed rows included). The keys carry the expiry date, so a renewal
	 * starts the stages again. Returns the number of emails sent.
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
		$vars = array(
			'domain'      => (string)$row->get('rdm_domain'),
			'expiry_date' => self::day((string)$row->get('rdm_expiry_time')),
			'days_left'   => (string)$days,
			'domain_url'  => LibraryFunctions::get_absolute_url('/profile/server_manager/domain'),
		);
		return self::send($provision, $key, 'domain_expiring', $vars, $to) ? 1 : 0;
	}

	/**
	 * Tell the buyer of a held order that it was not accepted and what was
	 * given back. Sent once, by the refusal itself (the row is gone after it,
	 * so the pass never sees it): a failure gets the queue's retries and no
	 * next pass. Returns whether the email went or waits for its retry.
	 */
	public static function tell_refused(CustomerCloudProvision $provision, float $refunded): bool {
		$to = trim((string)$provision->get('cvp_buyer_email'));
		if ($to === '' || $provision->local_order_item() === null) {
			return false;
		}
		return self::send($provision, 'refused', 'refused', array(
			'refund_amount' => $refunded > 0 ? ManagedSiteDraft::money(number_format($refunded, 2, '.', '')) : '',
		), $to);
	}

	/**
	 * Send notice $kind once per $key on the site's row. True when this call
	 * took the key: the email went, or it waits for its retry. A key already
	 * told sends nothing and renders nothing.
	 */
	private static function send(CustomerCloudProvision $provision, string $key, string $kind, array $vars, string $to): bool {
		$template = self::TEMPLATES[$kind] ?? '';
		if ($template === '') {
			return false;
		}
		try {
			return EmailSender::sendTemplateOnce($provision, $key, $template, $to,
				array_merge(self::common_vars($provision), $vars));
		} catch (Throwable $e) {
			error_log('HostingBuyerNotices: the ' . $kind . ' email for ' . $provision->get('cvp_domain')
				. ' could not be sent; it is tried again next pass: ' . $e->getMessage());
			return false;
		}
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

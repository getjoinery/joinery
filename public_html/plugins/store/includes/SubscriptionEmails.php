<?php
/**
 * SubscriptionEmails — what the store tells a subscriber by email about a
 * subscription it bills (spec subscription_emails):
 *
 *  - a receipt when a renewal is paid (renewal_paid());
 *  - a confirmation when its buyer cancels it or takes the cancel back
 *    (cancel_confirmed(), resumed());
 *  - a notice when it ends (ended());
 *  - a reminder a few days before it renews (remind_due()).
 *
 * The receipt, the ended notice and the reminder each have a store setting
 * that switches them off. A line whose product tells its buyer itself (a
 * hosted site: FulfillmentTellsBuyer) gets no cancel, failed-payment or ended
 * email from here, and a line that grants a tier gets its ended email from TierBilling.
 *
 * Every method answers with what it did in a few words ('sent', or why
 * nothing went), never throws, and never undoes what it was called about: an
 * email that fails leaves the payment, the cancel or the ending as it is.
 *
 * The receipt and the reminder are send-once emails on the outbox
 * (EmailSender::sendTemplateOnce(), specs/email_outbox.md), about the
 * subscription's oldest line: receipt:<invoice id>, renewal:<period end>.
 *
 * @version 1.3 - the receipt and the reminder are send-once on the outbox, about the subscription's anchor line; claim()
 *   and release() and $mailer are gone; period_key(); $stripe_quote stands in for Stripe's next amount in tests
 * @version 1.2 - told_by_store(): a failed-payment email leaves out the lines whose product tells its buyer itself
 * @version 1.1 - payment_failed_vars(): a failed-payment email names every line and the invoice amount
 * @version 1.0
 */
class SubscriptionEmails {

	const SETTING_RECEIPTS  = 'store_renewal_receipts';
	const SETTING_ENDED     = 'store_subscription_ended_emails';
	const SETTING_REMINDERS = 'store_renewal_reminders';

	/** Days before a renewal its reminder goes, unless the task is told otherwise. */
	const REMINDER_DAYS = 7;

	/** A test's stand-in for Stripe's next amount: callable($subscription_id) returning ?string. */
	public static $stripe_quote = null;

	/** A test's clock, 'Y-m-d H:i:s' UTC. */
	public static $now = null;

	/**
	 * A Stripe invoice was paid. Sends the one receipt of a renewal: not the
	 * first payment (the order's own receipt went), not an invoice that charged
	 * nothing, and once however many events Stripe sends for it.
	 *
	 * @param object      $invoice the Stripe invoice
	 * @param OrderItem[] $lines   the lines its subscription pays for now
	 * @param StripeHelper|null $stripe_helper to read the card that paid
	 */
	public static function renewal_paid($invoice, array $lines, $stripe_helper = null): string {
		try {
			if (!self::on(self::SETTING_RECEIPTS)) {
				return 'receipts are switched off';
			}
			$invoice_id = trim((string)($invoice->id ?? ''));
			$reason = (string)($invoice->billing_reason ?? '');
			if ($invoice_id === '' || !$lines) {
				return 'no invoice or no line';
			}
			$cents = (int)($invoice->amount_paid ?? 0);
			if ($cents <= 0 || !empty($invoice->paid_out_of_band)) {
				return 'nothing was charged';
			}
			if ($reason === 'subscription_update') {
				// A tier change that charged is told by the tier change itself.
				foreach ($lines as $line) {
					if (TierBilling::lineGrantsTier($line)) {
						return 'a tier change sends its own email';
					}
				}
			} elseif ($reason !== 'subscription_cycle') {
				return 'not a renewal (' . ($reason === '' ? 'no billing reason' : $reason) . ')';
			}
			$user = self::subscriber($lines);
			if ($user === null) {
				return 'the subscription has no account to write to';
			}
			$period_end = '';
			foreach ($lines as $line) {
				$period_end = trim((string)$line->get('odi_subscription_period_end'));
				if ($period_end !== '') {
					break;
				}
			}
			$paid_at = (int)($invoice->status_transitions->paid_at ?? 0);
			$vars = array(
				'recipient'         => $user->export_as_array(),
				'plan_name'         => self::labels($lines),
				'amount'            => self::money(self::from_minor($cents, (string)($invoice->currency ?? '')), (string)($invoice->currency ?? '')),
				'card_label'        => self::card_label($invoice, $stripe_helper),
				'paid_date'         => self::day($paid_at > 0 ? gmdate('Y-m-d H:i:s', $paid_at) : self::now(), $user),
				'next_payment_date' => self::day($period_end, $user),
			);
			// Once per invoice, however many events Stripe sends for it: the two
			// events of a pair pick the same anchor line and meet at its key.
			return self::send_once('subscription_renewal_receipt', $user, $vars, self::anchor($lines), 'receipt:' . $invoice_id,
				'already sent for this invoice');
		} catch (Throwable $e) {
			error_log('SubscriptionEmails::renewal_paid: ' . $e->getMessage());
			return 'failed: ' . $e->getMessage();
		}
	}

	/**
	 * Its buyer cancelled this line on the site, and with it every line its
	 * subscription pays for. Says when it ends: the end of the paid period, or
	 * at once.
	 */
	public static function cancel_confirmed(OrderItem $line): string {
		try {
			// The cancel ends the whole subscription: every line on it that
			// its product does not tell of itself is named.
			$lines = self::told_by_store(self::with_siblings($line));
			if (!$lines) {
				return 'its product tells the buyer';
			}
			$user = self::subscriber($lines);
			if ($user === null) {
				return 'the line has no account to write to';
			}
			$ends = trim((string)$line->get('odi_subscription_cancelled_time'));
			$later = $ends !== '' && strtotime($ends . ' UTC') > strtotime(self::now() . ' UTC') + 3600;
			return self::send('subscription_cancelled', $user, array(
				'recipient'       => $user->export_as_array(),
				'tier_name'       => self::labels($lines),
				'access_end_date' => $later ? self::day($ends, $user) : 'Immediately',
			)) ? 'sent' : 'the email could not be sent';
		} catch (Throwable $e) {
			error_log('SubscriptionEmails::cancel_confirmed: line #' . (int)$line->key . ': ' . $e->getMessage());
			return 'failed: ' . $e->getMessage();
		}
	}

	/** Its buyer took back a cancel: billing carries on. */
	public static function resumed(OrderItem $line): string {
		try {
			$lines = self::with_siblings($line);
			$user = self::subscriber($lines);
			if ($user === null) {
				return 'the line has no account to write to';
			}
			return self::send('subscription_reactivated', $user, array(
				'recipient'         => $user->export_as_array(),
				'tier_name'         => self::labels($lines),
				'next_billing_date' => self::day((string)$line->get('odi_subscription_period_end'), $user),
			)) ? 'sent' : 'the email could not be sent';
		} catch (Throwable $e) {
			error_log('SubscriptionEmails::resumed: line #' . (int)$line->key . ': ' . $e->getMessage());
			return 'failed: ' . $e->getMessage();
		}
	}

	/**
	 * This line's subscription has ended (its provider said so). Not for a
	 * line that grants a tier (TierBilling::handleSubscriptionExpired tells
	 * that), a product that tells its buyer itself, or a first payment that
	 * was never made.
	 */
	public static function ended(OrderItem $line): string {
		try {
			if (!self::on(self::SETTING_ENDED)) {
				return 'ended notices are switched off';
			}
			if ((string)$line->get('odi_subscription_status') === 'incomplete_expired') {
				return 'the first payment was never made';
			}
			if (TierBilling::lineGrantsTier($line)) {
				return 'a tier line is told by the tier';
			}
			if (self::provider_tells_buyer($line)) {
				return 'its product tells the buyer';
			}
			$user = self::subscriber(array($line));
			if ($user === null) {
				return 'the line has no account to write to';
			}
			return self::send('subscription_expired', $user, array(
				'recipient' => $user->export_as_array(),
				'tier_name' => self::labels(array($line)),
			)) ? 'sent' : 'the email could not be sent';
		} catch (Throwable $e) {
			error_log('SubscriptionEmails::ended: line #' . (int)$line->key . ': ' . $e->getMessage());
			return 'failed: ' . $e->getMessage();
		}
	}

	/**
	 * Remind every subscriber whose subscription renews within $days: one
	 * email per subscription, once per renewal. Only a subscription that will
	 * be charged: active, not cancelled, billed by Stripe or PayPal (an app
	 * store tells its own buyers). With $dry_run nothing is sent or recorded;
	 * $order_id keeps the pass to one order's lines.
	 *
	 * @return array{sent:int, failed:int, due:string[]} due: who and what, for a preview
	 */
	public static function remind_due(int $days = self::REMINDER_DAYS, bool $dry_run = false, ?int $order_id = null): array {
		$out = array('sent' => 0, 'failed' => 0, 'due' => array());
		$which = self::reminder_mode();
		if ($which === '') {
			return $out;
		}
		$days = max(1, $days);
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT odi_order_item_id FROM odi_order_items
			  WHERE odi_is_subscription = TRUE
			    AND odi_subscription_status = 'active'
			    AND odi_subscription_cancelled_time IS NULL
			    AND COALESCE(odi_subscription_cancel_at_period_end, FALSE) = FALSE
			    AND odi_usr_user_id IS NOT NULL
			    AND odi_subscription_period_end > ?::timestamp
			    AND odi_subscription_period_end <= ?::timestamp + make_interval(days => ?)
			    AND (?::int IS NULL OR odi_ord_order_id = ?::int)
			  ORDER BY odi_order_item_id");
		$q->execute(array(self::now(), self::now(), $days, $order_id, $order_id));

		// One email per subscription: several lines can share one.
		$groups = array();
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$line = new OrderItem((int)$id, TRUE);
			$source = $line->get_payment_source();
			if ($source !== 'stripe' && $source !== 'paypal') {
				continue;
			}
			if ($which === 'year' && self::period_of($line) !== 'year') {
				continue;
			}
			$sub = $source === 'stripe' ? (string)$line->get('odi_stripe_subscription_id') : (string)$line->get('odi_paypal_subscription_id');
			$groups[$sub !== '' ? $source . ':' . $sub : 'line:' . $line->key][] = $line;
		}

		foreach ($groups as $key => $lines) {
			try {
				$user = self::subscriber($lines);
				if ($user === null) {
					continue;
				}
				$renews = (string)$lines[0]->get('odi_subscription_period_end');
				// A subscription already reminded of this renewal is left out of
				// the send and the preview alike.
				$anchor = self::anchor($lines);
				$once = 'renewal:' . self::period_key($renews);
				if (in_array($once, EmailSender::onceKeys($anchor, 'renewal:'), true)) {
					continue;
				}
				$out['due'][] = $user->get('usr_email') . ': ' . html_entity_decode(self::labels($lines), ENT_NOQUOTES, 'UTF-8')
					. ', renews ' . self::day($renews, $user);
				if ($dry_run) {
					continue;
				}
				$amount = strpos($key, 'stripe:') === 0 ? self::upcoming_amount(substr($key, 7)) : null;
				$vars = array(
					'recipient'    => $user->export_as_array(),
					'plan_name'    => self::labels($lines),
					'renewal_date' => self::day($renews, $user),
					'amount'       => $amount === null ? '' : $amount,
				);
				$said = self::send_once('subscription_renewal_reminder', $user, $vars, $anchor, $once, 'already reminded');
				if ($said === 'sent') {
					$out['sent']++;
				} elseif ($said !== 'already reminded') {
					$out['failed']++;
				}
			} catch (Throwable $e) {
				$out['failed']++;
				error_log('SubscriptionEmails::remind_due: ' . $key . ': ' . $e->getMessage());
			}
		}
		return $out;
	}

	/** Which renewals are reminded: 'year' (yearly plans), 'all', or '' for none. */
	public static function reminder_mode(): string {
		$v = strtolower(trim((string)Globalvars::get_instance()->get_setting(self::SETTING_REMINDERS)));
		return in_array($v, array('year', 'all'), true) ? $v : '';
	}

	/**
	 * What a failed-payment email says of a subscription: every line the
	 * unpaid invoice bills, and the invoice's amount (the lines' prices
	 * when there is no invoice to read).
	 *
	 * @return array{tier_name:string, billing_amount:string}
	 */
	public static function payment_failed_vars(array $lines, $invoice = null): array {
		$currency = (string)($invoice->currency ?? '');
		if ($invoice !== null && isset($invoice->amount_due)) {
			$amount = self::from_minor((int)$invoice->amount_due, $currency);
		} else {
			$amount = 0.0;
			foreach ($lines as $line) {
				$amount += (float)$line->get('odi_price');
			}
		}
		return array(
			'tier_name'      => self::labels($lines),
			'billing_amount' => number_format($amount, self::has_no_minor_unit($currency) ? 0 : 2),
		);
	}

	/**
	 * The lines the store itself tells their buyer about: those whose product
	 * does not. None left means the store sends nothing.
	 *
	 * @param OrderItem[] $lines
	 * @return OrderItem[]
	 */
	public static function told_by_store(array $lines): array {
		$ours = array();
		foreach ($lines as $line) {
			if (!self::provider_tells_buyer($line)) {
				$ours[] = $line;
			}
		}
		return $ours;
	}

	/** Does this line's product tell its buyer about a cancel, a failed payment and an ending itself? */
	public static function provider_tells_buyer(OrderItem $line): bool {
		try {
			$product = new Product((int)$line->get('odi_pro_product_id'), TRUE);
			$key = $product->key ? trim((string)$product->get('pro_fulfillment_provider')) : '';
			$provider = $key !== '' ? FulfillmentRegistry::get($key) : null;
			return $provider instanceof FulfillmentTellsBuyer && $provider->tellsBuyerOfEnding($line);
		} catch (Throwable $e) {
			error_log('SubscriptionEmails::provider_tells_buyer: line #' . (int)$line->key . ': ' . $e->getMessage());
			return false;
		}
	}

	// ---- parts ----

	private static function on(string $setting): bool {
		return (string)Globalvars::get_instance()->get_setting($setting) !== '0';
	}

	private static function now(): string {
		return self::$now ?? gmdate('Y-m-d H:i:s');
	}

	/** The account the lines belong to: the first that has one with an address. */
	private static function subscriber(array $lines): ?User {
		foreach ($lines as $line) {
			$user_id = (int)$line->get('odi_usr_user_id');
			if ($user_id > 0 && User::check_if_exists($user_id)) {
				$user = new User($user_id, TRUE);
				if (trim((string)$user->get('usr_email')) !== '') {
					return $user;
				}
			}
		}
		return null;
	}

	/**
	 * The text form of a period end in a renewal reminder's key: whole
	 * seconds, UTC. The carry-over migration writes the same text
	 * (to_char(..., 'YYYY-MM-DD HH24:MI:SS')).
	 */
	public static function period_key(string $period_end): string {
		$t = strtotime(trim($period_end) . ' UTC');
		return $t === false ? trim($period_end) : gmdate('Y-m-d H:i:s', $t);
	}

	/** The line a send is recorded on: the oldest, so every event picks the same one. */
	private static function anchor(array $lines): OrderItem {
		$anchor = $lines[0];
		foreach ($lines as $line) {
			if ((int)$line->key < (int)$anchor->key) {
				$anchor = $line;
			}
		}
		return $anchor;
	}

	/** The lines as their buyer names them: 'Managed hosting — example.com, Services bundle'. */
	private static function labels(array $lines): string {
		$names = array();
		foreach ($lines as $line) {
			$label = $line->buyer_label();
			if ($label !== '' && !in_array($label, $names, true)) {
				$names[] = $label;
			}
		}
		// A buyer's own answer is in a label, and a template does not escape.
		return $names ? htmlspecialchars(implode(', ', $names), ENT_NOQUOTES, 'UTF-8') : 'your subscription';
	}

	/** An amount in $currency (the site's when none is given), with its symbol. */
	private static function money(float $amount, string $currency = ''): string {
		$code = trim($currency) !== '' ? $currency : (string)Globalvars::get_instance()->get_setting('site_currency');
		return CurrencyHelper::symbol($code) . number_format($amount, self::has_no_minor_unit($code) ? 0 : 2);
	}

	/** A provider's amount in a currency's smallest unit, as the main unit. */
	private static function from_minor(int $minor, string $currency): float {
		return self::has_no_minor_unit($currency) ? (float)$minor : $minor / 100;
	}

	/** Currencies Stripe bills in whole units (no cents). */
	private static function has_no_minor_unit(string $currency): bool {
		return in_array(strtolower(trim($currency)), array('bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg',
			'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'), true);
	}

	/**
	 * A line and the other lines its subscription pays for now: one checkout
	 * can put several on one Stripe subscription, and a cancel ends them all.
	 */
	private static function with_siblings(OrderItem $line): array {
		$sub = trim((string)$line->get('odi_stripe_subscription_id'));
		if ($sub === '' || $line->get_payment_source() !== 'stripe') {
			return array($line);
		}
		$lines = array($line);
		foreach (StripeHelper::subscription_current_lines($sub) as $other) {
			if ((int)$other->key !== (int)$line->key) {
				$lines[] = $other;
			}
		}
		return $lines;
	}

	/** 'December 21, 2026' in the subscriber's own timezone; '' for no date. */
	private static function day(string $utc, User $user): string {
		$utc = trim($utc);
		if ($utc === '') {
			return '';
		}
		$tz = trim((string)$user->get('usr_timezone'));
		try {
			$local = LibraryFunctions::convert_time($utc, 'UTC', $tz !== '' ? $tz : 'UTC', 'F j, Y');
			if (is_string($local) && $local !== '') {
				return $local;
			}
		} catch (Throwable $e) {
			// An unknown timezone on the account: the UTC day below.
		}
		return gmdate('F j, Y', strtotime($utc . ' UTC'));
	}

	/** 'Visa ending 4242', or '' when the card cannot be read. */
	private static function card_label($invoice, $stripe_helper): string {
		$charge_id = is_string($invoice->charge ?? null) ? $invoice->charge : (string)($invoice->charge->id ?? '');
		if ($stripe_helper === null || $charge_id === '') {
			return '';
		}
		try {
			$card = $stripe_helper->get_charge($charge_id)->payment_method_details->card ?? null;
			$last4 = trim((string)($card->last4 ?? ''));
			if ($last4 === '') {
				return '';
			}
			$brand = trim(ucwords(str_replace('_', ' ', (string)($card->brand ?? ''))));
			return ($brand !== '' ? $brand : 'Card') . ' ending ' . $last4;
		} catch (Throwable $e) {
			return '';
		}
	}

	/** What Stripe will charge at the next renewal, as the email says it, or null when it cannot say. */
	private static function upcoming_amount(string $subscription_id): ?string {
		if (self::$stripe_quote !== null) {
			return call_user_func(self::$stripe_quote, $subscription_id); // a test never asks Stripe
		}
		try {
			$due = (new StripeHelper())->upcoming_invoice_amount($subscription_id);
			return $due === null ? null : self::money(self::from_minor($due['minor'], $due['currency']), $due['currency']);
		} catch (Throwable $e) {
			return null;
		}
	}

	/** The billing period of a line's plan: 'day', 'week', 'month', 'year', or ''. */
	private static function period_of(OrderItem $line): string {
		$version_id = (int)$line->get('odi_prv_product_version_id');
		if ($version_id <= 0 || !ProductVersion::check_if_exists($version_id)) {
			return '';
		}
		return (string)((new ProductVersion($version_id, TRUE))->is_subscription() ?: '');
	}

	private static function send(string $template, User $user, array $vars): bool {
		$vars['billing_url'] = LibraryFunctions::get_absolute_url('/profile/billing');
		try {
			return EmailSender::sendTemplate($template, (string)$user->get('usr_email'), $vars) !== false;
		} catch (Throwable $e) {
			error_log('SubscriptionEmails: ' . $template . ' to user ' . (int)$user->key . ' could not be sent: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Send $template once per $key on $anchor: 'sent' when this call took the
	 * key (the email went, or waits for its retry), $taken when it was
	 * already taken, or why it could not be sent.
	 */
	private static function send_once(string $template, User $user, array $vars, OrderItem $anchor, string $key, string $taken): string {
		$vars['billing_url'] = LibraryFunctions::get_absolute_url('/profile/billing');
		if (trim((string)$user->get('usr_email')) === '') {
			return 'the account has no email address';
		}
		try {
			return EmailSender::sendTemplateOnce($anchor, $key, $template, (string)$user->get('usr_email'), $vars)
				? 'sent' : $taken;
		} catch (Throwable $e) {
			error_log('SubscriptionEmails: ' . $template . ' to user ' . (int)$user->key . ' could not be sent: ' . $e->getMessage());
			return 'the email could not be sent';
		}
	}
}

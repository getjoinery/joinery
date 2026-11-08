<?php
/**
 * JoineryServicesFulfillment — turns a paid services line into a paid-through
 * date on a self-hosted site's service rows
 * (specs/services_phase3_selling_nudge.md §1).
 *
 * Selecting this in the product-edit Purchase grants picker is the whole
 * product-side setup: the product stores pro_fulfillment_provider =
 * joinery_services and a PLAN number, and its line carries one requirement,
 * the site (JoineryServiceRequirement). Price and billing period live on the
 * product; what the plan says is which services it grants and, for a product
 * bought once rather than by subscription, how many days one purchase buys.
 *
 * THE DATE IS DERIVED, NEVER TYPED. What decides whether a site's mail and
 * backups work is svt_paid_until, compared every pass by the reconcile:
 *
 *   a subscription line   the date is the line's period end. fulfill() writes
 *                         it (or a term from today while the payment provider
 *                         has not said the period yet), names the line on the
 *                         row, and the reconcile moves the date to the line's
 *                         period end on every pass while the line is not
 *                         cancelled. A cancelled or failing line stops
 *                         advancing; the date falls into the past and the
 *                         ladder (grace, then suspension) runs with no new code.
 *   a line bought once    the date moves by the plan's term from the later of
 *                         today and the current date, so buying early loses
 *                         nothing.
 *
 * The allowances are the plane's settings for every plan (the decided pricing,
 * DEFERRED_services_pricing.md §0, has one figure each); a plan with larger
 * ones is the day a per-plan allowance is added here.
 *
 * A Managed site never buys this: its hosting is the entitlement.
 *
 * @version 1.1 - reviewer2: a released service takes a paid date without coming back (B1); past_due is not
 *                paying (B2); what is on sale is read once per request (B6)
 * @version 1.0 - specs/services_phase3_selling_nudge.md §1, §4 items 1 and 2
 */
require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/requirements/JoineryServiceRequirement.php'));

class JoineryServicesFulfillment implements FulfillmentProvider {

	const KEY = 'joinery_services';

	/** What is on sale, read once per request (offers()); forget_offers() clears it. */
	private static $offers = null;

	public static function forget_offers(): void {
		self::$offers = null;
	}

	/**
	 * The plan ladder: the reference a product stores => what it grants.
	 * term_days applies to a product bought once; a subscription product's
	 * term is its own billing period.
	 */
	const PLANS = array(
		1 => array(
			'label'     => 'Email and backup storage',
			'services'  => array('mail', 'shelf'),
			'term_days' => 31,
		),
	);

	/**
	 * Subscription statuses under which the line still pays. Not past_due:
	 * Stripe stores the next period end when the renewal invoice is made,
	 * before the charge, so a failed card would otherwise buy an unpaid
	 * month. The date stops at the last paid period end, the ladder's grace
	 * runs, and a later payment moves it on again. '' is a line the payment
	 * provider has not described yet.
	 */
	const PAYING_STATUSES = array('', 'active', 'trialing', 'grace_period');

	const STALE_SITE_SENTENCE = 'The site in your cart is no longer connected to this account. '
		. 'Remove it from the cart and buy again from your connected sites.';

	const ALREADY_RENEWING_SENTENCE = 'This site already has a getjoinery services subscription, and it renews on its own. '
		. 'Remove it from the cart; there is nothing more to buy.';

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return 'getjoinery services for a self-hosted site';
	}

	public function options(): array {
		$out = array();
		foreach (self::PLANS as $ref => $plan) {
			$out[$ref] = $plan['label'];
		}
		return $out;
	}

	public static function plan(int $ref): ?array {
		return self::PLANS[$ref] ?? null;
	}

	public function extraRequirements(Product $product, int $ref): array {
		return array(new JoineryServiceRequirement());
	}

	/**
	 * Before the charge: the line's site is still the buyer's and connected,
	 * the product names a plan, and the site is not already paying through a
	 * subscription that renews on its own (a second one would charge twice
	 * for the same month).
	 */
	public function checkAvailability(Product $product, int $ref, int $quantity, array $data = array()): ?string {
		if (self::plan($ref) === null) {
			return 'This product is not set up to grant a service yet.';
		}
		$user_id = (int)SessionControl::get_instance()->get_user_id();
		$row = JoineryServiceRequirement::row_for_line($data, $user_id);
		if ($row === null) {
			return self::STALE_SITE_SENTENCE;
		}
		if (self::renewing_line($row) !== null) {
			return self::ALREADY_RENEWING_SENTENCE;
		}
		return null;
	}

	public function fulfill(User $user, Product $product, OrderItem $order_item, Order $order, int $ref): array {
		$outcome = $order_item->get('odi_is_subscription') ? 'subscription' : 'plain';
		// The order is paid: a failure here is written down and alerted with
		// the order item id, never thrown into the purchase.
		try {
			$rows = self::grant_line($user, $order_item, $ref);
		} catch (\Throwable $e) {
			self::alert_problem($user, $order_item, $e->getMessage());
			return array('ref_id' => null, 'label' => 'getjoinery services need attention', 'labels' => null,
				'outcome' => $outcome);
		}
		$first = reset($rows);
		return array('ref_id' => null,
			'label' => 'getjoinery services for ' . JoineryServiceRequirement::site_label($first),
			'labels' => null, 'outcome' => $outcome);
	}

	/**
	 * Write the date the line pays for on every row the plan grants, creating
	 * a row the site has not asked for yet (a bundle bought from the Email
	 * step covers backups too). Idempotent on the order item. Returns the rows.
	 */
	public static function grant_line(User $user, OrderItem $order_item, int $ref, ?string $now = null): array {
		$plan = self::plan($ref);
		if ($plan === null) {
			throw new RuntimeException('The product names plan ' . $ref . ', which this plane does not have.');
		}
		if (!$order_item->key || !$user->key) {
			throw new RuntimeException('The order item or the buyer could not be loaded.');
		}
		$anchor = JoineryServiceRequirement::row_for_user(
			JoineryServiceRequirement::row_id_from((array)$order_item->get_raw_data()), (int)$user->key);
		if ($anchor === null) {
			throw new RuntimeException('The paid line names no live service row of the buyer\'s.');
		}
		$now = $now ?? gmdate('Y-m-d H:i:s');
		$site = $anchor->siteRows();
		$subscription = (bool)$order_item->get('odi_is_subscription');
		$granted = array();
		foreach ($plan['services'] as $service) {
			$row = $site[$service] ?? null;
			if ($row === null) {
				if ($anchor->is_node_linked()) {
					continue;   // a moved site's rows are made by its move, both at once
				}
				$row = JoineryServices::tenant((int)$user->key, (int)$anchor->get('svt_apk_api_key_id'),
					$service, (string)$anchor->get('svt_host'));
			}
			if ((int)$row->get('svt_odi_order_item_id') === (int)$order_item->key) {
				$granted[$service] = $row;   // this line was already granted
				continue;
			}
			$current = trim((string)$row->get('svt_paid_until'));
			if ($subscription) {
				$until = trim((string)$order_item->get('odi_subscription_period_end'));
				if ($until === '' || $until <= $now) {
					$until = self::plus_days($now, (int)$plan['term_days']);
				}
			} else {
				$from = ($current !== '' && $current > $now) ? $current : $now;
				$until = self::plus_days($from, (int)$plan['term_days']);
			}
			if ($current !== '' && $current > $until) {
				$until = $current;   // never shortens what was already paid for
			}
			$row->set('svt_odi_order_item_id', (int)$order_item->key);
			self::write_date($row, $until);
			$granted[$service] = $row;
		}
		if (!$granted) {
			throw new RuntimeException('The plan granted nothing on this site.');
		}
		return $granted;
	}

	private static function plus_days(string $from, int $days): string {
		return gmdate('Y-m-d H:i:s', strtotime($from . ' UTC') + max(1, $days) * 86400);
	}

	// ── The line a row follows ───────────────────────────────────────────────

	/** The store line that paid for a row, or null. */
	public static function line_of(ServiceTenant $row): ?OrderItem {
		$id = (int)$row->get('svt_odi_order_item_id');
		if ($id <= 0 || !class_exists('OrderItem')) {
			return null;
		}
		$item = new OrderItem($id, TRUE);
		return $item->key ? $item : null;
	}

	/** A subscription line still paying: not cancelled, at a paying status. */
	public static function line_paying(OrderItem $item): bool {
		return (bool)$item->get('odi_is_subscription')
			&& !$item->get('odi_subscription_cancelled_time')
			&& in_array((string)$item->get('odi_subscription_status'), self::PAYING_STATUSES, true);
	}

	/** Paying and set to go on: no reminder about its date is owed. */
	public static function line_renews(OrderItem $item): bool {
		return self::line_paying($item) && !$item->get('odi_subscription_cancel_at_period_end');
	}

	/** Does this row's date advance on its own? */
	public static function row_renews(ServiceTenant $row): bool {
		$item = self::line_of($row);
		return $item !== null && self::line_renews($item);
	}

	/** The subscription line a site already pays through, renewing on its own, or null. */
	public static function renewing_line(ServiceTenant $row): ?OrderItem {
		foreach ($row->siteRows() as $sibling) {
			$item = self::line_of($sibling);
			if ($item !== null && self::line_renews($item)) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * The reconcile's step: a row paid by a subscription takes the line's
	 * period end whenever it is later than the date. Returns 1 when it moved.
	 */
	public static function follow_line(ServiceTenant $row): int {
		$item = self::line_of($row);
		if ($item === null || !self::line_paying($item)) {
			return 0;
		}
		$end = trim((string)$item->get('odi_subscription_period_end'));
		if ($end === '' || $end <= trim((string)$row->get('svt_paid_until'))) {
			return 0;
		}
		self::write_date($row, $end);
		return 1;
	}

	/**
	 * Write a paid date. A released service (moved to the customer's own
	 * account) takes the date and stays released: it comes back only through
	 * the site's own enrol, never because a bundle that also pays for the
	 * other service renewed. Every other row goes through grant(), which
	 * brings a stopped one back in place.
	 */
	private static function write_date(ServiceTenant $row, string $until): void {
		if ((string)$row->get('svt_state') === ServiceTenant::STATE_RELEASED) {
			$row->set('svt_paid_until', $until);
			$row->set('svt_allowance', JoineryServices::allowance((string)$row->get('svt_service')));
			$row->save();
			return;
		}
		JoineryServices::grant($row, $until);
	}

	// ── What is on sale ──────────────────────────────────────────────────────

	/**
	 * Every product on sale that grants services, as
	 * [{product, version, plan, label, price}], cheapest first. The price is
	 * the product's, read here and nowhere else.
	 */
	public static function offers(): array {
		if (!class_exists('Product')) {
			return array();
		}
		if (self::$offers !== null) {
			return self::$offers;
		}
		$out = array();
		$products = new MultiProduct(array(
			'pro_fulfillment_provider' => self::KEY,
			'is_active'                => true,
			'deleted'                  => false,
		), array('pro_product_id' => 'ASC'));
		foreach ($products as $product) {
			$plan = self::plan((int)$product->get('pro_fulfillment_ref'));
			$versions = $product->get_product_versions(TRUE);
			if ($plan === null || !$versions || count($versions) === 0) {
				continue;
			}
			$version = $versions->get(0);
			$out[] = array(
				'product' => $product,
				'version' => $version,
				'plan'    => (int)$product->get('pro_fulfillment_ref'),
				'label'   => $plan['label'],
				'price'   => self::price_label($version),
				'amount'  => (float)$version->get('prv_version_price'),
			);
		}
		usort($out, function ($a, $b) { return $a['amount'] <=> $b['amount']; });
		return self::$offers = $out;
	}

	/**
	 * What the connected sites page offers one site, from its rows' C2 fields:
	 *   'renewing'  a subscription pays and renews on its own: nothing to buy
	 *   'buy'       never paid, or the date has passed: the offers, as Buy
	 *   'keep'      paid, and the date is inside the notice window: the offers,
	 *               as Keep going
	 *   ''          paid and not due yet, released, or a key no longer active
	 */
	public static function card_state(array $site, string $now, int $notice_days): string {
		if (empty($site['node_linked']) && empty($site['active'])) {
			return '';
		}
		$until = '';
		$live = false;
		foreach ($site['services'] as $s) {
			if (!empty($s['renews'])) {
				return 'renewing';
			}
			if (($s['state'] ?? '') === ServiceTenant::STATE_RELEASED) {
				continue;
			}
			$live = true;
			$paid = trim((string)($s['paid_until'] ?? ''));
			if ($paid !== '' && ($until === '' || $paid < $until)) {
				$until = $paid;
			}
		}
		if (!$live) {
			return '';
		}
		if ($until === '' || $until <= $now) {
			return 'buy';
		}
		return (strtotime($until . ' UTC') - strtotime($now . ' UTC') <= $notice_days * 86400) ? 'keep' : '';
	}

	/** "$2.99 a month", "$29.99 a year", "$2.99 once". */
	public static function price_label($version): string {
		$settings = Globalvars::get_instance();
		$symbol = CurrencyHelper::symbol(strtolower((string)$settings->get_setting('site_currency'))) ?: '$';
		$amount = $symbol . number_format((float)$version->get('prv_version_price'), 2, '.', '');
		$per = array('month' => 'a month', 'year' => 'a year', 'week' => 'a week', 'day' => 'a day');
		$type = (string)$version->get('prv_price_type');
		return $amount . ' ' . ($per[$type] ?? 'once');
	}

	/**
	 * The Buy button for one offer and one site: a single-button form posting
	 * what the product page's own form posts, plus the site's row id, so the
	 * store's product_logic adds the line. The one kind of form the platform
	 * allows to be hand-rolled: hidden inputs and a submit.
	 */
	public static function buy_form(array $offer, int $row_id, string $label): string {
		return '<form method="post" action="' . htmlspecialchars($offer['product']->get_url()) . '" class="sms-buy">'
			. '<input type="hidden" name="product_id" value="' . (int)$offer['product']->key . '">'
			. '<input type="hidden" name="product_version" value="' . (int)$offer['version']->key . '">'
			. '<input type="hidden" name="' . JoineryServiceRequirement::FIELD . '" value="' . $row_id . '">'
			. '<button type="submit" class="sms-buy-btn">' . htmlspecialchars($label) . '</button>'
			. '</form>';
	}

	protected static function alert_problem(User $user, OrderItem $order_item, string $reason): void {
		$line = 'JoineryServicesFulfillment: order item #' . $order_item->key . ' (buyer '
			. $user->get('usr_email') . '): ' . $reason;
		error_log($line);
		try {
			require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
			require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionManagedDomains.php'));
			$to = ProvisionManagedDomains::resolve_alert_recipient();
			if ($to === '') {
				return;
			}
			$body = "A paid getjoinery services order could not be granted.\n\n"
				. 'Order item: ' . $order_item->key . "\n"
				. 'Buyer: ' . $user->get('usr_email') . "\n"
				. 'Reason: ' . $reason . "\n\n"
				. "The buyer has paid. Set the date on /admin/server_manager/service_tenants and tell them.\n";
			EmailSender::quickSend($to, '[services] Paid but not granted: order item #' . $order_item->key, $body);
		} catch (\Throwable $e) {
			error_log('JoineryServicesFulfillment: the alert also failed: ' . $e->getMessage());
		}
	}

	public function displayReference(int $ref): string {
		$plan = self::plan($ref);
		return $plan === null ? '' : htmlspecialchars($plan['label']);
	}
}

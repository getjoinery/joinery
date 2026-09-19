<?php
/**
 * ManagedSiteDraft - the buyer's site before they have paid for it.
 *
 * A Managed site is configured on one page, then paid for once. Between the
 * two it is a draft provision row: draft while the buyer is still typing,
 * pending_payment once they have pressed Continue and the row is frozen so
 * the cart line built from it cannot drift. This class is everything the two
 * buyer pages (configure, sites) do to such a row that is not rendering:
 *
 *  - find it, only inside the signed-in buyer's own rows;
 *  - validate a submission in full and freeze it (save_and_freeze);
 *  - unfreeze it for editing, or delete it;
 *  - name the Managed product and price it as a sentence;
 *  - build the one hand-rolled form on the platform: the single button that
 *    posts the frozen draft's id at the product page, entering the cart by
 *    the store's own add-to-cart path with nothing faked.
 *
 * A draft holds nothing. It reserves no domain, no slug, no instance. The
 * domain's availability is checked here and bought only after payment by the
 * registration phase, which already refuses to buy from anything but a paid
 * row.
 *
 * @version 1.1 - a domain whose slug would not fit the node slug column is refused with a sentence; the
 *                freeze on a cart line is read through ManagedSiteRequirement::frozen_from()
 * @version 1.0 - specs/managed_hosting_phase1_purchase.md §4, §5
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ManagedDomainIntake.php'));

class ManagedSiteDraft {

	/** Where the buyer configures. */
	const CONFIGURE_URL = '/profile/server_manager/configure';

	/** Where a visitor without a session starts: step 1, the account, sign-in and sign-up side by side. */
	const START_URL = '/server_manager/start';

	/** The three steps every buyer walks, in order. */
	const STEPS = array(1 => 'Your account', 2 => 'Your site', 3 => 'Payment');

	/**
	 * The step strip every page on the path shows, so a buyer always knows
	 * where they are: steps before $current are done, $current is the one
	 * they are on. Styles ride along once per page.
	 */
	public static function steps_html(int $current): string {
		static $styled = false;
		$html = '';
		if (!$styled) {
			$styled = true;
			$html .= '<style>'
				. '.sms-steps{display:flex;gap:.75rem;list-style:none;margin:0 0 1.25rem;padding:0;flex-wrap:wrap}'
				. '.sms-steps li{display:flex;align-items:center;gap:.45rem;color:#6b7280;font-size:.95em}'
				. '.sms-steps li .sms-step-n{display:inline-flex;width:1.6em;height:1.6em;border-radius:50%;'
				. 'align-items:center;justify-content:center;border:1px solid #cbd5e1;font-size:.85em}'
				. '.sms-steps li.is-current{color:#111827;font-weight:600}'
				. '.sms-steps li.is-current .sms-step-n{background:#02b159;border-color:#02b159;color:#fff}'
				. '.sms-steps li.is-done .sms-step-n{background:#e6f7ee;border-color:#02b159;color:#02b159}'
				. '.sms-steps li+li:before{content:"\2192";color:#cbd5e1;margin-right:.3rem}'
				. '</style>';
		}
		$html .= '<ol class="sms-steps" aria-label="Steps">';
		foreach (self::STEPS as $n => $label) {
			$class = $n < $current ? 'is-done' : ($n === $current ? 'is-current' : '');
			$html .= '<li class="' . $class . '"' . ($n === $current ? ' aria-current="step"' : '') . '>'
				. '<span class="sms-step-n">' . ($n < $current ? '&#10003;' : $n) . '</span>'
				. '<span>Step ' . $n . ': ' . htmlspecialchars($label) . '</span></li>';
		}
		return $html . '</ol>';
	}

	/** The buyer's sites page. */
	const SITES_URL = '/profile/server_manager';

	/** The fulfilment reference of Managed hosting (the operator's account). */
	const MANAGED_REF = 1;

	/** What a site's internal name may look like: the install directory and database name. */
	const SITENAME_REGEX = '/^[a-z][a-z0-9_]{0,49}$/';

	/** The node slug column (cvp_slug, mgn slug) is varchar(50); a slug is the domain with dots as dashes. */
	const SLUG_MAX_LENGTH = 50;

	// ------------------------------------------------------------------
	// Finding
	// ------------------------------------------------------------------

	/**
	 * One of the buyer's own pre-payment rows, or null. Looked up inside the
	 * owner's rows so somebody else's id finds nothing rather than being
	 * loaded and refused.
	 */
	public static function load_for_user(int $id, int $user_id) {
		if ($id <= 0 || $user_id <= 0) {
			return null;
		}
		$row = new CustomerCloudProvision($id, TRUE);
		if (!$row->key || $row->get('cvp_delete_time')
				|| (int)$row->get('cvp_usr_user_id') !== $user_id
				|| (string)$row->get('cvp_origin') !== 'buyer'
				|| !$row->is_pre_payment()) {
			return null;
		}
		return $row;
	}

	// ------------------------------------------------------------------
	// The states
	// ------------------------------------------------------------------

	/**
	 * pending_payment -> draft. The cart line built from the frozen row is
	 * stale from here, so it is taken out of this session's cart — which is
	 * what the Edit button promised. A copy of the line in another browser's
	 * cart still names the old freeze, and the charge refuses it there.
	 */
	public static function unfreeze(CustomerCloudProvision $row): void {
		if ((string)$row->get('cvp_status') === 'pending_payment') {
			$row->set('cvp_status', 'draft');
			$row->save();
		}
		self::drop_from_cart($row);
	}

	/** Gone. A draft holds nothing, so there is nothing else to undo. */
	public static function delete(CustomerCloudProvision $row): void {
		if ($row->is_pre_payment()) {
			$row->soft_delete();
		}
		self::drop_from_cart($row);
	}

	/**
	 * Take this draft out of the session's cart: the hosting line naming it,
	 * and the domain-year line its freeze contributed (the line naming the
	 * draft's domain on the domain product). Called before the draft changes,
	 * so the domain on the row is still the one the companion line was priced
	 * for.
	 */
	public static function drop_from_cart(CustomerCloudProvision $row): void {
		if (!class_exists('ShoppingCart') || !isset($_SESSION)) {
			return;
		}
		$draft_id = (int)$row->key;
		$domain = strtolower(trim((string)$row->get('cvp_domain')));
		$domain_product_id = ManagedDomainIntake::domainProductId();
		try {
			$cart = ShoppingCart::current();
			foreach ($cart->items as $key => $item) {
				$data = (array)($item[2] ?? array());
				$product_id = isset($item[1]) && is_object($item[1]) ? (int)$item[1]->key : 0;
				$companion = $domain_product_id > 0 && $product_id === $domain_product_id && $domain !== ''
					&& strtolower(trim((string)($data['managed_domain']['answer'] ?? ''))) === $domain;
				if (self::line_names_draft($data, $draft_id) || $companion) {
					$cart->remove_item($key);
				}
			}
		} catch (Throwable $e) {
			error_log('ManagedSiteDraft: could not drop draft #' . $draft_id . ' from the cart: ' . $e->getMessage());
		}
	}

	/** Does a cart line's form data name this draft (any freeze)? */
	private static function line_names_draft(array $data, int $draft_id): bool {
		$answer = $data['managed_site'] ?? 0;
		if (is_array($answer)) {
			$answer = $answer['answer'] ?? 0;
		}
		return (int)$answer === $draft_id;
	}

	// ------------------------------------------------------------------
	// Validation and freezing
	// ------------------------------------------------------------------

	/** The site's internal name, from a domain: its second-level label, made safe. */
	public static function default_sitename(string $domain): string {
		$label = explode('.', strtolower(trim($domain)))[0] ?? '';
		$label = preg_replace('/[^a-z0-9_]/', '_', $label);
		$label = preg_replace('/_+/', '_', trim($label, '_'));
		if ($label === '' || !preg_match('/^[a-z]/', $label)) {
			$label = 'site' . ($label === '' ? '' : '_' . $label);
		}
		return substr($label, 0, 50);
	}

	/** The node slug the pipeline derives from a domain — the same rule fulfilment used. */
	public static function slug_for(string $domain): string {
		$slug = strtolower(trim($domain));
		$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
		$slug = preg_replace('/-+/', '-', $slug);
		return trim($slug, '-');
	}

	/**
	 * The regions a buyer may choose: server_manager_hosted_regions, falling
	 * back to the one customer-cloud region. One region means no choice and
	 * the field does not render.
	 */
	public static function regions(): array {
		$settings = Globalvars::get_instance();
		$raw = trim((string)$settings->get_setting('server_manager_hosted_regions', false, true));
		$out = array();
		foreach (preg_split('/[\s,]+/', $raw) as $region) {
			$region = trim($region);
			if ($region !== '') {
				$out[] = $region;
			}
		}
		if (empty($out)) {
			$default = trim((string)$settings->get_setting('server_manager_customer_cloud_region', false, true));
			$out[] = $default !== '' ? $default : 'us-southeast';
		}
		return array_values(array_unique($out));
	}

	/**
	 * Validate a configure submission in full and, when it passes, write it as
	 * a FROZEN draft (pending_payment). Returns the error sentences and, on
	 * success, the row.
	 *
	 * Order: the domain source, then the name (with the registrant block and
	 * the live quote when we register it), the site name, the region, the
	 * admin email, and last the slug rule — a name a live provision already
	 * holds is refused here so the buyer hears it while walking away is free.
	 * The same rule is binding again at activation.
	 *
	 * @return array{errors:array, row:?CustomerCloudProvision}
	 */
	public static function save_and_freeze(?CustomerCloudProvision $row, array $post, User $user): array {
		$errors = array();
		$user_id = (int)$user->key;

		$source = (string)($post['cvp_domain_source'] ?? '');
		if (!in_array($source, array('own', 'register'), true)) {
			return array('errors' => array('Choose whether we register the domain or you already own it.'), 'row' => null);
		}

		$domain = '';
		$price = null;
		$registrant = array();
		if ($source === 'register') {
			$intake = ManagedDomainIntake::validateRegistration($post, 'cvp_domain');
			$domain = $intake['domain'];
			$price = $intake['price'];
			$registrant = $intake['registrant'];
			foreach ($intake['errors'] as $error) {
				$errors[] = $error;
			}
		} else {
			$name = ManagedDomainIntake::checkOwnName((string)($post['cvp_domain'] ?? ''));
			$domain = $name['domain'];
			if ($name['error'] !== '') {
				$errors[] = $name['error'];
			}
		}

		$sitename = strtolower(trim((string)($post['cvp_sitename'] ?? '')));
		if ($sitename === '' && $domain !== '') {
			$sitename = self::default_sitename($domain);
		}
		if ($sitename !== '' && !preg_match(self::SITENAME_REGEX, $sitename)) {
			$errors[] = 'The site name is used internally as a folder and database name: letters, digits '
				. 'and underscores only, starting with a letter, up to 50 characters.';
		}

		$regions = self::regions();
		$region = trim((string)($post['cvp_region'] ?? ''));
		if (count($regions) <= 1) {
			$region = $regions[0];
		} elseif (!in_array($region, $regions, true)) {
			$errors[] = 'Choose where the site should live.';
		}

		$email = trim((string)($post['cvp_buyer_email'] ?? ''));
		if ($email === '') {
			$email = trim((string)$user->get('usr_email'));
		}
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$errors[] = 'The site admin email address is not valid.';
		}

		if (!empty($errors)) {
			return array('errors' => $errors, 'row' => null);
		}

		$slug = self::slug_for($domain);
		if ($slug === '') {
			return array('errors' => array('That domain cannot be turned into a site name. Try another.'), 'row' => null);
		}
		if (strlen($slug) > self::SLUG_MAX_LENGTH) {
			return array('errors' => array('That domain is too long for us to host: its name must fit in '
				. self::SLUG_MAX_LENGTH . ' characters with the dots. Choose a shorter one.'), 'row' => null);
		}
		$held = CustomerCloudProvision::held_by_live($domain, $slug, $row ? (int)$row->key : 0);
		if ($held !== null) {
			return array('errors' => array(htmlspecialchars($domain) . ' already has a site with us. If it is yours, '
				. 'it is on your sites page; otherwise choose another name.'), 'row' => null);
		}

		if ($row === null) {
			$row = new CustomerCloudProvision(NULL);
			$row->set('cvp_origin', 'buyer');
			$row->set('cvp_usr_user_id', $user_id);
			$row->set('cvp_install_mode', 'fresh');
			$row->set('cvp_docker_mode', 'docker');
		}
		$row->set('cvp_domain', $domain);
		$row->set('cvp_slug', $slug);
		$row->set('cvp_sitename', $sitename);
		$row->set('cvp_region', $region);
		$row->set('cvp_buyer_email', $email);
		$row->set('cvp_buyer_name', trim((string)$user->get('usr_first_name') . ' ' . (string)$user->get('usr_last_name')));
		$row->set('cvp_domain_source', $source);
		if ($source === 'register') {
			$row->set('cvp_domain_quote', $price);
			$row->set('cvp_domain_quote_time', gmdate('Y-m-d H:i:s'));
			$row->seal_registrant($registrant);
		} else {
			$row->set('cvp_domain_quote', null);
			$row->set('cvp_domain_quote_time', null);
			$row->set('cvp_registrant_sealed', null);
		}
		// Frozen: the cart line is built from this row and must not move under it.
		$row->set('cvp_status', 'pending_payment');
		$row->set('cvp_error', null);
		$row->save();
		$row->load();
		return array('errors' => array(), 'row' => $row);
	}

	/** The form values a row prefills: its columns plus the opened registrant block. */
	public static function form_values(?CustomerCloudProvision $row, User $user): array {
		$values = array(
			'cvp_domain_source' => ManagedDomainIntake::sellable() ? 'register' : 'own',
			'cvp_domain'        => '',
			'cvp_sitename'      => '',
			'cvp_region'        => self::regions()[0],
			'cvp_buyer_email'   => (string)$user->get('usr_email'),
			'md_first_name'     => (string)$user->get('usr_first_name'),
			'md_last_name'      => (string)$user->get('usr_last_name'),
			'md_email'          => (string)$user->get('usr_email'),
		);
		// The country and the phone country code default to the United States
		// until the buyer chooses; both blocks pick from the same table.
		$us = ManagedDomainIntake::countryIdFor('US');
		if ($us > 0) {
			$values['usa_cco_country_code_id'] = (string)$us;
			$values['phn_cco_country_code_id'] = (string)$us;
		}
		if ($row === null) {
			return $values;
		}
		foreach (array('cvp_domain_source', 'cvp_domain', 'cvp_sitename', 'cvp_region', 'cvp_buyer_email') as $col) {
			$stored = (string)$row->get($col);
			if ($stored !== '') {
				$values[$col] = $stored;
			}
		}
		foreach (ManagedDomainIntake::fieldsFromRegistrant($row->open_registrant()) as $field => $value) {
			if ($value !== '') {
				$values[$field] = $value;
			}
		}
		return $values;
	}

	// ------------------------------------------------------------------
	// The product and the price
	// ------------------------------------------------------------------

	/**
	 * The Managed product: active, fulfilled by customer_cloud on the
	 * operator's account. Exactly one is expected; none means Managed is not
	 * on sale and the pages say so.
	 */
	public static function managed_product() {
		if (!class_exists('Product')) {
			return null;
		}
		$products = new MultiProduct(array(
			'pro_fulfillment_provider' => 'customer_cloud',
			'pro_fulfillment_ref'      => self::MANAGED_REF,
			'is_active'                => true,
			'deleted'                  => false,
		), array('pro_product_id' => 'ASC'));
		foreach ($products as $product) {
			$versions = $product->get_product_versions(TRUE);
			if ($versions && count($versions) > 0) {
				return $product;
			}
		}
		return null;
	}

	/** The product's single active version, or null. */
	public static function managed_version($product) {
		if ($product === null) {
			return null;
		}
		$versions = $product->get_product_versions(TRUE);
		return ($versions && count($versions) > 0) ? $versions->get(0) : null;
	}

	/** "$12.99 a month from today", read from the product; '' when none is on sale. */
	public static function price_sentence($product): string {
		$version = self::managed_version($product);
		if ($version === null) {
			return '';
		}
		$amount = self::money((string)$version->get('prv_version_price'));
		$type = (string)$version->get('prv_price_type');
		$per = array('month' => 'a month', 'year' => 'a year', 'week' => 'a week', 'day' => 'a day');
		if (isset($per[$type])) {
			return $amount . ' ' . $per[$type] . ' from today';
		}
		return $amount . ' once';
	}

	/** "and $X once for the domain year", for a register draft; '' otherwise. */
	public static function domain_sentence(CustomerCloudProvision $row): string {
		if ((string)$row->get('cvp_domain_source') !== 'register') {
			return '';
		}
		return 'and ' . self::money((string)$row->get('cvp_domain_quote')) . ' once for the domain year';
	}

	public static function money(string $amount): string {
		$settings = Globalvars::get_instance();
		$symbol = CurrencyHelper::symbol(strtolower((string)$settings->get_setting('site_currency'))) ?: '$';
		return $symbol . number_format((float)$amount, 2, '.', '');
	}

	// ------------------------------------------------------------------
	// Continue to payment
	// ------------------------------------------------------------------

	/**
	 * Does the current cart already carry this draft, as it is frozen now?
	 * Then "finish payment" is the cart, not another add. A line from an
	 * earlier freeze does not count — the charge would refuse it.
	 */
	public static function in_cart(CustomerCloudProvision $row): bool {
		if (!class_exists('ShoppingCart') || !isset($_SESSION)) {
			return false;
		}
		try {
			$cart = ShoppingCart::current();
		} catch (Throwable $e) {
			return false;
		}
		$frozen = (string)$row->get('cvp_update_time');
		foreach ($cart->items as $item) {
			$data = (array)($item[2] ?? array());
			if (self::line_names_draft($data, (int)$row->key)
					&& ManagedSiteRequirement::frozen_from($data) === $frozen) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Step 2: the single-button form whose action is the Managed product's
	 * URL. It posts exactly what the product page's own form posts —
	 * product_id and product_version — plus the frozen draft's id, so the
	 * store's product_logic runs as for any product-page submit.
	 *
	 * The one kind of form the platform allows to be hand-rolled: hidden
	 * inputs and a submit, no user-entered field.
	 */
	public static function payment_form(CustomerCloudProvision $row, $product, string $label = 'Continue to payment'): string {
		$version = self::managed_version($product);
		if ($product === null || $version === null) {
			return '';
		}
		return '<form method="post" action="' . htmlspecialchars($product->get_url()) . '" class="sms-pay">'
			. '<input type="hidden" name="product_id" value="' . (int)$product->key . '">'
			. '<input type="hidden" name="product_version" value="' . (int)$version->key . '">'
			. '<input type="hidden" name="managed_site" value="' . (int)$row->key . '">'
			. '<button type="submit" class="sms-visit">' . htmlspecialchars($label) . '</button>'
			. '</form>';
	}
}

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
 * @version 1.12 - who the site is for: the business licence choice is saved with the freeze, priced in the table
 *                and taken out of the cart with the draft (BusinessLicense, hosting_after_purchase WP7)
 * @version 1.11 - the freeze is cvp_freeze_token, set by save_and_freeze and cleared by unfreeze (hosting_after_purchase B13)
 * @version 1.10 - price_table(): the summary's prices as lines with a total for today and after
 * @version 1.9 - the step strip and the payment button take the theme's colours (btn btn-primary, jy tokens)
 * @version 1.8 - each plan carries a features list in place of a blurb; the bundle's is BUNDLE_FEATURES (email and
 *                backups), Managed's adds our management, which an own-Linode site has free during the beta
 * @version 1.7 - the domain's yearly renewal is recorded with its quote (cvp_domain_renew_quote, domain_renewal_sentence)
 * @version 1.6 - a third plan, On your own Linode (reference 0, specs/own_linode_we_install.md W1): the plan is
 *                recorded on the draft (cvp_plan) and read back by plan_of(); its server is in us-east; a free
 *                product's price reads 'free'; the email and backups bundle rides with an own-Linode draft
 *                (cvp_services_wanted) and leaves the cart with it
 * @version 1.5 - a chosen plan not on sale is refused with a sentence, never swapped for the other (reviewer2 F2-6);
 *                a re-freeze keeps the draft's own plan
 * @version 1.4 - two plans (starter_tier.md, Launch scope step 2): Starter lands in a container on a shared host
 *                (fulfilment reference 2), Managed on an instance of its own (reference 1). The buyer picks one
 *                on the configure page; the draft records it as its landing, and its product, price and payment
 *                button follow the plan. Starter is offered only while its product is on sale and a starter host
 *                has room.
 * @version 1.3 - a site name is at least 2 characters (install.sh refuses one letter: Docker takes no such
 *                container name); a one-letter domain label defaults to site_<label>
 * @version 1.2 - the region falls back to us-east
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
				. '.sms-steps li{display:flex;align-items:center;gap:.45rem;color:var(--jy-color-text-muted);font-size:.95em}'
				. '.sms-steps li .sms-step-n{display:inline-flex;width:1.6em;height:1.6em;border-radius:50%;'
				. 'align-items:center;justify-content:center;border:1px solid var(--jy-color-border-strong);font-size:.85em}'
				. '.sms-steps li.is-current{color:var(--jy-color-text);font-weight:600}'
				. '.sms-steps li.is-current .sms-step-n{background:var(--jy-color-primary);border-color:var(--jy-color-primary);color:var(--jy-color-primary-text)}'
				. '.sms-steps li.is-done .sms-step-n{background:color-mix(in srgb, var(--jy-color-primary) 12%, var(--jy-color-bg));border-color:var(--jy-color-primary);color:var(--jy-color-primary)}'
				. '.sms-steps li+li:before{content:"\2192";color:var(--jy-color-border-strong);margin-right:.3rem}'
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

	/** The fulfilment reference of Managed hosting (an instance of its own on the operator's account). */
	const MANAGED_REF = 1;

	/** The fulfilment reference of Starter hosting (a container on one of our shared hosts). */
	const STARTER_REF = 2;

	/** The fulfilment reference of On your own Linode (a server we build in the buyer's own account). */
	const OWN_LINODE_REF = 0;

	/** Where an own-Linode server is built, and its size: what the configurator tells the buyer (Q6, reviewer2 B6). */
	const OWN_LINODE_REGION = 'us-east';
	const OWN_LINODE_TYPE = 'g6-nanode-1';

	/**
	 * The plans a buyer chooses between, cheapest first. Each is one product
	 * (found by its fulfilment reference) and one landing for the site.
	 */
	/** What the Joinery Managed Bundle brings an own-Linode site: email and backups. */
	const BUNDLE_FEATURES = array(
		'2,000 sent emails a month',
		'Daily backups',
	);

	/** What Managed hosting brings: the bundle's two, and our management of the server. */
	const MANAGED_FEATURES = array(
		'2,000 sent emails a month',
		'Daily backups',
		'Joinery monitors and manages your server',
	);

	const PLANS = array(
		'starter' => array('ref' => self::STARTER_REF, 'landing' => 'container', 'label' => 'Starter',
			'features' => self::MANAGED_FEATURES),
		'managed' => array('ref' => self::MANAGED_REF, 'landing' => 'instance', 'label' => 'Managed',
			'features' => self::MANAGED_FEATURES),
		// Management is free while in beta; email and backups are the buyer's own, or the bundle.
		'own_linode' => array('ref' => self::OWN_LINODE_REF, 'landing' => 'instance', 'label' => 'On your own Linode',
			'features' => array(
				'Joinery monitors and manages your server (optional), free during the beta',
				'You set up your own email provider and backups, or add them as a bundle below',
			)),
	);

	/** What a site's internal name may look like: the install directory and database name. */
	const SITENAME_REGEX = '/^[a-z][a-z0-9_]{1,49}$/';

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
			$row->set('cvp_freeze_token', null);
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
	 * draft's domain on the domain product), and the email and backups line
	 * naming the draft. Called before the draft changes,
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
				// The email and backups line that rode with this draft goes with it (B5),
				// and so does the business licence bought for it.
				$services = JoineryServiceRequirement::draft_id_from($data) === $draft_id
					|| BusinessLicense::draft_id_from_line($data) === $draft_id;
				if (self::line_names_draft($data, $draft_id) || $companion || $services) {
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
		if (strlen($label) < 2 || !preg_match('/^[a-z]/', $label)) {
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
			$out[] = $default !== '' ? $default : 'us-east';
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

		// The plan first: it decides the product the payment button posts at.
		// One plan on sale needs no choice; a plan not on sale is refused.
		// A plan the buyer chose that is not on sale now is said, never swapped
		// for the other one at a different price (reviewer2 F2-6). With one plan
		// on sale and none chosen (the form shows no choice), it is that one;
		// an existing draft keeps its own plan.
		$offered = self::offered_plans();
		$plan = (string)($post['plan'] ?? '');
		if ($plan === '' && $row !== null) {
			$plan = self::plan_of($row);
		}
		if ($plan === '' && count($offered) === 1) {
			$plan = (string)array_key_first($offered);
		}
		if (!isset($offered[$plan])) {
			if (empty($offered)) {
				$errors[] = 'Hosting is not on sale right now.';
			} elseif (isset(self::PLANS[$plan])) {
				$errors[] = self::PLANS[$plan]['label'] . ' is not available right now'
					. ($plan === 'starter' ? ': its servers are full. ' : '. ')
					. 'Choose ' . implode(' or ', array_map(function ($d) { return $d['label']; }, $offered)) . ' instead.';
			} else {
				$errors[] = 'Choose a plan.';
			}
		}

		$source = (string)($post['cvp_domain_source'] ?? '');
		if (!in_array($source, array('own', 'register'), true)) {
			return array('errors' => array('Choose whether we register the domain or you already own it.'), 'row' => null);
		}

		$domain = '';
		$price = null;
		$renew = '';
		$registrant = array();
		if ($source === 'register') {
			$intake = ManagedDomainIntake::validateRegistration($post, 'cvp_domain');
			$domain = $intake['domain'];
			$price = $intake['price'];
			$renew = (string)($intake['renew'] ?? '');
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
				. 'and underscores only, starting with a letter, 2 to 50 characters.';
		}

		$regions = self::regions();
		$region = trim((string)($post['cvp_region'] ?? ''));
		if ($plan === 'own_linode') {
			// One place and one size for a server in the buyer's account (Q6).
			$region = self::OWN_LINODE_REGION;
		} elseif (count($regions) <= 1) {
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

		// Who the site is for: a business needs a licence, on every plan.
		$licence = BusinessLicense::choice_from($post, $user_id, $row ? (int)$row->key : 0);
		if ($licence['error'] !== '') {
			$errors[] = $licence['error'];
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
			$row->set('cvp_domain_renew_quote', $renew !== '' ? $renew : null);
			$row->set('cvp_domain_quote_time', gmdate('Y-m-d H:i:s'));
			$row->seal_registrant($registrant);
		} else {
			$row->set('cvp_domain_quote', null);
			$row->set('cvp_domain_renew_quote', null);
			$row->set('cvp_domain_quote_time', null);
			$row->set('cvp_registrant_sealed', null);
		}
		// Frozen: the cart line is built from this row and must not move under it.
		$row->set('cvp_landing', self::PLANS[$plan]['landing']);
		$row->set('cvp_plan', $plan);
		// The size the buyer was told, pinned: not the shared customer-cloud default an operator may change.
		$row->set('cvp_instance_type', $plan === 'own_linode' ? self::OWN_LINODE_TYPE : null);
		// The email and backups bundle rides with an own-Linode site, when ticked and on sale.
		$row->set('cvp_services_wanted', $plan === 'own_linode' && !empty($post['with_services'])
			&& JoineryServicesFulfillment::bundle_offer() !== null);
		$row->set('cvp_license_choice', $licence['choice']);
		$row->set('cvp_status', 'pending_payment');
		$row->set('cvp_freeze_token', bin2hex(random_bytes(16)));
		$row->set('cvp_error', null);
		$row->save();
		$row->load();
		return array('errors' => array(), 'row' => $row);
	}

	/** The form values a row prefills: its columns plus the opened registrant block. */
	public static function form_values(?CustomerCloudProvision $row, User $user): array {
		$values = array(
			'plan'              => (string)(array_key_first(self::offered_plans()) ?? 'managed'),
			'cvp_domain_source' => ManagedDomainIntake::sellable() ? 'register' : 'own',
			'cvp_domain'        => '',
			'cvp_sitename'      => '',
			'cvp_region'        => self::regions()[0],
			'cvp_buyer_email'   => (string)$user->get('usr_email'),
			'with_services'     => '',
			'licence_use'       => '',
			'licence_choice'    => '',
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
		$values['plan'] = self::plan_of($row);
		$values['with_services'] = $row->get('cvp_services_wanted') ? '1' : '';
		$choice = (string)$row->get('cvp_license_choice');
		if ($choice !== '') {
			$values['licence_use'] = $choice === 'free' ? 'free' : 'business';
			$values['licence_choice'] = $choice === 'free' ? '' : $choice;
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
		return self::product_for_plan('managed');
	}

	/**
	 * Which plan a row is: the plan it was frozen for, or, on a row from
	 * before plans were recorded, its landing (a container is Starter).
	 */
	public static function plan_of(CustomerCloudProvision $row): string {
		$plan = (string)$row->get('cvp_plan');
		if (isset(self::PLANS[$plan])) {
			return $plan;
		}
		return $row->is_container() ? 'starter' : 'managed';
	}

	/**
	 * The plans on sale now, in PLANS order: each one's product is active,
	 * and Starter also needs a starter host with room, because a paid
	 * starter site with nowhere to land is a refund.
	 *
	 * @return array<string, array> plan => PLANS entry plus 'product'
	 */
	public static function offered_plans(): array {
		$out = array();
		foreach (self::PLANS as $plan => $def) {
			$product = self::product_for_plan($plan);
			if ($product === null) {
				continue;
			}
			if ($plan === 'starter' && !self::starter_has_room()) {
				continue;
			}
			$out[$plan] = $def + array('product' => $product);
		}
		return $out;
	}

	/** Is there a starter host taking sites with a place free? */
	public static function starter_has_room(): bool {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_hosts_class.php'));
		return ManagedHost::pick_for_provisioning('starter') !== null;
	}

	/** The product a draft pays for: its plan's. */
	public static function product_for_row(CustomerCloudProvision $row) {
		return self::product_for_plan(self::plan_of($row));
	}

	/**
	 * Every plan on sale as one phrase, cheapest first:
	 * "Starter is $4.99 a month, Managed $9.99 a month, from today"; '' when none is.
	 */
	public static function plans_sentence(): string {
		$parts = array();
		$free = '';
		foreach (self::offered_plans() as $plan => $def) {
			$price = self::price_sentence($def['product']);
			if ($price === 'free') {
				$free = $def['label'] . ' is free (Linode bills you for the server)';
			} elseif ($price !== '') {
				$parts[] = $def['label'] . ' ' . preg_replace('/ from today$/', '', $price);
			}
		}
		$out = empty($parts) ? '' : implode(', ', $parts) . ', from today';
		if ($free !== '') {
			$out = $out === '' ? $free : $out . '; ' . $free;
		}
		return $out;
	}

	/** One plan's product: active, fulfilled by customer_cloud with the plan's reference, with a version. */
	public static function product_for_plan(string $plan) {
		if (!class_exists('Product') || !isset(self::PLANS[$plan])) {
			return null;
		}
		$products = new MultiProduct(array(
			'pro_fulfillment_provider' => 'customer_cloud',
			'pro_fulfillment_ref'      => self::PLANS[$plan]['ref'],
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

	/** "$12.99 a month from today", read from the product; 'free' for a free one; '' when none is on sale. */
	public static function price_sentence($product): string {
		$version = self::managed_version($product);
		if ($version === null) {
			return '';
		}
		if ((float)$version->get('prv_version_price') <= 0) {
			return 'free';
		}
		$amount = self::money((string)$version->get('prv_version_price'));
		$type = (string)$version->get('prv_price_type');
		$per = array('month' => 'a month', 'year' => 'a year', 'week' => 'a week', 'day' => 'a day');
		if (isset($per[$type])) {
			return $amount . ' ' . $per[$type] . ' from today';
		}
		return $amount . ' once';
	}

	/**
	 * The summary's price table: one line per thing bought (the plan, the
	 * bundle when chosen, the business licence, the domain's first year) with what it costs today
	 * and what it costs after that, and the totals of both columns. Null when
	 * the plan is not on sale.
	 *
	 * @param array|null $bundle JoineryServicesFulfillment::bundle_offer(), counted only for an
	 *                           own-Linode draft that asked for it
	 * @return array|null ['lines' => [['label', 'note', 'today', 'then']], 'today' => string, 'then' => string]
	 */
	public static function price_table(CustomerCloudProvision $row, $product, ?array $bundle): ?array {
		$version = self::managed_version($product);
		if ($version === null) {
			return null;
		}
		$lines = array();
		$today = 0.0;
		$recurring = array();
		$add = function (string $label, string $note, $version) use (&$lines, &$today, &$recurring) {
			$amount = (float)$version->get('prv_version_price');
			$period = self::period_word((string)$version->get('prv_price_type'));
			$today += $amount;
			if ($amount <= 0) {
				$lines[] = array('label' => $label, 'note' => $note, 'today' => 'Free', 'then' => 'Free');
				return;
			}
			if ($period !== '') {
				$recurring[$period] = ($recurring[$period] ?? 0.0) + $amount;
			}
			$lines[] = array('label' => $label, 'note' => $note, 'today' => self::money((string)$amount),
				'then' => $period !== '' ? self::money((string)$amount) . ' a ' . $period : '');
		};

		$add('Plan: ' . self::PLANS[self::plan_of($row)]['label'], '', $version);
		if ($bundle !== null && self::plan_of($row) === 'own_linode' && $row->get('cvp_services_wanted')) {
			$add('Joinery Managed Bundle', '', $bundle['version']);
		}
		$licence = BusinessLicense::offers()[(string)$row->get('cvp_license_choice')] ?? null;
		if ($licence !== null) {
			$add(BusinessLicense::label((string)$row->get('cvp_license_choice')), 'Lifetime, for this site.', $licence['version']);
		} elseif (BusinessLicense::held_id((string)$row->get('cvp_license_choice')) > 0) {
			$lines[] = array('label' => 'Business licence', 'note' => 'One you already own.', 'today' => 'Free', 'then' => '');
		}
		if ((string)$row->get('cvp_domain_source') === 'register') {
			$quote = (float)$row->get('cvp_domain_quote');
			$today += $quote;
			$renew = trim((string)$row->get('cvp_domain_renew_quote'));
			$lines[] = array(
				'label' => 'Domain: ' . (string)$row->get('cvp_domain'),
				'note'  => 'First year. ' . ($renew !== ''
					? 'Then ' . self::money($renew) . ' a year, paid to the registrar once the domain is in your own account.'
					: 'After that it renews with the registrar, once the domain is in your own account.'),
				'today' => self::money((string)$quote),
				'then'  => '',
			);
		}

		$then = array();
		foreach ($recurring as $period => $amount) {
			$then[] = self::money((string)$amount) . ' a ' . $period;
		}
		return array(
			'lines' => $lines,
			'today' => $today > 0 ? self::money((string)$today) : 'Free',
			'then'  => $then ? implode(' + ', $then) : 'Free',
		);
	}

	/** 'month' for a monthly price type, and so on; '' for a one-time price. */
	private static function period_word(string $type): string {
		return in_array($type, array('month', 'year', 'week', 'day'), true) ? $type : '';
	}

	/** "Renews at $Y a year, paid to the registrar once the domain is yours", for a register draft with a renewal quote; '' otherwise. */
	public static function domain_renewal_sentence(CustomerCloudProvision $row): string {
		if ((string)$row->get('cvp_domain_source') !== 'register' || trim((string)$row->get('cvp_domain_renew_quote')) === '') {
			return '';
		}
		return 'Renews at ' . self::money((string)$row->get('cvp_domain_renew_quote')) . ' a year, paid to the registrar once the domain is in your own account';
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
		$frozen = (string)$row->get('cvp_freeze_token');
		if ($frozen === '') {
			return false;
		}
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
			. '<button type="submit" class="btn btn-primary">' . htmlspecialchars($label) . '</button>'
			. '</form>';
	}
}

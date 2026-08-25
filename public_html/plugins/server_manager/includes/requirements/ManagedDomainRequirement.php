<?php
/**
 * ManagedDomainRequirement - buy the domain name in the same click as the box.
 *
 * Attached to a hosting product, this turns "and now go buy a domain somewhere
 * else, then point it here" into two fields on the product page. The buyer
 * types the name they want, sees live availability and the one-year price, and
 * fills a contact block that is prefilled from their account. One payment
 * covers the server and the name.
 *
 * The contact block is not paperwork for us: it is the WHOIS record, and it
 * names the BUYER. They are the legal owner of the domain from the second it
 * is registered — the operator holds only management and billing, and hands
 * those over later too.
 *
 * Three things happen at three different moments, and the split matters:
 *
 *  - validate() asks the registrar, live, whether the name is free and what a
 *    year costs. Nothing price-shaped is ever read from the POST.
 *  - process() writes that server-derived quote into the cart line's form
 *    data, where extra_cart_lines() picks it up as the "Domain registration
 *    (1 year)" line and where coupon repricing later re-reads it without
 *    another API call.
 *  - post_purchase() — after the money moves — files the rdm_ row the
 *    provisioning pipeline works from. It is best-effort by contract, so it
 *    can never break a charge that already succeeded.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/AbstractProductRequirement.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/DomainRegistrarRegistry.php'));

class ManagedDomainRequirement extends AbstractProductRequirement {

	const LABEL = 'Managed domain';

	/** The registrant fields, in render order: key => [label, required]. */
	const CONTACT_FIELDS = array(
		'md_first_name'     => array('First name', true),
		'md_last_name'      => array('Last name', true),
		'md_address1'       => array('Street address', true),
		'md_city'           => array('City', true),
		'md_state_province' => array('State or province', true),
		'md_postal_code'    => array('Postal code', true),
		'md_country'        => array('Country', true),
		'md_phone'          => array('Phone', true),
		'md_email'          => array('Email', true),
	);

	/**
	 * The quote validate() obtained, reused by process() in the same request.
	 * validate_form() calls both on ONE instance, so this saves a second live
	 * lookup without ever letting a price cross a request boundary.
	 * @var array|null
	 */
	private $quote = null;

	public function getFormGroup() { return 'info'; }

	// ------------------------------------------------------------------
	// Availability of the feature itself
	// ------------------------------------------------------------------

	/** The registrar this deployment sells through, or null. */
	public static function registrar() {
		return DomainRegistrarRegistry::firstConfigured();
	}

	/** The product id the domain-year line is charged against, or 0. */
	public static function domainProductId(): int {
		$settings = Globalvars::get_instance();
		return (int)$settings->get_setting('store_domain_registration_product_id', false, true);
	}

	/**
	 * Can the domain year actually be charged for?
	 *
	 * A setting pointing at a product that was later deleted, or whose version
	 * was deactivated, still passes a `> 0` test — and then the cart line is
	 * silently skipped while the pipeline goes on to register the domain the
	 * buyer never paid for. So the gate loads the thing it names.
	 */
	public static function domainProductSellable(): bool {
		$product_id = self::domainProductId();
		if ($product_id <= 0) {
			return false;
		}
		require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
		$product = new Product($product_id, TRUE);
		if (!$product->key || $product->get('pro_delete_time')) {
			return false;
		}
		$versions = $product->get_product_versions(TRUE);
		return $versions && count($versions) > 0;
	}

	// ------------------------------------------------------------------
	// The form
	// ------------------------------------------------------------------

	public function render_fields($formwriter, $product, $existing_data = array()) {
		$get = function ($key, $fallback = '') use ($existing_data) {
			return isset($existing_data[$key]) ? (string)$existing_data[$key] : $fallback;
		};

		$formwriter->textinput('managed_domain_name', 'Domain name you want', array(
			'value'      => $get('managed_domain_name'),
			'maxlength'  => 253,
			'placeholder' => 'smithfamily.com',
			'helptext'   => 'We register it for you and point it at your new server. You are the legal '
				. 'owner from day one.',
			'validation' => array('required' => true),
		));
		// Where the live availability answer lands. Not a form control — the
		// price is derived on the server and never posted.
		echo '<div id="managed_domain_status" class="form-text" aria-live="polite"></div>';

		echo '<p class="form-text">Who owns the domain. This becomes the public registration '
			. 'record, so it has to be a real contact — private registration keeps it hidden from '
			. 'WHOIS lookups, and we turn that on for you at no cost.</p>';

		$formwriter->textinput('md_first_name', 'First name', array(
			'value' => $get('md_first_name', $get('usr_first_name')),
			'maxlength' => 100, 'validation' => array('required' => true)));
		$formwriter->textinput('md_last_name', 'Last name', array(
			'value' => $get('md_last_name', $get('usr_last_name')),
			'maxlength' => 100, 'validation' => array('required' => true)));
		$formwriter->textinput('md_address1', 'Street address', array(
			'value' => $get('md_address1'), 'maxlength' => 255,
			'validation' => array('required' => true)));
		$formwriter->textinput('md_city', 'City', array(
			'value' => $get('md_city'), 'maxlength' => 100,
			'validation' => array('required' => true)));
		$formwriter->textinput('md_state_province', 'State or province', array(
			'value' => $get('md_state_province'), 'maxlength' => 100,
			'validation' => array('required' => true)));
		$formwriter->textinput('md_postal_code', 'Postal code', array(
			'value' => $get('md_postal_code'), 'maxlength' => 20,
			'validation' => array('required' => true)));
		$formwriter->dropinput('md_country', 'Country', array(
			'options' => self::countryOptions(),
			'value'   => $get('md_country', 'US'),
			'validation' => array('required' => true)));
		$formwriter->textinput('md_phone', 'Phone', array(
			'value' => $get('md_phone'), 'maxlength' => 30,
			'placeholder' => '+1 555 123 4567',
			'helptext' => 'Include your country code — the registry requires it.',
			'validation' => array('required' => true)));
		$formwriter->textinput('md_email', 'Email', array(
			'value' => $get('md_email', $get('usr_email')), 'maxlength' => 255,
			'validation' => array('required' => true)));
	}

	/** ISO-2 code => country name, from the platform's country table. */
	public static function countryOptions(): array {
		$options = array();
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT cco_iso_code_2, cco_country FROM cco_country_codes '
				. "WHERE cco_iso_code_2 <> '' ORDER BY cco_country ASC");
			$q->execute();
			foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$options[strtoupper($row['cco_iso_code_2'])] = $row['cco_country'];
			}
		} catch (Throwable $e) {
			error_log('ManagedDomainRequirement: country list unavailable: ' . $e->getMessage());
		}
		if (empty($options)) {
			$options = array('US' => 'United States');
		}
		return $options;
	}

	public function get_javascript(): string {
		return <<<'JS'
(function () {
	function wire() {
	var input = document.getElementById('managed_domain_name');
	var status = document.getElementById('managed_domain_status');
	if (!input || !status || !window.joineryApi) { return; }
	var timer = null, last = '';
	function show(text, state) {
		status.textContent = text;
		status.setAttribute('data-state', state);
	}
	function check() {
		var value = (input.value || '').trim().toLowerCase();
		if (value === last) { return; }
		last = value;
		if (value === '') { show('', ''); return; }
		show('Checking ' + value + '…', 'checking');
		window.joineryApi.post('server_manager/domain_check', { domain: value }).then(function (data) {
			if ((input.value || '').trim().toLowerCase() !== value) { return; }
			if (data && data.available) {
				show(value + ' is available — ' + (data.price_display || '') + ' for the first year, '
					+ 'added to this order.', 'available');
			} else {
				show((data && data.message) || 'That name is not available.', 'unavailable');
			}
		}).catch(function () {
			show('We could not check that name just now. You can still submit — we check again before charging.', 'error');
		});
	}
	input.addEventListener('input', function () {
		clearTimeout(timer);
		timer = setTimeout(check, 600);
	});
	input.addEventListener('blur', function () { clearTimeout(timer); check(); });
	}
	// The field is normally already in the DOM (this script is emitted after
	// the form), but a theme that emits it earlier must not silently lose the
	// availability check.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', wire);
	} else {
		wire();
	}
})();
JS;
	}

	public function get_validation_info() {
		return array(
			'managed_domain_name' => array('required' => array('true', 'Enter the domain name you want.')),
			'md_first_name'       => array('required' => array('true', 'First name is required.')),
			'md_last_name'        => array('required' => array('true', 'Last name is required.')),
			'md_address1'         => array('required' => array('true', 'Street address is required.')),
			'md_city'             => array('required' => array('true', 'City is required.')),
			'md_postal_code'      => array('required' => array('true', 'Postal code is required.')),
			'md_phone'            => array('required' => array('true', 'Phone number is required.')),
			'md_email'            => array('required' => array('true', 'Email is required.')),
		);
	}

	// ------------------------------------------------------------------
	// Validation — and the authoritative quote
	// ------------------------------------------------------------------

	public function validate($post_data, $product) {
		$errors = array();
		$this->quote = null;

		$registrar = self::registrar();
		if ($registrar === null || !self::domainProductSellable()) {
			// A half-configured deployment must never sell an unpriced or
			// unregistrable domain, so this fails the submission outright
			// rather than quietly dropping the domain from the order.
			return array('Domain registration is not available right now. Please contact us before ordering.');
		}

		$domain = DomainRegistrarRegistry::normalizeName((string)($post_data['managed_domain_name'] ?? ''));
		if ($domain === '') {
			return array('Enter the domain name you want.');
		}

		if (!DomainRegistrarRegistry::isRegistrableName($domain)) {
			$errors[] = '"' . htmlspecialchars($domain) . '" is not a domain name we can register. '
				. 'Enter it like smithfamily.com.';
		} elseif (!DomainRegistrarRegistry::tldOffered($domain)) {
			$errors[] = 'We can register ' . DomainRegistrarRegistry::offeredTldsPhrase() . ' names. '
				. 'Choose one of those endings.';
		}

		foreach (self::CONTACT_FIELDS as $field => $spec) {
			if ($spec[1] && trim((string)($post_data[$field] ?? '')) === '') {
				$errors[] = $spec[0] . ' is required for the domain registration.';
			}
		}
		$country = strtoupper(trim((string)($post_data['md_country'] ?? '')));
		if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) {
			$errors[] = 'Choose the country for the domain registration.';
		}
		$email = trim((string)($post_data['md_email'] ?? ''));
		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$errors[] = 'The domain registration email address is not valid.';
		}
		$phone = trim((string)($post_data['md_phone'] ?? ''));
		if ($phone !== '' && $registrar->normalizeRegistrantPhone($phone) === '') {
			$errors[] = 'Enter the domain registration phone number with its country code, '
				. 'like +1 555 123 4567.';
		}

		if (!empty($errors)) {
			return $errors;
		}

		// The authoritative answer: available now, and priced now. The buyer's
		// browser saw a quote too, but nothing it reported is trusted.
		try {
			$answers = $registrar->checkAvailability(array($domain));
		} catch (DomainRegistrarException $e) {
			error_log('ManagedDomainRequirement: availability check failed for ' . $domain
				. ': ' . $e->getMessage());
			return array('We could not reach the domain registry just now. Please try again in a moment.');
		}

		$answer = $answers[$domain] ?? null;
		if ($answer === null || empty($answer['available']) || empty($answer['price_year'])) {
			$message = trim((string)($answer['message'] ?? ''));
			return array($message !== '' ? $message
				: 'That domain is not available. Try another name.');
		}

		$this->quote = array('domain' => $domain, 'price' => (string)$answer['price_year']);
		return array();
	}

	public function process($post_data, $product, $order_detail, $user) {
		if ($this->quote === null) {
			// Reached only if a caller processes without validating; there is
			// no price to trust and no domain to record, so contribute nothing.
			return array(array(), array());
		}

		$domain = $this->quote['domain'];
		$price  = $this->quote['price'];

		$data = array(
			'managed_domain'            => array('question' => 'Registered domain', 'answer' => $domain),
			'managed_domain_price_line' => $price,
		);
		foreach (array_keys(self::CONTACT_FIELDS) as $field) {
			$data[$field] = trim((string)($post_data[$field] ?? ''));
		}
		$data['md_country'] = strtoupper($data['md_country']);

		$display = array(
			'Domain' => $domain . ' (1 year)',
		);
		return array($data, $display);
	}

	// ------------------------------------------------------------------
	// The companion cart line
	// ------------------------------------------------------------------

	/**
	 * The "Domain registration (1 year)" line.
	 *
	 * A separate product line rather than a surcharge on the hosting line,
	 * because the hosting line may be a subscription and this charge is not:
	 * folded in, the domain year would be billed again every cycle. As its own
	 * non-recurring line, "one year, one charge, never again from us" is true
	 * structurally, and the receipt says what the domain cost.
	 */
	public function extra_cart_lines($form_data, $product) {
		$price = trim((string)($form_data['managed_domain_price_line'] ?? ''));
		$domain = '';
		if (isset($form_data['managed_domain']['answer'])) {
			$domain = (string)$form_data['managed_domain']['answer'];
		}
		if ($price === '' || $domain === '') {
			return array();   // this requirement was not part of the submission
		}
		$product_id = self::domainProductId();
		if ($product_id <= 0) {
			return array();
		}
		return array(array(
			'product_id' => $product_id,
			'form_data'  => array(
				'user_price_override' => $price,
				'managed_domain'      => array('question' => 'Registered domain', 'answer' => $domain),
			),
		));
	}

	// ------------------------------------------------------------------
	// Intake
	// ------------------------------------------------------------------

	/**
	 * File the row the provisioning pipeline works from.
	 *
	 * Best-effort by contract: the buyer has already paid, so nothing here may
	 * throw into the charge path. A failure is logged and the operator queue is
	 * where it surfaces — never a failed order for a successful payment.
	 */
	public function post_purchase($data, $order_item, $user, $order) {
		try {
			$domain = '';
			if (isset($data['managed_domain']['answer'])) {
				$domain = strtolower(trim((string)$data['managed_domain']['answer']));
			}
			if ($domain === '' || !$order_item || !$order_item->key || !$user || !$user->key) {
				return;
			}

			require_once(PathHelper::getIncludePath('plugins/server_manager/data/registered_domains_class.php'));

			$existing = new MultiRegisteredDomain(array(
				'external_order_item_id' => (int)$order_item->key, 'deleted' => false));
			foreach ($existing as $row) {
				return;   // already filed — post_purchase can run more than once
			}

			$registrar = self::registrar();
			$row = new RegisteredDomain(NULL);
			$row->set('rdm_registrar', $registrar ? $registrar::getKey() : 'namecheap');
			$row->set('rdm_domain', $domain);
			$row->set('rdm_usr_user_id', (int)$user->key);
			$row->set('rdm_external_order_item_id', (int)$order_item->key);
			$row->set('rdm_buyer_email', (string)$user->get('usr_email'));
			$row->set('rdm_price_paid', $data['managed_domain_price_line'] ?? null);
			$row->set('rdm_status', RegisteredDomain::STATUS_PENDING);
			$row->set('rdm_graduation_state', RegisteredDomain::GRAD_OPERATOR);
			$row->seal_registrant(self::registrantFrom($data));
			$row->prepare();
			$row->save();
		} catch (Throwable $e) {
			// post_purchase is best-effort by contract — the buyer has paid and
			// nothing here may break the charge — but a swallowed failure here
			// means somebody paid for a domain that no queue will ever show. The
			// commonest cause is two buyers racing for the same name, where the
			// loser hits the unique constraint. So it is logged AND reported.
			error_log('ManagedDomainRequirement: could not file the managed-domain row for order item '
				. (($order_item && $order_item->key) ? $order_item->key : '?') . ': ' . $e->getMessage());
			self::alert_intake_failure($data, $order_item, $user, $e);
		}
	}

	/** Tell the operator that a paid-for domain never reached the queue. */
	private static function alert_intake_failure($data, $order_item, $user, Throwable $e): void {
		try {
			if (!isset($data['managed_domain']['answer'])) {
				return;   // this line was not a domain purchase; nothing was lost
			}
			require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
			require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionManagedDomains.php'));

			$to = ProvisionManagedDomains::resolve_alert_recipient();
			if ($to === '') {
				return;
			}
			$domain = (string)$data['managed_domain']['answer'];
			$body = "A buyer paid for a domain and it never reached the provisioning queue.\n\n"
				. 'Domain: ' . $domain . "\n"
				. 'Order item: ' . (($order_item && $order_item->key) ? $order_item->key : 'unknown') . "\n"
				. 'Buyer: ' . (($user && $user->key) ? $user->get('usr_email') : 'unknown') . "\n"
				. 'Reason: ' . $e->getMessage() . "\n\n"
				. "Nothing is registered and nothing will retry on its own. If the name was taken by\n"
				. "another order in the same moment, the buyer needs a refund or an alternate name.\n";
			EmailSender::quickSend($to, '[managed-domain] Paid but not queued: ' . $domain, $body);
		} catch (Throwable $inner) {
			error_log('ManagedDomainRequirement: intake-failure alert also failed: ' . $inner->getMessage());
		}
	}

	/** The registrar-shaped contact block from a cart line's stored answers. */
	public static function registrantFrom(array $data): array {
		return array(
			'first_name'     => (string)($data['md_first_name'] ?? ''),
			'last_name'      => (string)($data['md_last_name'] ?? ''),
			'address1'       => (string)($data['md_address1'] ?? ''),
			'city'           => (string)($data['md_city'] ?? ''),
			'state_province' => (string)($data['md_state_province'] ?? ''),
			'postal_code'    => (string)($data['md_postal_code'] ?? ''),
			'country'        => strtoupper((string)($data['md_country'] ?? '')),
			'phone'          => (string)($data['md_phone'] ?? ''),
			'email'          => (string)($data['md_email'] ?? ''),
		);
	}
}

AbstractProductRequirement::register('ManagedDomainRequirement', __FILE__);

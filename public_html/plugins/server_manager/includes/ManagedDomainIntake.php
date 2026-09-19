<?php
/**
 * ManagedDomainIntake - one gate for what a domain and a registrant may be.
 *
 * Buying a name for a buyer starts with two questions that must get the same
 * answer wherever they are asked: is this a name we can register, and is this
 * contact block a WHOIS record the registry will accept? The configure page
 * asks them when the buyer types, the taken-name alternate asks them again
 * after payment, and the pipeline registers from what they produced. One
 * class answers, so a name the page accepted is never one the registrar
 * refuses.
 *
 * Three things, at three moments:
 *
 *  - The name gates (shape, offered TLD) and the contact gates (complete,
 *    country ISO-2, email, phone WITH its country code) are pure and cost
 *    nothing.
 *  - The quote is live: the registrar is asked whether the name is free and
 *    what a year costs, at the moment the buyer commits. Nothing price-shaped
 *    is ever trusted from a browser; this is the number that gets frozen on
 *    the draft and charged.
 *  - The feature gate (a configured registrar AND a sellable domain-year
 *    product) fails the whole submission rather than quietly dropping the
 *    domain: a half-configured deployment must never take money for hosting
 *    and silently not buy the name.
 *
 * @version 1.2 - the registrant's address and phone are the platform's own form blocks (Address and
 *                PhoneNumber), so a country and a phone country code are chosen from the country table
 *                by id and the phone is composed as "+CC number" for the registrar seam
 * @version 1.1 - the registrar's own message is escaped where it enters, so every sentence quote()
 *                returns is safe to print as the page prints the rest
 * @version 1.0 - lifted from ManagedDomainRequirement (specs/managed_hosting_phase1_purchase.md §6.1)
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/DomainRegistrarRegistry.php'));

class ManagedDomainIntake {

	/**
	 * The registrant fields, in render order: key => [label, required].
	 *
	 * The address and the phone are the platform's own form blocks
	 * (Address::renderFormFields, PhoneNumber::renderFormFields), so they
	 * carry those blocks' field names: a country and a phone country code
	 * are each chosen from the platform's country table by id, never typed.
	 * The name and the email have no block and are plain fields.
	 */
	const CONTACT_FIELDS = array(
		'md_first_name'           => array('First name', true),
		'md_last_name'            => array('Last name', true),
		'usa_cco_country_code_id' => array('Country', true),
		'usa_address1'            => array('Street address', true),
		'usa_address2'            => array('Apt, suite, etc.', false),
		'usa_city'                => array('City', true),
		'usa_state'               => array('State or province', true),
		'usa_zip_code_id'         => array('Postal code', true),
		'phn_cco_country_code_id' => array('Phone country code', true),
		'phn_phone_number'        => array('Phone number', true),
		'md_email'                => array('Email', true),
	);

	// ------------------------------------------------------------------
	// Availability of the feature itself
	// ------------------------------------------------------------------

	/** @var DomainRegistrarProvider|null A registrar pinned by a test; null means discover. */
	private static $pinned = null;

	/** The registrar this deployment sells through, or null. */
	public static function registrar() {
		if (self::$pinned !== null) {
			return self::$pinned;
		}
		return DomainRegistrarRegistry::firstConfigured();
	}

	/**
	 * Tests only: answer registrar() with this instance instead of discovery,
	 * so a suite runs against its stub even on a deployment whose real
	 * registrar is configured. Null unpins.
	 */
	public static function pinRegistrar($registrar): void {
		self::$pinned = $registrar;
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
	 * silently skipped while the pipeline goes on to register a domain the
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

	/** Can this deployment sell a domain at all? Registrar plus a sellable product. */
	public static function sellable(): bool {
		return self::registrar() !== null && self::domainProductSellable();
	}

	/** The one sentence a buyer sees when the feature is not on. */
	const UNAVAILABLE = 'Domain registration is not available right now. Please contact us before ordering.';

	// ------------------------------------------------------------------
	// The name
	// ------------------------------------------------------------------

	/**
	 * A name that can be registered here: the shape and the offered endings.
	 * Returns the normalized name, or an error sentence in 'error'.
	 *
	 * @return array{domain:string, error:string}
	 */
	public static function checkName(string $raw): array {
		$domain = DomainRegistrarRegistry::normalizeName($raw);
		if ($domain === '') {
			return array('domain' => '', 'error' => 'Enter the domain name you want.');
		}
		if (!DomainRegistrarRegistry::isRegistrableName($domain)) {
			return array('domain' => $domain, 'error' => '"' . htmlspecialchars($domain)
				. '" is not a domain name we can register. Enter it like smithfamily.com.');
		}
		if (!DomainRegistrarRegistry::tldOffered($domain)) {
			return array('domain' => $domain, 'error' => 'We can register '
				. DomainRegistrarRegistry::offeredTldsPhrase() . ' names. Choose one of those endings.');
		}
		return array('domain' => $domain, 'error' => '');
	}

	/**
	 * A name the buyer already holds: only the shape is checked. The ending is
	 * theirs to have chosen, and nothing is bought.
	 *
	 * @return array{domain:string, error:string}
	 */
	public static function checkOwnName(string $raw): array {
		$domain = DomainRegistrarRegistry::normalizeName($raw);
		if ($domain === '') {
			return array('domain' => '', 'error' => 'Enter your domain name.');
		}
		if (!DomainRegistrarRegistry::isRegistrableName($domain)) {
			return array('domain' => $domain, 'error' => '"' . htmlspecialchars($domain)
				. '" does not look like a domain name. Enter it like smithfamily.com.');
		}
		return array('domain' => $domain, 'error' => '');
	}

	// ------------------------------------------------------------------
	// The contact block
	// ------------------------------------------------------------------

	/**
	 * The registrant block's own gates. Returns the error sentences; empty
	 * means the block is complete and shaped as the registry requires.
	 *
	 * Needs the registrar for the phone rule: normalizeRegistrantPhone() lives
	 * on the seam so validation and the registration call can never disagree
	 * about what a valid phone is, and a bare national number is refused, not
	 * guessed at.
	 */
	public static function checkRegistrant(array $post, $registrar): array {
		$errors = array();
		foreach (self::CONTACT_FIELDS as $field => $spec) {
			if ($spec[1] && trim((string)($post[$field] ?? '')) === '') {
				$errors[] = $spec[0] . ' is required for the domain registration.';
			}
		}
		$country = self::countryRow((int)($post['usa_cco_country_code_id'] ?? 0));
		if (!empty($post['usa_cco_country_code_id']) && ($country === null || $country['iso'] === '')) {
			$errors[] = 'Choose the country for the domain registration.';
		}
		$email = trim((string)($post['md_email'] ?? ''));
		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$errors[] = 'The domain registration email address is not valid.';
		}
		$phone = self::phoneFrom($post);
		if (trim((string)($post['phn_phone_number'] ?? '')) !== '') {
			if ($phone === '') {
				$errors[] = 'Choose the country code for the domain registration phone number.';
			} elseif ($registrar !== null && $registrar->normalizeRegistrantPhone($phone) === '') {
				$errors[] = 'The domain registration phone number does not look like a phone number. '
					. 'Enter the digits after the country code, like 555 123 4567.';
			}
		}
		return $errors;
	}

	/**
	 * The phone as one string, "+CC number", from the chosen country code and
	 * the typed number — the shape the registrar seam normalizes. '' when
	 * either half is missing.
	 */
	public static function phoneFrom(array $data): string {
		$row = self::countryRow((int)($data['phn_cco_country_code_id'] ?? 0));
		$digits = preg_replace('/\D/', '', (string)($data['phn_phone_number'] ?? ''));
		if ($row === null || $row['code'] === '' || $digits === '') {
			return '';
		}
		return '+' . $row['code'] . ' ' . $digits;
	}

	/**
	 * The registrar-shaped contact block from posted or stored fields. The
	 * three ids the form blocks chose ride along, so the block prefills the
	 * same form again without a reverse lookup.
	 */
	public static function registrantFrom(array $data): array {
		$country = self::countryRow((int)($data['usa_cco_country_code_id'] ?? 0));
		return array(
			'first_name'             => trim((string)($data['md_first_name'] ?? '')),
			'last_name'              => trim((string)($data['md_last_name'] ?? '')),
			'address1'               => trim((string)($data['usa_address1'] ?? '')),
			'address2'               => trim((string)($data['usa_address2'] ?? '')),
			'city'                   => trim((string)($data['usa_city'] ?? '')),
			'state_province'         => trim((string)($data['usa_state'] ?? '')),
			'postal_code'            => trim((string)($data['usa_zip_code_id'] ?? '')),
			'country'                => $country ? $country['iso'] : '',
			'phone'                  => self::phoneFrom($data),
			'email'                  => trim((string)($data['md_email'] ?? '')),
			'country_code_id'        => (int)($data['usa_cco_country_code_id'] ?? 0),
			'phone_country_code_id'  => (int)($data['phn_cco_country_code_id'] ?? 0),
			'phone_number'           => preg_replace('/\D/', '', (string)($data['phn_phone_number'] ?? '')),
		);
	}

	/** The form fields from a stored registrant block — the inverse of registrantFrom(). */
	public static function fieldsFromRegistrant(?array $registrant): array {
		$registrant = $registrant ?: array();
		return array(
			'md_first_name'           => (string)($registrant['first_name'] ?? ''),
			'md_last_name'            => (string)($registrant['last_name'] ?? ''),
			'usa_cco_country_code_id' => (string)($registrant['country_code_id'] ?? ''),
			'usa_address1'            => (string)($registrant['address1'] ?? ''),
			'usa_address2'            => (string)($registrant['address2'] ?? ''),
			'usa_city'                => (string)($registrant['city'] ?? ''),
			'usa_state'               => (string)($registrant['state_province'] ?? ''),
			'usa_zip_code_id'         => (string)($registrant['postal_code'] ?? ''),
			'phn_cco_country_code_id' => (string)($registrant['phone_country_code_id'] ?? ''),
			'phn_phone_number'        => (string)($registrant['phone_number'] ?? ''),
			'md_email'                => (string)($registrant['email'] ?? ''),
		);
	}

	/** @var array<int, ?array{iso:string, code:string, country:string}> per-request cache of country rows */
	private static $country_rows = array();

	/**
	 * One row of the platform's country table by id: its ISO-2 code (what a
	 * WHOIS record carries), its dialling code and its name. Null when the
	 * id names no row.
	 *
	 * @return ?array{iso:string, code:string, country:string}
	 */
	public static function countryRow(int $id): ?array {
		if ($id <= 0) {
			return null;
		}
		if (array_key_exists($id, self::$country_rows)) {
			return self::$country_rows[$id];
		}
		$row = null;
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT cco_iso_code_2, cco_code, cco_country FROM cco_country_codes WHERE cco_country_code_id = ?');
			$q->execute(array($id));
			$found = $q->fetch(PDO::FETCH_ASSOC);
			if ($found) {
				$row = array(
					'iso'     => strtoupper(trim((string)$found['cco_iso_code_2'])),
					'code'    => trim((string)$found['cco_code']),
					'country' => (string)$found['cco_country'],
				);
			}
		} catch (Throwable $e) {
			error_log('ManagedDomainIntake: country row ' . $id . ' unavailable: ' . $e->getMessage());
		}
		self::$country_rows[$id] = $row;
		return $row;
	}

	/** The country table id for an ISO-2 code (the form's default is the deployment's country), or 0. */
	public static function countryIdFor(string $iso): int {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT cco_country_code_id FROM cco_country_codes WHERE upper(cco_iso_code_2) = ? ORDER BY cco_country_code_id ASC LIMIT 1');
			$q->execute(array(strtoupper(trim($iso))));
			return (int)$q->fetchColumn();
		} catch (Throwable $e) {
			error_log('ManagedDomainIntake: country id for ' . $iso . ' unavailable: ' . $e->getMessage());
			return 0;
		}
	}

	// ------------------------------------------------------------------
	// The quote
	// ------------------------------------------------------------------

	/**
	 * Ask the registrar, now, whether the name is free and what a year costs.
	 *
	 * @return array{price:?string, error:string}  price set means available.
	 */
	public static function quote(string $domain, $registrar = null): array {
		$registrar = $registrar ?: self::registrar();
		if ($registrar === null) {
			return array('price' => null, 'error' => self::UNAVAILABLE);
		}
		try {
			$answers = $registrar->checkAvailability(array($domain));
		} catch (DomainRegistrarException $e) {
			error_log('ManagedDomainIntake: availability check failed for ' . $domain . ': ' . $e->getMessage());
			return array('price' => null,
				'error' => 'We could not reach the domain registry just now. Please try again in a moment.');
		}
		$answer = $answers[$domain] ?? null;
		if ($answer === null || empty($answer['available']) || empty($answer['price_year'])) {
			// The registrar's text, not ours: escaped here so the sentence is
			// printable like every other one this class returns.
			$message = htmlspecialchars(trim((string)($answer['message'] ?? '')));
			return array('price' => null,
				'error' => $message !== '' ? $message : 'That domain is not available. Try another name.');
		}
		return array('price' => (string)$answer['price_year'], 'error' => '');
	}

	// ------------------------------------------------------------------
	// The whole intake, as the configure page submits it
	// ------------------------------------------------------------------

	/**
	 * Validate a register-this-name submission in full and quote it.
	 *
	 * Order matters and is cheap-first: the feature gate, then the name and
	 * the contact block (pure), then — only for a submission that has passed
	 * everything free — the live registrar call. Returns either a list of
	 * error sentences or the normalized domain, its one-year price and the
	 * registrant block ready to seal.
	 *
	 * @return array{errors:array, domain:string, price:?string, registrant:array}
	 */
	public static function validateRegistration(array $post, string $domain_field = 'cvp_domain'): array {
		$out = array('errors' => array(), 'domain' => '', 'price' => null, 'registrant' => array());

		$registrar = self::registrar();
		if ($registrar === null || !self::domainProductSellable()) {
			$out['errors'][] = self::UNAVAILABLE;
			return $out;
		}

		$name = self::checkName((string)($post[$domain_field] ?? ''));
		$out['domain'] = $name['domain'];
		if ($name['error'] !== '') {
			$out['errors'][] = $name['error'];
		}
		foreach (self::checkRegistrant($post, $registrar) as $error) {
			$out['errors'][] = $error;
		}
		if (!empty($out['errors'])) {
			return $out;
		}

		$quote = self::quote($out['domain'], $registrar);
		if ($quote['error'] !== '') {
			$out['errors'][] = $quote['error'];
			return $out;
		}
		$out['price'] = $quote['price'];
		$out['registrant'] = self::registrantFrom($post);
		return $out;
	}

	/**
	 * The client-side availability check, wired to a domain field.
	 *
	 * Debounced; calls the server_manager/domain_check action and writes the
	 * answer into the status element. A courtesy only — the number that is
	 * charged is derived again server-side at submit. $when_id names a radio
	 * group whose value must equal $when_value for the check to run (the
	 * register/own split); empty means always.
	 */
	public static function availabilityScript(string $input_id, string $status_id,
			string $when_name = '', string $when_value = ''): string {
		$cfg = json_encode(array(
			'input' => $input_id, 'status' => $status_id,
			'when_name' => $when_name, 'when_value' => $when_value,
		));
		return <<<JS
(function () {
	var cfg = {$cfg};
	function wire() {
		var input = document.getElementById(cfg.input);
		var status = document.getElementById(cfg.status);
		if (!input || !status || !window.joineryApi) { return; }
		var timer = null, last = '';
		function armed() {
			if (!cfg.when_name) { return true; }
			var picked = document.querySelector('input[name="' + cfg.when_name + '"]:checked');
			return !!picked && picked.value === cfg.when_value;
		}
		function show(text, state) {
			status.textContent = text;
			status.setAttribute('data-state', state);
		}
		function check() {
			if (!armed()) { last = ''; show('', ''); return; }
			var value = (input.value || '').trim().toLowerCase();
			if (value === last) { return; }
			last = value;
			if (value === '') { show('', ''); return; }
			show('Checking ' + value + '…', 'checking');
			window.joineryApi.post('server_manager/domain_check', { domain: value }).then(function (data) {
				if ((input.value || '').trim().toLowerCase() !== value) { return; }
				if (data && data.available) {
					show(value + ' is available — ' + (data.price_display || '') + ' for the first year.', 'available');
				} else {
					show((data && data.message) || 'That name is not available.', 'unavailable');
				}
			}).catch(function () {
				show('We could not check that name just now. You can still continue — we check again before anything is charged.', 'error');
			});
		}
		input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(check, 600); });
		input.addEventListener('blur', function () { clearTimeout(timer); check(); });
		if (cfg.when_name) {
			document.querySelectorAll('input[name="' + cfg.when_name + '"]').forEach(function (radio) {
				radio.addEventListener('change', function () { last = ''; check(); });
			});
		}
		check();
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', wire); } else { wire(); }
})();
JS;
	}
}

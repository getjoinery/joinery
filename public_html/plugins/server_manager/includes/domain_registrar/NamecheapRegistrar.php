<?php
/**
 * NamecheapRegistrar - buying and holding domains at Namecheap.
 *
 * Namecheap is the first registrar behind the managed-domain leg because it
 * needs no reseller application: an ordinary account that clears the API gate
 * (20 domains, or $50 in balance, or $50 spent in two years) plus an
 * allowlisted server address is the whole prerequisite.
 *
 * Two Namecheap facts shape everything here:
 *
 *  - **The buyer is the registrant from the first second.** domains.create
 *    takes four independent contact sets, so the domain is legally the
 *    buyer's on day one while its management and billing sit in the
 *    operator's account. Nothing has to be transferred later for ownership to
 *    be real.
 *  - **Custody moves by an account push, and the push has no API.** The
 *    Change Ownership push is free, immediate, and preserves DNS, privacy and
 *    auto-renew — but a person performs it in the dashboard. So the seam
 *    reports the mechanism, the pipeline queues an operator task, and
 *    inAccount() is what notices the domain has actually left.
 *
 * DNS is NOT this class's job. Records are published through
 * NamecheapDnsDriver via the shared reconciler, which is the only writer that
 * understands that Namecheap's setHosts replaces the entire host list.
 *
 * @version 1.0
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/DomainRegistrarProvider.php'));

use GuzzleHttp\Client;

class NamecheapRegistrar implements DomainRegistrarProvider {

	const API_BASE         = 'https://api.namecheap.com/xml.response';
	const API_BASE_SANDBOX = 'https://api.sandbox.namecheap.com/xml.response';

	/** Namecheap's own error text when a domain is not held by this account. */
	const NOT_IN_ACCOUNT_MARKERS = array('domain not found', 'not found in your account',
		'domain name not found', 'is not associated with your account');

	/** @var Client */
	private $http;

	/** @var array<string,?string> Per-TLD one-year price, cached for this instance. */
	private $tld_price = array();

	public function __construct(?Client $http = null) {
		$this->http = $http ?: new Client(array(
			'timeout'         => 30,
			'connect_timeout' => 10,
			'http_errors'     => true,
		));
	}

	public static function getKey(): string   { return 'namecheap'; }
	public static function getLabel(): string { return 'Namecheap'; }

	public static function isConfigured(): bool {
		return self::apiUser() !== '' && self::apiKey() !== '' && self::clientIp() !== '';
	}

	// ------------------------------------------------------------------
	// Credentials — the key is stored encrypted; the rest are plain settings.
	// ------------------------------------------------------------------

	private static function setting(string $name): string {
		$settings = Globalvars::get_instance();
		return trim((string)$settings->get_setting($name, false, true));
	}

	private static function apiUser(): string {
		return self::setting('server_manager_namecheap_api_user');
	}

	private static function apiKey(): string {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ProvisioningSetup.php'));
		return trim(ProvisioningSetup::readSecret('server_manager_namecheap_api_key'));
	}

	private static function clientIp(): string {
		return self::setting('server_manager_namecheap_client_ip');
	}

	private static function sandbox(): bool {
		$value = self::setting('server_manager_namecheap_sandbox');
		return $value !== '' && $value !== '0';
	}

	// ------------------------------------------------------------------
	// Purchase
	// ------------------------------------------------------------------

	public function checkAvailability(array $domains): array {
		$domains = array_values(array_filter(array_map(function ($d) {
			return strtolower(trim((string)$d));
		}, $domains)));
		if (empty($domains)) {
			return array();
		}

		$xml = $this->call('namecheap.domains.check', array('DomainList' => implode(',', $domains)));

		$out = array();
		foreach ($xml->CommandResponse->DomainCheckResult ?? array() as $result) {
			$name = strtolower((string)$result['Domain']);
			$available = strtolower((string)$result['Available']) === 'true';
			$premium   = strtolower((string)$result['IsPremiumName']) === 'true';
			$icann_fee = (float)((string)$result['IcannFee'] ?: '0');

			if ($premium) {
				// A premium name is priced by the registry, often in the
				// hundreds, and its renewal price diverges from its
				// registration price. Selling one through a checkout line that
				// promises "one year, one charge" would be a lie about the
				// second year, so v1 declines them outright and says why.
				$out[$name] = array(
					'available'  => false,
					'price_year' => null,
					'premium'    => true,
					'message'    => 'That is a premium name, which we cannot register for you. Try another.',
				);
				continue;
			}

			$price = $available ? $this->priceForDomain($name, $icann_fee) : null;
			$out[$name] = array(
				'available'  => $available && $price !== null,
				'price_year' => $price,
				'premium'    => false,
				'message'    => $available
					? ($price !== null ? '' : 'We could not price that name right now — try again in a moment.')
					: 'That name is already taken.',
			);
		}

		// A domain the API said nothing about is not silently "available".
		foreach ($domains as $name) {
			if (!isset($out[$name])) {
				$out[$name] = array('available' => false, 'price_year' => null, 'premium' => false,
					'message' => 'We could not check that name right now — try again in a moment.');
			}
		}
		return $out;
	}

	public function register(string $domain, array $registrant, int $years): array {
		$domain = strtolower(trim($domain));
		$years = max(1, (int)$years);

		$params = array(
			'DomainName'         => $domain,
			'Years'              => $years,
			// Free WHOIS privacy, asked for in the same call that registers —
			// a name is never briefly public while a second call catches up.
			'AddFreeWhoisguard'  => 'yes',
			'WGEnabled'          => 'yes',
		);
		// Namecheap requires all four contact sets. The buyer is every one of
		// them: they are the registrant, and there is no operator contact to
		// substitute without making the WHOIS owner ambiguous.
		foreach (array('Registrant', 'Tech', 'Admin', 'AuxBilling') as $role) {
			$params += $this->contactParams($role, $registrant);
		}

		$xml = $this->call('namecheap.domains.create', $params, 'POST');
		$result = $xml->CommandResponse->DomainCreateResult ?? null;
		if ($result === null || strtolower((string)$result['Registered']) !== 'true') {
			throw DomainRegistrarException::terminal(
				'Namecheap accepted the request but did not report ' . $domain . ' as registered.');
		}

		// The create response carries no expiry date; getInfo does, and it is
		// the number the whole graduation countdown is measured from, so it is
		// read rather than computed from today.
		$expiry = null;
		try {
			$expiry = $this->getExpiry($domain);
		} catch (DomainRegistrarException $e) {
			// Registration succeeded — a failed follow-up read must not read as
			// a failed purchase. The watcher refreshes the expiry later.
			error_log('NamecheapRegistrar: registered ' . $domain . ' but could not read its expiry: '
				. $e->getMessage());
		}
		if ($expiry === null) {
			$expiry = gmdate('Y-m-d H:i:s', strtotime('+' . $years . ' year'));
		}
		return array('expiry' => $expiry);
	}

	public function applyWhoisPrivacy(string $domain): void {
		$domain = strtolower(trim($domain));
		$info = $this->getInfoXml($domain);
		if ($info === null) {
			throw DomainRegistrarException::terminal('Namecheap does not hold ' . $domain . '.');
		}
		$guard = $info->Whoisguard ?? null;
		if ($guard === null) {
			throw DomainRegistrarException::terminal(
				'No WHOIS privacy subscription exists for ' . $domain . '.');
		}
		if (strtolower((string)$guard['Enabled']) === 'true') {
			return;   // registration already enabled it — the common path
		}

		$id = trim((string)($guard->ID ?? ''));
		if ($id === '') {
			throw DomainRegistrarException::terminal(
				'WHOIS privacy for ' . $domain . ' is off and Namecheap reported no subscription id.');
		}
		$this->call('namecheap.whoisguard.enable', array(
			'WhoisguardID'     => $id,
			'ForwardedToEmail' => trim((string)($guard->EmailDetails['ForwardedTo'] ?? '')),
		), 'POST');
	}

	// ------------------------------------------------------------------
	// DNS — served by the existing driver, not re-implemented here.
	// ------------------------------------------------------------------

	public function dnsDriverKey(): string { return 'namecheap'; }

	public function dnsCredential(): array {
		return array(
			'api_user'  => self::apiUser(),
			'api_key'   => self::apiKey(),
			'client_ip' => self::clientIp(),
		);
	}

	// ------------------------------------------------------------------
	// Lifecycle
	// ------------------------------------------------------------------

	public function getExpiry(string $domain): ?string {
		$info = $this->getInfoXml(strtolower(trim($domain)));
		if ($info === null) {
			return null;
		}
		$raw = trim((string)($info->DomainDetails->ExpiredDate ?? ''));
		if ($raw === '') {
			return null;
		}
		// Namecheap answers m/d/Y. strtotime reads that as US-format, which is
		// what it is — but an unparseable value must not become "today".
		$stamp = strtotime($raw . ' UTC');
		return $stamp === false ? null : gmdate('Y-m-d H:i:s', $stamp);
	}

	public function inAccount(string $domain): bool {
		return $this->getInfoXml(strtolower(trim($domain))) !== null;
	}

	public function graduationMechanism(): string { return 'account_push'; }

	// ==================================================================
	// Internals
	// ==================================================================

	/**
	 * getInfo for a domain, or null when this account does not hold it.
	 *
	 * "Not in this account" is the graduation success signal, so it is a null
	 * return rather than an exception; every other refusal still throws.
	 */
	private function getInfoXml(string $domain) {
		try {
			$xml = $this->call('namecheap.domains.getInfo', array('DomainName' => $domain));
		} catch (DomainRegistrarException $e) {
			if (!$e->transient && $this->looksLikeNotInAccount($e->getMessage())) {
				return null;
			}
			throw $e;
		}
		return $xml->CommandResponse->DomainGetInfoResult ?? null;
	}

	private function looksLikeNotInAccount(string $message): bool {
		$message = strtolower($message);
		foreach (self::NOT_IN_ACCOUNT_MARKERS as $marker) {
			if (strpos($message, $marker) !== false) {
				return true;
			}
		}
		return false;
	}

	/** The one-year price for a domain, including the ICANN fee. */
	private function priceForDomain(string $domain, float $icann_fee): ?string {
		$tld = $this->tldOf($domain);
		if ($tld === '') {
			return null;
		}
		$base = $this->tldPrice($tld);
		if ($base === null) {
			return null;
		}
		return number_format((float)$base + $icann_fee, 2, '.', '');
	}

	/** The registry price for one year of a TLD, cached for this request. */
	private function tldPrice(string $tld): ?string {
		if (array_key_exists($tld, $this->tld_price)) {
			return $this->tld_price[$tld];
		}
		$this->tld_price[$tld] = null;

		$xml = $this->call('namecheap.users.getPricing', array(
			'ProductType'     => 'DOMAIN',
			'ProductCategory' => 'DOMAINS',
			'ActionName'      => 'REGISTER',
			'ProductName'     => $tld,
		));

		$nodes = $xml->xpath('//Product[@Name="' . $tld . '"]/Price[@Duration="1"]');
		if (empty($nodes)) {
			return null;
		}
		$price_node = $nodes[0];
		// YourPrice is what the operator is charged; that is the number passed
		// through to the buyer. Price/RegularPrice are list prices.
		$value = (string)($price_node['YourPrice'] ?: $price_node['Price']);
		if ($value === '' || !is_numeric($value)) {
			return null;
		}
		$total = (float)$value;
		foreach ($price_node->AdditionalCost ?? array() as $extra) {
			$amount = (string)($extra->Amount ?? '');
			if ($amount !== '' && is_numeric($amount)) {
				$total += (float)$amount;
			}
		}
		$this->tld_price[$tld] = number_format($total, 2, '.', '');
		return $this->tld_price[$tld];
	}

	/** Everything after the first label — 'co.uk' for 'a.co.uk'. */
	private function tldOf(string $domain): string {
		$dot = strpos($domain, '.');
		return $dot === false ? '' : substr($domain, $dot + 1);
	}

	/** One Namecheap contact set, prefixed by role. */
	private function contactParams(string $role, array $c): array {
		return array(
			$role . 'FirstName'     => (string)($c['first_name'] ?? ''),
			$role . 'LastName'      => (string)($c['last_name'] ?? ''),
			$role . 'Address1'      => (string)($c['address1'] ?? ''),
			$role . 'City'          => (string)($c['city'] ?? ''),
			$role . 'StateProvince' => (string)($c['state_province'] ?? ''),
			$role . 'PostalCode'    => (string)($c['postal_code'] ?? ''),
			$role . 'Country'       => strtoupper((string)($c['country'] ?? '')),
			$role . 'Phone'         => self::normalizePhone((string)($c['phone'] ?? '')),
			$role . 'EmailAddress'  => (string)($c['email'] ?? ''),
		);
	}

	/**
	 * Namecheap accepts exactly one phone shape: +CC.NNNNNNNNNN.
	 *
	 * People type theirs a dozen ways, and a phone number the registrar
	 * rejects fails the registration AFTER the buyer has paid — so the shape
	 * is produced here and asserted at validation time by the same function.
	 * Returns '' when the value cannot be read as a phone number at all.
	 */
	public function normalizeRegistrantPhone(string $phone): string {
		return self::normalizePhone($phone);
	}

	public static function normalizePhone(string $phone): string {
		// A leading bracket is decoration around the country code, not a
		// different number: "(+1) 555…" is the same claim as "+1 555…".
		$phone = ltrim(trim($phone), '(');

		// The country code must be STATED, never inferred. A bare "5551234567"
		// is a US number to the person who typed it and a Brazilian one to a
		// prefix table, and guessing wrong puts a stranger's country code on a
		// WHOIS record. The field says to include it and validation refuses
		// without it, which costs one correction and no wrong answers.
		if (!preg_match('/^(\+|00)/', $phone)) {
			return '';
		}
		$phone = preg_replace('/^00/', '+', $phone);

		$digits = preg_replace('/\D/', '', $phone);
		if ($digits === '' || strlen($digits) < 8) {
			return '';
		}

		// A separator after the country code settles it outright:
		// "+44 20 7946 0000" needs no table at all.
		if (preg_match('/^\+\s*(\d{1,3})[\s.\-()]+(.+)$/', $phone, $m)) {
			$rest = preg_replace('/\D/', '', $m[2]);
			if ($rest !== '' && strlen($rest) >= 6) {
				return '+' . $m[1] . '.' . $rest;
			}
		}

		// Run together ("+442079460000"): the longest leading run that is a
		// real calling code and still leaves a plausible national number.
		foreach (array(3, 2, 1) as $len) {
			if (strlen($digits) <= $len + 5) {
				continue;
			}
			$candidate = substr($digits, 0, $len);
			if (in_array($candidate, self::callingCodes(), true)) {
				return '+' . $candidate . '.' . substr($digits, $len);
			}
		}
		return '';
	}

	/** ITU country calling codes, for splitting a typed number. */
	private static function callingCodes(): array {
		static $codes = null;
		if ($codes !== null) {
			return $codes;
		}
		$codes = array_map('strval', array_merge(
			array(1, 7, 20, 27, 30, 31, 32, 33, 34, 36, 39, 40, 41, 43, 44, 45, 46, 47, 48, 49,
				51, 52, 53, 54, 55, 56, 57, 58, 60, 61, 62, 63, 64, 65, 66, 81, 82, 84, 86,
				90, 91, 92, 93, 94, 95, 98),
			range(211, 213), array(216, 218), range(220, 269), array(290, 291), range(297, 299),
			range(350, 359), range(370, 378), range(380, 389), array(420, 421, 423),
			range(500, 509), range(590, 599),
			array(670), range(672, 692),
			array(850, 852, 853, 855, 856, 870, 880, 886),
			range(960, 979), range(991, 998)
		));
		return $codes;
	}

	/**
	 * Issue one API call and return the parsed XML.
	 *
	 * Namecheap answers HTTP 200 with Status="ERROR" on refusal, so success is
	 * read from the envelope. Transport failures and rate limits are transient
	 * (retry next tick); an API-level refusal is terminal, because repeating a
	 * request the registrar has already judged wrong just repeats the refusal.
	 */
	private function call(string $command, array $params = array(), string $method = 'GET'): SimpleXMLElement {
		$user = self::apiUser();
		$query = array_merge(array(
			'ApiUser'  => $user,
			'ApiKey'   => self::apiKey(),
			'UserName' => $user,
			'ClientIp' => self::clientIp(),
			'Command'  => $command,
		), $params);

		$options = ($method === 'POST') ? array('form_params' => $query) : array('query' => $query);
		$base = self::sandbox() ? self::API_BASE_SANDBOX : self::API_BASE;

		try {
			$response = $this->http->request($method, $base, $options);
		} catch (Throwable $e) {
			$status = 0;
			if (method_exists($e, 'getResponse') && $e->getResponse()) {
				$status = (int)$e->getResponse()->getStatusCode();
			}
			// 5xx, 429 and outright network failures say nothing about the
			// request's merit — only that now was a bad moment.
			if ($status === 0 || $status === 429 || $status >= 500) {
				throw DomainRegistrarException::transient(
					'Namecheap ' . $command . ' did not answer: ' . $e->getMessage());
			}
			throw DomainRegistrarException::terminal(
				'Namecheap refused ' . $command . ' (HTTP ' . $status . '): ' . $e->getMessage());
		}

		$previous = libxml_use_internal_errors(true);
		$xml = simplexml_load_string((string)$response->getBody());
		libxml_use_internal_errors($previous);
		if ($xml === false) {
			throw DomainRegistrarException::transient(
				'Namecheap returned a response that could not be parsed as XML.');
		}
		if (strtoupper((string)$xml['Status']) !== 'OK') {
			$reasons = array();
			foreach ($xml->Errors->Error ?? array() as $error) {
				$reasons[] = trim((string)$error);
			}
			$reason = $reasons ? implode('; ', $reasons) : 'no reason given';
			if (stripos($reason, 'ip') !== false
					&& (stripos($reason, 'whitelist') !== false || stripos($reason, 'allow') !== false)) {
				$reason .= ' — add ' . self::clientIp()
					. ' to Profile, Tools, API Access in the Namecheap account.';
			}
			if (stripos($reason, 'too many requests') !== false || stripos($reason, 'rate') !== false) {
				throw DomainRegistrarException::transient('Namecheap rate-limited ' . $command . ': ' . $reason);
			}
			throw DomainRegistrarException::terminal('Namecheap refused ' . $command . ': ' . $reason);
		}
		return $xml;
	}
}

<?php
/**
 * RdapLookup — who registered a domain, asked of the registry.
 *
 * Every registry publishes its registrations over RDAP, the JSON successor to
 * WHOIS: one HTTPS request, no credential, and the answer names the registrar.
 * The public redirector at rdap.org resolves a domain to the registry that
 * answers for its TLD, so the caller needs no bootstrap table of its own.
 *
 * What comes back is the registrar of record. A domain bought through a
 * reseller names the wholesaler (Tucows, Wild West Domains) rather than the
 * storefront, and a country-code TLD may answer nothing — the caller treats
 * null as "unknown", never as "no registrar".
 *
 * Outbound calls go through SafeHttpClient: TLS mandated, redirects walked
 * with the same address checks, body capped.
 *
 * @version 1.0
 */
class RdapLookup {

	const REDIRECTOR = 'https://rdap.org/domain/';

	/** @var callable|null Test seam: fn(string $url): ?array (decoded JSON). */
	private static $fetcher = null;

	/** Replace the HTTP fetch, or pass null to restore it. Tests only. */
	public static function useFetcher(?callable $fetcher): void {
		self::$fetcher = $fetcher;
	}

	/**
	 * The registrar of record.
	 *
	 * @return array|null ['name' => as published, 'display' => without the
	 *                     legal suffixes, 'iana_id' => '' or the IANA id],
	 *                     or null when the registry did not say.
	 */
	public static function registrar(string $domain): ?array {
		$domain = DnsRecord::normalizeName($domain);
		if ($domain === '' || strpos($domain, '.') === false) {
			return null;
		}
		$doc = self::fetch(self::REDIRECTOR . rawurlencode($domain));
		if (!is_array($doc)) {
			return null;
		}
		return self::parseRegistrar($doc);
	}

	/**
	 * The registrar entity out of an RDAP domain document. Entities nest (a
	 * registrar carries its abuse contact as a child), so the walk is
	 * recursive and the first entity wearing the registrar role wins.
	 */
	public static function parseRegistrar(array $doc): ?array {
		$entity = self::findEntity((array)($doc['entities'] ?? array()), 'registrar');
		if ($entity === null) {
			return null;
		}
		$name = self::vcardField((array)($entity['vcardArray'] ?? array()), 'fn');
		if ($name === '') {
			$name = trim((string)($entity['handle'] ?? ''));
		}
		if ($name === '') {
			return null;
		}
		$iana = '';
		foreach ((array)($entity['publicIds'] ?? array()) as $id) {
			if (stripos((string)($id['type'] ?? ''), 'IANA Registrar') !== false) {
				$iana = trim((string)($id['identifier'] ?? ''));
				break;
			}
		}
		return array(
			'name'    => $name,
			'display' => self::displayName($name),
			'iana_id' => $iana,
		);
	}

	/**
	 * A registrar's name the way a customer knows it: "GoDaddy.com, LLC" is
	 * GoDaddy, "NameCheap, Inc." is Namecheap, "Squarespace Domains II LLC" is
	 * Squarespace. Legal suffixes and a trailing ".com" go; capitalisation is
	 * left alone except for the handful of brands that spell themselves
	 * differently from their legal name.
	 */
	public static function displayName(string $name): string {
		$out = trim($name);
		$out = preg_replace('/\s*,?\s*\b(inc|llc|l\.l\.c|ltd|limited|corp|corporation|co|gmbh|s\.?a\.?|pty|plc|b\.?v\.?)\b\.?\s*$/i', '', $out);
		$out = preg_replace('/\s+domains?(\s+[ivx]+)?\s*$/i', '', $out);
		$out = preg_replace('/\.com$/i', '', $out);
		$out = trim($out, " ,.");
		$brands = array(
			'namecheap' => 'Namecheap',
			'godaddy'   => 'GoDaddy',
			'cloudflare' => 'Cloudflare',
			'porkbun'   => 'Porkbun',
			'squarespace' => 'Squarespace',
			'tucows'    => 'Tucows',
			'gandi sas' => 'Gandi',
			'gandi'     => 'Gandi',
			'name.com'  => 'Name.com',
			'hover'     => 'Hover',
			'dynadot'   => 'Dynadot',
			'ionos se'  => 'IONOS',
			'ionos'     => 'IONOS',
			'google'    => 'Google',
			'amazon registrar' => 'Amazon',
		);
		$key = strtolower($out);
		return $brands[$key] ?? $out;
	}

	private static function findEntity(array $entities, string $role): ?array {
		foreach ($entities as $entity) {
			if (!is_array($entity)) {
				continue;
			}
			$roles = array_map('strtolower', array_map('strval', (array)($entity['roles'] ?? array())));
			if (in_array($role, $roles, true)) {
				return $entity;
			}
			$nested = self::findEntity((array)($entity['entities'] ?? array()), $role);
			if ($nested !== null) {
				return $nested;
			}
		}
		return null;
	}

	/** jCard: ['vcard', [[name, params, type, value], ...]]. */
	private static function vcardField(array $vcard, string $field): string {
		foreach ((array)($vcard[1] ?? array()) as $prop) {
			if (is_array($prop) && strtolower((string)($prop[0] ?? '')) === $field) {
				$value = $prop[3] ?? '';
				return is_array($value) ? trim(implode(' ', array_map('strval', $value))) : trim((string)$value);
			}
		}
		return '';
	}

	private static function fetch(string $url): ?array {
		if (self::$fetcher !== null) {
			return call_user_func(self::$fetcher, $url);
		}
		try {
			$client = new SafeHttpClient(array(
				'allow_redirects'    => true,
				'max_redirects'      => 5,
				'connect_timeout'    => 5,
				'timeout'            => 8,
				'max_response_bytes' => 500000,
				'user_agent'         => 'Joinery/RdapLookup',
			));
			$response = $client->get($url, array('Accept: application/rdap+json, application/json'));
			if (!$response->isSuccess()) {
				return null;
			}
			return $response->json();
		} catch (Throwable $e) {
			error_log('RdapLookup: ' . $url . ': ' . $e->getMessage());
			return null;
		}
	}
}

<?php
/**
 * DnsDriverBase - the plumbing and the general quirks every DNS driver shares.
 *
 * Vendor-specific behaviour belongs in the driver; everything here is true of
 * DNS itself or of the platform's contract:
 *
 *  - **Long TXT values.** Anything over 255 bytes must be served as adjacent
 *    quoted strings. A value published unquoted is accepted by most APIs,
 *    stored, and then never served — no error at either end. Splitting happens
 *    here so no driver can forget it.
 *  - **Zone resolution** is longest-suffix match against the zones the
 *    credential can actually see, so mail.example.com resolves to the
 *    example.com zone and a same-named sibling TLD cannot be hit by accident.
 *  - **The credential is request-scoped.** It is held in a private property that
 *    refuses serialization and redacts itself from var_dump/print_r, so it
 *    cannot leak into a session, a log line or an error report. There is no
 *    persistence path in this code at all.
 *
 * @version 1.0
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/dns/DnsProvider.php'));

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

abstract class DnsDriverBase implements DnsProvider {

	/** A single TXT character-string may hold at most 255 bytes (RFC 1035). */
	const TXT_CHUNK_BYTES = 255;

	/** @var array The one-publish credential. Never persisted, never printed. */
	private $credential;

	/** @var Client */
	protected $http;

	/** @var string Account id chosen at publish time when a grant reaches several. */
	protected $account_id = '';

	/**
	 * @param array $credential Driver-specific; whatever credentialFields() or
	 *                          the OAuth flow produced. ['access_token' => …] for
	 *                          an OAuth2 driver.
	 */
	public function __construct(array $credential, ?Client $http = null) {
		$this->credential = $credential;
		$this->account_id = (string)($credential['account_id'] ?? '');
		$this->http = $http ?: new Client(array(
			'timeout'         => 30,
			'connect_timeout' => 10,
			'http_errors'     => true,
		));
	}

	/** Read one credential field. */
	protected function cred(string $field, string $default = ''): string {
		return (string)($this->credential[$field] ?? $default);
	}

	/** The OAuth access token, for drivers authorized by consent. */
	protected function accessToken(): string {
		return $this->cred('access_token');
	}

	/**
	 * Serializing a driver would be the one way a live credential could end up
	 * at rest. Refuse it outright rather than trusting every future caller.
	 */
	public function __sleep() {
		throw new DnsProviderException('A DNS driver holds a live credential and cannot be serialized.');
	}

	/** Keep the credential out of var_dump/print_r and therefore out of logs. */
	public function __debugInfo() {
		return array('driver' => static::getKey(), 'credential' => '[redacted]');
	}

	// ------------------------------------------------------------------
	// Capability defaults — a driver overrides only what differs
	// ------------------------------------------------------------------

	public static function credentialMode(): string { return self::CREDENTIAL_API; }
	public static function oauthProviderKey(): string { return ''; }
	public static function oauthScopes(): array { return array(); }
	public static function credentialFields(): array { return array(); }
	public static function prerequisiteNote(): string { return ''; }
	public static function credentialGuide(): ?array { return null; }
	public static function nameservers(): array { return array(); }
	public static function supportsZones(): bool { return false; }

	/**
	 * A vendor publishing a fixed nameserver set is identified by those names.
	 * Vendors that assign per-zone names override this with the shared fragment.
	 */
	public static function nameserverSuffixes(): array { return static::nameservers(); }

	public function createZone(string $domain): string {
		throw new DnsProviderException(static::getLabel() . ' cannot create zones through its API; '
			. 'create the zone in the provider dashboard first.');
	}

	public function deleteZone(string $zone): void {
		throw new DnsProviderException(static::getLabel() . ' cannot delete zones through its API.');
	}

	/**
	 * One account is the common case, and the publish box asks nothing. Drivers
	 * whose credential can reach several (a reseller or parent/child login)
	 * override this.
	 */
	public function accounts(): array {
		return array(array('id' => '', 'label' => static::getLabel()));
	}

	// ------------------------------------------------------------------
	// General quirks
	// ------------------------------------------------------------------

	/**
	 * Split a TXT value into 255-byte character-strings.
	 * @return string[] One entry for a short value.
	 */
	public static function txtChunks(string $value): array {
		if (strlen($value) <= self::TXT_CHUNK_BYTES) {
			return array($value);
		}
		return str_split($value, self::TXT_CHUNK_BYTES);
	}

	/**
	 * The wire form of a TXT value: one quoted string, or adjacent quoted
	 * strings when it exceeds 255 bytes. Embedded quotes and backslashes are
	 * escaped so a DKIM or SPF value containing them survives the round trip.
	 */
	public static function quoteTxt(string $value): string {
		$out = array();
		foreach (self::txtChunks($value) as $chunk) {
			$out[] = '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $chunk) . '"';
		}
		return implode(' ', $out);
	}

	/**
	 * Whether the vendor splits an over-length TXT value into 255-byte
	 * character-strings itself. Drivers that say true send the raw value and
	 * MUST have that behaviour verified; everyone else sends quoteTxt().
	 */
	public static function txtChunkingIsAutomatic(): bool { return false; }

	/**
	 * The TXT value as this vendor's API wants to receive it.
	 *
	 * A value that fits in one character-string is sent exactly as written —
	 * quoting a short value would risk a vendor storing the quotes literally.
	 * Only an over-length value is split, because there is no other way to serve
	 * it: published whole it is accepted, stored, and then never answered.
	 */
	public function txtWireValue(string $value): string {
		if (strlen($value) <= self::TXT_CHUNK_BYTES || static::txtChunkingIsAutomatic()) {
			return $value;
		}
		return self::quoteTxt($value);
	}

	/**
	 * Decompose a CAA value ("0 issue \"letsencrypt.org\"") into the three
	 * fields most APIs model separately.
	 *
	 * @return array{flags:int,tag:string,value:string}
	 */
	public static function parseCaa(string $value): array {
		$value = trim($value);
		if (preg_match('/^(\d+)\s+(\S+)\s+"?(.*?)"?$/', $value, $m)) {
			return array('flags' => (int)$m[1], 'tag' => strtolower($m[2]), 'value' => $m[3]);
		}
		return array('flags' => 0, 'tag' => 'issue', 'value' => trim($value, '"'));
	}

	/** The inverse of parseCaa(), in the platform's canonical spelling. */
	public static function formatCaa(int $flags, string $tag, string $value): string {
		return $flags . ' ' . strtolower($tag) . ' "' . trim($value, '"') . '"';
	}

	/**
	 * Longest-suffix zone match. $zones maps a zone NAME to whatever identifier
	 * the driver needs back (often the same string, sometimes a numeric id).
	 *
	 * @param array<string,string> $zones
	 * @return string|null The identifier of the most specific covering zone.
	 */
	public static function matchZone(string $domain, array $zones): ?string {
		$domain = DnsRecord::normalizeName($domain);
		$best = null;
		$best_len = -1;
		foreach ($zones as $name => $identifier) {
			$name = DnsRecord::normalizeName((string)$name);
			if ($name === '') {
				continue;
			}
			// Exact match, or a genuine label boundary — 'notexample.com' must
			// never match the 'example.com' zone.
			if ($domain !== $name && substr($domain, -(strlen($name) + 1)) !== '.' . $name) {
				continue;
			}
			if (strlen($name) > $best_len) {
				$best = (string)$identifier;
				$best_len = strlen($name);
			}
		}
		return $best;
	}

	/**
	 * The record name relative to its zone: 'mail.example.com' in zone
	 * 'example.com' is 'mail'; the zone apex is '@'.
	 */
	public static function relativeName(string $fqdn, string $zone_name, string $apex = '@'): string {
		$fqdn = DnsRecord::normalizeName($fqdn);
		$zone_name = DnsRecord::normalizeName($zone_name);
		if ($fqdn === $zone_name) {
			return $apex;
		}
		if (substr($fqdn, -(strlen($zone_name) + 1)) === '.' . $zone_name) {
			return substr($fqdn, 0, strlen($fqdn) - strlen($zone_name) - 1);
		}
		return $fqdn;
	}

	/** The inverse of relativeName(). */
	public static function absoluteName(string $relative, string $zone_name): string {
		$relative = trim($relative);
		$zone_name = DnsRecord::normalizeName($zone_name);
		if ($relative === '' || $relative === '@' || $relative === $zone_name) {
			return $zone_name;
		}
		$relative = DnsRecord::normalizeName($relative);
		if (substr($relative, -strlen($zone_name)) === $zone_name) {
			return $relative;
		}
		return $relative . '.' . $zone_name;
	}

	/**
	 * A hook for vendors that must be told to re-read DNS before they will trust
	 * a record — publishing everything correctly and then leaving a sending
	 * domain unverified is one of the failure modes this whole subsystem exists
	 * to end. Default: nothing to do.
	 *
	 * @param DnsRecord[] $applied
	 */
	public function afterPublish(string $zone, array $applied): void {
		// no-op
	}

	// ------------------------------------------------------------------
	// HTTP
	// ------------------------------------------------------------------

	/**
	 * Issue a JSON API request and return the decoded body. Failures are
	 * re-thrown as DnsProviderException carrying the vendor's own reason, never
	 * the credential.
	 *
	 * @param array $options Guzzle options; 'headers' are merged over authHeaders().
	 */
	protected function request(string $method, string $url, array $options = array()): array {
		$options['headers'] = array_merge(
			array('Accept' => 'application/json'),
			$this->authHeaders(),
			$options['headers'] ?? array()
		);
		try {
			$response = $this->http->request($method, $url, $options);
		} catch (RequestException $e) {
			$status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
			throw $this->translateError($e, $method, $url, $status);
		} catch (Throwable $e) {
			throw new DnsProviderException(static::getLabel() . ' request failed: ' . $e->getMessage(), 0, $e);
		}
		$body = (string)$response->getBody();
		if (trim($body) === '') {
			return array();
		}
		$decoded = json_decode($body, true);
		return is_array($decoded) ? $decoded : array();
	}

	/** Credential headers for this vendor. */
	protected function authHeaders(): array {
		return array();
	}

	/**
	 * Turn a vendor error into a DnsProviderException. Drivers override to
	 * recognise their own managed-record refusals and name the feature.
	 */
	protected function translateError(RequestException $e, string $method, string $url, int $status): DnsProviderException {
		return new DnsProviderException(static::getLabel() . ' API ' . $method . ' failed ('
			. $status . '): ' . $this->errorBody($e), $status, $e);
	}

	/** The vendor's response body, trimmed to something safe to show an admin. */
	protected function errorBody(RequestException $e): string {
		if (!$e->getResponse()) {
			return $e->getMessage();
		}
		$body = trim((string)$e->getResponse()->getBody());
		$decoded = json_decode($body, true);
		if (is_array($decoded)) {
			$reasons = array();
			array_walk_recursive($decoded, function ($v, $k) use (&$reasons) {
				if (in_array($k, array('reason', 'message', 'error', 'detail', 'title'), true) && is_scalar($v)) {
					$reasons[] = (string)$v;
				}
			});
			if (!empty($reasons)) {
				return implode('; ', array_unique($reasons));
			}
		}
		return substr($body, 0, 500);
	}
}

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
 * @version 1.5 - zoneNameservers() defaults to the vendor's fixed set; per-account vendors override
 * @version 1.4 - apiGateNote() defaults open: only a vendor with a gate declares one
 * @version 1.3 - srvNameFromParts() reassembles a whole SRV name from split labels
 * @version 1.2 - SRV decomposition helpers for providers that split the RDATA
 * @version 1.1 - rate limiting (429) is retried on reads, never on writes, and
 *                reported to a person; every vendor failure is logged with its
 *                status, trace id and Retry-After, and never the credential
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/dns/DnsProvider.php'));

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

abstract class DnsDriverBase implements DnsProvider {

	/** A single TXT character-string may hold at most 255 bytes (RFC 1035). */
	const TXT_CHUNK_BYTES = 255;

	// Rate-limit retry budget. Deliberately small: this runs inside a page
	// request, and an operator staring at a spinner is worse than an operator
	// told plainly to press the button again in a moment.
	const RATE_LIMIT_MAX_RETRIES      = 2;
	const RATE_LIMIT_MAX_WAIT_SECONDS = 5;
	const RATE_LIMIT_BACKOFF_SECONDS  = 2;

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

	/**
	 * Every type, unless a driver narrows it. A driver that models a type as
	 * separate vendor-named fields and has not had that mapping verified says
	 * false rather than guessing at the field names.
	 */
	public static function supportsType(string $type): bool { return true; }

	public static function credentialMode(): string { return self::CREDENTIAL_API; }
	public static function oauthProviderKey(): string { return ''; }
	public static function oauthScopes(): array { return array(); }
	public static function credentialFields(): array { return array(); }
	public static function prerequisiteNote(): string { return ''; }
	public static function apiGateNote(): string { return ''; }
	public static function credentialGuide(): ?array { return null; }
	public static function nameservers(): array { return array(); }
	public static function supportsZones(): bool { return false; }

	/**
	 * A vendor publishing a fixed nameserver set is identified by those names.
	 * Vendors that assign per-zone names override this with the shared fragment.
	 */
	public static function nameserverSuffixes(): array { return static::nameservers(); }

	/** The vendor's fixed set; a per-account vendor overrides by reading the zone. */
	public function zoneNameservers(string $zone): array {
		return static::nameservers();
	}

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
	 * Decompose an SRV value ("0 5 443 direct.example.com") into the four fields
	 * most APIs model separately.
	 *
	 * The platform stores SRV's whole RDATA in the record value, because a plan
	 * compares one string and a driver that already passes a value through then
	 * needs no special case at all. Providers that DO split the fields call this
	 * — the four numbers are the same everywhere even where the field names are
	 * not.
	 *
	 * @return array{priority:int,weight:int,port:int,target:string}
	 */
	public static function parseSrv(string $value): array {
		$parts = preg_split('/\s+/', trim($value));
		if (count($parts) >= 4) {
			return array(
				'priority' => (int)$parts[0],
				'weight'   => (int)$parts[1],
				'port'     => (int)$parts[2],
				'target'   => rtrim(strtolower($parts[3]), '.'),
			);
		}
		// A value that is only a target is still a usable record; the defaults
		// are what a single-endpoint service publishes anyway.
		return array('priority' => 0, 'weight' => 5, 'port' => 443,
			'target' => rtrim(strtolower(trim($value)), '.'));
	}

	/** The inverse of parseSrv(), in the platform's canonical spelling. */
	public static function formatSrv(int $priority, int $weight, int $port, string $target): string {
		return $priority . ' ' . $weight . ' ' . $port . ' ' . rtrim(strtolower(trim($target)), '.');
	}

	/**
	 * The "weight port target" half of an SRV value, for the several vendors
	 * that model SRV exactly as they model MX: the priority in its own numeric
	 * field and the REST of the RDATA in the content string.
	 *
	 * Verified against working implementations for Vultr, DNSimple, Porkbun and
	 * name.com, which all take this shape. Naming it once means a fifth vendor
	 * on the same pattern is a two-line driver change rather than a fresh guess.
	 */
	public static function srvContent(string $value): string {
		$srv = self::parseSrv($value);
		return $srv['weight'] . ' ' . $srv['port'] . ' ' . $srv['target'];
	}

	/** The inverse: rebuild the canonical RDATA from that split representation. */
	public static function srvFromContent(string $content, int $priority): string {
		$parts = preg_split('/\s+/', trim($content));
		if (count($parts) >= 3) {
			return self::formatSrv($priority, (int)$parts[0], (int)$parts[1], $parts[2]);
		}
		// A vendor that handed back something else is not a record we can
		// compare; return it as-is so the diff shows a difference rather than
		// silently agreeing with a value nobody can parse.
		return trim($content);
	}

	/**
	 * Rebuild an SRV record's whole name from a vendor's separate service and
	 * protocol labels plus an optional sub-host. The platform compares whole
	 * names, so `_joinery._tcp.example.com` has to be reassembled from the three
	 * pieces the fully-decomposed vendors (GoDaddy, Linode, Namecheap) store it
	 * as. Labels are accepted with or without their leading underscore — vendors
	 * disagree on whether they return it — and normalized to exactly one.
	 */
	public static function srvNameFromParts(string $service, string $protocol, string $host, string $zone): string {
		$labels = array();
		foreach (array($service, $protocol) as $label) {
			$label = trim($label);
			if ($label !== '') {
				$labels[] = (substr($label, 0, 1) === '_') ? $label : '_' . $label;
			}
		}
		$host = trim($host);
		if ($host !== '' && $host !== '@') {
			$labels[] = $host;
		}
		return self::absoluteName(implode('.', $labels) ?: '@', $zone);
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
		$attempt = 0;
		while (true) {
			try {
				$response = $this->http->request($method, $url, $options);
				break;
			} catch (RequestException $e) {
				$status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
				$this->logVendorFailure($method, $url, $status, $e);

				// A 429 on a READ is free to repeat — nothing changed, so the only
				// cost of trying again is the wait. A 429 on a WRITE is never
				// retried here: the vendor may have applied it before deciding to
				// rate-limit the response, and a repeated create would leave a
				// duplicate record behind. Those are reported for a human instead.
				if ($status === 429 && $this->isSafeToRepeat($method)
						&& $attempt < self::RATE_LIMIT_MAX_RETRIES) {
					$wait = $this->retryAfterSeconds($e);
					// Sleeping is bounded because this runs inside a page request.
					// A vendor asking for longer than we are willing to hold the
					// operator is reported to them rather than waited out.
					if ($wait <= self::RATE_LIMIT_MAX_WAIT_SECONDS) {
						$attempt++;
						$this->pause(max(1, $wait ?: self::RATE_LIMIT_BACKOFF_SECONDS * $attempt));
						continue;
					}
				}
				throw $this->translateError($e, $method, $url, $status);
			} catch (Throwable $e) {
				throw new DnsProviderException(static::getLabel() . ' request failed: ' . $e->getMessage(), 0, $e);
			}
		}
		$body = (string)$response->getBody();
		if (trim($body) === '') {
			return array();
		}
		$decoded = json_decode($body, true);
		return is_array($decoded) ? $decoded : array();
	}

	/** Reads may be repeated safely; anything that writes may not. */
	/**
	 * The retry wait, as its own seam. Production sleeps; the rate-limit tests
	 * record the schedule instead — ten real seconds of sleeping proved nothing
	 * the recorded numbers don't, and cost every gate run ten seconds.
	 */
	protected function pause(int $seconds): void {
		sleep($seconds);
	}

	protected function isSafeToRepeat(string $method): bool {
		return in_array(strtoupper($method), array('GET', 'HEAD'), true);
	}

	/** The vendor's Retry-After in seconds, honouring both the delta and date forms. */
	protected function retryAfterSeconds(RequestException $e): int {
		if (!$e->getResponse()) {
			return 0;
		}
		$raw = trim($e->getResponse()->getHeaderLine('Retry-After'));
		if ($raw === '') {
			return 0;
		}
		if (ctype_digit($raw)) {
			return (int)$raw;
		}
		$when = strtotime($raw);
		return ($when === false) ? 0 : max(0, $when - time());
	}

	/**
	 * Record what the vendor actually said, so a failure can be diagnosed after
	 * the operator has closed the page.
	 *
	 * A publish failure used to reach the operator as one flashed sentence and
	 * leave nothing behind — no status, no vendor trace id, no Retry-After — so
	 * the only way to investigate afterwards was to reason from the outside.
	 *
	 * THE CREDENTIAL IS NEVER PART OF THIS. Only the response is logged, plus the
	 * trace ids vendors put in headers; request headers, where the token lives,
	 * are not touched.
	 */
	protected function logVendorFailure(string $method, string $url, int $status, RequestException $e): void {
		$parts = array(
			'dns_publish', static::getKey(), $method,
			// The path only — a query string can carry a token on some vendors.
			(string)parse_url($url, PHP_URL_PATH),
			'status=' . $status,
		);
		if ($e->getResponse()) {
			foreach (array('Retry-After', 'CF-RAY', 'X-Request-Id', 'X-Amzn-Requestid',
				'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'RateLimit-Reset') as $h) {
				$v = trim($e->getResponse()->getHeaderLine($h));
				if ($v !== '') { $parts[] = $h . '=' . $v; }
			}
		}
		$parts[] = 'body=' . str_replace("\n", ' ', substr($this->errorBody($e), 0, 400));
		error_log(implode(' | ', $parts));
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
		if ($status === 429) {
			return $this->rateLimitError($e, $method);
		}
		return new DnsProviderException(static::getLabel() . ' API ' . $method . ' failed ('
			. $status . '): ' . $this->errorBody($e), $status, $e);
	}

	/**
	 * A 429, said to a person.
	 *
	 * Vendors answer this in their own voice and they are all talking to a
	 * program — Cloudflare's is "please wait and consider throttling your request
	 * speed", which an operator can do nothing with. What they need is: it is the
	 * account being limited and not this server, whether anything changed, how
	 * long to wait, and that their own dashboard spends the same quota.
	 */
	protected function rateLimitError(RequestException $e, string $method): DnsRateLimitedException {
		$wait = $this->retryAfterSeconds($e);
		$vendor = static::getLabel();

		// Whether anything changed is the first thing worth knowing, and it is
		// answerable: reads happen before any write in every driver here, so a
		// rate-limited read means the records were never touched.
		$changed = $this->isSafeToRepeat($method)
			? 'Nothing was changed — this failed while reading your existing records, before anything is written.'
			: 'This failed while writing, so some records may already have been updated. Check the difference '
				. 'before publishing again; publishing is safe to repeat once you have looked.';

		$when = $wait > 0
			? $vendor . ' asked us to wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . '.'
			: 'Wait a minute or two.';

		return new DnsRateLimitedException(
			$vendor . ' is rate-limiting your account, not this server. ' . $changed . ' ' . $when
			. ' That limit is shared with the ' . $vendor . ' website, which spends it quickly while you '
			. 'click around — so if you have it open, close it and try again.',
			$wait, $e);
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

<?php
/**
 * DirectCapability - "does this domain speak Joinery Direct, on what host, port
 * and key?"
 *
 * Two records under the domain answer it, both published through the ordinary
 * DNS plan/driver flow:
 *
 *   SRV  _joinery._tcp.<domain>   → host + port of the receiving endpoint
 *   TXT  _joinery-key.<domain>    → `v=joinery1; k=<key id>; p=<base64 Ed25519>`
 *
 * Absence of the SRV record means the domain does not speak Direct, and the
 * sender falls back silently. SRV earns its place despite the endpoint path
 * being fixed: a customer's mail domain usually does not point its web traffic
 * at the Joinery box, so `https://<maildomain>/.well-known/joinery-direct`
 * would land on their marketing site. SRV names the machine that actually
 * receives, independent of where the domain's website lives.
 *
 * Lookups are BOUNDED, and that is not an optimization. On the receive side the
 * domain being resolved is the attacker-chosen sender domain on an unverified
 * preflight, so an unbounded "fresh lookup per request" would make the receiver
 * an outbound-DNS engine driven by attacker input, before authentication and
 * therefore before any per-instance limit could apply. Three bounds close it:
 * cache the record, negative-cache the failures, and rate-limit resolution by
 * connecting peer. This is DNS through the system resolver, not an HTTP fetch,
 * so it is not the SSRF surface SafeHttpClient addresses — the concern here is
 * the VOLUME of attacker-driven lookups.
 *
 * @version 1.1
 * @changelog 1.1 - lookup() takes $fresh: a member-triggered re-check resolves past the cache (callers rate-limit)
 */

require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
require_once(PathHelper::getIncludePath('data/direct_capability_cache_class.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));

class DirectCapability {

	/**
	 * How long a positive answer is held. A sane floor and ceiling rather than
	 * the raw record TTL: a 30-second TTL would defeat the bound this cache
	 * exists to provide, and a week-long one would make a rotation invisible.
	 * A key id not in the cache forces one refresh anyway, so a rotation is
	 * picked up long before this expires.
	 */
	const POSITIVE_TTL_SECONDS = 1800;
	/** How long "this domain does not speak Direct" is remembered. */
	const NEGATIVE_TTL_SECONDS = 900;

	/** Peer-keyed cap on resolver work, for the pre-authentication receive path. */
	const PEER_LOOKUP_LIMIT  = 60;
	const PEER_LOOKUP_WINDOW = 60;

	/** @var array<string,array|null> request-scoped memo on top of the row cache */
	private static $memo = array();

	/**
	 * What $domain publishes, or null when it publishes nothing usable.
	 *
	 * @param bool $peer_limited true on the receive path, where the domain is
	 *        attacker-chosen and resolver work must be capped per connecting peer.
	 * @param bool $fresh skip the memo and the row cache and resolve now — for a
	 *        member-triggered re-check, where a cached "no" from a network blip
	 *        would otherwise be served back on every retry. The result is still
	 *        stored, so a fresh answer refreshes the cache for everyone. Callers
	 *        must rate-limit; this parameter is resolver work on demand.
	 * @return array{host:string,port:int,keys:array<string,string>}|null
	 */
	public static function lookup(string $domain, bool $peer_limited = false, bool $fresh = false): ?array {
		$domain = strtolower(trim($domain));
		if ($domain === '' || !self::looksLikeDomain($domain)) {
			return null;
		}
		if (!$fresh && array_key_exists($domain, self::$memo)) {
			return self::$memo[$domain];
		}

		$row = DirectCapabilityCache::forDomain($domain);
		if (!$fresh && $row !== null && $row->isFresh()) {
			return self::$memo[$domain] = self::fromRow($row);
		}

		// Only a lookup that will actually touch the resolver is charged to the
		// peer limit, so a busy legitimate sender served entirely from cache is
		// never throttled by it.
		if ($peer_limited && !RequestLogger::check_rate_limit(
				DirectProtocol::LOG_FEATURE . '_dns', self::PEER_LOOKUP_LIMIT, self::PEER_LOOKUP_WINDOW)) {
			// Over the cap: answer from a STALE cached record if there is one —
			// better than refusing a legitimate sender because someone else is
			// flooding — and otherwise report no capability.
			return self::$memo[$domain] = ($row !== null ? self::fromRow($row) : null);
		}
		if ($peer_limited) {
			RequestLogger::log(DirectProtocol::LOG_FEATURE . '_dns', 'capability_lookup', true);
		}

		$resolved = self::resolve($domain);
		self::store($domain, $resolved);
		return self::$memo[$domain] = $resolved;
	}

	/**
	 * The public key a domain publishes under one key id, or null.
	 *
	 * A key id that is not in the cached record triggers at most ONE refresh, so
	 * a rotation is picked up promptly without letting an attacker force
	 * unbounded lookups by naming random key ids — the refresh is itself under
	 * the peer limit.
	 */
	public static function publicKeyFor(string $domain, string $key_id, bool $peer_limited = false): ?string {
		$record = self::lookup($domain, $peer_limited);
		if ($record === null) {
			return null;
		}
		if (isset($record['keys'][$key_id])) {
			return $record['keys'][$key_id];
		}

		$domain = strtolower(trim($domain));
		unset(self::$memo[$domain]);
		self::expire($domain);
		$record = self::lookup($domain, $peer_limited);
		return ($record !== null && isset($record['keys'][$key_id])) ? $record['keys'][$key_id] : null;
	}

	/** Read both records for real. Never throws — a resolver failure is "no capability". */
	private static function resolve(string $domain): ?array {
		try {
			$srv = DnsResolver::getSrv(DirectProtocol::SRV_PREFIX . $domain);
		} catch (\Throwable $e) {
			return null;
		}
		if (empty($srv)) {
			return null;
		}
		$target = $srv[0];
		$host = rtrim(trim((string)$target['host']), '.');
		$port = (int)$target['port'];
		if ($host === '' || $host === '.') {
			return null; // the RFC 2782 "service decidedly not available" form
		}
		if ($port <= 0 || $port > 65535) {
			$port = 443;
		}

		try {
			$txt = DnsResolver::getTxt(DirectProtocol::KEY_PREFIX . $domain);
		} catch (\Throwable $e) {
			return null;
		}
		$keys = self::parseKeyRecords($txt);
		if (empty($keys)) {
			// A host with no key is not a usable capability: the signature is
			// mandatory at both ends, so there would be nothing to verify against.
			return null;
		}

		return array('host' => $host, 'port' => $port, 'keys' => $keys);
	}

	/**
	 * Parse `v=joinery1; k=<key id>; p=<base64>` strings into id => key.
	 *
	 * Several TXT strings at one name is the normal state during a rotation —
	 * the old key stays published while a sender may still be quoting its id.
	 */
	public static function parseKeyRecords(array $txt_values): array {
		$keys = array();
		foreach ($txt_values as $value) {
			$fields = array();
			foreach (explode(';', (string)$value) as $chunk) {
				$pair = explode('=', trim($chunk), 2);
				if (count($pair) === 2) {
					$fields[strtolower(trim($pair[0]))] = trim($pair[1]);
				}
			}
			if (($fields['v'] ?? '') !== 'joinery1') {
				continue;
			}
			$key_id = (string)($fields['k'] ?? '');
			$public = (string)($fields['p'] ?? '');
			if ($key_id === '' || $public === '') {
				continue;
			}
			$decoded = base64_decode($public, true);
			if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
				continue; // a malformed key is no key
			}
			$keys[$key_id] = $public;
		}
		return $keys;
	}

	/** The TXT value this deployment publishes for one signing identity. */
	public static function keyRecordValue(string $key_id, string $public_key_b64): string {
		return 'v=joinery1; k=' . $key_id . '; p=' . $public_key_b64;
	}

	/** The SRV fields this deployment publishes, separately — DNS provider
	 *  forms ask for priority, weight, port and target as their own inputs. */
	public static function srvRecordFields(string $host, int $port): array {
		return array(
			'priority' => 0,
			'weight'   => 5,
			'port'     => $port,
			'target'   => rtrim(strtolower(trim($host)), '.'),
		);
	}

	/** The SRV RDATA this deployment publishes: priority weight port target. */
	public static function srvRecordValue(string $host, int $port): string {
		$fields = self::srvRecordFields($host, $port);
		return $fields['priority'] . ' ' . $fields['weight'] . ' ' . $fields['port'] . ' ' . $fields['target'];
	}

	private static function fromRow(DirectCapabilityCache $row): ?array {
		if (!$row->get('jdc_has_capability')) {
			return null;
		}
		return array(
			'host' => (string)$row->get('jdc_host'),
			'port' => intval($row->get('jdc_port')),
			'keys' => $row->keys(),
		);
	}

	/** Write the answer — positive or negative — with its TTL. */
	private static function store(string $domain, ?array $resolved): void {
		$ttl = ($resolved === null) ? self::NEGATIVE_TTL_SECONDS : self::POSITIVE_TTL_SECONDS;
		$expires = gmdate('Y-m-d H:i:s', time() + $ttl);

		try {
			$row = DirectCapabilityCache::forDomain($domain);
			if ($row === null) {
				$row = new DirectCapabilityCache(NULL);
				$row->set('jdc_domain', $domain);
			}
			$row->set('jdc_has_capability', $resolved !== null);
			$row->set('jdc_host', $resolved !== null ? $resolved['host'] : null);
			$row->set('jdc_port', $resolved !== null ? $resolved['port'] : null);
			$row->set('jdc_keys', $resolved !== null ? json_encode($resolved['keys']) : null);
			$row->set('jdc_expires_time', $expires);
			$row->set('jdc_update_time', gmdate('Y-m-d H:i:s'));
			$row->save();
		} catch (\Throwable $e) {
			// A cache that cannot be written must not take down the delivery it was
			// only meant to speed up.
			error_log('DirectCapability: could not cache ' . $domain . ': ' . $e->getMessage());
		}
	}

	/** Force the next lookup for a domain to hit the resolver. */
	public static function expire(string $domain): void {
		$domain = strtolower(trim($domain));
		unset(self::$memo[$domain]);
		try {
			$row = DirectCapabilityCache::forDomain($domain);
			if ($row !== null) {
				$row->set('jdc_expires_time', gmdate('Y-m-d H:i:s', time() - 1));
				$row->save();
			}
		} catch (\Throwable $e) {
			error_log('DirectCapability: could not expire ' . $domain . ': ' . $e->getMessage());
		}
	}

	private static function looksLikeDomain(string $domain): bool {
		return (bool)preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain);
	}

	/** Forget the request-scoped memo. Tests only. */
	public static function resetForTests(): void {
		self::$memo = array();
	}
}

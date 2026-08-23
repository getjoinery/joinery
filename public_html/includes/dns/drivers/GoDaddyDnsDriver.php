<?php
/**
 * GoDaddyDnsDriver - GoDaddy Domains API v1.
 *
 * No OAuth2; GoDaddy authenticates with an sso-key pair supplied at the publish
 * moment and discarded when the request returns.
 *
 * GoDaddy has no per-record identifier — a record is addressed by its type and
 * name, and writing that address replaces every record sharing it. So a create
 * is a read-modify-write of the whole set, which is what DnsRrsetDriverBase
 * does; doing it any other way would silently delete the siblings.
 *
 * SRV is addressed by HOST, not by the full _service._protocol name (the service
 * and protocol are fields), so several services share one address. The set is
 * therefore scoped to a single service on read and the other services are re-sent
 * on every write and delete, or publishing one would erase the rest.
 *
 * @version 1.2 - The account-size gate is declared as apiGateNote(), not a prerequisite
 * @version 1.1 - SRV read-modify-write preserves the host's other services
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsRrsetDriverBase.php'));

class GoDaddyDnsDriver extends DnsRrsetDriverBase {

	const API_BASE = 'https://api.godaddy.com/v1/';

	/** @var string[]|null */
	private $zones = null;

	public static function getKey(): string { return 'godaddy'; }
	public static function getLabel(): string { return 'GoDaddy'; }


	public static function nameservers(): array {
		return array('ns01.domaincontrol.com', 'ns02.domaincontrol.com');
	}

	/** GoDaddy assigns per-zone names, e.g. ns37.domaincontrol.com. */
	public static function nameserverSuffixes(): array { return array('domaincontrol.com'); }

	public static function apiGateNote(): string {
		return 'GoDaddy issues production API keys only to accounts holding at least ten domains or a '
			. 'Discount Domain Club membership; a key outside that grants read-only access.';
	}

	public static function credentialFields(): array {
		return array(
			'api_key' => array(
				'label'  => 'GoDaddy API key',
				'help'   => 'A production key from developer.godaddy.com. Used for this one publish and never stored.',
				'secret' => false,
			),
			'api_secret' => array(
				'label'  => 'GoDaddy API secret',
				'help'   => 'Issued alongside the key. Used for this one publish and never stored.',
				'secret' => true,
			),
		);
	}

	public static function credentialGuide(): ?array {
		return array(
			'title'     => 'Create a GoDaddy production API key',
			'url'       => 'https://developer.godaddy.com/keys',
			'url_label' => 'Open the GoDaddy developer portal',
			'steps'     => array(
				'Sign in at developer.godaddy.com and choose Create New API Key.',
				'Set Environment to Production, then create the key.',
				'Copy the Key and the Secret — they are shown once.',
			),
			'caution'   => 'An OTE key is for GoDaddy\'s test environment. It looks valid and cannot '
				. 'change live DNS.',
		);
	}

	public function zoneFor(string $domain): ?string {
		$map = array();
		foreach ($this->zoneNames() as $name) {
			$map[$name] = $name;
		}
		return self::matchZone($domain, $map);
	}

	public function listRecords(string $zone): array {
		$zone = DnsRecord::normalizeName($zone);
		$rows = $this->request('GET', self::API_BASE . 'domains/' . rawurlencode($zone) . '/records');
		$out = array();
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$type = strtoupper((string)($row['type'] ?? ''));
			if (!in_array($type, DnsRecord::TYPES, true)) {
				continue;
			}
			$ttl = (int)($row['ttl'] ?? 0);
			$name = self::absoluteName((string)($row['name'] ?? '@'), $zone);
			$record = new DnsRecord(
				$type,
				// GoDaddy addresses an SRV record as service + protocol + host,
				// so the record's real name has to be reassembled from all three
				// or every SRV row would read as the bare host.
				$type === DnsRecord::TYPE_SRV ? self::srvName($row, $zone) : $name,
				// And its RDATA lives in four separate fields, with `data`
				// holding the target alone.
				$type === DnsRecord::TYPE_SRV
					? self::formatSrv((int)($row['priority'] ?? 0), (int)($row['weight'] ?? 0),
						(int)($row['port'] ?? 0), (string)($row['data'] ?? ''))
					: (string)($row['data'] ?? ''),
				$ttl > 0 ? $ttl : null,
				$type === DnsRecord::TYPE_MX ? (int)($row['priority'] ?? 0) : null
			);
			$record->provider_id = $name . '/' . $type;
			$out[] = $record;
		}
		return $out;
	}

	// ------------------------------------------------------------------

	protected function readRrset(string $zone, string $name, string $type): ?array {
		$zone = DnsRecord::normalizeName($zone);
		try {
			$rows = $this->request('GET', self::API_BASE . 'domains/' . rawurlencode($zone) . '/records/'
				. rawurlencode($type) . '/' . rawurlencode($this->recordPathName($name, $zone, $type)));
		} catch (DnsProviderException $e) {
			return null;
		}
		// GoDaddy addresses SRV by HOST, so a read at the apex returns every SRV
		// service there — _sip, _xmpp, and ours. The record set the platform is
		// reconciling is one service, so the others are filtered out here; leaving
		// them in would fold foreign services into this set and the write-back
		// would rewrite them all under our service label.
		$want = ($type === DnsRecord::TYPE_SRV) ? self::srvLabels($name) : null;
		$values = array();
		$ttl = null;
		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row['data'])) {
				continue;
			}
			if ($want !== null && !self::srvRowMatches($row, $want)) {
				continue;
			}
			$value = (string)$row['data'];
			if ($type === DnsRecord::TYPE_MX) {
				$value = (int)($row['priority'] ?? 10) . ' ' . rtrim($value, '.') . '.';
			}
			if ($type === DnsRecord::TYPE_SRV) {
				// GoDaddy decomposes SRV, so the trailing dot never reaches its wire;
				// the set value carries it anyway (see DnsRrsetDriverBase::rrsetValue)
				// so read and write compare as the same string.
				$value = self::formatSrv((int)($row['priority'] ?? 0), (int)($row['weight'] ?? 0),
					(int)($row['port'] ?? 0), $value) . '.';
			}
			$values[] = $value;
			if ($ttl === null && !empty($row['ttl'])) {
				$ttl = (int)$row['ttl'];
			}
		}
		return empty($values) ? null : array('values' => $values, 'ttl' => $ttl);
	}

	protected function writeRrset(string $zone, string $name, string $type, array $values, ?int $ttl): void {
		$zone = DnsRecord::normalizeName($zone);
		// A GoDaddy PUT replaces every record of this type at this host. For SRV
		// that host holds other services too, so they are read back and re-sent
		// verbatim — otherwise publishing _joinery._tcp would delete every other
		// SRV service at the apex.
		$payload = ($type === DnsRecord::TYPE_SRV) ? $this->srvSiblingEntries($zone, $name) : array();
		foreach ($values as $value) {
			$entry = array('data' => (string)$value);
			if ($type === DnsRecord::TYPE_MX && preg_match('/^\s*(\d+)\s+(.+)$/', (string)$value, $m)) {
				$entry['priority'] = (int)$m[1];
				$entry['data'] = $m[2];
			}
			if ($type === DnsRecord::TYPE_SRV) {
				$srv = self::parseSrv((string)$value);
				$entry['data']     = $srv['target'];
				$entry['priority'] = $srv['priority'];
				$entry['weight']   = $srv['weight'];
				$entry['port']     = $srv['port'];
				// service/protocol are part of the ADDRESS here, not the value.
				list($service, $protocol) = self::srvLabels($name);
				$entry['service']  = $service;
				$entry['protocol'] = $protocol;
			}
			// GoDaddy floors TTL at 600 and rejects a shorter one outright.
			$entry['ttl'] = max(600, $ttl !== null ? (int)$ttl : 3600);
			$payload[] = $entry;
		}
		$this->request('PUT', self::API_BASE . 'domains/' . rawurlencode($zone) . '/records/'
			. rawurlencode($type) . '/' . rawurlencode($this->recordPathName($name, $zone, $type)),
			array('json' => $payload));
	}

	/**
	 * The full record name for one of GoDaddy's SRV rows.
	 *
	 * GoDaddy stores `_joinery._tcp.example.com` as service `_joinery`,
	 * protocol `_tcp` and name `@`; the platform compares whole names, so the
	 * three are put back together on the way in.
	 */
	private static function srvName(array $row, string $zone): string {
		$service  = trim((string)($row['service'] ?? ''));
		$protocol = trim((string)($row['protocol'] ?? ''));
		$host     = trim((string)($row['name'] ?? '@'));
		$labels   = array();
		foreach (array($service, $protocol) as $label) {
			if ($label !== '') {
				$labels[] = (substr($label, 0, 1) === '_') ? $label : '_' . $label;
			}
		}
		if ($host !== '' && $host !== '@') {
			$labels[] = $host;
		}
		return self::absoluteName(implode('.', $labels) ?: '@', $zone);
	}

	/**
	 * Split `_joinery._tcp.<host>` back into the service and protocol labels
	 * GoDaddy wants as their own fields. A name without them yields empty
	 * strings, which is what a non-SRV caller would have sent anyway.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function srvLabels(string $name): array {
		$parts = explode('.', DnsRecord::normalizeName($name));
		$service  = (isset($parts[0]) && substr($parts[0], 0, 1) === '_') ? $parts[0] : '';
		$protocol = (isset($parts[1]) && substr($parts[1], 0, 1) === '_') ? $parts[1] : '';
		return array($service, $protocol);
	}

	/**
	 * The name GoDaddy addresses a record by. For SRV the service and protocol
	 * labels travel as fields, so they are stripped from the path — leaving
	 * them in would address a host that does not exist.
	 */
	private function recordPathName(string $name, string $zone, string $type): string {
		if ($type === DnsRecord::TYPE_SRV) {
			$parts = explode('.', DnsRecord::normalizeName($name));
			while (!empty($parts) && substr($parts[0], 0, 1) === '_') {
				array_shift($parts);
			}
			$name = implode('.', $parts);
			if ($name === '' || $name === DnsRecord::normalizeName($zone)) {
				return '@';
			}
		}
		return self::relativeName($name, $zone, '@');
	}

	protected function deleteRrset(string $zone, string $name, string $type): void {
		$zone = DnsRecord::normalizeName($zone);
		// A DELETE at an SRV host would take every service there with it. When
		// other services share the host, the whole set is re-written without ours
		// instead; only when we are the last service is the host's set deleted.
		if ($type === DnsRecord::TYPE_SRV) {
			$siblings = $this->srvSiblingEntries($zone, $name);
			if (!empty($siblings)) {
				$this->request('PUT', self::API_BASE . 'domains/' . rawurlencode($zone) . '/records/'
					. rawurlencode($type) . '/' . rawurlencode($this->recordPathName($name, $zone, $type)),
					array('json' => $siblings));
				return;
			}
		}
		$this->request('DELETE', self::API_BASE . 'domains/' . rawurlencode($zone) . '/records/'
			. rawurlencode($type) . '/' . rawurlencode($this->recordPathName($name, $zone, $type)));
	}

	/**
	 * The raw GoDaddy payload entries for every SRV service at this record's host
	 * EXCEPT its own — the records a whole-host PUT would otherwise destroy. Each
	 * is re-sent exactly as GoDaddy holds it, carrying its own service, protocol
	 * and RDATA. A read failure yields none, so a write never silently drops a
	 * sibling it could not confirm.
	 */
	private function srvSiblingEntries(string $zone, string $name): array {
		$zone = DnsRecord::normalizeName($zone);
		try {
			$rows = $this->request('GET', self::API_BASE . 'domains/' . rawurlencode($zone) . '/records/'
				. rawurlencode(DnsRecord::TYPE_SRV) . '/'
				. rawurlencode($this->recordPathName($name, $zone, DnsRecord::TYPE_SRV)));
		} catch (DnsProviderException $e) {
			return array();
		}
		$mine = self::srvLabels($name);
		$entries = array();
		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row['data']) || self::srvRowMatches($row, $mine)) {
				continue;
			}
			$entries[] = array(
				'data'     => (string)$row['data'],
				'priority' => (int)($row['priority'] ?? 0),
				'weight'   => (int)($row['weight'] ?? 0),
				'port'     => (int)($row['port'] ?? 0),
				'service'  => (string)($row['service'] ?? ''),
				'protocol' => (string)($row['protocol'] ?? ''),
				'ttl'      => max(600, (int)($row['ttl'] ?? 3600)),
			);
		}
		return $entries;
	}

	/**
	 * Whether a GoDaddy SRV row belongs to the service and protocol in $labels
	 * (`[service, protocol]`, underscore-prefixed). GoDaddy may or may not return
	 * the leading underscore, so both sides are normalized before comparing.
	 *
	 * @param array{0:string,1:string} $labels
	 */
	private static function srvRowMatches(array $row, array $labels): bool {
		return self::srvLabelNorm((string)($row['service'] ?? '')) === $labels[0]
			&& self::srvLabelNorm((string)($row['protocol'] ?? '')) === $labels[1];
	}

	/** A service or protocol label with exactly one leading underscore, or '' when empty. */
	private static function srvLabelNorm(string $label): string {
		$label = trim($label);
		if ($label === '') {
			return '';
		}
		return (substr($label, 0, 1) === '_') ? $label : '_' . $label;
	}

	/** @return string[] */
	private function zoneNames(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$rows = $this->request('GET', self::API_BASE . 'domains?limit=1000&statuses=ACTIVE');
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$name = DnsRecord::normalizeName((string)($row['domain'] ?? ''));
			if ($name !== '') {
				$this->zones[] = $name;
			}
		}
		return $this->zones;
	}

	protected function authHeaders(): array {
		return array('Authorization' => 'sso-key ' . $this->cred('api_key') . ':' . $this->cred('api_secret'));
	}
}

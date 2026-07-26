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

	public static function prerequisiteNote(): string {
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
				$name,
				(string)($row['data'] ?? ''),
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
				. rawurlencode($type) . '/' . rawurlencode(self::relativeName($name, $zone, '@')));
		} catch (DnsProviderException $e) {
			return null;
		}
		$values = array();
		$ttl = null;
		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row['data'])) {
				continue;
			}
			$value = (string)$row['data'];
			if ($type === DnsRecord::TYPE_MX) {
				$value = (int)($row['priority'] ?? 10) . ' ' . rtrim($value, '.') . '.';
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
		$payload = array();
		foreach ($values as $value) {
			$entry = array('data' => (string)$value);
			if ($type === DnsRecord::TYPE_MX && preg_match('/^\s*(\d+)\s+(.+)$/', (string)$value, $m)) {
				$entry['priority'] = (int)$m[1];
				$entry['data'] = $m[2];
			}
			// GoDaddy floors TTL at 600 and rejects a shorter one outright.
			$entry['ttl'] = max(600, $ttl !== null ? (int)$ttl : 3600);
			$payload[] = $entry;
		}
		$this->request('PUT', self::API_BASE . 'domains/' . rawurlencode($zone) . '/records/'
			. rawurlencode($type) . '/' . rawurlencode(self::relativeName($name, $zone, '@')),
			array('json' => $payload));
	}

	protected function deleteRrset(string $zone, string $name, string $type): void {
		$zone = DnsRecord::normalizeName($zone);
		$this->request('DELETE', self::API_BASE . 'domains/' . rawurlencode($zone) . '/records/'
			. rawurlencode($type) . '/' . rawurlencode(self::relativeName($name, $zone, '@')));
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

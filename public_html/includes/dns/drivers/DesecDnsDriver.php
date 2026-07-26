<?php
/**
 * DesecDnsDriver - deSEC, API v1.
 *
 * No OAuth2; a deSEC API token is supplied at the publish moment and discarded
 * when the request returns.
 *
 * deSEC is record-set based (subname + type + records[]), so writes go through
 * DnsRrsetDriverBase's read-modify-write. Its minimum TTL is 3600 and it
 * rejects anything lower, so the driver floors what it sends.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsRrsetDriverBase.php'));

class DesecDnsDriver extends DnsRrsetDriverBase {

	const API_BASE = 'https://desec.io/api/v1/';
	const MIN_TTL = 3600;

	/** @var string[]|null */
	private $zones = null;

	public static function getKey(): string { return 'desec'; }
	public static function getLabel(): string { return 'deSEC'; }
	public static function supportsZones(): bool { return true; }

	public static function nameservers(): array {
		return array('ns1.desec.io', 'ns2.desec.org');
	}

	public static function nameserverSuffixes(): array { return array('desec.io', 'desec.org'); }

	public static function credentialFields(): array {
		return array(
			'api_token' => array(
				'label'  => 'deSEC API token',
				'help'   => 'From the deSEC account token page. Used for this one publish and never stored.',
				'secret' => true,
			),
		);
	}

	public function zoneFor(string $domain): ?string {
		$map = array();
		foreach ($this->zoneNames() as $name) {
			$map[$name] = $name;
		}
		return self::matchZone($domain, $map);
	}

	public function createZone(string $domain): string {
		$domain = DnsRecord::normalizeName($domain);
		if ($this->zoneFor($domain) === $domain) {
			return $domain;
		}
		$this->request('POST', self::API_BASE . 'domains/', array('json' => array('name' => $domain)));
		$this->zones = null;
		return $domain;
	}

	public function deleteZone(string $zone): void {
		$this->request('DELETE', self::API_BASE . 'domains/' . rawurlencode(DnsRecord::normalizeName($zone)) . '/');
		$this->zones = null;
	}

	public function listRecords(string $zone): array {
		$zone = DnsRecord::normalizeName($zone);
		$body = $this->request('GET', self::API_BASE . 'domains/' . rawurlencode($zone) . '/rrsets/');
		$out = array();
		foreach ($body as $rrset) {
			if (!is_array($rrset)) {
				continue;
			}
			$type = strtoupper((string)($rrset['type'] ?? ''));
			$name = self::absoluteName((string)($rrset['subname'] ?? ''), $zone);
			$ttl  = (int)($rrset['ttl'] ?? 0);
			foreach ((array)($rrset['records'] ?? array()) as $value) {
				$record = $this->recordFromValue($type, $name, (string)$value, $ttl > 0 ? $ttl : null);
				if ($record !== null) {
					$record->provider_id = $name . '/' . $type;
					$out[] = $record;
				}
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------

	protected function readRrset(string $zone, string $name, string $type): ?array {
		$zone = DnsRecord::normalizeName($zone);
		$subname = self::relativeName($name, $zone, '');
		try {
			$body = $this->request('GET', self::API_BASE . 'domains/' . rawurlencode($zone) . '/rrsets/'
				. rawurlencode($subname === '' ? '@' : $subname) . '/' . rawurlencode($type) . '/');
		} catch (DnsProviderException $e) {
			return null;
		}
		if (empty($body['records'])) {
			return null;
		}
		$ttl = (int)($body['ttl'] ?? 0);
		return array('values' => array_map('strval', (array)$body['records']), 'ttl' => $ttl > 0 ? $ttl : null);
	}

	protected function writeRrset(string $zone, string $name, string $type, array $values, ?int $ttl): void {
		$zone = DnsRecord::normalizeName($zone);
		$subname = self::relativeName($name, $zone, '');
		// PUT on the collection upserts a set without needing to know whether it
		// already existed.
		$this->request('PUT', self::API_BASE . 'domains/' . rawurlencode($zone) . '/rrsets/',
			array('json' => array(array(
				'subname' => $subname,
				'type'    => $type,
				'ttl'     => max(self::MIN_TTL, $ttl !== null ? (int)$ttl : self::MIN_TTL),
				'records' => array_values($values),
			))));
	}

	protected function deleteRrset(string $zone, string $name, string $type): void {
		// An empty records list is how deSEC spells "remove this set".
		$zone = DnsRecord::normalizeName($zone);
		$this->request('PUT', self::API_BASE . 'domains/' . rawurlencode($zone) . '/rrsets/',
			array('json' => array(array(
				'subname' => self::relativeName($name, $zone, ''),
				'type'    => $type,
				'records' => array(),
			))));
	}

	/** @return string[] */
	private function zoneNames(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$body = $this->request('GET', self::API_BASE . 'domains/');
		foreach ($body as $row) {
			if (!is_array($row)) {
				continue;
			}
			$name = DnsRecord::normalizeName((string)($row['name'] ?? ''));
			if ($name !== '') {
				$this->zones[] = $name;
			}
		}
		return $this->zones;
	}

	protected function authHeaders(): array {
		return array('Authorization' => 'Token ' . $this->cred('api_token'));
	}
}

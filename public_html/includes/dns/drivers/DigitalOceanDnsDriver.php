<?php
/**
 * DigitalOceanDnsDriver - DigitalOcean DNS, API v2.
 *
 * Authorized by OAuth2 consent (DigitalOceanOAuthProvider) rather than a pasted
 * key. DigitalOcean does issue a refresh token; the DNS consumer discards it
 * with the rest of the grant when the publish returns.
 *
 * Vendor notes: record names are relative to the domain with '@' for the apex,
 * CNAME and MX targets are stored with a trailing dot, and CAA carries flags and
 * tag as their own fields.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverBase.php'));

class DigitalOceanDnsDriver extends DnsDriverBase {

	const API_BASE = 'https://api.digitalocean.com/v2/';

	/** @var string[]|null Cached zone names. */
	private $zones = null;

	public static function getKey(): string { return 'digitalocean'; }
	public static function getLabel(): string { return 'DigitalOcean DNS'; }

	public static function credentialMode(): string { return self::CREDENTIAL_OAUTH2; }
	public static function oauthProviderKey(): string { return 'digitalocean'; }
	public static function oauthScopes(): array { return array('read', 'write'); }
	public static function supportsZones(): bool { return true; }

	public static function nameservers(): array {
		return array('ns1.digitalocean.com', 'ns2.digitalocean.com', 'ns3.digitalocean.com');
	}

	public static function nameserverSuffixes(): array { return array('.digitalocean.com'); }

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
		$this->request('POST', self::API_BASE . 'domains', array('json' => array('name' => $domain)));
		$this->zones = null;
		return $domain;
	}

	public function deleteZone(string $zone): void {
		$this->request('DELETE', self::API_BASE . 'domains/' . rawurlencode(DnsRecord::normalizeName($zone)));
		$this->zones = null;
	}

	public function listRecords(string $zone): array {
		$zone = DnsRecord::normalizeName($zone);
		$out = array();
		$url = self::API_BASE . 'domains/' . rawurlencode($zone) . '/records?per_page=200';
		while ($url !== '') {
			$body = $this->request('GET', $url);
			foreach ((array)($body['domain_records'] ?? array()) as $row) {
				$record = $this->toRecord($zone, $row);
				if ($record !== null) {
					$out[] = $record;
				}
			}
			$url = (string)($body['links']['pages']['next'] ?? '');
		}
		return $out;
	}

	public function createRecord(string $zone, DnsRecord $record): void {
		$this->request('POST', self::API_BASE . 'domains/' . rawurlencode(DnsRecord::normalizeName($zone)) . '/records',
			array('json' => $this->toApi($zone, $record)));
	}

	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot update ' . $desired->describe() . ': no DigitalOcean record id.');
		}
		$this->request('PUT', self::API_BASE . 'domains/' . rawurlencode(DnsRecord::normalizeName($zone))
			. '/records/' . rawurlencode($live->provider_id), array('json' => $this->toApi($zone, $desired)));
	}

	public function deleteRecord(string $zone, DnsRecord $live): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot delete ' . $live->describe() . ': no DigitalOcean record id.');
		}
		$this->request('DELETE', self::API_BASE . 'domains/' . rawurlencode(DnsRecord::normalizeName($zone))
			. '/records/' . rawurlencode($live->provider_id));
	}

	// ------------------------------------------------------------------

	/** @return string[] */
	private function zoneNames(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$url = self::API_BASE . 'domains?per_page=200';
		while ($url !== '') {
			$body = $this->request('GET', $url);
			foreach ((array)($body['domains'] ?? array()) as $row) {
				$name = DnsRecord::normalizeName((string)($row['name'] ?? ''));
				if ($name !== '') {
					$this->zones[] = $name;
				}
			}
			$url = (string)($body['links']['pages']['next'] ?? '');
		}
		return $this->zones;
	}

	private function toRecord(string $zone, array $row): ?DnsRecord {
		$type = strtoupper((string)($row['type'] ?? ''));
		if (!in_array($type, DnsRecord::TYPES, true)) {
			return null;
		}
		$value = (string)($row['data'] ?? '');
		if ($type === DnsRecord::TYPE_CAA) {
			$value = self::formatCaa((int)($row['flags'] ?? 0), (string)($row['tag'] ?? 'issue'), $value);
		}
		$ttl = (int)($row['ttl'] ?? 0);
		$record = new DnsRecord(
			$type,
			self::absoluteName((string)($row['name'] ?? '@'), $zone),
			$value,
			$ttl > 0 ? $ttl : null,
			$type === DnsRecord::TYPE_MX ? (int)($row['priority'] ?? 0) : null
		);
		$record->provider_id = (string)($row['id'] ?? '');
		return $record;
	}

	private function toApi(string $zone, DnsRecord $record): array {
		$body = array(
			'type' => $record->type,
			'name' => self::relativeName($record->name, $zone, '@'),
		);
		switch ($record->type) {
			case DnsRecord::TYPE_CAA:
				$caa = self::parseCaa($record->value);
				$body['flags'] = $caa['flags'];
				$body['tag']   = $caa['tag'];
				$body['data']  = $caa['value'];
				break;
			case DnsRecord::TYPE_TXT:
				$body['data'] = $this->txtWireValue($record->value);
				break;
			case DnsRecord::TYPE_CNAME:
			case DnsRecord::TYPE_MX:
				// DigitalOcean stores hostname targets fully qualified.
				$body['data'] = $record->value . '.';
				break;
			default:
				$body['data'] = $record->value;
		}
		if ($record->type === DnsRecord::TYPE_MX) {
			$body['priority'] = $record->priority !== null ? (int)$record->priority : 10;
		}
		if ($record->ttl !== null) {
			$body['ttl'] = (int)$record->ttl;
		}
		return $body;
	}

	protected function authHeaders(): array {
		return array('Authorization' => 'Bearer ' . $this->accessToken());
	}
}

<?php
/**
 * HetznerDnsDriver - Hetzner DNS Console, API v1.
 *
 * No OAuth2; a Hetzner DNS API token is supplied at the publish moment and
 * discarded when the request returns.
 *
 * Vendor note: Hetzner bakes MX priority into the record value ("10
 * mail.example.com"), so the driver splits it out on read and folds it back in
 * on write — no caller ever sees the combined form.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverBase.php'));

class HetznerDnsDriver extends DnsDriverBase {

	const API_BASE = 'https://dns.hetzner.com/api/v1/';

	/** @var array<string,string>|null zone name => zone id. */
	private $zones = null;

	public static function getKey(): string { return 'hetzner'; }
	public static function getLabel(): string { return 'Hetzner DNS'; }
	public static function supportsZones(): bool { return true; }

	public static function nameservers(): array {
		return array('hydrogen.ns.hetzner.com', 'oxygen.ns.hetzner.com', 'helium.ns.hetzner.de');
	}

	public static function nameserverSuffixes(): array { return array('ns.hetzner.'); }

	public static function credentialFields(): array {
		return array(
			'api_token' => array(
				'label'  => 'Hetzner DNS API token',
				'help'   => 'From the Hetzner DNS Console under API tokens. Used for this one publish and never stored.',
				'secret' => true,
			),
		);
	}

	public function zoneFor(string $domain): ?string {
		$id = self::matchZone($domain, $this->zoneMap());
		if ($id === null) {
			return null;
		}
		foreach ($this->zoneMap() as $name => $zone_id) {
			if ($zone_id === $id) {
				return $name;
			}
		}
		return null;
	}

	public function createZone(string $domain): string {
		$domain = DnsRecord::normalizeName($domain);
		if ($this->zoneFor($domain) === $domain) {
			return $domain;
		}
		$this->request('POST', self::API_BASE . 'zones', array('json' => array('name' => $domain)));
		$this->zones = null;
		return $domain;
	}

	public function deleteZone(string $zone): void {
		$this->request('DELETE', self::API_BASE . 'zones/' . rawurlencode($this->zoneId($zone)));
		$this->zones = null;
	}

	public function listRecords(string $zone): array {
		$body = $this->request('GET', self::API_BASE . 'records?zone_id=' . rawurlencode($this->zoneId($zone))
			. '&per_page=1000');
		$out = array();
		foreach ((array)($body['records'] ?? array()) as $row) {
			$record = $this->toRecord($zone, $row);
			if ($record !== null) {
				$out[] = $record;
			}
		}
		return $out;
	}

	public function createRecord(string $zone, DnsRecord $record): void {
		$body = $this->toApi($zone, $record);
		$body['zone_id'] = $this->zoneId($zone);
		$this->request('POST', self::API_BASE . 'records', array('json' => $body));
	}

	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot update ' . $desired->describe() . ': no Hetzner record id.');
		}
		$body = $this->toApi($zone, $desired);
		$body['zone_id'] = $this->zoneId($zone);
		$this->request('PUT', self::API_BASE . 'records/' . rawurlencode($live->provider_id), array('json' => $body));
	}

	public function deleteRecord(string $zone, DnsRecord $live): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot delete ' . $live->describe() . ': no Hetzner record id.');
		}
		$this->request('DELETE', self::API_BASE . 'records/' . rawurlencode($live->provider_id));
	}

	// ------------------------------------------------------------------

	/** @return array<string,string> */
	private function zoneMap(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$body = $this->request('GET', self::API_BASE . 'zones?per_page=100');
		foreach ((array)($body['zones'] ?? array()) as $row) {
			$name = DnsRecord::normalizeName((string)($row['name'] ?? ''));
			if ($name !== '' && !empty($row['id'])) {
				$this->zones[$name] = (string)$row['id'];
			}
		}
		return $this->zones;
	}

	private function zoneId(string $zone): string {
		$zones = $this->zoneMap();
		$name = DnsRecord::normalizeName($zone);
		if (!isset($zones[$name])) {
			throw new DnsZoneNotFoundException('This Hetzner token can see no zone for ' . $zone . '.');
		}
		return $zones[$name];
	}

	private function toRecord(string $zone, array $row): ?DnsRecord {
		$type = strtoupper((string)($row['type'] ?? ''));
		if (!in_array($type, DnsRecord::TYPES, true)) {
			return null;
		}
		$value = (string)($row['value'] ?? '');
		$priority = null;
		if ($type === DnsRecord::TYPE_MX && preg_match('/^\s*(\d+)\s+(.+)$/', $value, $m)) {
			$priority = (int)$m[1];
			$value = $m[2];
		}
		$ttl = (int)($row['ttl'] ?? 0);
		$record = new DnsRecord($type, self::absoluteName((string)($row['name'] ?? '@'), $zone),
			$value, $ttl > 0 ? $ttl : null, $priority);
		$record->provider_id = (string)($row['id'] ?? '');
		return $record;
	}

	private function toApi(string $zone, DnsRecord $record): array {
		$value = $record->type === DnsRecord::TYPE_TXT
			? $this->txtWireValue($record->value) : $record->value;
		if ($record->type === DnsRecord::TYPE_MX) {
			$value = ($record->priority !== null ? (int)$record->priority : 10) . ' ' . $record->value;
		}
		$body = array(
			'type'  => $record->type,
			'name'  => self::relativeName($record->name, $zone, '@'),
			'value' => $value,
		);
		if ($record->ttl !== null) {
			$body['ttl'] = (int)$record->ttl;
		}
		return $body;
	}

	protected function authHeaders(): array {
		return array('Auth-API-Token' => $this->cred('api_token'));
	}
}

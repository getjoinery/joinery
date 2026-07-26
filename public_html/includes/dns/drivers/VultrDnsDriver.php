<?php
/**
 * VultrDnsDriver - Vultr DNS, API v2.
 *
 * Vultr offers no OAuth2 for its API, so it takes a bearer personal access
 * token supplied at the publish moment and discarded when the request returns.
 *
 * Vendor note: Vultr wants TXT values quoted whatever their length, so this
 * driver quotes every TXT it writes rather than only over-length ones.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverBase.php'));

class VultrDnsDriver extends DnsDriverBase {

	const API_BASE = 'https://api.vultr.com/v2/';

	/** @var string[]|null */
	private $zones = null;

	public static function getKey(): string { return 'vultr'; }
	public static function getLabel(): string { return 'Vultr DNS'; }
	public static function supportsZones(): bool { return true; }

	public static function nameservers(): array {
		return array('ns1.vultr.com', 'ns2.vultr.com');
	}

	public static function nameserverSuffixes(): array { return array('.vultr.com'); }

	public static function credentialFields(): array {
		return array(
			'api_key' => array(
				'label'  => 'Vultr personal access token',
				'help'   => 'From Account · API in the Vultr customer portal. Used for this one publish and never stored.',
				'secret' => true,
			),
		);
	}

	/** Vultr stores a TXT value verbatim, quotes included, so quote always. */
	public function txtWireValue(string $value): string {
		return self::quoteTxt($value);
	}

	public static function prerequisiteNote(): string {
		return 'Vultr keeps API access off until you enable it, and its Access Control list decides which '
			. 'addresses may call. Enable the API and add this server\'s public IP under Account · API, '
			. 'or the key is refused before it reaches a zone.';
	}

	public static function credentialGuide(): ?array {
		return array(
			'title'     => 'Create a Vultr personal access token',
			'url'       => 'https://my.vultr.com/settings/#settingsapi',
			'url_label' => 'Open Vultr API settings',
			'steps'     => array(
				'In the Vultr portal open Account, then API.',
				'Under Personal Access Token choose Enable API if it is off.',
				'Under Access Control add this server\'s public IP, or Vultr refuses calls from it.',
				'Copy the API key from the same page.',
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
		$this->request('POST', self::API_BASE . 'domains', array('json' => array(
			'domain' => $domain,
			// Vultr seeds a zone with an A record when handed an IP; the platform
			// publishes its own records through the diff, so seed nothing.
			'dns_sec' => 'disabled',
		)));
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
		$cursor = '';
		do {
			$url = self::API_BASE . 'domains/' . rawurlencode($zone) . '/records?per_page=500'
				. ($cursor !== '' ? '&cursor=' . rawurlencode($cursor) : '');
			$body = $this->request('GET', $url);
			foreach ((array)($body['records'] ?? array()) as $row) {
				$record = $this->toRecord($zone, $row);
				if ($record !== null) {
					$out[] = $record;
				}
			}
			$cursor = (string)($body['meta']['links']['next'] ?? '');
		} while ($cursor !== '');
		return $out;
	}

	public function createRecord(string $zone, DnsRecord $record): void {
		$this->request('POST', self::API_BASE . 'domains/' . rawurlencode(DnsRecord::normalizeName($zone)) . '/records',
			array('json' => $this->toApi($zone, $record)));
	}

	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot update ' . $desired->describe() . ': no Vultr record id.');
		}
		$body = $this->toApi($zone, $desired);
		unset($body['type']);   // Vultr will not change a record's type in place
		$this->request('PATCH', self::API_BASE . 'domains/' . rawurlencode(DnsRecord::normalizeName($zone))
			. '/records/' . rawurlencode($live->provider_id), array('json' => $body));
	}

	public function deleteRecord(string $zone, DnsRecord $live): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot delete ' . $live->describe() . ': no Vultr record id.');
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
		$cursor = '';
		do {
			$url = self::API_BASE . 'domains?per_page=500' . ($cursor !== '' ? '&cursor=' . rawurlencode($cursor) : '');
			$body = $this->request('GET', $url);
			foreach ((array)($body['domains'] ?? array()) as $row) {
				$name = DnsRecord::normalizeName((string)($row['domain'] ?? ''));
				if ($name !== '') {
					$this->zones[] = $name;
				}
			}
			$cursor = (string)($body['meta']['links']['next'] ?? '');
		} while ($cursor !== '');
		return $this->zones;
	}

	private function toRecord(string $zone, array $row): ?DnsRecord {
		$type = strtoupper((string)($row['type'] ?? ''));
		if (!in_array($type, DnsRecord::TYPES, true)) {
			return null;
		}
		$ttl = (int)($row['ttl'] ?? 0);
		$record = new DnsRecord(
			$type,
			self::absoluteName((string)($row['name'] ?? ''), $zone),
			(string)($row['data'] ?? ''),
			$ttl > 0 ? $ttl : null,
			$type === DnsRecord::TYPE_MX ? (int)($row['priority'] ?? 0) : null
		);
		$record->provider_id = (string)($row['id'] ?? '');
		return $record;
	}

	private function toApi(string $zone, DnsRecord $record): array {
		$body = array(
			'type' => $record->type,
			'name' => self::relativeName($record->name, $zone, ''),
			'data' => $record->type === DnsRecord::TYPE_TXT
				? $this->txtWireValue($record->value) : $record->value,
		);
		if ($record->type === DnsRecord::TYPE_MX) {
			$body['priority'] = $record->priority !== null ? (int)$record->priority : 10;
		}
		if ($record->ttl !== null) {
			$body['ttl'] = (int)$record->ttl;
		}
		return $body;
	}

	protected function authHeaders(): array {
		return array('Authorization' => 'Bearer ' . $this->cred('api_key'));
	}
}

<?php
/**
 * NameComDnsDriver - Name.com Core API v4.
 *
 * No OAuth2; Name.com authenticates with an account username and an API token
 * over HTTP basic auth, supplied at the publish moment and discarded when the
 * request returns.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverBase.php'));

class NameComDnsDriver extends DnsDriverBase {

	const API_BASE = 'https://api.name.com/v4/';

	/** @var string[]|null */
	private $zones = null;

	public static function getKey(): string { return 'namecom'; }
	public static function getLabel(): string { return 'Name.com'; }

	public static function nameservers(): array {
		return array('ns1ex.name.com', 'ns2ex.name.com', 'ns3ex.name.com', 'ns4ex.name.com');
	}

	public static function nameserverSuffixes(): array { return array('.name.com'); }

	public static function credentialFields(): array {
		return array(
			'username' => array(
				'label'  => 'Name.com username',
				'help'   => 'The account the API token belongs to.',
				'secret' => false,
			),
			'api_token' => array(
				'label'  => 'Name.com API token',
				'help'   => 'From Account Settings · API Tokens. Used for this one publish and never stored.',
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

	public function listRecords(string $zone): array {
		$zone = DnsRecord::normalizeName($zone);
		$out = array();
		$page = '';
		do {
			$body = $this->request('GET', self::API_BASE . 'domains/' . rawurlencode($zone) . '/records?perPage=1000'
				. ($page !== '' ? '&page=' . rawurlencode($page) : ''));
			foreach ((array)($body['records'] ?? array()) as $row) {
				$type = strtoupper((string)($row['type'] ?? ''));
				if (!in_array($type, DnsRecord::TYPES, true)) {
					continue;
				}
				$ttl = (int)($row['ttl'] ?? 0);
				$record = new DnsRecord(
					$type,
					(string)($row['fqdn'] ?? $zone),
					(string)($row['answer'] ?? ''),
					$ttl > 0 ? $ttl : null,
					$type === DnsRecord::TYPE_MX ? (int)($row['priority'] ?? 0) : null
				);
				$record->provider_id = (string)($row['id'] ?? '');
				$out[] = $record;
			}
			$page = (string)($body['nextPage'] ?? '');
		} while ($page !== '');
		return $out;
	}

	public function createRecord(string $zone, DnsRecord $record): void {
		$this->request('POST', self::API_BASE . 'domains/' . rawurlencode(DnsRecord::normalizeName($zone)) . '/records',
			array('json' => $this->toApi($zone, $record)));
	}

	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot update ' . $desired->describe() . ': no Name.com record id.');
		}
		$this->request('PUT', self::API_BASE . 'domains/' . rawurlencode(DnsRecord::normalizeName($zone))
			. '/records/' . rawurlencode($live->provider_id), array('json' => $this->toApi($zone, $desired)));
	}

	public function deleteRecord(string $zone, DnsRecord $live): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot delete ' . $live->describe() . ': no Name.com record id.');
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
		$page = '';
		do {
			$body = $this->request('GET', self::API_BASE . 'domains?perPage=1000'
				. ($page !== '' ? '&page=' . rawurlencode($page) : ''));
			foreach ((array)($body['domains'] ?? array()) as $row) {
				$name = DnsRecord::normalizeName((string)($row['domainName'] ?? ''));
				if ($name !== '') {
					$this->zones[] = $name;
				}
			}
			$page = (string)($body['nextPage'] ?? '');
		} while ($page !== '');
		return $this->zones;
	}

	private function toApi(string $zone, DnsRecord $record): array {
		$body = array(
			'type'   => $record->type,
			'host'   => self::relativeName($record->name, $zone, ''),
			'answer' => $record->type === DnsRecord::TYPE_TXT
				? $this->txtWireValue($record->value) : $record->value,
		);
		if ($record->type === DnsRecord::TYPE_MX) {
			$body['priority'] = $record->priority !== null ? (int)$record->priority : 10;
		}
		if ($record->ttl !== null) {
			$body['ttl'] = max(300, (int)$record->ttl);
		}
		return $body;
	}

	protected function authHeaders(): array {
		return array('Authorization' => 'Basic '
			. base64_encode($this->cred('username') . ':' . $this->cred('api_token')));
	}
}

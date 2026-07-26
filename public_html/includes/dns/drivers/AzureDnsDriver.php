<?php
/**
 * AzureDnsDriver - Azure DNS, Microsoft.Network/dnszones 2018-05-01.
 *
 * Authorized by OAuth2 consent through the shipping MicrosoftOAuthProvider. An
 * Azure zone sits under a subscription and a resource group, so the grant may
 * reach several places to write; accounts() lists the subscriptions and the
 * publish box asks which only when there is more than one. The resource group
 * comes from the zone's own resource id, so nobody has to type it.
 *
 * Azure models each record type with its own typed array, so translation
 * between the platform's flat record and Azure's shape lives in
 * azureRecords()/valuesFromProperties().
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsRrsetDriverBase.php'));

class AzureDnsDriver extends DnsRrsetDriverBase {

	const MANAGEMENT_BASE = 'https://management.azure.com';
	const API_VERSION = '2018-05-01';

	/** @var array<string,string>|null zone name => full ARM resource id. */
	private $zones = null;
	/** @var string|null Resolved subscription id. */
	private $subscription = null;

	public static function getKey(): string { return 'azure_dns'; }
	public static function getLabel(): string { return 'Azure DNS'; }

	public static function credentialMode(): string { return self::CREDENTIAL_OAUTH2; }
	public static function oauthProviderKey(): string { return 'microsoft'; }

	public static function oauthScopes(): array {
		return array('https://management.azure.com/user_impersonation', 'offline_access');
	}

	/** Azure assigns per-zone names, e.g. ns1-01.azure-dns.com. */
	public static function nameserverSuffixes(): array {
		return array('azure-dns.com', 'azure-dns.net', 'azure-dns.org', 'azure-dns.info');
	}

	public function accounts(): array {
		$out = array();
		$body = $this->request('GET', self::MANAGEMENT_BASE . '/subscriptions?api-version=2020-01-01');
		foreach ((array)($body['value'] ?? array()) as $row) {
			$id = (string)($row['subscriptionId'] ?? '');
			if ($id !== '') {
				$out[] = array('id' => $id, 'label' => (string)($row['displayName'] ?? $id));
			}
		}
		return $out ?: parent::accounts();
	}

	public function zoneFor(string $domain): ?string {
		$names = array_keys($this->zoneMap());
		return self::matchZone($domain, array_combine($names, $names));
	}

	public function listRecords(string $zone): array {
		$zone_id = $this->zoneResourceId($zone);
		$out = array();
		$url = self::MANAGEMENT_BASE . $zone_id . '/recordsets?api-version=' . self::API_VERSION;
		while ($url !== '') {
			$body = $this->request('GET', $url);
			foreach ((array)($body['value'] ?? array()) as $rrset) {
				$type = strtoupper(substr(strrchr((string)($rrset['type'] ?? ''), '/') ?: '', 1));
				if (!in_array($type, DnsRecord::TYPES, true)) {
					continue;
				}
				$name = self::absoluteName((string)($rrset['name'] ?? '@'), $zone);
				$props = (array)($rrset['properties'] ?? array());
				$ttl = (int)($props['TTL'] ?? 0);
				foreach ($this->valuesFromProperties($type, $props) as $value) {
					$record = $this->recordFromValue($type, $name, $value, $ttl > 0 ? $ttl : null);
					if ($record !== null) {
						$record->provider_id = $name . '/' . $type;
						$out[] = $record;
					}
				}
			}
			$url = (string)($body['nextLink'] ?? '');
		}
		return $out;
	}

	// ------------------------------------------------------------------

	protected function readRrset(string $zone, string $name, string $type): ?array {
		try {
			$body = $this->request('GET', $this->rrsetUrl($zone, $name, $type));
		} catch (DnsProviderException $e) {
			return null;
		}
		$props = (array)($body['properties'] ?? array());
		$values = $this->valuesFromProperties(strtoupper($type), $props);
		if (empty($values)) {
			return null;
		}
		$ttl = (int)($props['TTL'] ?? 0);
		return array('values' => $values, 'ttl' => $ttl > 0 ? $ttl : null);
	}

	protected function writeRrset(string $zone, string $name, string $type, array $values, ?int $ttl): void {
		$properties = array('TTL' => $ttl !== null ? (int)$ttl : 3600);
		$properties = array_merge($properties, $this->azureRecords(strtoupper($type), $values));
		$this->request('PUT', $this->rrsetUrl($zone, $name, $type),
			array('json' => array('properties' => $properties)));
	}

	protected function deleteRrset(string $zone, string $name, string $type): void {
		$this->request('DELETE', $this->rrsetUrl($zone, $name, $type));
	}

	private function rrsetUrl(string $zone, string $name, string $type): string {
		$relative = self::relativeName($name, $zone, '@');
		return self::MANAGEMENT_BASE . $this->zoneResourceId($zone) . '/' . strtoupper($type) . '/'
			. rawurlencode($relative) . '?api-version=' . self::API_VERSION;
	}

	/** Azure's typed record arrays, flattened to the platform's value strings. */
	private function valuesFromProperties(string $type, array $props): array {
		$out = array();
		switch ($type) {
			case DnsRecord::TYPE_A:
				foreach ((array)($props['ARecords'] ?? array()) as $r) {
					$out[] = (string)($r['ipv4Address'] ?? '');
				}
				break;
			case DnsRecord::TYPE_AAAA:
				foreach ((array)($props['AAAARecords'] ?? array()) as $r) {
					$out[] = (string)($r['ipv6Address'] ?? '');
				}
				break;
			case DnsRecord::TYPE_CNAME:
				$cname = (string)($props['CNAMERecord']['cname'] ?? '');
				if ($cname !== '') { $out[] = $cname; }
				break;
			case DnsRecord::TYPE_MX:
				foreach ((array)($props['MXRecords'] ?? array()) as $r) {
					$out[] = (int)($r['preference'] ?? 10) . ' ' . (string)($r['exchange'] ?? '');
				}
				break;
			case DnsRecord::TYPE_TXT:
				foreach ((array)($props['TXTRecords'] ?? array()) as $r) {
					// Azure holds the 255-byte character-strings as a list.
					$out[] = implode('', array_map('strval', (array)($r['value'] ?? array())));
				}
				break;
			case DnsRecord::TYPE_CAA:
				foreach ((array)($props['caaRecords'] ?? $props['CaaRecords'] ?? array()) as $r) {
					$out[] = self::formatCaa((int)($r['flags'] ?? 0),
						(string)($r['tag'] ?? 'issue'), (string)($r['value'] ?? ''));
				}
				break;
		}
		return array_values(array_filter($out, function ($v) { return $v !== ''; }));
	}

	/** The inverse: platform value strings back into Azure's typed arrays. */
	private function azureRecords(string $type, array $values): array {
		$records = array();
		foreach ($values as $value) {
			$value = (string)$value;
			switch ($type) {
				case DnsRecord::TYPE_A:
					$records[] = array('ipv4Address' => $value);
					break;
				case DnsRecord::TYPE_AAAA:
					$records[] = array('ipv6Address' => $value);
					break;
				case DnsRecord::TYPE_CNAME:
					return array('CNAMERecord' => array('cname' => rtrim($value, '.')));
				case DnsRecord::TYPE_MX:
					if (preg_match('/^\s*(\d+)\s+(.+)$/', $value, $m)) {
						$records[] = array('preference' => (int)$m[1], 'exchange' => rtrim($m[2], '.'));
					}
					break;
				case DnsRecord::TYPE_TXT:
					$records[] = array('value' => self::txtChunks(DnsRecord::canonicalValue(DnsRecord::TYPE_TXT, $value)));
					break;
				case DnsRecord::TYPE_CAA:
					$caa = self::parseCaa($value);
					$records[] = array('flags' => $caa['flags'], 'tag' => $caa['tag'], 'value' => $caa['value']);
					break;
			}
		}
		$key = array(
			DnsRecord::TYPE_A    => 'ARecords',
			DnsRecord::TYPE_AAAA => 'AAAARecords',
			DnsRecord::TYPE_MX   => 'MXRecords',
			DnsRecord::TYPE_TXT  => 'TXTRecords',
			DnsRecord::TYPE_CAA  => 'caaRecords',
		);
		return isset($key[$type]) ? array($key[$type] => $records) : array();
	}

	/** @return array<string,string> zone name => ARM resource id. */
	private function zoneMap(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$url = self::MANAGEMENT_BASE . '/subscriptions/' . rawurlencode($this->subscription())
			. '/providers/Microsoft.Network/dnszones?api-version=' . self::API_VERSION;
		while ($url !== '') {
			$body = $this->request('GET', $url);
			foreach ((array)($body['value'] ?? array()) as $row) {
				if (strtolower((string)($row['properties']['zoneType'] ?? 'public')) === 'private') {
					continue;
				}
				$name = DnsRecord::normalizeName((string)($row['name'] ?? ''));
				$id   = (string)($row['id'] ?? '');
				if ($name !== '' && $id !== '') {
					$this->zones[$name] = $id;
				}
			}
			$url = (string)($body['nextLink'] ?? '');
		}
		return $this->zones;
	}

	private function zoneResourceId(string $zone): string {
		$zones = $this->zoneMap();
		$name = DnsRecord::normalizeName($zone);
		if (!isset($zones[$name])) {
			throw new DnsZoneNotFoundException('This Azure subscription holds no DNS zone for ' . $zone . '.');
		}
		return $zones[$name];
	}

	/** The subscription the publish acts in — chosen at publish time, never recorded. */
	private function subscription(): string {
		if ($this->subscription === null) {
			$this->subscription = $this->account_id;
			if ($this->subscription === '') {
				$accounts = $this->accounts();
				$this->subscription = (string)($accounts[0]['id'] ?? '');
			}
			if ($this->subscription === '') {
				throw new DnsProviderException('This Microsoft grant reaches no Azure subscription, so there is '
					. 'nowhere to look for the zone.');
			}
		}
		return $this->subscription;
	}

	protected function authHeaders(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->accessToken(),
			'Content-Type'  => 'application/json',
		);
	}
}

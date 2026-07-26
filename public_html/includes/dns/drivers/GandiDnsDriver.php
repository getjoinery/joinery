<?php
/**
 * GandiDnsDriver - Gandi LiveDNS, API v5.
 *
 * No OAuth2; a Gandi personal access token is supplied at the publish moment
 * and discarded when the request returns.
 *
 * Gandi models DNS as record sets, so creating one record is a read-modify-write
 * of the whole (name, type) set — handled by DnsRrsetDriverBase.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsRrsetDriverBase.php'));

class GandiDnsDriver extends DnsRrsetDriverBase {

	const API_BASE = 'https://api.gandi.net/v5/livedns/';

	/** @var string[]|null */
	private $zones = null;

	public static function getKey(): string { return 'gandi'; }
	public static function getLabel(): string { return 'Gandi LiveDNS'; }

	public static function nameservers(): array {
		return array('ns-1-a.gandi.net', 'ns-2-b.gandi.net', 'ns-3-c.gandi.net');
	}

	/** Gandi assigns per-zone names, e.g. ns-93-a.gandi.net. */
	public static function nameserverSuffixes(): array { return array('gandi.net'); }

	public static function credentialFields(): array {
		return array(
			'api_token' => array(
				'label'  => 'Gandi personal access token',
				'help'   => 'From Gandi account settings, with the "Manage domain name technical configurations" '
					. 'permission. Used for this one publish and never stored.',
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
		$body = $this->request('GET', self::API_BASE . 'domains/' . rawurlencode($zone) . '/records');
		$out = array();
		foreach ($body as $rrset) {
			if (!is_array($rrset)) {
				continue;
			}
			$type = strtoupper((string)($rrset['rrset_type'] ?? ''));
			$name = self::absoluteName((string)($rrset['rrset_name'] ?? '@'), $zone);
			$ttl  = (int)($rrset['rrset_ttl'] ?? 0);
			foreach ((array)($rrset['rrset_values'] ?? array()) as $value) {
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
		try {
			$body = $this->request('GET', self::API_BASE . 'domains/' . rawurlencode($zone) . '/records/'
				. rawurlencode(self::relativeName($name, $zone, '@')) . '/' . rawurlencode($type));
		} catch (DnsProviderException $e) {
			return null;   // 404 — the set does not exist yet
		}
		if (empty($body['rrset_values'])) {
			return null;
		}
		$ttl = (int)($body['rrset_ttl'] ?? 0);
		return array('values' => array_map('strval', (array)$body['rrset_values']), 'ttl' => $ttl > 0 ? $ttl : null);
	}

	protected function writeRrset(string $zone, string $name, string $type, array $values, ?int $ttl): void {
		$zone = DnsRecord::normalizeName($zone);
		$payload = array('rrset_values' => array_values($values));
		// Gandi requires a TTL on every write and rejects anything under 300.
		$payload['rrset_ttl'] = max(300, $ttl !== null ? (int)$ttl : 10800);
		$this->request('PUT', self::API_BASE . 'domains/' . rawurlencode($zone) . '/records/'
			. rawurlencode(self::relativeName($name, $zone, '@')) . '/' . rawurlencode($type),
			array('json' => $payload));
	}

	protected function deleteRrset(string $zone, string $name, string $type): void {
		$zone = DnsRecord::normalizeName($zone);
		$this->request('DELETE', self::API_BASE . 'domains/' . rawurlencode($zone) . '/records/'
			. rawurlencode(self::relativeName($name, $zone, '@')) . '/' . rawurlencode($type));
	}

	/** @return string[] */
	private function zoneNames(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$body = $this->request('GET', self::API_BASE . 'domains');
		foreach ($body as $row) {
			if (!is_array($row)) {
				continue;
			}
			$name = DnsRecord::normalizeName((string)($row['fqdn'] ?? ''));
			if ($name !== '') {
				$this->zones[] = $name;
			}
		}
		return $this->zones;
	}

	protected function authHeaders(): array {
		return array('Authorization' => 'Bearer ' . $this->cred('api_token'));
	}
}

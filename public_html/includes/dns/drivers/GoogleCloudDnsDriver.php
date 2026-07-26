<?php
/**
 * GoogleCloudDnsDriver - Google Cloud DNS, API v1.
 *
 * Authorized by OAuth2 consent through the shipping GoogleOAuthProvider. A zone
 * in Google Cloud lives inside a *project*, so the grant may reach several
 * places to write; accounts() lists the projects and the publish box asks which
 * only when there is more than one. Nothing about that choice is persisted.
 *
 * Cloud DNS is record-set based and has no in-place update — a change is a
 * single atomic "change" carrying the old set as a deletion and the new set as
 * an addition. DnsRrsetDriverBase's read-modify-write feeds exactly that.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsRrsetDriverBase.php'));

class GoogleCloudDnsDriver extends DnsRrsetDriverBase {

	const API_BASE = 'https://dns.googleapis.com/dns/v1/';
	const PROJECTS_URL = 'https://cloudresourcemanager.googleapis.com/v1/projects';

	/** @var array<string,string>|null dns name => managed zone name. */
	private $zones = null;
	/** @var string|null Resolved project id. */
	private $project = null;

	public static function getKey(): string { return 'google_cloud_dns'; }
	public static function getLabel(): string { return 'Google Cloud DNS'; }

	public static function credentialMode(): string { return self::CREDENTIAL_OAUTH2; }
	public static function oauthProviderKey(): string { return 'google'; }

	public static function oauthScopes(): array {
		return array(
			'https://www.googleapis.com/auth/ndev.clouddns.readwrite',
			'https://www.googleapis.com/auth/cloudplatformprojects.readonly',
		);
	}

	/** Cloud DNS assigns per-zone names, e.g. ns-cloud-a1.googledomains.com. */
	public static function nameserverSuffixes(): array { return array('googledomains.com'); }

	public function accounts(): array {
		$out = array();
		$body = $this->request('GET', self::PROJECTS_URL);
		foreach ((array)($body['projects'] ?? array()) as $row) {
			$id = (string)($row['projectId'] ?? '');
			if ($id !== '' && (string)($row['lifecycleState'] ?? 'ACTIVE') === 'ACTIVE') {
				$out[] = array('id' => $id, 'label' => (string)($row['name'] ?? $id) . ' (' . $id . ')');
			}
		}
		return $out ?: parent::accounts();
	}

	public function zoneFor(string $domain): ?string {
		$name = self::matchZone($domain, array_combine(array_keys($this->zoneMap()), array_keys($this->zoneMap())));
		return $name;
	}

	public function listRecords(string $zone): array {
		$managed = $this->managedZone($zone);
		$out = array();
		$token = '';
		do {
			$body = $this->request('GET', self::API_BASE . 'projects/' . rawurlencode($this->project())
				. '/managedZones/' . rawurlencode($managed) . '/rrsets?maxResults=500'
				. ($token !== '' ? '&pageToken=' . rawurlencode($token) : ''));
			foreach ((array)($body['rrsets'] ?? array()) as $rrset) {
				$type = strtoupper((string)($rrset['type'] ?? ''));
				$name = DnsRecord::normalizeName((string)($rrset['name'] ?? ''));
				$ttl  = (int)($rrset['ttl'] ?? 0);
				foreach ((array)($rrset['rrdatas'] ?? array()) as $value) {
					$record = $this->recordFromValue($type, $name, (string)$value, $ttl > 0 ? $ttl : null);
					if ($record !== null) {
						$record->provider_id = $name . '/' . $type;
						$out[] = $record;
					}
				}
			}
			$token = (string)($body['nextPageToken'] ?? '');
		} while ($token !== '');
		return $out;
	}

	// ------------------------------------------------------------------

	protected function readRrset(string $zone, string $name, string $type): ?array {
		$managed = $this->managedZone($zone);
		$body = $this->request('GET', self::API_BASE . 'projects/' . rawurlencode($this->project())
			. '/managedZones/' . rawurlencode($managed) . '/rrsets'
			. '?name=' . rawurlencode(DnsRecord::normalizeName($name) . '.')
			. '&type=' . rawurlencode(strtoupper($type)));
		foreach ((array)($body['rrsets'] ?? array()) as $rrset) {
			$values = array_map('strval', (array)($rrset['rrdatas'] ?? array()));
			if (!empty($values)) {
				$ttl = (int)($rrset['ttl'] ?? 0);
				return array('values' => $values, 'ttl' => $ttl > 0 ? $ttl : null);
			}
		}
		return null;
	}

	protected function writeRrset(string $zone, string $name, string $type, array $values, ?int $ttl): void {
		$existing = $this->readRrset($zone, $name, $type);
		$change = array('additions' => array(array(
			'name'    => DnsRecord::normalizeName($name) . '.',
			'type'    => strtoupper($type),
			'ttl'     => $ttl !== null ? (int)$ttl : 300,
			'rrdatas' => array_values($values),
		)));
		if ($existing !== null) {
			// Cloud DNS replaces a set by deleting it and adding the new one in
			// the same atomic change.
			$change['deletions'] = array(array(
				'name'    => DnsRecord::normalizeName($name) . '.',
				'type'    => strtoupper($type),
				'ttl'     => $existing['ttl'] !== null ? (int)$existing['ttl'] : 300,
				'rrdatas' => array_values($existing['values']),
			));
		}
		$this->applyChange($zone, $change);
	}

	protected function deleteRrset(string $zone, string $name, string $type): void {
		$existing = $this->readRrset($zone, $name, $type);
		if ($existing === null) {
			return;
		}
		$this->applyChange($zone, array('deletions' => array(array(
			'name'    => DnsRecord::normalizeName($name) . '.',
			'type'    => strtoupper($type),
			'ttl'     => $existing['ttl'] !== null ? (int)$existing['ttl'] : 300,
			'rrdatas' => array_values($existing['values']),
		))));
	}

	private function applyChange(string $zone, array $change): void {
		$this->request('POST', self::API_BASE . 'projects/' . rawurlencode($this->project())
			. '/managedZones/' . rawurlencode($this->managedZone($zone)) . '/changes',
			array('json' => $change));
	}

	/** @return array<string,string> dns name => managed zone name. */
	private function zoneMap(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$token = '';
		do {
			$body = $this->request('GET', self::API_BASE . 'projects/' . rawurlencode($this->project())
				. '/managedZones?maxResults=500' . ($token !== '' ? '&pageToken=' . rawurlencode($token) : ''));
			foreach ((array)($body['managedZones'] ?? array()) as $row) {
				if ((string)($row['visibility'] ?? 'public') === 'private') {
					continue;
				}
				$dns_name = DnsRecord::normalizeName((string)($row['dnsName'] ?? ''));
				$managed  = (string)($row['name'] ?? '');
				if ($dns_name !== '' && $managed !== '') {
					$this->zones[$dns_name] = $managed;
				}
			}
			$token = (string)($body['nextPageToken'] ?? '');
		} while ($token !== '');
		return $this->zones;
	}

	private function managedZone(string $zone): string {
		$zones = $this->zoneMap();
		$name = DnsRecord::normalizeName($zone);
		if (!isset($zones[$name])) {
			throw new DnsZoneNotFoundException('This Google Cloud project holds no managed zone for ' . $zone . '.');
		}
		return $zones[$name];
	}

	/** The project the publish acts in — chosen at publish time, never recorded. */
	private function project(): string {
		if ($this->project === null) {
			$this->project = $this->account_id;
			if ($this->project === '') {
				$accounts = $this->accounts();
				$this->project = (string)($accounts[0]['id'] ?? '');
			}
			if ($this->project === '') {
				throw new DnsProviderException('This Google grant reaches no Cloud project, so there is nowhere '
					. 'to look for the zone.');
			}
		}
		return $this->project;
	}

	protected function authHeaders(): array {
		return array('Authorization' => 'Bearer ' . $this->accessToken());
	}
}

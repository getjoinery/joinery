<?php
/**
 * HetznerDnsDriver - Hetzner DNS through the Hetzner Cloud API (v1).
 *
 * No OAuth2; a Hetzner Cloud project API token (Read & Write, Bearer auth) is
 * supplied at the publish moment and discarded when the request returns. A zone
 * belongs to one Cloud project, so the token has to come from that project.
 *
 * Hetzner stores record SETS: one RRSet per (name, type) holding a list of
 * values and one TTL, addressed as /zones/{zone}/rrsets/{name}/{type} with the
 * apex spelled '@'. There are no per-record ids, so writes go through
 * DnsRrsetDriverBase's read-modify-write — changing one value of a multi-value
 * set rewrites the set with its siblings intact. Values travel in zone-file
 * spelling: TXT as quoted 255-byte strings, MX as "10 mail.example.com.", CNAME
 * and SRV targets absolute (dotted) — exactly what the base's rrsetValue() makes.
 *
 * Every write returns an asynchronous Action. The driver waits for it to finish
 * before returning, so the next read sees the change and a second write to the
 * same set is not refused as locked.
 *
 * @version 2.0 - Hetzner Cloud API: RRSets through DnsRrsetDriverBase, actions awaited, per-zone nameservers
 * @version 1.1 - SRV writes an absolute target so Hetzner cannot re-append the zone
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsRrsetDriverBase.php'));

use GuzzleHttp\Exception\RequestException;

class HetznerDnsDriver extends DnsRrsetDriverBase {

	const API_BASE = 'https://api.hetzner.cloud/v1/';

	/** Hetzner refuses a TTL below 60 seconds. */
	const MIN_TTL = 60;

	/** Listing page sizes: 50 is the API-wide maximum, 100 on the RRSet listing. */
	const ZONES_PER_PAGE  = 50;
	const RRSETS_PER_PAGE = 100;

	/** How long a write waits for its Action, in one-second polls. */
	const ACTION_MAX_POLLS = 15;

	/** @var array<string,string>|null zone name => zone id. */
	private $zones = null;

	/** @var array<string,string[]> zone name => the nameservers Hetzner assigned it. */
	private $zone_ns = array();

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
				'label'  => 'Hetzner Cloud API token',
				'help'   => 'A Read & Write API token from the Hetzner Console project that holds the zone '
					. '(Security, then API tokens). Used for this one publish and never stored.',
				'secret' => true,
			),
		);
	}

	public static function credentialGuide(): ?array {
		return array(
			'title'     => 'Create a Hetzner Cloud API token',
			'url'       => 'https://console.hetzner.com/projects',
			'url_label' => 'Open the Hetzner Console',
			'steps'     => array(
				'Sign in to the Hetzner Console and open the project that holds the domain\'s DNS zone.',
				'Choose Security in the left menu, then API tokens.',
				'Choose Generate API token, enter a description, and select Read & Write.',
				'Copy the token now — Hetzner will not show it again.',
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

	/** The nameservers Hetzner assigned this zone; its usual trio when the zone does not say. */
	public function zoneNameservers(string $zone): array {
		$this->zoneMap();
		$assigned = $this->zone_ns[DnsRecord::normalizeName($zone)] ?? array();
		return !empty($assigned) ? $assigned : static::nameservers();
	}

	public function createZone(string $domain): string {
		$domain = DnsRecord::normalizeName($domain);
		if ($this->zoneFor($domain) === $domain) {
			return $domain;
		}
		$this->write('POST', self::API_BASE . 'zones', array('name' => $domain, 'mode' => 'primary'));
		$this->zones = null;
		return $domain;
	}

	public function deleteZone(string $zone): void {
		$this->write('DELETE', self::API_BASE . 'zones/' . rawurlencode($this->zoneId($zone)));
		$this->zones = null;
	}

	public function listRecords(string $zone): array {
		$zone = DnsRecord::normalizeName($zone);
		$base = $this->zoneUrl($zone) . '/rrsets?per_page=' . self::RRSETS_PER_PAGE;
		$out = array();
		$page = 1;
		do {
			$body = $this->request('GET', $base . '&page=' . $page);
			foreach ((array)($body['rrsets'] ?? array()) as $rrset) {
				if (!is_array($rrset)) {
					continue;
				}
				$type = strtoupper((string)($rrset['type'] ?? ''));
				$name = self::absoluteName((string)($rrset['name'] ?? '@'), $zone);
				$ttl  = (int)($rrset['ttl'] ?? 0);
				foreach ((array)($rrset['records'] ?? array()) as $row) {
					$record = $this->recordFromValue($type, $name, (string)($row['value'] ?? ''),
						$ttl > 0 ? $ttl : null);
					if ($record !== null) {
						$record->provider_id = (string)($rrset['id'] ?? ($name . '/' . $type));
						$out[] = $record;
					}
				}
			}
			$page = self::nextPage($body, $page);
		} while ($page !== null);
		return $out;
	}

	// ------------------------------------------------------------------
	// The three RRSet primitives
	// ------------------------------------------------------------------

	protected function readRrset(string $zone, string $name, string $type): ?array {
		// The filtered listing answers an absent set with an empty list rather
		// than a 404, so "not there" and "could not read" stay distinct: a read
		// that fails throws, and the write it guards never happens.
		$body = $this->request('GET', $this->zoneUrl($zone) . '/rrsets'
			. '?name=' . rawurlencode(self::relativeName($name, $zone, '@'))
			. '&type=' . rawurlencode(strtoupper($type)));
		foreach ((array)($body['rrsets'] ?? array()) as $rrset) {
			$values = array();
			foreach ((array)($rrset['records'] ?? array()) as $row) {
				$values[] = (string)($row['value'] ?? '');
			}
			if (!empty($values)) {
				$ttl = (int)($rrset['ttl'] ?? 0);
				return array('values' => $values, 'ttl' => $ttl > 0 ? $ttl : null);
			}
		}
		return null;
	}

	protected function writeRrset(string $zone, string $name, string $type, array $values, ?int $ttl): void {
		$records = array();
		foreach (array_values(array_unique($values)) as $value) {
			$records[] = array('value' => (string)$value);
		}
		$ttl = $ttl !== null ? max(self::MIN_TTL, (int)$ttl) : null;
		$existing = $this->readRrset($zone, $name, $type);

		if ($existing === null) {
			$body = array('name' => self::relativeName($name, $zone, '@'), 'type' => strtoupper($type),
				'records' => $records);
			if ($ttl !== null) {
				$body['ttl'] = $ttl;
			}
			$this->write('POST', $this->zoneUrl($zone) . '/rrsets', $body);
			return;
		}

		// An existing set: overwrite its values, then its TTL only when the plan
		// asked for a different one. Leaving a null TTL alone keeps a set on the
		// zone default.
		$this->write('POST', $this->rrsetUrl($zone, $name, $type) . '/actions/set_records',
			array('records' => $records));
		if ($ttl !== null && $ttl !== $existing['ttl']) {
			$this->write('POST', $this->rrsetUrl($zone, $name, $type) . '/actions/change_ttl',
				array('ttl' => $ttl));
		}
	}

	protected function deleteRrset(string $zone, string $name, string $type): void {
		$this->write('DELETE', $this->rrsetUrl($zone, $name, $type));
	}

	// ------------------------------------------------------------------

	/**
	 * Issue a write and wait for the Action it starts. Hetzner applies DNS
	 * changes asynchronously; returning before the Action finishes would let the
	 * next read see the old set and the next write to it be refused as locked.
	 */
	private function write(string $method, string $url, ?array $json = null): void {
		$body = $this->request($method, $url, $json !== null ? array('json' => $json) : array());
		$action = is_array($body['action'] ?? null) ? $body['action'] : null;
		$polls = 0;
		while ($action !== null) {
			$status = (string)($action['status'] ?? 'success');
			if ($status === 'success') {
				return;
			}
			if ($status === 'error') {
				$error = (array)($action['error'] ?? array());
				throw new DnsProviderException('Hetzner could not apply the change: '
					. (string)($error['message'] ?? ($error['code'] ?? 'the action failed')) . '.');
			}
			if ($polls >= self::ACTION_MAX_POLLS || empty($action['id'])) {
				throw new DnsProviderException('Hetzner accepted the change but was still applying it after '
					. self::ACTION_MAX_POLLS . ' seconds. Wait a minute, then publish again — the difference '
					. 'will show whatever has not yet landed.');
			}
			$polls++;
			$this->pause(1);
			$next = $this->request('GET', self::API_BASE . 'zones/actions/' . rawurlencode((string)$action['id']));
			$action = is_array($next['action'] ?? null) ? $next['action'] : null;
		}
	}

	/** @return array<string,string> */
	private function zoneMap(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$zones = array();
		$page = 1;
		do {
			$body = $this->request('GET', self::API_BASE . 'zones?per_page=' . self::ZONES_PER_PAGE
				. '&page=' . $page);
			foreach ((array)($body['zones'] ?? array()) as $row) {
				$name = DnsRecord::normalizeName((string)($row['name'] ?? ''));
				if ($name === '' || empty($row['id'])) {
					continue;
				}
				$zones[$name] = (string)$row['id'];
				$assigned = array();
				foreach ((array)($row['authoritative_nameservers']['assigned'] ?? array()) as $ns) {
					$ns = DnsRecord::normalizeName((string)$ns);
					if ($ns !== '') {
						$assigned[] = $ns;
					}
				}
				$this->zone_ns[$name] = $assigned;
			}
			$page = self::nextPage($body, $page);
		} while ($page !== null);
		$this->zones = $zones;
		return $this->zones;
	}

	private function zoneId(string $zone): string {
		$zones = $this->zoneMap();
		$name = DnsRecord::normalizeName($zone);
		if (!isset($zones[$name])) {
			throw new DnsZoneNotFoundException('This Hetzner token can see no zone for ' . $zone
				. '. The token has to come from the Hetzner Console project that holds the zone.');
		}
		return $zones[$name];
	}

	private function zoneUrl(string $zone): string {
		return self::API_BASE . 'zones/' . rawurlencode($this->zoneId($zone));
	}

	private function rrsetUrl(string $zone, string $name, string $type): string {
		return $this->zoneUrl($zone) . '/rrsets/' . rawurlencode(self::relativeName($name, $zone, '@'))
			. '/' . rawurlencode(strtoupper($type));
	}

	/** The next page number from a listing's meta.pagination, or null on the last page. */
	private static function nextPage(array $body, int $page): ?int {
		$next = $body['meta']['pagination']['next_page'] ?? null;
		return (is_numeric($next) && (int)$next > $page) ? (int)$next : null;
	}

	protected function authHeaders(): array {
		return array('Authorization' => 'Bearer ' . $this->cred('api_token'));
	}

	/**
	 * Hetzner names every failure with a machine code beside its message; the
	 * ones an operator can act on are said in their terms.
	 */
	protected function translateError(RequestException $e, string $method, string $url, int $status): DnsProviderException {
		$code = '';
		if ($e->getResponse()) {
			$decoded = json_decode((string)$e->getResponse()->getBody(), true);
			$code = (string)($decoded['error']['code'] ?? '');
		}
		$reason = $this->errorBody($e);
		switch ($code) {
			case 'unauthorized':
				return new DnsProviderException('Hetzner refused the token (' . $status . '): ' . $reason
					. ' — it has to be a Hetzner Cloud API token, generated under Security / API tokens in the '
					. 'Hetzner Console project that holds the zone.', $status, $e);
			case 'token_readonly':
			case 'forbidden':
				return new DnsProviderException('Hetzner refused the change (' . $status . '): ' . $reason
					. ' — the token needs Read & Write permission. Generate a new one with Read & Write '
					. 'in the project that holds the zone.', $status, $e);
			case 'incorrect_zone_mode':
				return new DnsProviderException('This Hetzner zone is in secondary mode: its records are copied '
					. 'from another primary nameserver and cannot be written through Hetzner. Change them on '
					. 'the primary instead. (' . $reason . ')', $status, $e);
			case 'protected':
				return new DnsManagedRecordException('Hetzner record protection',
					'This record set is protected in the Hetzner Console; turn off its protection there, '
					. 'then publish again. (' . $reason . ')');
			case 'locked':
				return new DnsProviderException('Hetzner is still applying an earlier change to this zone. '
					. 'Wait a moment, then publish again. (' . $reason . ')', $status, $e);
		}
		return parent::translateError($e, $method, $url, $status);
	}
}

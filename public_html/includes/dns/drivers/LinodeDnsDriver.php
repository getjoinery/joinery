<?php
/**
 * LinodeDnsDriver - Linode (Akamai Cloud) DNS Manager, API v4.
 *
 * The reference driver and the deployment default. It is the compute driver's
 * HTTP plumbing on the same API base with the domains:read_write scope, and it
 * rides LinodeOAuthProvider so a publish is authorized by consent with no key
 * pasted into a form. Linode issues no refresh token and its access token lasts
 * two hours, so a Linode grant is ephemeral by construction — exactly the
 * property this subsystem wants everywhere.
 *
 * Linode is also the platform-authoritative host: it can create a zone, and its
 * nameserver set is published here so the publish box can detect delegation by
 * resolving the domain's live NS records.
 *
 * Vendor notes worth knowing:
 *  - Record names are relative to the domain; the apex is the empty string.
 *  - TXT targets are stored whole and split into 255-byte character-strings by
 *    Linode when serving, so the driver sends the raw value.
 *  - CAA is modelled as separate tag/target fields with an implicit flags of 0.
 *  - A parent (reseller) login reaches child accounts; a chosen child is
 *    exchanged for a scoped token at publish time and never recorded.
 *  - SRV carries its service and protocol as their own fields (name unused),
 *    and Linode prepends the underscore itself, so those labels are submitted
 *    bare and reassembled on read.
 *
 * @version 1.1 - SRV writes the service/protocol fields Linode requires
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverBase.php'));

use GuzzleHttp\Exception\RequestException;

class LinodeDnsDriver extends DnsDriverBase {

	const API_BASE = 'https://api.linode.com/v4/';

	/** @var array<string,int>|null Cached zone name => Linode domain id. */
	private $zones = null;

	/** @var bool Whether the child-account token exchange has already run. */
	private $account_switched = false;

	/** @var string Token actually used for API calls (parent's, or a child's). */
	private $effective_token = '';

	public static function getKey(): string { return 'linode'; }
	public static function getLabel(): string { return 'Linode DNS'; }

	public static function credentialMode(): string { return self::CREDENTIAL_OAUTH2; }
	public static function oauthProviderKey(): string { return 'linode'; }
	public static function oauthScopes(): array { return array('domains:read_write'); }
	public static function supportsZones(): bool { return true; }

	public static function nameservers(): array {
		return array('ns1.linode.com', 'ns2.linode.com', 'ns3.linode.com',
			'ns4.linode.com', 'ns5.linode.com');
	}

	public static function nameserverSuffixes(): array { return array('.linode.com'); }

	/** Linode stores a TXT target whole and chunks it on the wire. */
	public static function txtChunkingIsAutomatic(): bool { return true; }

	// ------------------------------------------------------------------
	// Zones
	// ------------------------------------------------------------------

	public function zoneFor(string $domain): ?string {
		$zones = $this->zoneMap();
		$id = self::matchZone($domain, array_map('strval', $zones));
		if ($id === null) {
			return null;
		}
		// The zone identifier the rest of the interface passes around is the
		// zone NAME — it is what an admin reads in the diff. The id is looked up
		// again from the cached map.
		foreach ($zones as $name => $zone_id) {
			if ((string)$zone_id === $id) {
				return $name;
			}
		}
		return null;
	}

	public function createZone(string $domain): string {
		$domain = DnsRecord::normalizeName($domain);
		$existing = $this->zoneFor($domain);
		if ($existing === $domain) {
			return $existing;   // idempotent: the zone is already here
		}
		$settings = Globalvars::get_instance();
		$soa_email = trim((string)$settings->get_setting('contact_email'));
		if ($soa_email === '') {
			$soa_email = 'postmaster@' . $domain;
		}
		$created = $this->request('POST', self::API_BASE . 'domains', array('json' => array(
			'domain'    => $domain,
			'type'      => 'master',
			'soa_email' => $soa_email,
		)));
		$this->zones = null;
		if (empty($created['id'])) {
			throw new DnsProviderException('Linode did not return a zone id for ' . $domain . '.');
		}
		return $domain;
	}

	public function deleteZone(string $zone): void {
		$this->request('DELETE', self::API_BASE . 'domains/' . rawurlencode((string)$this->zoneId($zone)));
		$this->zones = null;
	}

	// ------------------------------------------------------------------
	// Records
	// ------------------------------------------------------------------

	public function listRecords(string $zone): array {
		$zone_id = $this->zoneId($zone);
		$out = array();
		$page = 1;
		do {
			$body = $this->request('GET', self::API_BASE . 'domains/' . $zone_id . '/records?page=' . $page . '&page_size=500');
			foreach ((array)($body['data'] ?? array()) as $row) {
				$record = $this->toRecord($zone, $row);
				if ($record !== null) {
					$out[] = $record;
				}
			}
			$pages = (int)($body['pages'] ?? 1);
		} while (++$page <= $pages);
		return $out;
	}

	public function createRecord(string $zone, DnsRecord $record): void {
		$this->request('POST', self::API_BASE . 'domains/' . $this->zoneId($zone) . '/records',
			array('json' => $this->toApi($zone, $record)));
	}

	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot update ' . $desired->describe() . ': no Linode record id.');
		}
		$this->request('PUT', self::API_BASE . 'domains/' . $this->zoneId($zone) . '/records/'
			. rawurlencode($live->provider_id), array('json' => $this->toApi($zone, $desired)));
	}

	public function deleteRecord(string $zone, DnsRecord $live): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot delete ' . $live->describe() . ': no Linode record id.');
		}
		$this->request('DELETE', self::API_BASE . 'domains/' . $this->zoneId($zone) . '/records/'
			. rawurlencode($live->provider_id));
	}

	// ------------------------------------------------------------------
	// Accounts
	// ------------------------------------------------------------------

	/**
	 * The granting account, plus any child accounts a parent (reseller) login
	 * reaches. When there is only one, the publish box asks nothing.
	 */
	public function accounts(): array {
		$out = array();
		try {
			$account = $this->request('GET', self::API_BASE . 'account');
			$label = trim((string)($account['company'] ?? '')) ?: trim((string)($account['email'] ?? ''));
			$out[] = array('id' => '', 'label' => $label !== '' ? $label : 'Linode account');
		} catch (DnsProviderException $e) {
			$out[] = array('id' => '', 'label' => 'Linode account');
		}
		try {
			$children = $this->request('GET', self::API_BASE . 'account/child-accounts');
			foreach ((array)($children['data'] ?? array()) as $child) {
				$euuid = trim((string)($child['euuid'] ?? ''));
				if ($euuid === '') {
					continue;
				}
				$out[] = array('id' => $euuid,
					'label' => trim((string)($child['company'] ?? $euuid)) ?: $euuid);
			}
		} catch (DnsProviderException $e) {
			// Not a parent account, or the scope does not include child reads —
			// a single-account grant, which is the common case.
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/** @return array<string,int> zone name => Linode domain id. */
	private function zoneMap(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$page = 1;
		do {
			$body = $this->request('GET', self::API_BASE . 'domains?page=' . $page . '&page_size=500');
			foreach ((array)($body['data'] ?? array()) as $row) {
				$name = DnsRecord::normalizeName((string)($row['domain'] ?? ''));
				if ($name !== '' && !empty($row['id'])) {
					$this->zones[$name] = (int)$row['id'];
				}
			}
			$pages = (int)($body['pages'] ?? 1);
		} while (++$page <= $pages);
		return $this->zones;
	}

	private function zoneId(string $zone): int {
		$zones = $this->zoneMap();
		$name = DnsRecord::normalizeName($zone);
		if (!isset($zones[$name])) {
			throw new DnsZoneNotFoundException('This Linode account holds no zone for ' . $zone . '.');
		}
		return $zones[$name];
	}

	/** Normalize one Linode record row, or null for a type outside the vocabulary. */
	private function toRecord(string $zone, array $row): ?DnsRecord {
		$type = strtoupper((string)($row['type'] ?? ''));
		if (!in_array($type, DnsRecord::TYPES, true)) {
			return null;
		}
		$target = (string)($row['target'] ?? '');
		if ($type === DnsRecord::TYPE_CAA) {
			$target = self::formatCaa(0, (string)($row['tag'] ?? 'issue'), $target);
		}
		if ($type === DnsRecord::TYPE_SRV) {
			$target = self::formatSrv((int)($row['priority'] ?? 0), (int)($row['weight'] ?? 0),
				(int)($row['port'] ?? 0), $target);
		}
		$ttl = (int)($row['ttl_sec'] ?? 0);
		// Linode leaves an SRV row's name empty and carries the labels in its own
		// service/protocol fields, so the whole name is reassembled from those or
		// every SRV record would read back as the bare zone apex and never match
		// its plan.
		$name = $type === DnsRecord::TYPE_SRV
			? self::srvNameFromParts((string)($row['service'] ?? ''), (string)($row['protocol'] ?? ''),
				(string)($row['name'] ?? ''), $zone)
			: self::absoluteName((string)($row['name'] ?? ''), $zone);
		$record = new DnsRecord(
			$type,
			$name,
			$target,
			$ttl > 0 ? $ttl : null,
			$type === DnsRecord::TYPE_MX ? (int)($row['priority'] ?? 0) : null
		);
		$record->provider_id = (string)($row['id'] ?? '');
		return $record;
	}

	/**
	 * The service and protocol labels Linode wants as its own fields, taken from
	 * an SRV record's name and stripped of the leading underscore Linode prepends
	 * itself: `_joinery._tcp.example.com` becomes `['joinery', 'tcp']`.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function srvSubmitLabels(string $name): array {
		$parts = explode('.', DnsRecord::normalizeName($name));
		$service  = isset($parts[0]) ? ltrim($parts[0], '_') : '';
		$protocol = isset($parts[1]) ? ltrim($parts[1], '_') : '';
		return array($service, $protocol);
	}

	/** The request body for a create or update. */
	private function toApi(string $zone, DnsRecord $record): array {
		$body = array(
			'type' => $record->type,
			'name' => self::relativeName($record->name, $zone, ''),
		);
		if ($record->type === DnsRecord::TYPE_CAA) {
			$caa = self::parseCaa($record->value);
			$body['tag'] = $caa['tag'];
			$body['target'] = $caa['value'];
		} elseif ($record->type === DnsRecord::TYPE_SRV) {
			// Linode models SRV as service + protocol fields, prepends the
			// underscore itself, and IGNORES name — so the service and protocol
			// labels move out of the name (submitted without their leading
			// underscore) and name is left empty. Omitting them writes a record
			// Linode rejects or files at the wrong service, and the capability
			// record never resolves.
			$srv = self::parseSrv($record->value);
			list($service, $protocol) = self::srvSubmitLabels($record->name);
			$body['name']     = '';
			$body['service']  = $service;
			$body['protocol'] = $protocol;
			$body['target']   = $srv['target'];
			$body['priority'] = $srv['priority'];
			$body['weight']   = $srv['weight'];
			$body['port']     = $srv['port'];
		} elseif ($record->type === DnsRecord::TYPE_TXT) {
			$body['target'] = $this->txtWireValue($record->value);
		} else {
			$body['target'] = $record->value;
		}
		if ($record->type === DnsRecord::TYPE_MX) {
			$body['priority'] = $record->priority !== null ? (int)$record->priority : 10;
		}
		if ($record->ttl !== null) {
			$body['ttl_sec'] = (int)$record->ttl;
		}
		return $body;
	}

	protected function authHeaders(): array {
		return array('Authorization' => 'Bearer ' . $this->token());
	}

	/**
	 * The token API calls actually use. When the publish chose a child account,
	 * the parent grant is exchanged once for that child's scoped token — held in
	 * memory for this request only, like every other DNS credential.
	 */
	private function token(): string {
		if ($this->effective_token !== '') {
			return $this->effective_token;
		}
		$this->effective_token = $this->accessToken();
		if ($this->account_id !== '' && !$this->account_switched) {
			$this->account_switched = true;   // never retry a failed exchange in a loop
			try {
				$exchanged = $this->request('POST', self::API_BASE . 'account/child-accounts/'
					. rawurlencode($this->account_id) . '/token');
				if (!empty($exchanged['token'])) {
					$this->effective_token = (string)$exchanged['token'];
				}
			} catch (DnsProviderException $e) {
				throw new DnsProviderException('Could not act as the chosen Linode child account: '
					. $e->getMessage(), 0, $e);
			}
		}
		return $this->effective_token;
	}

	protected function translateError(RequestException $e, string $method, string $url, int $status): DnsProviderException {
		$reason = $this->errorBody($e);
		if ($status === 401 || $status === 403) {
			return new DnsProviderException('Linode refused the grant (' . $status . '): ' . $reason
				. ' — Linode access tokens last two hours, so authorize again and re-publish.', $status, $e);
		}
		return parent::translateError($e, $method, $url, $status);
	}
}

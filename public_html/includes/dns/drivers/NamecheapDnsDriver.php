<?php
/**
 * NamecheapDnsDriver - Namecheap XML API.
 *
 * No OAuth2; Namecheap authenticates with an API key plus the account username,
 * and it also requires the calling server's IP to be allowlisted in the
 * Namecheap account. That allowlist is a real deployment constraint rather than
 * a detail, so it is declared as a prerequisite and surfaced in the publish box
 * instead of failing as an opaque API error.
 *
 * The quirk that shapes this whole driver: **Namecheap has no per-record write.**
 * `setHosts` replaces the domain's entire host list, so writing one record means
 * reading every record, changing one entry and sending them all back. Anything
 * dropped from that list is deleted. The driver therefore carries the raw host
 * rows through untouched — including record types outside the platform's
 * vocabulary, like URL redirects and MXE — so a publish can never quietly
 * destroy something it does not understand.
 *
 * Namecheap only serves DNS for domains registered with it and left on
 * BasicDNS, and it has no concept of a delegated subdomain zone: the zone is
 * always the registered domain.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverBase.php'));

class NamecheapDnsDriver extends DnsDriverBase {

	const API_BASE = 'https://api.namecheap.com/xml.response';

	/** @var string[]|null Registered domain names. */
	private $zones = null;
	/** @var array<string,array> Raw host rows per zone, as last read. */
	private $hosts = array();
	/** @var array<string,string> The zone's EmailType, preserved across writes. */
	private $email_type = array();

	public static function getKey(): string { return 'namecheap'; }
	public static function getLabel(): string { return 'Namecheap'; }

	public static function nameservers(): array {
		return array('dns1.registrar-servers.com', 'dns2.registrar-servers.com');
	}

	/** Namecheap's own BasicDNS answers on registrar-servers.com. */
	public static function nameserverSuffixes(): array { return array('registrar-servers.com'); }

	public static function prerequisiteNote(): string {
		return 'Namecheap only accepts API calls from allowlisted addresses. Add this server\'s public IP to '
			. 'Profile · Tools · API Access in your Namecheap account, and make sure the domain is on BasicDNS.';
	}

	public static function credentialFields(): array {
		return array(
			'api_user' => array(
				'label'  => 'Namecheap username',
				'help'   => 'The account that owns the API key.',
				'secret' => false,
			),
			'api_key' => array(
				'label'  => 'Namecheap API key',
				'help'   => 'From Profile · Tools · API Access. Used for this one publish and never stored.',
				'secret' => true,
			),
			'client_ip' => array(
				'label'  => 'Allowlisted IP',
				'help'   => 'The address Namecheap expects calls from. Leave blank to use this server\'s '
					. 'detected public IP.',
				'secret' => false,
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
		foreach ($this->rawHosts($zone) as $index => $host) {
			$type = strtoupper((string)($host['Type'] ?? ''));
			if (!in_array($type, DnsRecord::TYPES, true)) {
				continue;   // URL / URL301 / FRAME / MXE / NS — carried, never compared
			}
			$ttl = (int)($host['TTL'] ?? 0);
			$record = new DnsRecord(
				$type,
				self::absoluteName((string)($host['Name'] ?? '@'), $zone),
				(string)($host['Address'] ?? ''),
				$ttl > 0 ? $ttl : null,
				$type === DnsRecord::TYPE_MX ? (int)($host['MXPref'] ?? 10) : null
			);
			// There is no stable record id; the position in the host list is what
			// the driver needs to change the right row.
			$record->provider_id = (string)$index;
			$out[] = $record;
		}
		return $out;
	}

	public function createRecord(string $zone, DnsRecord $record): void {
		$zone = DnsRecord::normalizeName($zone);
		$hosts = $this->rawHosts($zone);
		$hosts[] = $this->toHost($zone, $record);
		$this->setHosts($zone, $hosts);
	}

	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		$zone = DnsRecord::normalizeName($zone);
		$hosts = $this->rawHosts($zone);
		$index = $live->provider_id !== '' ? (int)$live->provider_id : -1;
		if (!isset($hosts[$index])) {
			throw new DnsProviderException('Cannot update ' . $desired->describe()
				. ': the record is no longer in the Namecheap host list.');
		}
		$hosts[$index] = $this->toHost($zone, $desired);
		$this->setHosts($zone, $hosts);
	}

	public function deleteRecord(string $zone, DnsRecord $live): void {
		$zone = DnsRecord::normalizeName($zone);
		$hosts = $this->rawHosts($zone);
		$index = $live->provider_id !== '' ? (int)$live->provider_id : -1;
		if (!isset($hosts[$index])) {
			return;
		}
		unset($hosts[$index]);
		$this->setHosts($zone, array_values($hosts));
	}

	// ------------------------------------------------------------------

	/** @return string[] */
	private function zoneNames(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$page = 1;
		do {
			$xml = $this->call('namecheap.domains.getList', array('Page' => $page, 'PageSize' => 100));
			$result = $xml->CommandResponse->DomainGetListResult ?? null;
			$count = 0;
			if ($result !== null) {
				foreach ($result->Domain as $domain) {
					$name = DnsRecord::normalizeName((string)$domain['Name']);
					if ($name !== '') {
						$this->zones[] = $name;
					}
					$count++;
				}
			}
			$total = (int)($xml->CommandResponse->Paging->TotalItems ?? 0);
			$page++;
		} while ($count > 0 && count($this->zones) < $total);
		return $this->zones;
	}

	/** The zone's whole host list, exactly as Namecheap holds it. */
	private function rawHosts(string $zone): array {
		if (isset($this->hosts[$zone])) {
			return $this->hosts[$zone];
		}
		list($sld, $tld) = $this->splitZone($zone);
		$xml = $this->call('namecheap.domains.dns.getHosts', array('SLD' => $sld, 'TLD' => $tld));
		$result = $xml->CommandResponse->DomainDNSGetHostsResult ?? null;
		$rows = array();
		if ($result !== null) {
			$this->email_type[$zone] = (string)$result['EmailType'];
			foreach ($result->host as $host) {
				$rows[] = array(
					'Name'    => (string)$host['Name'],
					'Type'    => strtoupper((string)$host['Type']),
					'Address' => (string)$host['Address'],
					'MXPref'  => (string)$host['MXPref'],
					'TTL'     => (string)$host['TTL'],
				);
			}
		}
		$this->hosts[$zone] = $rows;
		return $rows;
	}

	/** Write the whole host list back and refresh the cache. */
	private function setHosts(string $zone, array $hosts): void {
		list($sld, $tld) = $this->splitZone($zone);
		$params = array('SLD' => $sld, 'TLD' => $tld);
		if (!empty($this->email_type[$zone])) {
			// Omitting EmailType resets the domain's email routing setting.
			$params['EmailType'] = $this->email_type[$zone];
		}
		$i = 1;
		foreach ($hosts as $host) {
			$params['HostName' . $i]   = $host['Name'] !== '' ? $host['Name'] : '@';
			$params['RecordType' . $i] = $host['Type'];
			$params['Address' . $i]    = $host['Address'];
			if ($host['Type'] === DnsRecord::TYPE_MX) {
				$params['MXPref' . $i] = $host['MXPref'] !== '' ? $host['MXPref'] : '10';
			}
			if ($host['TTL'] !== '') {
				$params['TTL' . $i] = $host['TTL'];
			}
			$i++;
		}
		$this->call('namecheap.domains.dns.setHosts', $params, 'POST');
		$this->hosts[$zone] = $hosts;
	}

	/** One planned record as a raw Namecheap host row. */
	private function toHost(string $zone, DnsRecord $record): array {
		return array(
			'Name'    => self::relativeName($record->name, $zone, '@'),
			'Type'    => $record->type,
			'Address' => $record->type === DnsRecord::TYPE_TXT
				? $this->txtWireValue($record->value) : $record->value,
			'MXPref'  => $record->type === DnsRecord::TYPE_MX
				? (string)($record->priority !== null ? (int)$record->priority : 10) : '',
			// Namecheap's minimum TTL is 60; blank means leave it at the default.
			'TTL'     => $record->ttl !== null ? (string)max(60, (int)$record->ttl) : '',
		);
	}

	/** Namecheap addresses a zone as SLD + TLD, where the TLD may be multi-label. */
	private function splitZone(string $zone): array {
		$zone = DnsRecord::normalizeName($zone);
		$dot = strpos($zone, '.');
		if ($dot === false) {
			throw new DnsProviderException('"' . $zone . '" is not a registrable Namecheap domain.');
		}
		return array(substr($zone, 0, $dot), substr($zone, $dot + 1));
	}

	/**
	 * Issue one API call and return the parsed XML. Namecheap answers HTTP 200
	 * with Status="ERROR" on failure, so success is read from the envelope.
	 */
	private function call(string $command, array $params = array(), string $method = 'GET'): SimpleXMLElement {
		$query = array_merge(array(
			'ApiUser'  => $this->cred('api_user'),
			'ApiKey'   => $this->cred('api_key'),
			'UserName' => $this->cred('api_user'),
			'ClientIp' => $this->clientIp(),
			'Command'  => $command,
		), $params);

		$options = ($method === 'POST')
			? array('form_params' => $query)
			: array('query' => $query);

		try {
			$response = $this->http->request($method, self::API_BASE, $options);
		} catch (Throwable $e) {
			throw new DnsProviderException('Namecheap request failed: ' . $e->getMessage(), 0, $e);
		}

		$previous = libxml_use_internal_errors(true);
		$xml = simplexml_load_string((string)$response->getBody());
		libxml_use_internal_errors($previous);
		if ($xml === false) {
			throw new DnsProviderException('Namecheap returned a response that could not be parsed as XML.');
		}
		if (strtoupper((string)$xml['Status']) !== 'OK') {
			$reasons = array();
			foreach ($xml->Errors->Error ?? array() as $error) {
				$reasons[] = trim((string)$error);
			}
			$reason = $reasons ? implode('; ', $reasons) : 'no reason given';
			if (stripos($reason, 'ip') !== false && stripos($reason, 'whitelist') !== false) {
				$reason .= ' — add ' . $this->clientIp() . ' to Profile · Tools · API Access in Namecheap.';
			}
			throw new DnsProviderException('Namecheap refused ' . $command . ': ' . $reason);
		}
		return $xml;
	}

	/** The address Namecheap must have allowlisted: the operator's, or detected. */
	private function clientIp(): string {
		$configured = trim($this->cred('client_ip'));
		if ($configured !== '') {
			return $configured;
		}
		$socket = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
		if ($socket) {
			$name = @stream_socket_get_name($socket, false);
			@fclose($socket);
			if ($name && strrpos($name, ':') !== false) {
				return substr($name, 0, strrpos($name, ':'));
			}
		}
		return '';
	}
}

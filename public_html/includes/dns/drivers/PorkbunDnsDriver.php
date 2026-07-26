<?php
/**
 * PorkbunDnsDriver - Porkbun DNS, API v3.
 *
 * No OAuth2; Porkbun authenticates with an API key plus a secret key, both
 * carried in the JSON body of every call. They are supplied at the publish
 * moment and discarded when the request returns.
 *
 * Vendor prerequisite worth surfacing: API access is off per-domain until it is
 * switched on in the Porkbun domain management page, so a correct key still
 * cannot see a zone until that toggle is set.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverBase.php'));

class PorkbunDnsDriver extends DnsDriverBase {

	const API_BASE = 'https://api.porkbun.com/api/json/v3/';

	/** @var string[]|null */
	private $zones = null;

	public static function getKey(): string { return 'porkbun'; }
	public static function getLabel(): string { return 'Porkbun'; }

	public static function nameservers(): array {
		return array('curitiba.ns.porkbun.com', 'fortaleza.ns.porkbun.com',
			'maceio.ns.porkbun.com', 'salvador.ns.porkbun.com');
	}

	public static function nameserverSuffixes(): array { return array('ns.porkbun.com'); }

	public static function prerequisiteNote(): string {
		return 'Porkbun keeps API access off per domain. Turn on "API Access" for this domain in the '
			. 'Porkbun domain management page, or the key will see no zone here.';
	}

	public static function credentialFields(): array {
		return array(
			'api_key' => array(
				'label'  => 'Porkbun API key',
				'help'   => 'From Account · API Access. Used for this one publish and never stored.',
				'secret' => true,
			),
			'secret_key' => array(
				'label'  => 'Porkbun secret key',
				'help'   => 'Issued alongside the API key. Used for this one publish and never stored.',
				'secret' => true,
			),
		);
	}

	public static function credentialGuide(): ?array {
		return array(
			'title'     => 'Create a Porkbun API key',
			'url'       => 'https://porkbun.com/account/api',
			'url_label' => 'Open Porkbun API access',
			'steps'     => array(
				'Sign in, open Account, then API Access, name the key and choose Create API Key.',
				'Copy both the API key and the secret key before leaving the page — the secret is '
					. 'shown once.',
				'Open Account, then Domain Management, choose Details on this domain, and switch '
					. 'API Access to Enabled. Porkbun keeps it off per domain.',
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
		$body = $this->call('dns/retrieve/' . rawurlencode($zone));
		$out = array();
		foreach ((array)($body['records'] ?? array()) as $row) {
			$type = strtoupper((string)($row['type'] ?? ''));
			if (!in_array($type, DnsRecord::TYPES, true)) {
				continue;
			}
			$ttl = (int)($row['ttl'] ?? 0);
			$record = new DnsRecord(
				$type,
				(string)($row['name'] ?? $zone),      // Porkbun returns the FQDN
				(string)($row['content'] ?? ''),
				$ttl > 0 ? $ttl : null,
				$type === DnsRecord::TYPE_MX ? (int)($row['prio'] ?? 0) : null
			);
			$record->provider_id = (string)($row['id'] ?? '');
			$out[] = $record;
		}
		return $out;
	}

	public function createRecord(string $zone, DnsRecord $record): void {
		$this->call('dns/create/' . rawurlencode(DnsRecord::normalizeName($zone)), $this->toApi($zone, $record));
	}

	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot update ' . $desired->describe() . ': no Porkbun record id.');
		}
		$this->call('dns/edit/' . rawurlencode(DnsRecord::normalizeName($zone)) . '/'
			. rawurlencode($live->provider_id), $this->toApi($zone, $desired));
	}

	public function deleteRecord(string $zone, DnsRecord $live): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot delete ' . $live->describe() . ': no Porkbun record id.');
		}
		$this->call('dns/delete/' . rawurlencode(DnsRecord::normalizeName($zone)) . '/'
			. rawurlencode($live->provider_id));
	}

	// ------------------------------------------------------------------

	/** @return string[] */
	private function zoneNames(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$body = $this->call('domain/listAll');
		foreach ((array)($body['domains'] ?? array()) as $row) {
			$name = DnsRecord::normalizeName((string)($row['domain'] ?? ''));
			if ($name !== '') {
				$this->zones[] = $name;
			}
		}
		return $this->zones;
	}

	private function toApi(string $zone, DnsRecord $record): array {
		$body = array(
			'type'    => $record->type,
			'name'    => self::relativeName($record->name, $zone, ''),
			'content' => $record->type === DnsRecord::TYPE_TXT
				? $this->txtWireValue($record->value) : $record->value,
		);
		if ($record->type === DnsRecord::TYPE_MX) {
			$body['prio'] = (string)($record->priority !== null ? (int)$record->priority : 10);
		}
		if ($record->ttl !== null) {
			$body['ttl'] = (string)max(600, (int)$record->ttl);   // Porkbun floors TTL at 600
		}
		return $body;
	}

	/**
	 * Every Porkbun call is a POST whose body carries the credential. The API
	 * answers 200 with {"status":"ERROR"} on failure, so success is checked from
	 * the body rather than the HTTP status.
	 */
	private function call(string $path, array $body = array()): array {
		$body['apikey'] = $this->cred('api_key');
		$body['secretapikey'] = $this->cred('secret_key');
		$response = $this->request('POST', self::API_BASE . $path, array('json' => $body));
		if (strtoupper((string)($response['status'] ?? '')) !== 'SUCCESS') {
			throw new DnsProviderException('Porkbun refused the request: '
				. ((string)($response['message'] ?? 'no reason given')));
		}
		return $response;
	}
}

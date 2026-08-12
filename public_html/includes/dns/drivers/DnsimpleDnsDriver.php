<?php
/**
 * DnsimpleDnsDriver - DNSimple, API v2.
 *
 * Authorized by OAuth2 consent (DnsimpleOAuthProvider). Every DNSimple API path
 * is scoped to an account id, which the driver reads from /v2/whoami rather
 * than asking anyone to record it; a grant that reaches several accounts
 * surfaces them through accounts() so the publish box asks which, once.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverBase.php'));

class DnsimpleDnsDriver extends DnsDriverBase {

	const API_BASE = 'https://api.dnsimple.com/v2/';

	/** @var string|null Resolved account id. */
	private $account = null;
	/** @var string[]|null Cached zone names. */
	private $zones = null;

	public static function getKey(): string { return 'dnsimple'; }
	public static function getLabel(): string { return 'DNSimple'; }


	public static function credentialMode(): string { return self::CREDENTIAL_OAUTH2; }
	public static function oauthProviderKey(): string { return 'dnsimple'; }
	public static function oauthScopes(): array { return array(); }

	public static function nameservers(): array {
		return array('ns1.dnsimple.com', 'ns2.dnsimple-edge.net',
			'ns3.dnsimple.com', 'ns4.dnsimple-edge.org');
	}

	public static function nameserverSuffixes(): array {
		return array('dnsimple.com', 'dnsimple-edge.');
	}

	public function accounts(): array {
		$out = array();
		$body = $this->request('GET', self::API_BASE . 'accounts');
		foreach ((array)($body['data'] ?? array()) as $row) {
			$id = (string)($row['id'] ?? '');
			if ($id !== '') {
				$out[] = array('id' => $id, 'label' => (string)($row['email'] ?? $id));
			}
		}
		return $out ?: parent::accounts();
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
		$page = 1;
		do {
			$body = $this->request('GET', $this->accountBase() . 'zones/' . rawurlencode($zone)
				. '/records?per_page=100&page=' . $page);
			foreach ((array)($body['data'] ?? array()) as $row) {
				$record = $this->toRecord($zone, $row);
				if ($record !== null) {
					$out[] = $record;
				}
			}
			$pages = (int)($body['pagination']['total_pages'] ?? 1);
		} while (++$page <= $pages);
		return $out;
	}

	public function createRecord(string $zone, DnsRecord $record): void {
		$this->request('POST', $this->accountBase() . 'zones/' . rawurlencode(DnsRecord::normalizeName($zone))
			. '/records', array('json' => $this->toApi($zone, $record)));
	}

	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot update ' . $desired->describe() . ': no DNSimple record id.');
		}
		$this->request('PATCH', $this->accountBase() . 'zones/' . rawurlencode(DnsRecord::normalizeName($zone))
			. '/records/' . rawurlencode($live->provider_id), array('json' => $this->toApi($zone, $desired)));
	}

	public function deleteRecord(string $zone, DnsRecord $live): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot delete ' . $live->describe() . ': no DNSimple record id.');
		}
		$this->request('DELETE', $this->accountBase() . 'zones/' . rawurlencode(DnsRecord::normalizeName($zone))
			. '/records/' . rawurlencode($live->provider_id));
	}

	// ------------------------------------------------------------------

	/** The account-scoped API prefix, resolved once from whoami. */
	private function accountBase(): string {
		if ($this->account === null) {
			if ($this->account_id !== '') {
				$this->account = $this->account_id;
			} else {
				$body = $this->request('GET', self::API_BASE . 'whoami');
				$this->account = (string)($body['data']['account']['id'] ?? '');
			}
			if ($this->account === '') {
				throw new DnsProviderException('This DNSimple grant reaches no account — it may be a user token '
					. 'without an account context.');
			}
		}
		return self::API_BASE . rawurlencode($this->account) . '/';
	}

	/** @return string[] */
	private function zoneNames(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$page = 1;
		do {
			$body = $this->request('GET', $this->accountBase() . 'zones?per_page=100&page=' . $page);
			foreach ((array)($body['data'] ?? array()) as $row) {
				$name = DnsRecord::normalizeName((string)($row['name'] ?? ''));
				if ($name !== '') {
					$this->zones[] = $name;
				}
			}
			$pages = (int)($body['pagination']['total_pages'] ?? 1);
		} while (++$page <= $pages);
		return $this->zones;
	}

	private function toRecord(string $zone, array $row): ?DnsRecord {
		$type = strtoupper((string)($row['type'] ?? ''));
		if (!in_array($type, DnsRecord::TYPES, true)) {
			return null;
		}
		$ttl = (int)($row['ttl'] ?? 0);
		$value = (string)($row['content'] ?? '');
		if ($type === DnsRecord::TYPE_SRV) {
			// SRV is modelled exactly as MX here: the priority in its own numeric
			// field and "weight port target" in the content string. Rebuild the
			// canonical RDATA so the plan compares one value.
			$value = self::srvFromContent($value, (int)($row['priority'] ?? 0));
		}
		$record = new DnsRecord(
			$type,
			self::absoluteName((string)($row['name'] ?? ''), $zone),
			$value,
			$ttl > 0 ? $ttl : null,
			$type === DnsRecord::TYPE_MX ? (int)($row['priority'] ?? 0) : null
		);
		$record->provider_id = (string)($row['id'] ?? '');
		return $record;
	}

	private function toApi(string $zone, DnsRecord $record): array {
		$body = array(
			'type'    => $record->type,
			'name'    => self::relativeName($record->name, $zone, ''),
			'content' => $record->type === DnsRecord::TYPE_TXT
				? $this->txtWireValue($record->value) : $record->value,
		);
		if ($record->type === DnsRecord::TYPE_MX) {
			$body['priority'] = $record->priority !== null ? (int)$record->priority : 10;
		}
		if ($record->type === DnsRecord::TYPE_SRV) {
			$srv = self::parseSrv($record->value);
			$body['content']  = self::srvContent($record->value);
			$body['priority'] = $srv['priority'];
		}
		if ($record->ttl !== null) {
			$body['ttl'] = (int)$record->ttl;
		}
		return $body;
	}

	protected function authHeaders(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->accessToken(),
			'Content-Type'  => 'application/json',
		);
	}
}

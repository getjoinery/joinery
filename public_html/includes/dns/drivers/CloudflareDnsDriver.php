<?php
/**
 * CloudflareDnsDriver - Cloudflare DNS, client API v4.
 *
 * Cloudflare has no OAuth2 for its DNS API, so it takes a scoped API token —
 * Zone / DNS / Edit, narrowed to the one zone where the account allows it —
 * supplied at the publish moment and discarded when the request returns.
 *
 * Two Cloudflare quirks matter more than anything else here:
 *
 *  - **The orange cloud.** Creating an A or CNAME through the API applies the
 *    zone's default proxy setting. A proxied mail host or node record makes the
 *    world resolve Cloudflare's address instead of the server's — breaking mail
 *    and hiding the real address from the SSL gate waiting on it. The write
 *    succeeds and the wrong thing resolves, which is exactly the silent failure
 *    this subsystem exists to end. Every record this driver writes is forced to
 *    DNS-only; proxying is not something a plan can opt into.
 *  - **Email Routing owns MX** (and its own DKIM record). Cloudflare refuses
 *    writes to them while the feature is on. That refusal is reported by name —
 *    disable Email Routing in the Cloudflare dashboard — rather than as a
 *    generic API failure.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverBase.php'));

use GuzzleHttp\Exception\RequestException;

class CloudflareDnsDriver extends DnsDriverBase {

	const API_BASE = 'https://api.cloudflare.com/client/v4/';

	/** @var array<string,string>|null zone name => Cloudflare zone id. */
	private $zones = null;

	public static function getKey(): string { return 'cloudflare'; }
	public static function getLabel(): string { return 'Cloudflare'; }

	/** Cloudflare assigns two per-zone names, e.g. chuck.ns.cloudflare.com. */
	public static function nameserverSuffixes(): array { return array('ns.cloudflare.com'); }

	public static function credentialFields(): array {
		return array(
			'api_token' => array(
				'label'  => 'Cloudflare API token',
				'help'   => 'Create a token with the Zone · DNS · Edit permission, scoped to this zone if you can. '
					. 'It is used for this one publish and never stored.',
				'secret' => true,
			),
		);
	}

	public static function credentialGuide(): ?array {
		return array(
			'title'     => 'Create a Cloudflare API token',
			'url'       => 'https://dash.cloudflare.com/profile/api-tokens',
			'url_label' => 'Open Cloudflare API tokens',
			'steps'     => array(
				'Sign in to Cloudflare and open My Profile, then API Tokens.',
				'Choose Create Token and use the "Edit zone DNS" template.',
				'Under Zone Resources pick Include, Specific zone, and this domain.',
				'Create the token and copy it — it is shown once.',
				'If you restrict the token by client IP, list every address this server sends from, '
					. 'IPv6 included — it may reach Cloudflare from either family.',
			),
			'caution'   => 'Not the Global API Key sitting next to it — that is a different credential '
				. 'and Cloudflare will refuse the write.',
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

	public function listRecords(string $zone): array {
		$zone_id = $this->zoneId($zone);
		$out = array();
		$page = 1;
		do {
			$body = $this->request('GET', self::API_BASE . 'zones/' . $zone_id
				. '/dns_records?per_page=100&page=' . $page);
			foreach ((array)($body['result'] ?? array()) as $row) {
				$record = $this->toRecord($row);
				if ($record !== null) {
					$out[] = $record;
				}
			}
			$info = (array)($body['result_info'] ?? array());
			$pages = (int)($info['total_pages'] ?? 1);
		} while (++$page <= $pages);
		return $out;
	}

	public function createRecord(string $zone, DnsRecord $record): void {
		$this->request('POST', self::API_BASE . 'zones/' . $this->zoneId($zone) . '/dns_records',
			array('json' => $this->toApi($record)));
	}

	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot update ' . $desired->describe() . ': no Cloudflare record id.');
		}
		$this->request('PUT', self::API_BASE . 'zones/' . $this->zoneId($zone) . '/dns_records/'
			. rawurlencode($live->provider_id), array('json' => $this->toApi($desired)));
	}

	public function deleteRecord(string $zone, DnsRecord $live): void {
		if ($live->provider_id === '') {
			throw new DnsProviderException('Cannot delete ' . $live->describe() . ': no Cloudflare record id.');
		}
		$this->request('DELETE', self::API_BASE . 'zones/' . $this->zoneId($zone) . '/dns_records/'
			. rawurlencode($live->provider_id));
	}

	// ------------------------------------------------------------------

	/** @return array<string,string> */
	private function zoneMap(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$page = 1;
		do {
			$body = $this->request('GET', self::API_BASE . 'zones?per_page=50&page=' . $page);
			foreach ((array)($body['result'] ?? array()) as $row) {
				$name = DnsRecord::normalizeName((string)($row['name'] ?? ''));
				if ($name !== '' && !empty($row['id'])) {
					$this->zones[$name] = (string)$row['id'];
				}
			}
			$info = (array)($body['result_info'] ?? array());
			$pages = (int)($info['total_pages'] ?? 1);
		} while (++$page <= $pages);
		return $this->zones;
	}

	private function zoneId(string $zone): string {
		$zones = $this->zoneMap();
		$name = DnsRecord::normalizeName($zone);
		if (!isset($zones[$name])) {
			throw new DnsZoneNotFoundException('This Cloudflare token can see no zone for ' . $zone . '.');
		}
		return $zones[$name];
	}

	private function toRecord(array $row): ?DnsRecord {
		$type = strtoupper((string)($row['type'] ?? ''));
		if (!in_array($type, DnsRecord::TYPES, true)) {
			return null;
		}
		$value = (string)($row['content'] ?? '');
		if ($type === DnsRecord::TYPE_CAA && !empty($row['data'])) {
			$data = (array)$row['data'];
			$value = self::formatCaa((int)($data['flags'] ?? 0),
				(string)($data['tag'] ?? 'issue'), (string)($data['value'] ?? ''));
		}
		if ($type === DnsRecord::TYPE_SRV && !empty($row['data'])) {
			// Cloudflare models SRV as fields; the platform compares one RDATA
			// string, so rebuild it here rather than trusting the rendered
			// content, whose spelling varies.
			$data = (array)$row['data'];
			$value = self::formatSrv((int)($data['priority'] ?? 0), (int)($data['weight'] ?? 0),
				(int)($data['port'] ?? 0), (string)($data['target'] ?? ''));
		}
		$ttl = (int)($row['ttl'] ?? 0);
		$record = new DnsRecord(
			$type,
			(string)($row['name'] ?? ''),
			$value,
			// Cloudflare spells "automatic" as TTL 1, which is a provider
			// default, not a value the plan can have asked for.
			($ttl > 1) ? $ttl : null,
			$type === DnsRecord::TYPE_MX ? (int)($row['priority'] ?? 0) : null
		);
		$record->provider_id = (string)($row['id'] ?? '');
		return $record;
	}

	private function toApi(DnsRecord $record): array {
		$body = array(
			'type' => $record->type,
			'name' => $record->name,
			'ttl'  => $record->ttl !== null ? (int)$record->ttl : 1,   // 1 = automatic
		);
		if ($record->type === DnsRecord::TYPE_CAA) {
			$caa = self::parseCaa($record->value);
			$body['data'] = array('flags' => $caa['flags'], 'tag' => $caa['tag'], 'value' => $caa['value']);
		} elseif ($record->type === DnsRecord::TYPE_SRV) {
			$srv = self::parseSrv($record->value);
			$body['data'] = array('priority' => $srv['priority'], 'weight' => $srv['weight'],
				'port' => $srv['port'], 'target' => $srv['target']);
		} elseif ($record->type === DnsRecord::TYPE_TXT) {
			$body['content'] = $this->txtWireValue($record->value);
		} else {
			$body['content'] = $record->value;
		}
		if ($record->type === DnsRecord::TYPE_MX) {
			$body['priority'] = $record->priority !== null ? (int)$record->priority : 10;
		}
		// The orange cloud is never turned on by a publish. An address record
		// this platform writes exists so the world can reach the server itself.
		if (in_array($record->type, array(DnsRecord::TYPE_A, DnsRecord::TYPE_AAAA, DnsRecord::TYPE_CNAME), true)) {
			$body['proxied'] = false;
		}
		return $body;
	}

	protected function authHeaders(): array {
		return array('Authorization' => 'Bearer ' . $this->cred('api_token'));
	}

	protected function translateError(RequestException $e, string $method, string $url, int $status): DnsProviderException {
		$reason = $this->errorBody($e);
		$lower = strtolower($reason);
		if (strpos($lower, 'email routing') !== false || strpos($lower, 'email_routing') !== false) {
			return new DnsManagedRecordException('Cloudflare Email Routing',
				'This record is managed by Cloudflare Email Routing; disable Email Routing for this zone '
				. 'in the Cloudflare dashboard, then publish again. (' . $reason . ')');
		}
		if ($status === 403) {
			// Cloudflare returns 403 for two unrelated causes, and guessing wrong
			// sends the operator to edit permissions on a token whose permissions
			// are fine. A token restricted by client IP names the address it saw:
			// commonly the server's IPv6 address, when the allowlist was written
			// from the IPv4 one and the outbound connection preferred v6.
			if (strpos($lower, 'access token from location') !== false) {
				return new DnsProviderException('Cloudflare refused the token (403): ' . $reason
					. ' — the token is restricted by client IP and this server is not on the list. In Cloudflare '
					. 'under My Profile / API Tokens, edit the token and add that exact address to Client IP '
					. 'Address Filtering, or remove the filter. A server holding both an IPv4 and an IPv6 address '
					. 'reaches Cloudflare from either, so allow both — and add an IPv6 address on its own rather '
					. 'than as a /48 or /64 range, which Cloudflare mishandles.', $status, $e);
			}
			return new DnsProviderException('Cloudflare refused the token (403): ' . $reason
				. ' — the token needs the Zone · DNS · Edit permission on this zone.', $status, $e);
		}
		return parent::translateError($e, $method, $url, $status);
	}
}

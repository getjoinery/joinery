<?php
/**
 * Route53DnsDriver - AWS Route 53, over its REST/XML API, signed with the
 * platform's own SigV4 signer (AwsSigV4) and sent through ProviderHttp.
 *
 * No OAuth2; Route 53 takes an IAM access key pair supplied at the publish
 * moment and discarded when the request returns. Scope it with an IAM policy
 * limited to the one hosted zone where you can.
 *
 * Route 53 is record-set based and its DELETE requires the set to be described
 * exactly as it currently stands, so writes go through DnsRrsetDriverBase's
 * read-modify-write. Private hosted zones are ignored — a zone the public
 * internet cannot resolve is never what a plan means.
 *
 * THE XML: every list (HostedZones, ResourceRecordSets, ResourceRecords) is
 * read as a list even with one child, and every flag (IsTruncated,
 * PrivateZone) is the string "true" or "false", compared as a string — a
 * non-empty "false" is not true.
 *
 * @version 1.1 - talks to the API directly (AwsSigV4 + ProviderHttp), no AWS SDK; IsTruncated and
 *   PrivateZone are compared as the string "true"; retries a 429, a 5xx and a 400 Throttling or
 *   PriorRequestNotComplete twice (specs/email_provider_sdk_removal.md WP7)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsRrsetDriverBase.php'));

class Route53DnsDriver extends DnsRrsetDriverBase {

	const HOST = 'route53.amazonaws.com';
	const API  = '/2013-04-01';
	const XMLNS = 'https://route53.amazonaws.com/doc/2013-04-01/';
	/** Route 53 is a global service, signed in us-east-1. */
	const REGION = 'us-east-1';
	/** Error codes that mean "try again": throttled, or an earlier change to the zone still running. */
	const RETRY_CODES = array('Throttling', 'PriorRequestNotComplete');

	/** @var array<string,string>|null zone name => hosted zone id. */
	private $zones = null;

	public static function getKey(): string { return 'route53'; }
	public static function getLabel(): string { return 'AWS Route 53'; }

	/** Route 53 assigns four per-zone names, e.g. ns-123.awsdns-45.org. */
	public static function nameserverSuffixes(): array { return array('awsdns-'); }

	public static function credentialFields(): array {
		return array(
			'access_key_id' => array(
				'label'  => 'AWS access key id',
				'help'   => 'An IAM user or role key with route53:ChangeResourceRecordSets on this hosted zone.',
				'secret' => false,
			),
			'secret_access_key' => array(
				'label'  => 'AWS secret access key',
				'help'   => 'Used for this one publish and never stored.',
				'secret' => true,
			),
			'session_token' => array(
				'label'  => 'AWS session token',
				'help'   => 'Only for temporary STS credentials — leave blank for a long-lived key pair.',
				'secret' => true,
			),
		);
	}

	public static function credentialGuide(): ?array {
		return array(
			'title'     => 'Create an AWS access key for Route 53',
			'url'       => 'https://console.aws.amazon.com/iam/home#/users',
			'url_label' => 'Open AWS IAM users',
			'steps'     => array(
				'In IAM, create a user with no console access, or pick an existing one.',
				'Attach a policy allowing route53:ListHostedZones, route53:GetChange and '
					. 'route53:ChangeResourceRecordSets on this hosted zone.',
				'Open the user, then Security credentials, then Create access key, and choose '
					. '"Application running outside AWS".',
				'Copy the access key id and secret access key — the secret is shown once.',
				'Leave the session token blank unless these are temporary STS credentials.',
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

	public function listRecords(string $zone): array {
		$path = self::API . '/hostedzone/' . rawurlencode($this->zoneId($zone)) . '/rrset';
		$out = array();
		$query = array('maxitems' => '300');
		do {
			$xml = $this->call('GET', $path, $query);
			foreach (self::children($xml->ResourceRecordSets, 'ResourceRecordSet') as $rrset) {
				$type = strtoupper((string)$rrset->Type);
				$name = DnsRecord::normalizeName($this->decodeName((string)$rrset->Name));
				$ttl  = (int)(string)$rrset->TTL;
				foreach (self::children($rrset->ResourceRecords, 'ResourceRecord') as $rr) {
					$record = $this->recordFromValue($type, $name, (string)$rr->Value,
						$ttl > 0 ? $ttl : null);
					if ($record !== null) {
						$record->provider_id = $name . '/' . $type;
						$out[] = $record;
					}
				}
			}
			$more = (string)$xml->IsTruncated === 'true';
			if ($more) {
				$query['name'] = (string)$xml->NextRecordName;
				$query['type'] = (string)$xml->NextRecordType;
			}
		} while ($more);
		return $out;
	}

	// ------------------------------------------------------------------

	protected function readRrset(string $zone, string $name, string $type): ?array {
		$name = DnsRecord::normalizeName($name);
		$xml = $this->call('GET', self::API . '/hostedzone/' . rawurlencode($this->zoneId($zone)) . '/rrset', array(
			'name'     => $name . '.',
			'type'     => strtoupper($type),
			'maxitems' => '1',
		));
		foreach (self::children($xml->ResourceRecordSets, 'ResourceRecordSet') as $rrset) {
			if (DnsRecord::normalizeName($this->decodeName((string)$rrset->Name)) !== $name
					|| strtoupper((string)$rrset->Type) !== strtoupper($type)) {
				continue;
			}
			$values = array();
			foreach (self::children($rrset->ResourceRecords, 'ResourceRecord') as $rr) {
				$values[] = (string)$rr->Value;
			}
			$ttl = (string)$rrset->TTL;
			return empty($values) ? null
				: array('values' => $values, 'ttl' => $ttl !== '' ? (int)$ttl : null);
		}
		return null;
	}

	protected function writeRrset(string $zone, string $name, string $type, array $values, ?int $ttl): void {
		$this->change($zone, 'UPSERT', $name, $type, $values, $ttl);
	}

	protected function deleteRrset(string $zone, string $name, string $type): void {
		$existing = $this->readRrset($zone, $name, $type);
		if ($existing === null) {
			return;
		}
		// Route 53 refuses a DELETE that does not describe the set exactly.
		$this->change($zone, 'DELETE', $name, $type, $existing['values'], $existing['ttl']);
	}

	private function change(string $zone, string $action, string $name, string $type, array $values, ?int $ttl): void {
		$records = '';
		foreach (array_values($values) as $value) {
			$records .= '<ResourceRecord><Value>' . self::esc((string)$value) . '</Value></ResourceRecord>';
		}
		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<ChangeResourceRecordSetsRequest xmlns="' . self::XMLNS . '">'
			. '<ChangeBatch><Changes><Change>'
			. '<Action>' . self::esc($action) . '</Action>'
			. '<ResourceRecordSet>'
			. '<Name>' . self::esc(DnsRecord::normalizeName($name) . '.') . '</Name>'
			. '<Type>' . self::esc(strtoupper($type)) . '</Type>'
			. '<TTL>' . ($ttl !== null ? (int)$ttl : 300) . '</TTL>'
			. '<ResourceRecords>' . $records . '</ResourceRecords>'
			. '</ResourceRecordSet>'
			. '</Change></Changes></ChangeBatch>'
			. '</ChangeResourceRecordSetsRequest>';
		$this->call('POST', self::API . '/hostedzone/' . rawurlencode($this->zoneId($zone)) . '/rrset', array(), $body);
	}

	/** @return array<string,string> */
	private function zoneMap(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$marker = null;
		do {
			$query = array('maxitems' => '100');
			if ($marker !== null) {
				$query['marker'] = $marker;
			}
			$xml = $this->call('GET', self::API . '/hostedzone', $query);
			foreach (self::children($xml->HostedZones, 'HostedZone') as $row) {
				if ((string)$row->Config->PrivateZone === 'true') {
					continue;
				}
				$name = DnsRecord::normalizeName($this->decodeName((string)$row->Name));
				$id = (string)$row->Id;
				if ($name !== '' && $id !== '') {
					// Ids come back as /hostedzone/Z123; the API wants either form.
					$this->zones[$name] = str_replace('/hostedzone/', '', $id);
				}
			}
			$marker = (string)$xml->IsTruncated === 'true' ? (string)$xml->NextMarker : null;
		} while ($marker !== null && $marker !== '');
		return $this->zones;
	}

	private function zoneId(string $zone): string {
		$zones = $this->zoneMap();
		$name = DnsRecord::normalizeName($zone);
		if (!isset($zones[$name])) {
			throw new DnsZoneNotFoundException('These AWS credentials can see no public hosted zone for ' . $zone . '.');
		}
		return $zones[$name];
	}

	/** Route 53 escapes non-ASCII and special characters in names as \\DDD octals. */
	private function decodeName(string $name): string {
		return preg_replace_callback('/\\\\(\d{3})/', function ($m) {
			return chr(octdec($m[1]));
		}, $name);
	}

	/**
	 * One signed request; the parsed XML answer. A refusal raises
	 * DnsProviderException with Route 53's own code and message.
	 */
	private function call(string $method, string $path, array $query = array(), ?string $body = null): SimpleXMLElement {
		$access = $this->cred('access_key_id');
		$secret = $this->cred('secret_access_key');
		$token  = $this->cred('session_token');
		$headers = array('Accept' => 'application/xml');
		if ($body !== null) {
			$headers['Content-Type'] = 'application/xml';
		}
		$sign = function (array $headers, string $payload) use ($method, $path, $query, $access, $secret, $token) {
			$signed = array();
			if (isset($headers['Content-Type'])) {
				$signed['content-type'] = $headers['Content-Type'];
			}
			return AwsSigV4::signHeaders(array(
				'method' => $method, 'host' => self::HOST, 'path' => $path, 'query' => $query,
				'headers' => $signed, 'payload_hash' => hash('sha256', $payload),
				'service' => 'route53', 'region' => self::REGION,
				'access_key' => $access, 'secret_key' => $secret,
				'session_token' => $token !== '' ? $token : null,
				'time' => time(), 'double_encode_path' => true,
			));
		};
		$options = array(
			'headers'   => $headers,
			'query'     => $query,
			'retries'   => 2,
			'retry_5xx' => true,
			'retry_if'  => function (ProviderHttpResponse $resp) {
				return $resp->status === 400 && in_array(self::errorOf($resp->body)[0], self::RETRY_CODES, true);
			},
			'sign'      => $sign,
		);
		if ($body !== null) {
			$options['body'] = $body;
		}
		try {
			$resp = ProviderHttp::request($method, 'https://' . self::HOST . $path, $options);
		} catch (ProviderHttpException $e) {
			throw new DnsProviderException('Route 53 request failed: ' . $e->getMessage(), 0, $e);
		}
		if (!$resp->ok()) {
			list($code, $message) = self::errorOf($resp->body);
			throw new DnsProviderException('Route 53 refused the request ('
				. ($code !== '' ? $code : 'HTTP ' . $resp->status) . '): ' . $message);
		}
		$xml = self::parse($resp->body);
		if ($xml === null) {
			throw new DnsProviderException('Route 53 request failed: the answer was not XML.');
		}
		return $xml;
	}

	/** [code, message] from an error body; ['', ''] when it holds none. */
	private static function errorOf(string $body): array {
		$xml = self::parse($body);
		if ($xml === null) {
			return array('', '');
		}
		if (isset($xml->Error)) {
			return array((string)$xml->Error->Code, (string)$xml->Error->Message);
		}
		// InvalidChangeBatch carries its reasons in Messages, not an Error.
		$messages = array();
		foreach (self::children($xml->Messages, 'Message') as $m) {
			$messages[] = (string)$m;
		}
		return array($xml->getName(), implode('; ', $messages));
	}

	private static function parse(string $body): ?SimpleXMLElement {
		if (trim($body) === '') {
			return null;
		}
		$prev = libxml_use_internal_errors(true);
		$xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		return $xml === false ? null : $xml;
	}

	/**
	 * The $child elements under $parent, always as a list — one child is
	 * still a list of one, and a missing parent is an empty list.
	 *
	 * @return SimpleXMLElement[]
	 */
	private static function children($parent, string $child): array {
		$out = array();
		if ($parent === null || !isset($parent->{$child})) {
			return $out;
		}
		foreach ($parent->{$child} as $node) {
			$out[] = $node;
		}
		return $out;
	}

	private static function esc(string $value): string {
		return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}

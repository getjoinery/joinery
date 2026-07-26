<?php
/**
 * Route53DnsDriver - AWS Route 53, through the AWS SDK already in the vendor
 * tree (SigV4 request signing is the SDK's job, not this driver's).
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
 * @version 1.0
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/dns/DnsRrsetDriverBase.php'));

use Aws\Route53\Route53Client;
use Aws\Exception\AwsException;

class Route53DnsDriver extends DnsRrsetDriverBase {

	/** @var Route53Client|null */
	private $client = null;
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
		$zone_id = $this->zoneId($zone);
		$out = array();
		$params = array('HostedZoneId' => $zone_id, 'MaxItems' => '300');
		do {
			$result = $this->call(function (Route53Client $client) use ($params) {
				return $client->listResourceRecordSets($params);
			});
			foreach ((array)($result['ResourceRecordSets'] ?? array()) as $rrset) {
				$type = strtoupper((string)($rrset['Type'] ?? ''));
				$name = DnsRecord::normalizeName($this->decodeName((string)($rrset['Name'] ?? '')));
				$ttl  = isset($rrset['TTL']) ? (int)$rrset['TTL'] : 0;
				foreach ((array)($rrset['ResourceRecords'] ?? array()) as $rr) {
					$record = $this->recordFromValue($type, $name, (string)($rr['Value'] ?? ''),
						$ttl > 0 ? $ttl : null);
					if ($record !== null) {
						$record->provider_id = $name . '/' . $type;
						$out[] = $record;
					}
				}
			}
			$more = !empty($result['IsTruncated']);
			if ($more) {
				$params['StartRecordName'] = (string)$result['NextRecordName'];
				$params['StartRecordType'] = (string)$result['NextRecordType'];
			}
		} while ($more);
		return $out;
	}

	// ------------------------------------------------------------------

	protected function readRrset(string $zone, string $name, string $type): ?array {
		$name = DnsRecord::normalizeName($name);
		$result = $this->call(function (Route53Client $client) use ($zone, $name, $type) {
			return $client->listResourceRecordSets(array(
				'HostedZoneId'    => $this->zoneId($zone),
				'StartRecordName' => $name . '.',
				'StartRecordType' => $type,
				'MaxItems'        => '1',
			));
		});
		foreach ((array)($result['ResourceRecordSets'] ?? array()) as $rrset) {
			if (DnsRecord::normalizeName($this->decodeName((string)$rrset['Name'])) !== $name
					|| strtoupper((string)$rrset['Type']) !== strtoupper($type)) {
				continue;
			}
			$values = array();
			foreach ((array)($rrset['ResourceRecords'] ?? array()) as $rr) {
				$values[] = (string)($rr['Value'] ?? '');
			}
			return empty($values) ? null
				: array('values' => $values, 'ttl' => isset($rrset['TTL']) ? (int)$rrset['TTL'] : null);
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
		$records = array();
		foreach (array_values($values) as $value) {
			$records[] = array('Value' => (string)$value);
		}
		$this->call(function (Route53Client $client) use ($zone, $action, $name, $type, $records, $ttl) {
			return $client->changeResourceRecordSets(array(
				'HostedZoneId' => $this->zoneId($zone),
				'ChangeBatch'  => array('Changes' => array(array(
					'Action'            => $action,
					'ResourceRecordSet' => array(
						'Name'            => DnsRecord::normalizeName($name) . '.',
						'Type'            => strtoupper($type),
						'TTL'             => $ttl !== null ? (int)$ttl : 300,
						'ResourceRecords' => $records,
					),
				))),
			));
		});
	}

	/** @return array<string,string> */
	private function zoneMap(): array {
		if ($this->zones !== null) {
			return $this->zones;
		}
		$this->zones = array();
		$marker = null;
		do {
			$params = array('MaxItems' => '100');
			if ($marker !== null) {
				$params['Marker'] = $marker;
			}
			$result = $this->call(function (Route53Client $client) use ($params) {
				return $client->listHostedZones($params);
			});
			foreach ((array)($result['HostedZones'] ?? array()) as $row) {
				if (!empty($row['Config']['PrivateZone'])) {
					continue;
				}
				$name = DnsRecord::normalizeName($this->decodeName((string)($row['Name'] ?? '')));
				$id = (string)($row['Id'] ?? '');
				if ($name !== '' && $id !== '') {
					// Ids come back as /hostedzone/Z123; the API wants either form.
					$this->zones[$name] = str_replace('/hostedzone/', '', $id);
				}
			}
			$marker = !empty($result['IsTruncated']) ? (string)$result['NextMarker'] : null;
		} while ($marker !== null);
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

	private function client(): Route53Client {
		if ($this->client === null) {
			$credentials = array(
				'key'    => $this->cred('access_key_id'),
				'secret' => $this->cred('secret_access_key'),
			);
			$token = $this->cred('session_token');
			if ($token !== '') {
				$credentials['token'] = $token;
			}
			$this->client = new Route53Client(array(
				'version'     => '2013-04-01',
				'region'      => 'us-east-1',   // Route 53 is a global service
				'credentials' => $credentials,
			));
		}
		return $this->client;
	}

	/** Run an SDK call, translating AWS failures into the platform's exception. */
	private function call(callable $operation) {
		try {
			return $operation($this->client());
		} catch (AwsException $e) {
			throw new DnsProviderException('Route 53 refused the request ('
				. $e->getAwsErrorCode() . '): ' . $e->getAwsErrorMessage(), 0, $e);
		} catch (DnsProviderException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new DnsProviderException('Route 53 request failed: ' . $e->getMessage(), 0, $e);
		}
	}
}

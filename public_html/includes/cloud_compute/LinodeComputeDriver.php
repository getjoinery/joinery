<?php
/**
 * LinodeComputeDriver - Linode API v4 implementation of CloudComputeProvider.
 *
 * Acts on the account that issued the bearer token (the customer's), so
 * instances it creates are billed by Linode to the customer. Requires the
 * 'linodes:read_write' OAuth scope.
 *
 * @version 1.8 - a transport failure (timeout, DNS, refused connection) is a CloudComputeException too, so a
 *                path carrying the transfer code is redacted on every failure, not only an HTTP error
 * @version 1.7 - CloudInstanceTransfers: the provider-side eligibility check, create, status and cancel of a
 *                Service Transfer (specs/managed_to_self_hosted_transfer.md §2, §3). Creating one needs
 *                account:read_write; the check also reads firewalls and volumes. The transfer token never
 *                appears in an exception message.
 * @version 1.6 - the instance report carries its region; regionSupportsMetadata() refuses a blank region
 * @version 1.5 - the instance report carries ipv6 (Linode's addr/128, prefix stripped) beside the first public IPv4
 * @version 1.4 - shutdownInstance()/bootInstance() (POST …/shutdown, …/boot) and getTransfer()
 *                (GET account/transfer): the hosted tier's only automatic lever is power, and
 *                the transfer figure it watches is the account pool's, not an instance's.
 * @version 1.3 - user_data (the Metadata service; cloud-init, base64) and a StackScript
 *                fallback; regions() (the token step called it and no driver had it, so a
 *                bad token was never caught)
 *                on createInstance/rebuildInstance, regionSupportsMetadata(),
 *                ensureStackScript() - how a relay is born configured
 *                (specs/relay_without_a_shell.md). No root password when user-data
 *                is the whole mechanism: the driver mints one the platform never
 *                sees, because the API insists on one.
 * @version 1.2 - rebuildInstance().
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/cloud_compute/CloudComputeProvider.php'));

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class LinodeComputeDriver implements CloudComputeProvider, CloudInstanceTransfers {

	const API_BASE = 'https://api.linode.com/v4/';

	/** @var Client */
	private $http;
	/** @var string */
	private $access_token;

	public function __construct(string $access_token, ?Client $http = null) {
		$this->access_token = $access_token;
		$this->http = $http ?: new Client([
			'base_uri'        => self::API_BASE,
			'timeout'         => 30,
			'connect_timeout' => 10,
		]);
	}

	public function createInstance(array $opts): array {
		foreach (array('label', 'region', 'type', 'image') as $required) {
			if (empty($opts[$required])) {
				throw new CloudComputeException('createInstance missing required option: ' . $required);
			}
		}
		$body = array(
			'label'     => $opts['label'],
			'region'    => $opts['region'],
			'type'      => $opts['type'],
			'image'     => $opts['image'],
			'root_pass' => self::rootPassword($opts),
			'booted'    => true,
		);
		if (!empty($opts['authorized_keys'])) {
			$body['authorized_keys'] = array_values($opts['authorized_keys']);
		}
		$this->applyFirstBoot($body, $opts);
		return $this->normalize($this->request('POST', 'linode/instances', $body));
	}

	public function getInstance(string $instance_id): array {
		return $this->normalize($this->request('GET', 'linode/instances/' . rawurlencode($instance_id)));
	}

	public function rebuildInstance(string $instance_id, array $opts): array {
		if (empty($opts['image'])) {
			throw new CloudComputeException('rebuildInstance missing required option: image');
		}
		$body = array(
			'image'     => $opts['image'],
			'root_pass' => self::rootPassword($opts),
			'booted'    => true,
		);
		if (!empty($opts['authorized_keys'])) {
			$body['authorized_keys'] = array_values($opts['authorized_keys']);
		}
		$this->applyFirstBoot($body, $opts);
		// Linode keeps the Linode object and its IPv4 across a rebuild; only the
		// disks and configuration profiles are replaced.
		return $this->normalize($this->request('POST',
			'linode/instances/' . rawurlencode($instance_id) . '/rebuild', $body));
	}

	/**
	 * The regions this account may create in: id => label. Also the cheapest
	 * live check that a token works, which is what the Setup tab's token step
	 * uses it for.
	 */
	public function regions(): array {
		$listing = $this->request('GET', 'regions');
		$out = array();
		foreach ((array)($listing['data'] ?? array()) as $region) {
			$id = (string)($region['id'] ?? '');
			if ($id !== '') {
				$out[$id] = (string)($region['label'] ?? $id);
			}
		}
		return $out;
	}

	/**
	 * Does a region offer the Metadata service, which is what carries cloud-init
	 * user-data? Regions without it take the StackScript fallback.
	 */
	public function regionSupportsMetadata(string $region): bool {
		// A blank region would ask GET regions/ — the list of every region, with
		// no capabilities at its top — and read as "no Metadata here".
		if (trim($region) === '') {
			throw new CloudComputeException('regionSupportsMetadata needs a region');
		}
		$info = $this->request('GET', 'regions/' . rawurlencode($region));
		$capabilities = isset($info['capabilities']) && is_array($info['capabilities']) ? $info['capabilities'] : array();
		return in_array('Metadata', $capabilities, true);
	}

	/**
	 * Find this account's private StackScript by label, or create it. Returns
	 * its id. The script's content is compared and updated when it drifted, so
	 * a release that changes the first-boot template reaches the next run.
	 *
	 * @param string[] $images the image ids the script may run on
	 */
	public function ensureStackScript(string $label, string $script, array $images): string {
		$filter = json_encode(array('label' => $label, 'mine' => true));
		$listing = $this->request('GET', 'linode/stackscripts', null, array('X-Filter' => $filter));
		foreach ((array)($listing['data'] ?? array()) as $existing) {
			if ((string)($existing['label'] ?? '') !== $label) {
				continue;
			}
			$id = (string)($existing['id'] ?? '');
			if ($id !== '' && ((string)($existing['script'] ?? '') !== $script
					|| array_values((array)($existing['images'] ?? array())) !== array_values($images))) {
				$this->request('PUT', 'linode/stackscripts/' . rawurlencode($id),
					array('script' => $script, 'images' => array_values($images)));
			}
			if ($id !== '') {
				return $id;
			}
		}
		$created = $this->request('POST', 'linode/stackscripts', array(
			'label'       => $label,
			'description' => 'Joinery relay first boot (specs/relay_without_a_shell.md); managed by the plane.',
			'images'      => array_values($images),
			'is_public'   => false,
			'script'      => $script,
		));
		return (string)($created['id'] ?? '');
	}

	/**
	 * user_data goes to the Metadata service base64-encoded; a StackScript rides
	 * as its id plus the UDF values. Both are fields of the same create/rebuild
	 * call, so neither is a provider process of its own.
	 */
	private function applyFirstBoot(array &$body, array $opts): void {
		if (!empty($opts['user_data'])) {
			$body['metadata'] = array('user_data' => base64_encode((string)$opts['user_data']));
		}
		if (!empty($opts['stackscript_id'])) {
			$body['stackscript_id'] = (int)$opts['stackscript_id'];
			$body['stackscript_data'] = (array)($opts['stackscript_data'] ?? array());
		}
	}

	/**
	 * The API requires a root password on create and rebuild. When the caller
	 * has none to give - a relay is reached only through its own API and the
	 * platform records no root password - one is minted here and forgotten.
	 */
	private static function rootPassword(array $opts): string {
		if (!empty($opts['root_pass'])) {
			return (string)$opts['root_pass'];
		}
		return 'Aa1!' . bin2hex(random_bytes(20));
	}

	public function deleteInstance(string $instance_id): void {
		$this->request('DELETE', 'linode/instances/' . rawurlencode($instance_id));
	}

	public function shutdownInstance(string $instance_id): void {
		$this->request('POST', 'linode/instances/' . rawurlencode($instance_id) . '/shutdown');
	}

	public function bootInstance(string $instance_id): void {
		$this->request('POST', 'linode/instances/' . rawurlencode($instance_id) . '/boot');
	}

	/**
	 * The account's transfer pool for the current billing period. Linode
	 * reports it in GB as used / quota / billable.
	 */
	public function getTransfer(): array {
		$t = $this->request('GET', 'account/transfer');
		return array(
			'used_gb'     => (float)($t['used'] ?? 0),
			'quota_gb'    => (float)($t['quota'] ?? 0),
			'billable_gb' => (float)($t['billable'] ?? 0),
		);
	}

	public function setReverseDns(string $instance_id, string $ip, string $hostname): array {
		$result = $this->request('PUT',
			'linode/instances/' . rawurlencode($instance_id) . '/ips/' . rawurlencode($ip),
			array('rdns' => $hostname));
		return array(
			'ip'   => (string)($result['address'] ?? $ip),
			'rdns' => (string)($result['rdns'] ?? ''),
		);
	}

	// ── Service Transfer (CloudInstanceTransfers) ─────────────────────────────

	/**
	 * Linode refuses a transfer of an instance with any of these attached, at
	 * create and again at accept. Checking first is for a clear page; the
	 * create call's own reason is still the last word, since something can be
	 * attached between the check and the create.
	 *
	 * A check that cannot be asked (a token missing the scope, an outage) is a
	 * blocker that says so: "could not look" must never read as "nothing there".
	 */
	public function transferEligibility(string $instance_id): array {
		$base = 'linode/instances/' . rawurlencode($instance_id);
		$out = array();
		$add = function (string $key, string $label, string $result, string $detail = '', string $fix = '') use (&$out) {
			$out[] = array('key' => $key, 'label' => $label, 'result' => $result, 'detail' => $detail, 'fix' => $fix);
		};

		try {
			$instance = $this->request('GET', $base);
		} catch (CloudComputeException $e) {
			if ((int)$e->getCode() === 404) {
				$add('instance', 'The instance is on this account', 'blocker',
					'Instance ' . $instance_id . ' is not on the operator\'s Linode account.',
					'Nothing to hand over from here: the instance was deleted or has already moved.');
				return $out;
			}
			throw $e;
		}
		$add('instance', 'The instance is on this account', 'pass');

		$locks = array();
		foreach ((array)($instance['locks'] ?? array()) as $lock) {
			$locks[] = is_array($lock) ? (string)($lock['lock_type'] ?? $lock['type'] ?? 'lock') : (string)$lock;
		}
		$locks
			? $add('locks', 'No resource lock', 'blocker', 'Locked: ' . implode(', ', $locks) . '.',
				'Remove the lock in Cloud Manager (the instance\'s Settings).')
			: $add('locks', 'No resource lock', 'pass');

		$this->transferListCheck($add, 'firewalls', 'No Cloud Firewall attached', $base . '/firewalls',
			'Cloud Firewall', 'Detach the firewall from the instance in Cloud Manager.');
		$this->transferListCheck($add, 'volumes', 'No Block Storage volume attached', $base . '/volumes',
			'Volume', 'Detach the volume. Data on it does not move with the instance.');
		$this->transferListCheck($add, 'nodebalancers', 'Not behind a NodeBalancer', $base . '/nodebalancers',
			'NodeBalancer', 'Remove the instance from the NodeBalancer.');

		try {
			$ips = $this->request('GET', $base . '/ips');
			$ipv4 = (array)($ips['ipv4'] ?? array());
			$shared = array();
			foreach ((array)($ipv4['shared'] ?? array()) as $ip) {
				$shared[] = (string)($ip['address'] ?? '');
			}
			$shared
				? $add('shared_ipv4', 'No shared IPv4', 'blocker', 'Shared: ' . implode(', ', $shared) . '.',
					'Stop sharing the address with this instance.')
				: $add('shared_ipv4', 'No shared IPv4', 'pass');
			$reserved = array();
			foreach (array('public', 'shared') as $kind) {
				foreach ((array)($ipv4[$kind] ?? array()) as $ip) {
					if (!empty($ip['reserved'])) {
						$reserved[] = (string)($ip['address'] ?? '');
					}
				}
			}
			$reserved
				? $add('reserved_ipv4', 'No reserved IPv4', 'blocker', 'Reserved: ' . implode(', ', $reserved) . '.',
					'Convert the address to ephemeral in Cloud Manager; the address itself is kept.')
				: $add('reserved_ipv4', 'No reserved IPv4', 'pass');
			$ranges = array();
			foreach ((array)($ips['ipv6']['global'] ?? array()) as $range) {
				$ranges[] = (string)($range['range'] ?? '') . '/' . (string)($range['prefix'] ?? '');
			}
			$ranges
				? $add('ipv6_ranges', 'No IPv6 range', 'blocker', 'Ranges: ' . implode(', ', $ranges) . '.',
					'Remove the IPv6 range from the instance.')
				: $add('ipv6_ranges', 'No IPv6 range', 'pass');
		} catch (CloudComputeException $e) {
			$add('ips', 'The instance\'s addresses', 'blocker', 'Could not be read: ' . $e->getMessage(),
				'Check the operator token\'s scopes, then re-check.');
		}

		try {
			$private = array();
			if ((string)($instance['interface_generation'] ?? '') === 'linode') {
				$listing = $this->request('GET', $base . '/interfaces');
				foreach ((array)($listing['interfaces'] ?? $listing['data'] ?? array()) as $iface) {
					if (!empty($iface['vpc'])) { $private[] = 'VPC'; }
					if (!empty($iface['vlan'])) { $private[] = 'VLAN'; }
				}
			} else {
				$configs = $this->request('GET', $base . '/configs');
				foreach ((array)($configs['data'] ?? array()) as $config) {
					foreach ((array)($config['interfaces'] ?? array()) as $iface) {
						$purpose = strtolower((string)($iface['purpose'] ?? ''));
						if ($purpose === 'vpc' || $purpose === 'vlan') {
							$private[] = strtoupper($purpose);
						}
					}
				}
			}
			$private
				? $add('private_networks', 'No VLAN or VPC interface', 'blocker',
					'Attached: ' . implode(', ', array_unique($private)) . '.',
					'Remove the VLAN or VPC interface from the instance\'s configuration.')
				: $add('private_networks', 'No VLAN or VPC interface', 'pass');
		} catch (CloudComputeException $e) {
			$add('private_networks', 'No VLAN or VPC interface', 'blocker', 'Could not be read: ' . $e->getMessage(),
				'Check the operator token\'s scopes, then re-check.');
		}

		try {
			$account = $this->request('GET', 'account/settings');
			!empty($account['managed'])
				? $add('linode_managed', 'Linode Managed is off on this account', 'blocker',
					'The operator account has Linode Managed enabled.', 'Linode does not transfer from a Managed account.')
				: $add('linode_managed', 'Linode Managed is off on this account', 'pass');
			if (!empty($account['backups_enabled'])) {
				$add('account_backups', 'Account does not auto-enrol Linode Backups', 'warning',
					'Every new instance on the operator account is enrolled in Linode Backups.',
					'Turn off "Backup auto-enrollment" on the operator account, then disable Backups on instances that do not need it.');
			}
		} catch (CloudComputeException $e) {
			$add('linode_managed', 'Linode Managed is off on this account', 'blocker',
				'Could not be read: ' . $e->getMessage(), 'Give the operator token account access, then re-check.');
		}

		if (!empty($instance['backups']['enabled'])) {
			$add('instance_backups', 'Linode Backups', 'warning',
				'Linode Backups are on for this instance; they move with it and the customer is billed for them.',
				'Leave it, or turn Backups off before the code is issued.');
		}

		try {
			$pending = $this->request('GET', 'account/service-transfers', null,
				array('X-Filter' => json_encode(array('status' => 'pending'))));
			$found = false;
			foreach ((array)($pending['data'] ?? array()) as $transfer) {
				$ids = array_map('strval', (array)($transfer['entities']['linodes'] ?? array()));
				if (!empty($transfer['is_sender']) && (string)($transfer['status'] ?? '') === 'pending'
						&& in_array((string)$instance_id, $ids, true)) {
					$found = true;
				}
			}
			$found
				? $add('pending_transfer', 'Not already in a pending transfer', 'blocker',
					'A transfer code for this instance is already out.',
					'Cancel it (Cloud Manager → Account → Service Transfers), or wait for it to expire.')
				: $add('pending_transfer', 'Not already in a pending transfer', 'pass');
		} catch (CloudComputeException $e) {
			$add('pending_transfer', 'Not already in a pending transfer', 'blocker',
				'Could not be read: ' . $e->getMessage(), 'Give the operator token account access, then re-check.');
		}

		return $out;
	}

	/** One "nothing of this kind is attached" check over a paginated listing. */
	private function transferListCheck(callable $add, string $key, string $label, string $path, string $noun, string $fix): void {
		try {
			$listing = $this->request('GET', $path);
		} catch (CloudComputeException $e) {
			$add($key, $label, 'blocker', 'Could not be read: ' . $e->getMessage(),
				'Check the operator token\'s scopes, then re-check.');
			return;
		}
		$names = array();
		foreach ((array)($listing['data'] ?? array()) as $item) {
			$names[] = (string)($item['label'] ?? $item['id'] ?? '?');
		}
		$names
			? $add($key, $label, 'blocker', $noun . ($names ? ': ' . implode(', ', $names) : '') . '.', $fix)
			: $add($key, $label, 'pass');
	}

	/** An instance type's list price a month, in US dollars, or null when Linode does not say. */
	public function typeMonthlyPrice(string $type): ?float {
		$info = $this->request('GET', 'linode/types/' . rawurlencode($type));
		$monthly = $info['price']['monthly'] ?? null;
		return is_numeric($monthly) ? (float)$monthly : null;
	}

	public function createTransfer(string $instance_id): array {
		$created = $this->request('POST', 'account/service-transfers',
			array('entities' => array('linodes' => array((int)$instance_id))));
		$token = (string)($created['token'] ?? '');
		if ($token === '') {
			throw new CloudComputeException('Linode created a transfer but returned no code.');
		}
		return array(
			'token'  => $token,
			'status' => (string)($created['status'] ?? 'pending'),
			'expiry' => self::utc((string)($created['expiry'] ?? '')),
		);
	}

	public function getTransferStatus(string $token): array {
		$found = $this->transferRequest('GET', $token);
		return array(
			'status' => (string)($found['status'] ?? ''),
			'expiry' => self::utc((string)($found['expiry'] ?? '')),
		);
	}

	public function cancelTransfer(string $token): void {
		$this->transferRequest('DELETE', $token);
	}

	/**
	 * A call whose path carries the transfer token. Any failure is re-thrown
	 * with the token taken out: the message ends up in error logs and on a
	 * stored row, and the token is a bearer secret for the whole machine.
	 */
	private function transferRequest(string $method, string $token): array {
		try {
			return $this->request($method, 'account/service-transfers/' . rawurlencode($token));
		} catch (Throwable $e) {
			// Any failure at all, transport included: the message is redacted
			// and the original (whose message and URL carry the code) is not
			// chained, so nothing downstream can print it.
			$message = str_replace(array($token, rawurlencode($token)), '[code]', $e->getMessage());
			throw new CloudComputeException($message, (int)$e->getCode());
		}
	}

	/** Linode's "2026-10-03T14:05:00" (UTC, no zone) as 'Y-m-d H:i:s', or ''. */
	private static function utc(string $when): string {
		$when = trim($when);
		if ($when === '') {
			return '';
		}
		$stamp = strtotime(rtrim($when, 'Z') . ' UTC');
		return $stamp === false ? '' : gmdate('Y-m-d H:i:s', $stamp);
	}

	/**
	 * Normalize a Linode instance object to the CloudComputeProvider shape.
	 */
	private function normalize(array $instance): array {
		$ip = '';
		if (!empty($instance['ipv4']) && is_array($instance['ipv4'])) {
			foreach ($instance['ipv4'] as $candidate) {
				// Skip private RFC1918 addresses; Linode lists public first, but be explicit.
				if (!preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $candidate)) {
					$ip = $candidate;
					break;
				}
			}
		}
		// Linode reports the instance's public IPv6 as "addr/128". A dual-stack
		// box reaches a plane with AAAA records over IPv6, so a join can arrive
		// from this address and the plane must know it belongs to the instance.
		$ipv6 = '';
		if (!empty($instance['ipv6']) && is_string($instance['ipv6'])) {
			$ipv6 = strtolower(trim(explode('/', $instance['ipv6'], 2)[0]));
		}
		return array(
			'id'     => isset($instance['id']) ? (string)$instance['id'] : '',
			'status' => isset($instance['status']) ? (string)$instance['status'] : '',
			'ip'     => $ip,
			'ipv6'   => $ipv6,
			'label'  => isset($instance['label']) ? (string)$instance['label'] : '',
			'region' => isset($instance['region']) ? (string)$instance['region'] : '',
		);
	}

	/**
	 * Issue an API request; decode the JSON body. Throws CloudComputeException
	 * with the Linode error reason on failure. A 401 is surfaced with a
	 * distinguishable message so callers can mark the grant revoked.
	 */
	private function request(string $method, string $path, ?array $body = null, array $extra_headers = array()): array {
		$options = array(
			'headers' => array_merge(array(
				'Authorization' => 'Bearer ' . $this->access_token,
				'Accept'        => 'application/json',
			), $extra_headers),
		);
		if ($body !== null) {
			$options['json'] = $body;
		}
		try {
			$response = $this->http->request($method, $path, $options);
		} catch (RequestException $e) {
			$status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
			$reason = $this->extractError($e);
			if ($status === 401) {
				throw new CloudComputeException('unauthorized: ' . $reason, 401, $e);
			}
			throw new CloudComputeException('Linode API ' . $method . ' ' . $path . ' failed (' . $status . '): ' . $reason, $status, $e);
		} catch (GuzzleException $e) {
			// A timeout, a DNS failure, a refused connection: not a
			// RequestException, and its message carries the full URL. Wrapped
			// so every caller sees one exception type, and a path carrying a
			// secret is redacted by the caller that knows it.
			throw new CloudComputeException('Linode API ' . $method . ' ' . $path . ' failed (0): ' . $e->getMessage(), 0, $e);
		}
		$decoded = json_decode((string)$response->getBody(), true);
		return is_array($decoded) ? $decoded : array();
	}

	/**
	 * Pull the human reason out of a Linode error envelope ({"errors":[{"reason":...}]}).
	 */
	private function extractError(RequestException $e): string {
		if (!$e->getResponse()) {
			return $e->getMessage();
		}
		$decoded = json_decode((string)$e->getResponse()->getBody(), true);
		if (isset($decoded['errors'][0]['reason'])) {
			$parts = array();
			foreach ($decoded['errors'] as $err) {
				$field = isset($err['field']) ? $err['field'] . ': ' : '';
				$parts[] = $field . ($err['reason'] ?? '');
			}
			return implode('; ', $parts);
		}
		return $e->getMessage();
	}
}

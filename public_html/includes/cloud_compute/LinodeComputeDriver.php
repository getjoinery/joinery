<?php
/**
 * LinodeComputeDriver - Linode API v4 implementation of CloudComputeProvider.
 *
 * Acts on the account that issued the bearer token (the customer's), so
 * instances it creates are billed by Linode to the customer. Requires the
 * 'linodes:read_write' OAuth scope.
 *
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
use GuzzleHttp\Exception\RequestException;

class LinodeComputeDriver implements CloudComputeProvider {

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
		return array(
			'id'     => isset($instance['id']) ? (string)$instance['id'] : '',
			'status' => isset($instance['status']) ? (string)$instance['status'] : '',
			'ip'     => $ip,
			'label'  => isset($instance['label']) ? (string)$instance['label'] : '',
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

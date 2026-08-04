<?php
/**
 * LinodeComputeDriver - Linode API v4 implementation of CloudComputeProvider.
 *
 * Acts on the account that issued the bearer token (the customer's), so
 * instances it creates are billed by Linode to the customer. Requires the
 * 'linodes:read_write' OAuth scope.
 *
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
		foreach (array('label', 'region', 'type', 'image', 'root_pass') as $required) {
			if (empty($opts[$required])) {
				throw new CloudComputeException('createInstance missing required option: ' . $required);
			}
		}
		$body = array(
			'label'     => $opts['label'],
			'region'    => $opts['region'],
			'type'      => $opts['type'],
			'image'     => $opts['image'],
			'root_pass' => $opts['root_pass'],
			'booted'    => true,
		);
		if (!empty($opts['authorized_keys'])) {
			$body['authorized_keys'] = array_values($opts['authorized_keys']);
		}
		return $this->normalize($this->request('POST', 'linode/instances', $body));
	}

	public function getInstance(string $instance_id): array {
		return $this->normalize($this->request('GET', 'linode/instances/' . rawurlencode($instance_id)));
	}

	public function rebuildInstance(string $instance_id, array $opts): array {
		foreach (array('image', 'root_pass') as $required) {
			if (empty($opts[$required])) {
				throw new CloudComputeException('rebuildInstance missing required option: ' . $required);
			}
		}
		$body = array(
			'image'     => $opts['image'],
			'root_pass' => $opts['root_pass'],
			'booted'    => true,
		);
		if (!empty($opts['authorized_keys'])) {
			$body['authorized_keys'] = array_values($opts['authorized_keys']);
		}
		// Linode keeps the Linode object and its IPv4 across a rebuild; only the
		// disks and configuration profiles are replaced.
		return $this->normalize($this->request('POST',
			'linode/instances/' . rawurlencode($instance_id) . '/rebuild', $body));
	}

	public function deleteInstance(string $instance_id): void {
		$this->request('DELETE', 'linode/instances/' . rawurlencode($instance_id));
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
	private function request(string $method, string $path, ?array $body = null): array {
		$options = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->access_token,
				'Accept'        => 'application/json',
			),
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

<?php
/**
 * GetJoineryApiClient - Thin cURL wrapper for the getjoinery REST API.
 *
 * Used by provisioning tasks to poll orders and queue welcome emails.
 * Auth: public_key / secret_key request headers.
 *
 * @version 1.0
 */
class GetJoineryApiClient {

	private $base_url;
	private $public_key;
	private $secret_key;

	public function __construct($base_url, $public_key, $secret_key) {
		$this->base_url   = rtrim($base_url, '/');
		$this->public_key = $public_key;
		$this->secret_key = $secret_key;
	}

	/**
	 * GET /api/v1/{path}?{query}
	 * Returns the `data` field from the API envelope, or null on failure.
	 * Multi-result endpoints return an array; single-record endpoints return an associative array.
	 */
	public function get($path, array $query = []) {
		$url = $this->base_url . '/api/v1/' . ltrim($path, '/');
		if ($query) $url .= '?' . http_build_query($query);
		return $this->_request('GET', $url);
	}

	/**
	 * POST /api/v1/{path} with form-encoded body.
	 * Returns the `data` field from the API envelope, or null on failure.
	 */
	public function post($path, array $data) {
		$url = $this->base_url . '/api/v1/' . ltrim($path, '/');
		return $this->_request('POST', $url, $data);
	}

	private function _request($method, $url, array $post_data = []) {
		$ch = curl_init($url);
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_HTTPHEADER     => [
				'public_key: ' . $this->public_key,
				'secret_key: ' . $this->secret_key,
				'Accept: application/json',
			],
			CURLOPT_FOLLOWLOCATION => false,
		];
		if ($method === 'POST') {
			$opts[CURLOPT_POST]       = true;
			$opts[CURLOPT_POSTFIELDS] = http_build_query($post_data);
		}
		curl_setopt_array($ch, $opts);
		$body   = curl_exec($ch);
		$errno  = curl_errno($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($errno || $status < 200 || $status >= 300) return null;
		$decoded = json_decode($body, true);
		if (!is_array($decoded) || !array_key_exists('data', $decoded)) return null;
		return $decoded['data'];
	}
}
?>

<?php
/**
 * GetJoineryApiClient - Thin cURL wrapper for the getjoinery REST API.
 *
 * Used by provisioning tasks to poll orders and queue welcome emails.
 * Auth: public_key / secret_key request headers.
 *
 * A call that fails returns null, and last_error says why: the cURL error,
 * or the HTTP status with the API's own error, so a caller can name the
 * failure instead of guessing at credentials.
 *
 * @version 1.2 - last_error (B21)
 * @version 1.1
 */
class GetJoineryApiClient {

	private $base_url;
	private $public_key;
	private $secret_key;

	/** Why the last call returned null, or '' after one that worked. */
	public $last_error = '';

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
				// Dash spelling: Apache→FPM stacks silently drop header names
				// containing underscores (see api/apiv1.php's normalization).
				'public-key: ' . $this->public_key,
				'secret-key: ' . $this->secret_key,
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

		$this->last_error = '';
		if ($errno) {
			$this->last_error = 'no answer from ' . parse_url($url, PHP_URL_HOST) . ': ' . curl_error($ch);
			return null;
		}
		$decoded = json_decode((string)$body, true);
		if ($status < 200 || $status >= 300) {
			$said = (is_array($decoded) && is_string($decoded['error'] ?? null)) ? ': ' . mb_substr($decoded['error'], 0, 300) : '';
			$this->last_error = 'HTTP ' . $status . $said;
			return null;
		}
		if (!is_array($decoded) || !array_key_exists('data', $decoded)) {
			$this->last_error = 'HTTP ' . $status . ' with no API answer in it';
			return null;
		}
		return $decoded['data'];
	}
}
?>

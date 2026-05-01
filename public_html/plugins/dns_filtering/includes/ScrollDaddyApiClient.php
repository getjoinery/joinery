<?php
/**
 * ScrollDaddyApiClient - Helper for making API calls to ScrollDaddy DNS servers.
 *
 * Supports primary and optional secondary server for multi-server deployments.
 *
 * @version 1.0
 */

class ScrollDaddyApiClient {

	/**
	 * Call an endpoint on the primary DNS server.
	 *
	 * @param string $path    URL path (e.g. '/device/abc123/seen')
	 * @param string $method  HTTP method ('GET' or 'POST')
	 * @param int    $timeout Request timeout in seconds
	 * @return array|null     Decoded JSON response, or null on failure
	 */
	public static function callPrimary($path, $method = 'GET', $timeout = 5) {
		$settings = Globalvars::get_instance();
		$url = $settings->get_setting('dns_filtering_dns_internal_url');
		$api_key = $settings->get_setting('dns_filtering_dns_api_key');
		if (!$url) return null;
		return self::call($url, $api_key, $path, $method, $timeout);
	}

	/**
	 * Call an endpoint on the secondary DNS server.
	 * Returns null if no secondary server is configured.
	 *
	 * @param string $path    URL path (e.g. '/device/abc123/seen')
	 * @param string $method  HTTP method ('GET' or 'POST')
	 * @param int    $timeout Request timeout in seconds
	 * @return array|null     Decoded JSON response, or null on failure/not configured
	 */
	public static function callSecondary($path, $method = 'GET', $timeout = 5) {
		$settings = Globalvars::get_instance();
		$url = $settings->get_setting('dns_filtering_dns_secondary_internal_url');
		$api_key = $settings->get_setting('dns_filtering_dns_secondary_api_key');
		if (!$url) return null;
		// Fall back to primary API key if secondary key is not set
		if (!$api_key) {
			$api_key = $settings->get_setting('dns_filtering_dns_api_key');
		}
		return self::call($url, $api_key, $path, $method, $timeout);
	}

	/**
	 * Call an endpoint on both servers and return both responses.
	 *
	 * @param string $path    URL path
	 * @param string $method  HTTP method
	 * @param int    $timeout Request timeout in seconds
	 * @return array          ['primary' => response|null, 'secondary' => response|null]
	 */
	public static function callBoth($path, $method = 'GET', $timeout = 5) {
		return array(
			'primary'   => self::callPrimary($path, $method, $timeout),
			'secondary' => self::callSecondary($path, $method, $timeout),
		);
	}

	/**
	 * Make an HTTP request to a DNS server endpoint.
	 *
	 * @param string $base_url Server base URL (e.g. 'http://127.0.0.1:8053')
	 * @param string $api_key  API key for authentication
	 * @param string $path     URL path
	 * @param string $method   HTTP method
	 * @param int    $timeout  Request timeout in seconds
	 * @return array|null      Decoded JSON response, or null on failure
	 */
	private static function call($base_url, $api_key, $path, $method = 'GET', $timeout = 5) {
		$url = rtrim($base_url, '/') . $path;
		$ch = curl_init($url);

		$headers = array();
		if ($api_key) {
			$headers[] = 'X-API-Key: ' . $api_key;
		}
		if ($method === 'POST') {
			$headers[] = 'Content-Length: 0';
		}

		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_HTTPHEADER     => $headers,
		));

		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
		}

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false || $http_code < 200 || $http_code >= 300) {
			return null;
		}

		$data = json_decode($response, true);
		return $data;
	}
}
?>

<?php
/**
 * TargetTester — probe a BackupTarget's credentials and bucket.
 *
 * Uses curl against provider REST APIs (no CLI tools required on the web server).
 *
 * @version 2.0
 */

class TargetTester {

	const TIMEOUT_SECONDS = 15;

	/**
	 * Test a BackupTarget. Returns ['success' => bool, 'message' => string].
	 */
	public static function test($target) {
		$provider = $target->get('bkt_provider');
		$bucket = $target->get('bkt_bucket');
		$creds = $target->get_credentials();

		if (empty($bucket)) {
			return ['success' => false, 'message' => 'No bucket configured.'];
		}

		try {
			if ($provider === 'b2') {
				return self::test_b2($creds, $bucket);
			} elseif ($provider === 's3') {
				return self::test_s3($creds, $bucket, null);
			} elseif ($provider === 'linode') {
				$endpoint = $creds['endpoint'] ?? '';
				if (empty($endpoint)) {
					return ['success' => false, 'message' => 'Linode endpoint URL is required.'];
				}
				return self::test_s3($creds, $bucket, $endpoint);
			}
			return ['success' => false, 'message' => 'Unknown provider: ' . $provider];
		} catch (Exception $e) {
			return ['success' => false, 'message' => 'Test error: ' . $e->getMessage()];
		}
	}

	// ── Backblaze B2 ──

	private static function test_b2($creds, $bucket) {
		$key_id = $creds['key_id'] ?? '';
		$app_key = $creds['app_key'] ?? '';
		if (empty($key_id) || empty($app_key)) {
			return ['success' => false, 'message' => 'B2 Key ID and Application Key are required.'];
		}

		// Step 1: authorize_account
		$auth = self::curl_request(
			'GET',
			'https://api.backblazeb2.com/b2api/v3/b2_authorize_account',
			['Authorization: Basic ' . base64_encode($key_id . ':' . $app_key)]
		);
		if ($auth['status'] !== 200) {
			$err = self::extract_error($auth['body']) ?: 'HTTP ' . $auth['status'];
			return ['success' => false, 'message' => 'B2 authentication failed: ' . $err];
		}
		$auth_data = json_decode($auth['body'], true);
		if (!is_array($auth_data)) {
			return ['success' => false, 'message' => 'B2 returned unexpected response.'];
		}

		// If the key is bucket-restricted, check the restriction matches.
		$allowed_bucket = $auth_data['allowed']['bucketName'] ?? null;
		if ($allowed_bucket !== null && $allowed_bucket !== $bucket) {
			return [
				'success' => false,
				'message' => 'B2 key is restricted to bucket "' . $allowed_bucket . '", not "' . $bucket . '".',
			];
		}

		// Step 2: confirm the bucket exists using list_buckets (supports both key types).
		$api_url = $auth_data['apiInfo']['storageApi']['apiUrl'] ?? ($auth_data['apiUrl'] ?? '');
		$account_id = $auth_data['accountId'] ?? '';
		$token = $auth_data['authorizationToken'] ?? '';
		if (empty($api_url) || empty($account_id) || empty($token)) {
			return ['success' => false, 'message' => 'B2 response missing required fields.'];
		}

		$list = self::curl_request(
			'POST',
			rtrim($api_url, '/') . '/b2api/v3/b2_list_buckets',
			['Authorization: ' . $token, 'Content-Type: application/json'],
			json_encode(['accountId' => $account_id, 'bucketName' => $bucket])
		);
		if ($list['status'] !== 200) {
			$err = self::extract_error($list['body']) ?: 'HTTP ' . $list['status'];
			return ['success' => false, 'message' => 'B2 bucket lookup failed: ' . $err];
		}
		$list_data = json_decode($list['body'], true);
		if (empty($list_data['buckets'])) {
			return ['success' => false, 'message' => 'B2 bucket "' . $bucket . '" not found for this account.'];
		}

		return ['success' => true, 'message' => 'B2 credentials valid and bucket "' . $bucket . '" is accessible.'];
	}

	// ── S3 / Linode (S3-compatible) ──

	private static function test_s3($creds, $bucket, $custom_endpoint) {
		$access = $creds['access_key'] ?? '';
		$secret = $creds['secret_key'] ?? '';
		$region = $creds['region'] ?? 'us-east-1';
		if (empty($access) || empty($secret)) {
			return ['success' => false, 'message' => 'Access key and secret key are required.'];
		}

		// Build host + URL. Both AWS and Linode use path-style: https://{host}/{bucket}/?list-type=2&max-keys=1
		if ($custom_endpoint) {
			$parsed = parse_url($custom_endpoint);
			if (empty($parsed['host'])) {
				return ['success' => false, 'message' => 'Invalid endpoint URL.'];
			}
			$scheme = $parsed['scheme'] ?? 'https';
			$host = $parsed['host'];
		} else {
			$scheme = 'https';
			$host = 's3.' . $region . '.amazonaws.com';
		}

		$canonical_uri = '/' . rawurlencode($bucket) . '/';
		$canonical_querystring = 'list-type=2&max-keys=1';
		$url = $scheme . '://' . $host . $canonical_uri . '?' . $canonical_querystring;

		$amz_date = gmdate('Ymd\THis\Z');
		$date_stamp = gmdate('Ymd');
		$payload_hash = hash('sha256', '');

		$canonical_headers = "host:{$host}\n"
			. "x-amz-content-sha256:{$payload_hash}\n"
			. "x-amz-date:{$amz_date}\n";
		$signed_headers = 'host;x-amz-content-sha256;x-amz-date';

		$canonical_request = "GET\n{$canonical_uri}\n{$canonical_querystring}\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";

		$credential_scope = "{$date_stamp}/{$region}/s3/aws4_request";
		$string_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);

		$k_date    = hash_hmac('sha256', $date_stamp, 'AWS4' . $secret, true);
		$k_region  = hash_hmac('sha256', $region, $k_date, true);
		$k_service = hash_hmac('sha256', 's3', $k_region, true);
		$k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
		$signature = hash_hmac('sha256', $string_to_sign, $k_signing);

		$authorization = "AWS4-HMAC-SHA256 Credential={$access}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

		$response = self::curl_request('GET', $url, [
			'Host: ' . $host,
			'x-amz-content-sha256: ' . $payload_hash,
			'x-amz-date: ' . $amz_date,
			'Authorization: ' . $authorization,
		]);

		if ($response['status'] === 200) {
			return ['success' => true, 'message' => 'Credentials valid and bucket "' . $bucket . '" is accessible.'];
		}
		if ($response['status'] === 403) {
			return ['success' => false, 'message' => 'Access denied (403) — credentials rejected or lack permission on bucket "' . $bucket . '".'];
		}
		if ($response['status'] === 404) {
			return ['success' => false, 'message' => 'Bucket "' . $bucket . '" not found (404).'];
		}
		if ($response['status'] === 301 || $response['status'] === 400) {
			$err = self::extract_s3_error($response['body']);
			return ['success' => false, 'message' => 'Request rejected (HTTP ' . $response['status'] . '): ' . ($err ?: 'check region/endpoint') . '.'];
		}
		$err = self::extract_s3_error($response['body']) ?: ('HTTP ' . $response['status']);
		return ['success' => false, 'message' => 'Test failed: ' . $err];
	}

	// ── curl helper ──

	private static function curl_request($method, $url, $headers = [], $body = null) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		$response_body = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_err = curl_error($ch);
		curl_close($ch);
		if ($response_body === false) {
			throw new Exception('curl failed: ' . $curl_err);
		}
		return ['status' => (int)$status, 'body' => $response_body];
	}

	private static function extract_error($json_body) {
		$data = json_decode($json_body, true);
		if (is_array($data)) {
			return $data['message'] ?? $data['code'] ?? null;
		}
		return null;
	}

	private static function extract_s3_error($xml_body) {
		if (empty($xml_body)) return null;
		if (preg_match('#<Message>(.*?)</Message>#s', $xml_body, $m)) return trim($m[1]);
		if (preg_match('#<Code>(.*?)</Code>#s', $xml_body, $m)) return trim($m[1]);
		return null;
	}
}
?>

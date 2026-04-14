<?php
/**
 * TargetLister — enumerate objects under a BackupTarget's configured prefix.
 *
 * Uses curl against provider REST APIs (no CLI tools required on the web server).
 *
 * Return shape: ['success' => bool, 'files' => [...], 'truncated' => bool, 'error' => ?string]
 * Each file: ['key' => full-path, 'size' => bytes, 'modified' => ISO8601 string]
 *
 * @version 1.0
 */

class TargetLister {

	const TIMEOUT_SECONDS = 15;

	public static function list_files($target, $max = 500) {
		$provider = $target->get('bkt_provider');
		$bucket = $target->get('bkt_bucket');
		$prefix = rtrim($target->get('bkt_path_prefix') ?: 'joinery-backups', '/') . '/';
		$creds = $target->get_credentials();

		if (empty($bucket)) {
			return ['success' => false, 'files' => [], 'truncated' => false, 'error' => 'No bucket configured.'];
		}

		try {
			if ($provider === 'b2') {
				return self::list_b2($creds, $bucket, $prefix, $max);
			} elseif ($provider === 's3') {
				return self::list_s3($creds, $bucket, $prefix, null, $max);
			} elseif ($provider === 'linode') {
				$endpoint = $creds['endpoint'] ?? '';
				if (empty($endpoint)) {
					return ['success' => false, 'files' => [], 'truncated' => false, 'error' => 'Linode endpoint URL is required.'];
				}
				return self::list_s3($creds, $bucket, $prefix, $endpoint, $max);
			}
			return ['success' => false, 'files' => [], 'truncated' => false, 'error' => 'Unknown provider: ' . $provider];
		} catch (Exception $e) {
			return ['success' => false, 'files' => [], 'truncated' => false, 'error' => $e->getMessage()];
		}
	}

	// ── Backblaze B2 ──

	private static function list_b2($creds, $bucket, $prefix, $max) {
		$key_id = $creds['key_id'] ?? '';
		$app_key = $creds['app_key'] ?? '';
		if (empty($key_id) || empty($app_key)) {
			return ['success' => false, 'files' => [], 'truncated' => false, 'error' => 'B2 Key ID and Application Key are required.'];
		}

		// Step 1: authorize
		$auth = self::curl_request('GET',
			'https://api.backblazeb2.com/b2api/v3/b2_authorize_account',
			['Authorization: Basic ' . base64_encode($key_id . ':' . $app_key)]
		);
		if ($auth['status'] !== 200) {
			return ['success' => false, 'files' => [], 'truncated' => false, 'error' => 'B2 authentication failed (HTTP ' . $auth['status'] . ').'];
		}
		$auth_data = json_decode($auth['body'], true);
		$api_url = $auth_data['apiInfo']['storageApi']['apiUrl'] ?? ($auth_data['apiUrl'] ?? '');
		$account_id = $auth_data['accountId'] ?? '';
		$token = $auth_data['authorizationToken'] ?? '';

		// Step 2: look up bucketId
		$lb = self::curl_request('POST',
			rtrim($api_url, '/') . '/b2api/v3/b2_list_buckets',
			['Authorization: ' . $token, 'Content-Type: application/json'],
			json_encode(['accountId' => $account_id, 'bucketName' => $bucket])
		);
		if ($lb['status'] !== 200) {
			return ['success' => false, 'files' => [], 'truncated' => false, 'error' => 'B2 bucket lookup failed (HTTP ' . $lb['status'] . ').'];
		}
		$lb_data = json_decode($lb['body'], true);
		if (empty($lb_data['buckets'][0]['bucketId'])) {
			return ['success' => false, 'files' => [], 'truncated' => false, 'error' => 'B2 bucket "' . $bucket . '" not found.'];
		}
		$bucket_id = $lb_data['buckets'][0]['bucketId'];

		// Step 3: list_file_names with prefix, paginated up to $max
		$files = [];
		$start_name = null;
		$truncated = false;
		while (count($files) < $max) {
			$body = ['bucketId' => $bucket_id, 'prefix' => $prefix, 'maxFileCount' => min(1000, $max - count($files))];
			if ($start_name) $body['startFileName'] = $start_name;

			$r = self::curl_request('POST',
				rtrim($api_url, '/') . '/b2api/v3/b2_list_file_names',
				['Authorization: ' . $token, 'Content-Type: application/json'],
				json_encode($body)
			);
			if ($r['status'] !== 200) {
				return ['success' => false, 'files' => $files, 'truncated' => false, 'error' => 'B2 list failed (HTTP ' . $r['status'] . ').'];
			}
			$data = json_decode($r['body'], true);
			foreach ($data['files'] ?? [] as $f) {
				$ts = isset($f['uploadTimestamp']) ? gmdate('Y-m-d\TH:i:s\Z', (int)($f['uploadTimestamp'] / 1000)) : '';
				$files[] = [
					'key' => $f['fileName'] ?? '',
					'size' => (int)($f['contentLength'] ?? 0),
					'modified' => $ts,
				];
			}
			if (empty($data['nextFileName'])) break;
			$start_name = $data['nextFileName'];
			if (count($files) >= $max) { $truncated = true; break; }
		}

		return ['success' => true, 'files' => $files, 'truncated' => $truncated, 'error' => null];
	}

	// ── S3 / Linode (S3-compatible) ──

	private static function list_s3($creds, $bucket, $prefix, $custom_endpoint, $max) {
		$access = $creds['access_key'] ?? '';
		$secret = $creds['secret_key'] ?? '';
		$region = $creds['region'] ?? 'us-east-1';
		if (empty($access) || empty($secret)) {
			return ['success' => false, 'files' => [], 'truncated' => false, 'error' => 'Access key and secret key are required.'];
		}

		if ($custom_endpoint) {
			$parsed = parse_url($custom_endpoint);
			if (empty($parsed['host'])) {
				return ['success' => false, 'files' => [], 'truncated' => false, 'error' => 'Invalid endpoint URL.'];
			}
			$scheme = $parsed['scheme'] ?? 'https';
			$host = $parsed['host'];
		} else {
			$scheme = 'https';
			$host = 's3.' . $region . '.amazonaws.com';
		}

		$files = [];
		$continuation = null;
		$truncated = false;

		while (count($files) < $max) {
			$page_size = min(1000, $max - count($files));
			$params = [
				'list-type' => '2',
				'max-keys' => (string)$page_size,
				'prefix' => $prefix,
			];
			if ($continuation !== null) $params['continuation-token'] = $continuation;

			$response = self::signed_s3_get($access, $secret, $region, $host, $scheme, '/' . rawurlencode($bucket) . '/', $params);

			if ($response['status'] !== 200) {
				$err = self::extract_s3_error($response['body']) ?: ('HTTP ' . $response['status']);
				return ['success' => false, 'files' => $files, 'truncated' => false, 'error' => $err];
			}

			$xml = simplexml_load_string($response['body']);
			if ($xml === false) {
				return ['success' => false, 'files' => $files, 'truncated' => false, 'error' => 'Could not parse listing XML.'];
			}

			foreach ($xml->Contents as $c) {
				$files[] = [
					'key' => (string)$c->Key,
					'size' => (int)$c->Size,
					'modified' => (string)$c->LastModified,
				];
			}

			$is_truncated = ((string)$xml->IsTruncated) === 'true';
			if (!$is_truncated) break;
			$continuation = (string)$xml->NextContinuationToken;
			if (count($files) >= $max) { $truncated = true; break; }
		}

		return ['success' => true, 'files' => $files, 'truncated' => $truncated, 'error' => null];
	}

	// ── SigV4 signed GET with query params ──

	private static function signed_s3_get($access, $secret, $region, $host, $scheme, $canonical_uri, $params) {
		ksort($params);
		$canonical_querystring = '';
		foreach ($params as $k => $v) {
			if ($canonical_querystring !== '') $canonical_querystring .= '&';
			$canonical_querystring .= rawurlencode($k) . '=' . rawurlencode($v);
		}

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

		return self::curl_request('GET', $url, [
			'Host: ' . $host,
			'x-amz-content-sha256: ' . $payload_hash,
			'x-amz-date: ' . $amz_date,
			'Authorization: ' . $authorization,
		]);
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

	private static function extract_s3_error($xml_body) {
		if (empty($xml_body)) return null;
		if (preg_match('#<Message>(.*?)</Message>#s', $xml_body, $m)) return trim($m[1]);
		if (preg_match('#<Code>(.*?)</Code>#s', $xml_body, $m)) return trim($m[1]);
		return null;
	}
}
?>

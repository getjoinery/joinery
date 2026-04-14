<?php
/**
 * S3Signer — AWS SigV4 signing for S3-compatible providers.
 *
 * Supports any S3-compatible endpoint: Amazon S3, Backblaze B2 (via S3-compat
 * endpoint), Linode Object Storage, DigitalOcean Spaces, Wasabi, Cloudflare R2,
 * MinIO, etc.
 *
 * Expected credential shape: ['access_key' => ..., 'secret_key' => ...,
 *                             'region' => ..., 'endpoint' => ...]
 *
 * @version 1.0
 */

class S3SignerException extends Exception {}

class S3Signer {

	const TIMEOUT_SECONDS = 15;
	const SERVICE = 's3';

	/**
	 * Execute a signed GET against a bucket path with querystring params.
	 * Returns ['status' => int, 'body' => string, 'headers' => array].
	 *
	 * @param array  $creds   ['access_key','secret_key','region','endpoint']
	 * @param string $bucket  Bucket name.
	 * @param string $path    Path after the bucket (e.g., '/' or '/some/key'). Leading slash required.
	 * @param array  $params  Query-string params.
	 */
	public static function get($creds, $bucket, $path, $params = []) {
		return self::request('GET', $creds, $bucket, $path, $params);
	}

	/**
	 * Execute a signed DELETE against a bucket object.
	 * Returns ['status' => int, 'body' => string, 'headers' => array].
	 */
	public static function delete($creds, $bucket, $path) {
		return self::request('DELETE', $creds, $bucket, $path, []);
	}

	/**
	 * Execute a signed PUT from a local file path (streamed).
	 * Uses x-amz-content-sha256: UNSIGNED-PAYLOAD so we do not have to pre-hash.
	 */
	public static function put_file($creds, $bucket, $path, $local_path, $content_type = 'application/octet-stream') {
		$size = filesize($local_path);
		if ($size === false) {
			throw new S3SignerException('Cannot stat local file: ' . $local_path);
		}
		$fh = fopen($local_path, 'rb');
		if (!$fh) {
			throw new S3SignerException('Cannot open local file: ' . $local_path);
		}
		try {
			return self::request('PUT', $creds, $bucket, $path, [], $fh, $size, $content_type);
		} finally {
			fclose($fh);
		}
	}

	/**
	 * Low-level signed request. $body can be null (GET/DELETE) or a stream resource (PUT).
	 */
	private static function request($method, $creds, $bucket, $path, $params, $body = null, $body_size = 0, $content_type = null) {
		self::validate_creds($creds);

		$endpoint = $creds['endpoint'];
		$region = $creds['region'];
		$parsed = parse_url($endpoint);
		if (empty($parsed['host'])) {
			throw new S3SignerException('Invalid endpoint: ' . $endpoint);
		}
		$scheme = $parsed['scheme'] ?? 'https';
		$host = $parsed['host'];

		// Canonical URI is "/{bucket}{path}" path-style. Encode bucket but leave "/" in path unescaped.
		$canonical_uri = '/' . rawurlencode($bucket) . self::encode_path($path);

		// Sorted querystring
		ksort($params);
		$canonical_qs = '';
		foreach ($params as $k => $v) {
			if ($canonical_qs !== '') $canonical_qs .= '&';
			$canonical_qs .= rawurlencode($k) . '=' . rawurlencode($v);
		}

		$amz_date = gmdate('Ymd\THis\Z');
		$date_stamp = gmdate('Ymd');

		// For GET/DELETE (no body) use SHA256(""). For PUT streaming, use UNSIGNED-PAYLOAD.
		$payload_hash = ($body === null) ? hash('sha256', '') : 'UNSIGNED-PAYLOAD';

		$headers = [
			'host' => $host,
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date' => $amz_date,
		];
		if ($content_type !== null) {
			$headers['content-type'] = $content_type;
		}
		if ($body !== null && $body_size > 0) {
			$headers['content-length'] = (string)$body_size;
		}

		// Canonical headers (sorted, lowercase keys, trimmed values)
		ksort($headers);
		$canonical_headers = '';
		$signed_header_names = [];
		foreach ($headers as $k => $v) {
			$canonical_headers .= $k . ':' . trim($v) . "\n";
			$signed_header_names[] = $k;
		}
		$signed_headers = implode(';', $signed_header_names);

		$canonical_request = "{$method}\n{$canonical_uri}\n{$canonical_qs}\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";

		$credential_scope = "{$date_stamp}/{$region}/" . self::SERVICE . "/aws4_request";
		$string_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);

		$k_date    = hash_hmac('sha256', $date_stamp, 'AWS4' . $creds['secret_key'], true);
		$k_region  = hash_hmac('sha256', $region, $k_date, true);
		$k_service = hash_hmac('sha256', self::SERVICE, $k_region, true);
		$k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
		$signature = hash_hmac('sha256', $string_to_sign, $k_signing);

		$authorization = "AWS4-HMAC-SHA256 Credential={$creds['access_key']}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

		$url = $scheme . '://' . $host . $canonical_uri . ($canonical_qs !== '' ? '?' . $canonical_qs : '');

		// Build header array for curl
		$curl_headers = ['Authorization: ' . $authorization];
		foreach ($headers as $k => $v) {
			// curl sets Host automatically; skip ours to avoid duplicate.
			if (strtolower($k) === 'host') continue;
			$curl_headers[] = $k . ': ' . $v;
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
		curl_setopt($ch, CURLOPT_HEADER, true); // include response headers in body

		if ($body !== null) {
			curl_setopt($ch, CURLOPT_UPLOAD, true);
			curl_setopt($ch, CURLOPT_INFILE, $body);
			curl_setopt($ch, CURLOPT_INFILESIZE, $body_size);
			curl_setopt($ch, CURLOPT_TIMEOUT, 3600); // uploads can take longer
		}

		$raw = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$curl_err = curl_error($ch);
		curl_close($ch);

		if ($raw === false) {
			throw new S3SignerException('curl failed: ' . $curl_err);
		}

		$resp_headers_raw = substr($raw, 0, $header_size);
		$resp_body = substr($raw, $header_size);
		$resp_headers = self::parse_headers($resp_headers_raw);

		return ['status' => $status, 'body' => $resp_body, 'headers' => $resp_headers];
	}

	public static function extract_error($xml_body) {
		if (empty($xml_body)) return null;
		if (preg_match('#<Message>(.*?)</Message>#s', $xml_body, $m)) return trim($m[1]);
		if (preg_match('#<Code>(.*?)</Code>#s', $xml_body, $m)) return trim($m[1]);
		return null;
	}

	private static function validate_creds($creds) {
		foreach (['access_key', 'secret_key', 'region', 'endpoint'] as $f) {
			if (empty($creds[$f])) {
				throw new S3SignerException("Missing required credential field: {$f}");
			}
		}
	}

	private static function encode_path($path) {
		if ($path === '' || $path === '/') return '/';
		// Keep slashes, encode everything else per segment.
		$parts = explode('/', $path);
		return implode('/', array_map('rawurlencode', $parts));
	}

	private static function parse_headers($raw) {
		$headers = [];
		foreach (preg_split("/\r?\n/", $raw) as $line) {
			if (strpos($line, ':') === false) continue;
			list($k, $v) = explode(':', $line, 2);
			$headers[strtolower(trim($k))] = trim($v);
		}
		return $headers;
	}
}
?>

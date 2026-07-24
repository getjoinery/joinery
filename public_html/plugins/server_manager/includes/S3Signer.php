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
 * @version 1.2 - list() (ListObjectsV2, continuation-token paged) so the control plane
 *                can enumerate a bucket prefix without a live node
 * @version 1.1
 */

class S3SignerException extends Exception {}

class S3Signer {

	const TIMEOUT_SECONDS = 15;
	// Object transfers (streamed upload/download of a backup archive) are not a
	// 15-second operation — a multi-GB restore download would otherwise time out.
	const DOWNLOAD_TIMEOUT_SECONDS = 3600;
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
	 * Execute a signed GET that streams the object body directly to a local file
	 * instead of buffering it in memory. Used for downloading backup archives,
	 * which can be many gigabytes and must never be held whole in RAM on a node.
	 * On success returns ['status' => 200, 'body' => '']; on an error status the
	 * (small XML) body is read back for the caller and the sink file removed.
	 */
	public static function get_to_file($creds, $bucket, $path, $local_path) {
		return self::request('GET', $creds, $bucket, $path, [], null, 0, null, $local_path);
	}

	/**
	 * Execute a signed DELETE against a bucket object.
	 * Returns ['status' => int, 'body' => string, 'headers' => array].
	 */
	public static function delete($creds, $bucket, $path) {
		return self::request('DELETE', $creds, $bucket, $path, []);
	}

	/**
	 * List every object under a prefix (ListObjectsV2), following the continuation
	 * token so a large bucket pages fully. Runs from anywhere the target credentials
	 * are available — the control plane included — so a decommissioned node's backups
	 * stay enumerable with no live host to proxy through.
	 *
	 * Returns a flat array of ['key' => string, 'size' => int, 'last_modified' => string].
	 * Throws S3SignerException on a non-200 status (with the provider's error message).
	 *
	 * @param string $prefix Key prefix to scope the listing (e.g. 'joinery-backups/slug/').
	 */
	public static function list($creds, $bucket, $prefix = '') {
		$objects = [];
		$token = null;
		// Hard page cap: a runaway loop guard, far above any real backup count.
		for ($page = 0; $page < 10000; $page++) {
			$params = ['list-type' => '2', 'prefix' => $prefix];
			if ($token !== null && $token !== '') {
				$params['continuation-token'] = $token;
			}
			$resp = self::get($creds, $bucket, '/', $params);
			if ((int)$resp['status'] !== 200) {
				$msg = self::extract_error($resp['body']) ?: ('HTTP ' . $resp['status']);
				throw new S3SignerException('List failed for bucket ' . $bucket . ': ' . $msg);
			}
			foreach (self::parse_list_contents($resp['body']) as $obj) {
				$objects[] = $obj;
			}
			$token = self::parse_next_token($resp['body']);
			if ($token === null) {
				break;
			}
		}
		return $objects;
	}

	/** Parse <Contents> entries out of a ListObjectsV2 XML body. Regex-based to
	 *  avoid a hard dependency on ext-simplexml and to tolerate namespace noise. */
	private static function parse_list_contents($xml) {
		$out = [];
		if (!is_string($xml) || $xml === '') return $out;
		if (preg_match_all('#<Contents>(.*?)</Contents>#s', $xml, $blocks)) {
			foreach ($blocks[1] as $block) {
				if (!preg_match('#<Key>(.*?)</Key>#s', $block, $km)) continue;
				$key = html_entity_decode($km[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
				$size = preg_match('#<Size>(\d+)</Size>#', $block, $sm) ? (int)$sm[1] : 0;
				$lm = preg_match('#<LastModified>(.*?)</LastModified>#s', $block, $lmm) ? trim($lmm[1]) : '';
				$out[] = ['key' => $key, 'size' => $size, 'last_modified' => $lm];
			}
		}
		return $out;
	}

	/** Return the NextContinuationToken when the listing is truncated, else null. */
	private static function parse_next_token($xml) {
		if (is_string($xml) && preg_match('#<IsTruncated>\s*true\s*</IsTruncated>#i', $xml)
			&& preg_match('#<NextContinuationToken>(.*?)</NextContinuationToken>#s', $xml, $m)) {
			return html_entity_decode(trim($m[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
		}
		return null;
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
	private static function request($method, $creds, $bucket, $path, $params, $body = null, $body_size = 0, $content_type = null, $sink_file = null) {
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
		curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);

		// Streaming download to disk: write the body straight to the sink file so
		// a multi-GB archive never lands whole in RAM, with a long transfer window.
		if ($sink_file !== null) {
			$sink = fopen($sink_file, 'wb');
			if (!$sink) {
				curl_close($ch);
				throw new S3SignerException('Cannot open sink file: ' . $sink_file);
			}
			curl_setopt($ch, CURLOPT_FILE, $sink);
			curl_setopt($ch, CURLOPT_TIMEOUT, self::DOWNLOAD_TIMEOUT_SECONDS);
			$ok = curl_exec($ch);
			$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curl_err = curl_error($ch);
			curl_close($ch);
			fclose($sink);
			if ($ok === false) {
				@unlink($sink_file);
				throw new S3SignerException('curl failed: ' . $curl_err);
			}
			if ($status !== 200) {
				// Error responses are small XML — hand them back, then drop the
				// file so a failed download never leaves a bogus archive behind.
				$body_back = @file_get_contents($sink_file);
				@unlink($sink_file);
				return ['status' => $status, 'body' => (string)$body_back, 'headers' => []];
			}
			return ['status' => $status, 'body' => '', 'headers' => []];
		}

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
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

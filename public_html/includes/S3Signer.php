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
 * @version 1.5 - presign_get(): a URL that reads one object for a bounded time, so a node can
 *                fetch a backup back without ever being handed a bucket credential
 * @version 1.4 - multipart upload: put_file() switches to the S3 multipart API above
 *                MULTIPART_THRESHOLD_BYTES, lifting the 5 GB single-PUT ceiling; parts
 *                are hashed and signed individually, retried on the existing budget,
 *                and an incomplete upload is aborted rather than left claimable
 * @version 1.3 - transient provider failures (5xx, 429, transport errors) are retried with
 *                backoff inside request(), bounded by a wall-clock budget
 * @version 1.2 - list() (ListObjectsV2, continuation-token paged) so the management node
 *                can enumerate a bucket prefix without a live node
 * @version 1.1
 */

class S3SignerException extends Exception {}

class S3Signer {

	const TIMEOUT_SECONDS = 15;
	// Object transfers (streamed upload/download of a backup archive) are not a
	// 15-second operation — a multi-GB restore download would otherwise time out.
	const TRANSFER_TIMEOUT_SECONDS = 3600;
	const SERVICE = 's3';

	// Retry policy. Every request this class makes is idempotent — a PUT overwrites
	// its key, an UploadPart overwrites its part number within its uploadId, and
	// GET/DELETE/list have no cumulative effect — so a repeat is a no-op rather than
	// a duplicate. That is what makes retrying safe here. The one repeat that could
	// leave residue is a CreateMultipartUpload whose response was lost: the retry
	// mints a second uploadId and the first is orphaned, which is why the bucket
	// carries a cancel-unfinished-multipart lifecycle rule as backstop.
	const MAX_ATTEMPTS = 3;
	const RETRY_BASE_DELAY_SECONDS = 2;
	// Extra wall clock, on top of one attempt's timeout, that retries may consume.
	// This is what bounds the total: an attempt that burns the entire transfer
	// timeout leaves no room for another, which is the right answer — a transfer
	// that cannot finish in an hour will not finish on the second try either.
	const RETRY_WINDOW_SECONDS = 1200;

	// Objects above the threshold upload via the S3 multipart API; the single PUT
	// every provider caps at 5 GB stays the path for everything smaller. The
	// threshold is deliberately well under the cap so the multipart path runs
	// routinely (a nightly database dump crosses it) instead of only in the
	// emergency it exists for — a path exercised only in emergencies is broken
	// when the emergency comes. Parts are read into memory one at a time to be
	// hashed and signed, so the part size is also the peak memory cost, chosen to
	// fit the smallest fleet VPS. 100 MiB × the API's 10,000-part cap ≈ 1 TB per
	// object, far beyond any plausible artifact.
	const MULTIPART_THRESHOLD_BYTES = 1073741824;   // 1 GiB
	const MULTIPART_PART_BYTES = 104857600;         // 100 MiB
	const MULTIPART_MAX_PARTS = 10000;

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
	 * are available — the management node included — so a decommissioned node's backups
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
	 * Upload a local file to a bucket key. At or below MULTIPART_THRESHOLD_BYTES
	 * this is one signed streamed PUT (x-amz-content-sha256: UNSIGNED-PAYLOAD so
	 * the file is never pre-hashed); above it the multipart API takes over
	 * transparently — same arguments, same return shape, no caller involvement.
	 */
	public static function put_file($creds, $bucket, $path, $local_path, $content_type = 'application/octet-stream') {
		$size = filesize($local_path);
		if ($size === false) {
			throw new S3SignerException('Cannot stat local file: ' . $local_path);
		}
		if ($size > self::MULTIPART_THRESHOLD_BYTES) {
			return self::put_file_multipart($creds, $bucket, $path, $local_path, $content_type);
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
	 * Multipart upload: CreateMultipartUpload, one signed UploadPart per planned
	 * part, CompleteMultipartUpload. Each part is read into memory, hashed, and
	 * signed with its real payload hash — the source is a seekable file, so a
	 * failed part is simply re-read and retried on the ordinary request budget.
	 * Any failure aborts the upload (best-effort) so no partial object survives
	 * to be mistaken for a backup.
	 *
	 * Returns the same ['status','body','headers',...] shape as a single PUT: a
	 * failed sub-request hands back that sub-request's response so the caller's
	 * status check and error extraction work unchanged.
	 *
	 * Public, with an overridable part size, so the flow is testable against a
	 * local fixture without a gigabyte file; production callers go through
	 * put_file() and never pass $part_size.
	 */
	public static function put_file_multipart($creds, $bucket, $path, $local_path, $content_type = 'application/octet-stream', $part_size = self::MULTIPART_PART_BYTES) {
		$size = filesize($local_path);
		if ($size === false) {
			throw new S3SignerException('Cannot stat local file: ' . $local_path);
		}
		$parts = self::plan_parts($size, $part_size);

		$create = self::request('POST', $creds, $bucket, $path, ['uploads' => ''], null, 0, $content_type);
		if ((int)$create['status'] < 200 || (int)$create['status'] >= 300) {
			return $create;
		}
		$upload_id = null;
		if (preg_match('#<UploadId>(.*?)</UploadId>#s', (string)$create['body'], $m)) {
			$upload_id = html_entity_decode(trim($m[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
		}
		if ($upload_id === null || $upload_id === '') {
			throw new S3SignerException('CreateMultipartUpload returned no uploadId.');
		}

		$fh = fopen($local_path, 'rb');
		if (!$fh) {
			self::abort_multipart($creds, $bucket, $path, $upload_id);
			throw new S3SignerException('Cannot open local file: ' . $local_path);
		}

		$done = false;
		try {
			$etags = [];
			foreach ($parts as $p) {
				$chunk = self::read_exactly($fh, $p['bytes'], $local_path);
				$resp = self::request('PUT', $creds, $bucket, $path,
					['partNumber' => (string)$p['number'], 'uploadId' => $upload_id],
					$chunk, strlen($chunk));
				if ((int)$resp['status'] < 200 || (int)$resp['status'] >= 300) {
					$resp['retry_log'][] = 'multipart: part ' . $p['number'] . ' of ' . count($parts) . ' failed; upload aborted';
					return $resp;
				}
				$etag = trim((string)($resp['headers']['etag'] ?? ''));
				if ($etag === '') {
					throw new S3SignerException('UploadPart returned no ETag for part ' . $p['number'] . '.');
				}
				$etags[$p['number']] = $etag;
			}

			$xml = self::build_complete_xml($etags);
			// CompleteMultipartUpload has its own trap: a provider can answer
			// HTTP 200 with an <Error> document in the body (the assembly failed
			// server-side after the response line was committed). Providers
			// document that as retryable, so it gets a bounded retry of its own;
			// what must never happen is a 200-with-Error read as success.
			$resp = null;
			for ($try = 1; $try <= self::MAX_ATTEMPTS; $try++) {
				$resp = self::request('POST', $creds, $bucket, $path, ['uploadId' => $upload_id],
					$xml, strlen($xml), 'application/xml');
				if ((int)$resp['status'] < 200 || (int)$resp['status'] >= 300) {
					$resp['retry_log'][] = 'multipart: CompleteMultipartUpload failed; upload aborted';
					return $resp;
				}
				if (self::complete_body_ok($resp['body'])) {
					$done = true;
					return $resp;
				}
				if ($try < self::MAX_ATTEMPTS) {
					sleep(self::RETRY_BASE_DELAY_SECONDS * (1 << ($try - 1)));
				}
			}
			// Semantically a server failure, whatever the status line said: hand
			// back a 5xx so no caller's success check can be fooled by the 200.
			$resp['retry_log'][] = 'multipart: CompleteMultipartUpload answered HTTP ' . $resp['status']
				. ' with an error body after ' . self::MAX_ATTEMPTS . ' attempts; upload aborted';
			$resp['status'] = 500;
			return $resp;
		} finally {
			fclose($fh);
			if (!$done) {
				self::abort_multipart($creds, $bucket, $path, $upload_id);
			}
		}
	}

	/**
	 * Slice an object into upload parts: every part is $part_size except the
	 * last, which takes the remainder. Pure and public so the arithmetic —
	 * boundaries, the last part, the part-count cap — is testable without a
	 * network.
	 *
	 * Returns [['number' => 1-based part number, 'offset' => byte offset,
	 * 'bytes' => part length], ...].
	 */
	public static function plan_parts($size, $part_size = self::MULTIPART_PART_BYTES) {
		$size = (int)$size;
		$part_size = (int)$part_size;
		if ($size <= 0) {
			throw new S3SignerException('Cannot plan a multipart upload of ' . $size . ' bytes.');
		}
		if ($part_size <= 0) {
			throw new S3SignerException('Invalid multipart part size: ' . $part_size . '.');
		}
		$count = (int)ceil($size / $part_size);
		if ($count > self::MULTIPART_MAX_PARTS) {
			throw new S3SignerException('An object of ' . $size . ' bytes needs ' . $count
				. ' parts of ' . $part_size . ' bytes, over the ' . self::MULTIPART_MAX_PARTS . '-part API cap.');
		}
		$parts = [];
		for ($i = 0; $i < $count; $i++) {
			$offset = $i * $part_size;
			$parts[] = [
				'number' => $i + 1,
				'offset' => $offset,
				'bytes'  => min($part_size, $size - $offset),
			];
		}
		return $parts;
	}

	/**
	 * The CompleteMultipartUpload request body: every part number with the ETag
	 * the provider returned for it. Pure and public for the same testability
	 * reason as plan_parts().
	 *
	 * @param array $etags [part number => ETag as returned, quotes and all]
	 */
	public static function build_complete_xml(array $etags) {
		ksort($etags);
		$xml = '<CompleteMultipartUpload>';
		foreach ($etags as $number => $etag) {
			$xml .= '<Part><PartNumber>' . (int)$number . '</PartNumber><ETag>'
				. htmlspecialchars((string)$etag, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</ETag></Part>';
		}
		return $xml . '</CompleteMultipartUpload>';
	}

	/**
	 * Did a CompleteMultipartUpload body actually report completion? A 2xx status
	 * alone does not answer that — see the trap note at the call site. Pure and
	 * public so the trap has a test.
	 */
	public static function complete_body_ok($body) {
		return is_string($body) && $body !== ''
			&& !preg_match('#<Error\b#i', $body)
			&& stripos($body, 'CompleteMultipartUploadResult') !== false;
	}

	/**
	 * Best-effort AbortMultipartUpload. Called on every failure path; a failure
	 * here is logged and swallowed, because the caller is already reporting the
	 * real error and the bucket's cancel-unfinished-multipart lifecycle rule is
	 * the backstop for an abort that never lands.
	 */
	private static function abort_multipart($creds, $bucket, $path, $upload_id) {
		try {
			self::request('DELETE', $creds, $bucket, $path, ['uploadId' => $upload_id]);
		} catch (\Throwable $e) {
			error_log('S3Signer: could not abort multipart upload ' . $upload_id . ': ' . $e->getMessage());
		}
	}

	/** Read exactly $bytes from an open handle, or throw — a short read silently
	 *  truncating an upload part is the kind of corruption a signed per-part hash
	 *  exists to prevent, so it must not get as far as the wire. */
	private static function read_exactly($fh, $bytes, $local_path) {
		$chunk = '';
		$remaining = (int)$bytes;
		while ($remaining > 0) {
			$piece = fread($fh, min($remaining, 8388608));
			if ($piece === false || $piece === '') {
				throw new S3SignerException('Short read from ' . $local_path . ' ('
					. $remaining . ' of ' . $bytes . ' part bytes missing).');
			}
			$chunk .= $piece;
			$remaining -= strlen($piece);
		}
		return $chunk;
	}

	/**
	 * Low-level signed request. $body can be null (GET/DELETE), a stream resource
	 * (streamed PUT, signed UNSIGNED-PAYLOAD), or a string (a multipart part or a
	 * small XML control body, signed with its real payload hash).
	 *
	 * Transient provider failures are retried with backoff. A single HTTP 500 from
	 * the storage provider used to strand a backup on the node with no offsite copy
	 * and no way to retry short of running the whole backup again; see is_retryable()
	 * for what counts as transient and MAX_ATTEMPTS/RETRY_WINDOW_SECONDS for the
	 * bound. Returns ['status','body','headers','attempts','retry_log'].
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
		// A non-default port belongs in the host, for the URL and for the signature
		// alike: curl sends `Host: host:port`, and SigV4 signs the host header, so
		// dropping the port here produces a signature the provider rejects. Matters
		// for self-hosted endpoints (MinIO and friends), which are usually host:port.
		$host = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');

		// Canonical URI is "/{bucket}{path}" path-style. Encode bucket but leave "/" in path unescaped.
		$canonical_uri = '/' . rawurlencode($bucket) . self::encode_path($path);

		// Sorted querystring
		ksort($params);
		$canonical_qs = '';
		foreach ($params as $k => $v) {
			if ($canonical_qs !== '') $canonical_qs .= '&';
			$canonical_qs .= rawurlencode($k) . '=' . rawurlencode($v);
		}

		// A streamed upload or a download-to-file gets the long per-attempt window;
		// everything else is a small control call.
		$is_transfer = ($body !== null || $sink_file !== null);
		$attempt_timeout = $is_transfer ? self::TRANSFER_TIMEOUT_SECONDS : self::TIMEOUT_SECONDS;
		$deadline = microtime(true) + $attempt_timeout + self::RETRY_WINDOW_SECONDS;

		$retry_log = [];
		$attempts_used = 0;
		$last = null;            // last real HTTP response, if any attempt got one
		$last_transport_error = '';

		while (true) {
			$attempts_used++;

			// A retry must replay the body from byte zero. curl consumed the stream
			// on the previous attempt while CURLOPT_INFILESIZE still claims the full
			// length, so without this rewind curl sends nothing and then blocks
			// waiting for data that never arrives until the timeout expires — a
			// silent hang, not an error. A stream that cannot seek cannot be
			// replayed at all, so stop rather than PUT a truncated object. String
			// bodies replay themselves and need none of this.
			if ($attempts_used > 1 && $body !== null && !is_string($body) && !@rewind($body)) {
				$retry_log[] = 'not retried: upload stream could not be rewound';
				$attempts_used--;
				break;
			}

			$result = self::attempt(
				$method, $creds, $region, $scheme, $host, $canonical_uri, $canonical_qs,
				$body, $body_size, $content_type, $sink_file, $attempt_timeout
			);

			if (!$result['retryable']) {
				if ($result['transport_failed']) {
					throw new S3SignerException('curl failed: ' . $result['curl_error']
						. ($attempts_used > 1 ? ' (after ' . $attempts_used . ' attempts)' : ''));
				}
				return [
					'status'    => $result['status'],
					'body'      => $result['body'],
					'headers'   => $result['headers'],
					'attempts'  => $attempts_used,
					'retry_log' => $retry_log,
				];
			}

			if ($result['transport_failed']) {
				$last_transport_error = $result['curl_error'];
				$why = 'transport error ' . $result['curl_errno'] . ' (' . $result['curl_error'] . ')';
			} else {
				$last = $result;
				$msg = self::extract_error($result['body']);
				$why = 'HTTP ' . $result['status'] . ($msg ? ' ' . $msg : '');
			}

			if ($attempts_used >= self::MAX_ATTEMPTS) {
				$retry_log[] = "attempt {$attempts_used} failed ({$why}); no attempts left";
				break;
			}
			$delay = self::RETRY_BASE_DELAY_SECONDS * (1 << ($attempts_used - 1));
			if (microtime(true) + $delay >= $deadline) {
				$retry_log[] = "attempt {$attempts_used} failed ({$why}); out of time budget";
				break;
			}
			$retry_log[] = "attempt {$attempts_used} failed ({$why}); retrying in {$delay}s";
			sleep($delay);
			// Jitter, so a fleet-wide provider blip does not resynchronise every
			// node's retry into the same instant.
			usleep(mt_rand(0, 500000));
		}

		if ($last === null) {
			throw new S3SignerException('curl failed after ' . $attempts_used . ' attempt(s): ' . $last_transport_error);
		}
		return [
			'status'    => $last['status'],
			'body'      => $last['body'],
			'headers'   => $last['headers'],
			'attempts'  => $attempts_used,
			'retry_log' => $retry_log,
		];
	}

	/**
	 * One signed HTTP attempt. Signing lives here, not in the caller, because
	 * x-amz-date is part of the signature: SigV4 rejects a stale timestamp, so a
	 * replayed request would start failing with 403 the moment a backoff pushed it
	 * past the provider's clock-skew window. Every attempt signs afresh.
	 *
	 * Returns the raw outcome plus a 'retryable' verdict; the caller owns the loop.
	 */
	private static function attempt($method, $creds, $region, $scheme, $host, $canonical_uri, $canonical_qs,
	                                $body, $body_size, $content_type, $sink_file, $attempt_timeout) {

		$amz_date = gmdate('Ymd\THis\Z');
		$date_stamp = gmdate('Ymd');

		// No body: SHA256(""). String body (a multipart part, a control XML): its
		// real hash, so the provider verifies the bytes it received are the bytes
		// that were signed. Stream body: UNSIGNED-PAYLOAD, because the payload
		// hash must be known before the first byte is sent and a stream is not
		// read twice.
		if ($body === null) {
			$payload_hash = hash('sha256', '');
		} elseif (is_string($body)) {
			$payload_hash = hash('sha256', $body);
		} else {
			$payload_hash = 'UNSIGNED-PAYLOAD';
		}

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
		curl_setopt($ch, CURLOPT_TIMEOUT, $attempt_timeout);

		$sink = null;
		if ($sink_file !== null) {
			// Streaming download to disk: write the body straight to the sink file
			// so a multi-GB archive never lands whole in RAM. Opened fresh on every
			// attempt — 'wb' truncates, so a partial body from a failed attempt can
			// never be prepended to the next one's.
			$sink = fopen($sink_file, 'wb');
			if (!$sink) {
				throw new S3SignerException('Cannot open sink file: ' . $sink_file);
			}
			curl_setopt($ch, CURLOPT_FILE, $sink);
		} else {
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HEADER, true); // include response headers in body
			if (is_string($body)) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			} elseif ($body !== null) {
				curl_setopt($ch, CURLOPT_UPLOAD, true);
				curl_setopt($ch, CURLOPT_INFILE, $body);
				curl_setopt($ch, CURLOPT_INFILESIZE, $body_size);
			}
		}

		$raw = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$header_size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$curl_errno = curl_errno($ch);
		$curl_err = curl_error($ch);
		if ($sink !== null) {
			fclose($sink);
		}

		$transport_failed = ($raw === false);
		$resp_body = '';
		$resp_headers = [];

		if ($sink_file !== null) {
			if ($transport_failed || $status !== 200) {
				// Error responses are small XML — hand them back, then drop the
				// file so a failed download never leaves a bogus archive behind.
				if (!$transport_failed) {
					$resp_body = (string) @file_get_contents($sink_file);
				}
				@unlink($sink_file);
			}
		} elseif (!$transport_failed) {
			$resp_headers = self::parse_headers(substr($raw, 0, $header_size));
			$resp_body = substr($raw, $header_size);
		}

		return [
			'transport_failed' => $transport_failed,
			'curl_errno'       => $curl_errno,
			'curl_error'       => $curl_err,
			'status'           => $status,
			'body'             => $resp_body,
			'headers'          => $resp_headers,
			'retryable'        => $transport_failed
				? self::is_retryable(0, $curl_errno)
				: self::is_retryable($status, 0),
		];
	}

	/**
	 * Is this failure worth trying again?
	 *
	 * Transient: the provider's own 5xx (Backblaze B2 answers a blip with
	 * `500 internal incident`), throttling, request timeout, and the transport
	 * errors that mean the connection died rather than the request being wrong.
	 * Everything else — 403 signature, 404, 400 — is deterministic, so a retry
	 * only burns the time budget and buries the real message.
	 *
	 * Pure and public so the policy can be tested without a network.
	 */
	public static function is_retryable($status, $curl_errno = 0) {
		if ($curl_errno) {
			return in_array((int)$curl_errno, [
				CURLE_COULDNT_RESOLVE_HOST,
				CURLE_COULDNT_CONNECT,
				CURLE_OPERATION_TIMEDOUT,
				CURLE_SSL_CONNECT_ERROR,
				CURLE_PARTIAL_FILE,
				CURLE_GOT_NOTHING,
				CURLE_SEND_ERROR,
				CURLE_RECV_ERROR,
			], true);
		}
		$status = (int)$status;
		if ($status === 408 || $status === 429) return true;
		return $status >= 500 && $status <= 599;
	}

	/**
	 * Wall-clock ceiling one transfer can occupy, retries and backoff included.
	 * Job steps that shell out to the node uploader size their own timeout from
	 * this so the two cannot drift apart — a step budget smaller than the retry
	 * budget would have the agent kill the upload mid-retry.
	 */
	public static function transfer_budget_seconds() {
		return self::TRANSFER_TIMEOUT_SECONDS + self::RETRY_WINDOW_SECONDS;
	}

	public static function extract_error($xml_body) {
		if (empty($xml_body)) return null;
		if (preg_match('#<Message>(.*?)</Message>#s', $xml_body, $m)) return trim($m[1]);
		if (preg_match('#<Code>(.*?)</Code>#s', $xml_body, $m)) return trim($m[1]);
		return null;
	}

	/**
	 * A URL that reads ONE object, for a bounded number of seconds, and can do
	 * nothing else.
	 *
	 * This exists so that a node fetching a backup back out of the bucket never
	 * receives a bucket credential. The standing rule for the fleet is that a
	 * node holds a WRITE-ONLY credential — it may add to the shelf and may not
	 * read from it or clear it — because a node that could read the shelf is a
	 * node whose compromise reaches every other node's backups. A restore needs
	 * a read, and the honest way to grant exactly one read is to sign one, here,
	 * on the machine that already holds the credential, and hand over the
	 * signature rather than the key.
	 *
	 * What the node gets is therefore not a narrower credential; it is not a
	 * credential. It names one object key, it expires, and it cannot be
	 * re-pointed: the object key is inside the signature.
	 *
	 * SigV4 query-string form. The payload hash is UNSIGNED-PAYLOAD because a
	 * GET has no payload and every S3-compatible provider accepts that here;
	 * host is the only signed header, so the fetching side needs to set nothing
	 * but the URL.
	 *
	 * @param array  $creds   ['access_key','secret_key','region','endpoint']
	 * @param string $bucket  Bucket name.
	 * @param string $path    Object path after the bucket, leading slash required.
	 * @param int    $expires Seconds the URL stays valid. Clamped to the SigV4
	 *                        maximum of seven days; callers should pass the
	 *                        smallest window the transfer can finish in.
	 */
	public static function presign_get($creds, $bucket, $path, $expires = 3600) {
		self::validate_creds($creds);

		$expires = (int)$expires;
		if ($expires < 60) { $expires = 60; }
		if ($expires > 604800) { $expires = 604800; }   // SigV4 ceiling: 7 days

		$parsed = parse_url($creds['endpoint']);
		if (empty($parsed['host'])) {
			throw new S3SignerException('Invalid endpoint: ' . $creds['endpoint']);
		}
		$scheme = $parsed['scheme'] ?? 'https';
		// The port belongs in the host for the signature as well as the URL —
		// same reason request() gives: SigV4 signs the host header and curl
		// sends host:port.
		$host = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');

		$region     = $creds['region'];
		$amz_date   = gmdate('Ymd\THis\Z');
		$date_stamp = gmdate('Ymd');
		$scope      = "{$date_stamp}/{$region}/" . self::SERVICE . "/aws4_request";

		$canonical_uri = '/' . rawurlencode($bucket) . self::encode_path($path);

		$params = [
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $creds['access_key'] . '/' . $scope,
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Expires'       => (string)$expires,
			'X-Amz-SignedHeaders' => 'host',
		];
		ksort($params);
		$canonical_qs = '';
		foreach ($params as $k => $v) {
			if ($canonical_qs !== '') { $canonical_qs .= '&'; }
			$canonical_qs .= rawurlencode($k) . '=' . rawurlencode($v);
		}

		$canonical_headers = "host:{$host}\n";
		$canonical_request = "GET\n{$canonical_uri}\n{$canonical_qs}\n{$canonical_headers}\nhost\nUNSIGNED-PAYLOAD";
		$string_to_sign    = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash('sha256', $canonical_request);

		$k_date    = hash_hmac('sha256', $date_stamp, 'AWS4' . $creds['secret_key'], true);
		$k_region  = hash_hmac('sha256', $region, $k_date, true);
		$k_service = hash_hmac('sha256', self::SERVICE, $k_region, true);
		$k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
		$signature = hash_hmac('sha256', $string_to_sign, $k_signing);

		return $scheme . '://' . $host . $canonical_uri . '?' . $canonical_qs
			. '&X-Amz-Signature=' . $signature;
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

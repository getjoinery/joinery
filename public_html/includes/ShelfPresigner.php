<?php
/**
 * ShelfPresigner — SigV4 query-string presigning for any S3 verb.
 *
 * A presigned URL is one HTTP request, to one object key, for one operation,
 * valid for minutes, signed with a credential the requester never sees. The
 * shelf broker (specs/services_phase2_platform.md §3) hands these to a site
 * for every object it writes or reads, so no box ever holds a storage
 * credential; the standard is honoured identically by Backblaze, AWS and
 * Linode, which is what makes the shelf cross-provider by construction.
 *
 * The signing is the same as S3Signer::presign_get() — the verb and the
 * query are what vary: PUT for a single upload, GET for a read, POST ?uploads
 * to open a multipart upload, PUT ?partNumber&uploadId for each part, POST
 * ?uploadId to complete. The payload is always UNSIGNED-PAYLOAD (the URL is
 * signed before the bytes exist) and only the host header is signed. A URL
 * signs one key and one verb: a site handed a PUT URL for one key can write
 * exactly that key and nothing else; nothing signs a DELETE.
 *
 * @version 1.0
 */
class ShelfPresignerException extends Exception {}

class ShelfPresigner {

	const SERVICE = 's3';

	/** SigV4's ceiling on a presigned URL's life. */
	const MAX_EXPIRES = 604800;

	/**
	 * Presign one request.
	 *
	 * @param array  $creds   ['access_key','secret_key','region','endpoint']
	 * @param string $bucket
	 * @param string $key     The object key (no leading slash needed).
	 * @param string $method  GET | PUT | POST | HEAD
	 * @param array  $query   Extra query parameters, signed with the URL
	 *                        (e.g. ['uploads' => ''], ['partNumber' => '3', 'uploadId' => '…']).
	 * @param int    $expires Seconds the URL is good for.
	 */
	public static function presign(array $creds, string $bucket, string $key, string $method = 'GET',
			array $query = array(), int $expires = 3600): string {
		foreach (array('access_key', 'secret_key', 'region', 'endpoint') as $f) {
			if (empty($creds[$f])) {
				throw new ShelfPresignerException("Missing required credential field: {$f}");
			}
		}
		$method = strtoupper(trim($method));
		if (!in_array($method, array('GET', 'PUT', 'POST', 'HEAD'), true)) {
			// DELETE is refused by construction: the plane never signs one.
			throw new ShelfPresignerException('Refusing to presign a ' . $method . ' request.');
		}
		if ($expires < 60) { $expires = 60; }
		if ($expires > self::MAX_EXPIRES) { $expires = self::MAX_EXPIRES; }

		$parsed = parse_url((string)$creds['endpoint']);
		if (empty($parsed['host'])) {
			throw new ShelfPresignerException('Invalid endpoint: ' . $creds['endpoint']);
		}
		$scheme = $parsed['scheme'] ?? 'https';
		// The port belongs in the host for the signature as well as the URL:
		// SigV4 signs the host header and the client sends host:port.
		$host = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');

		$region     = (string)$creds['region'];
		$amz_date   = gmdate('Ymd\THis\Z');
		$date_stamp = gmdate('Ymd');
		$scope      = "{$date_stamp}/{$region}/" . self::SERVICE . "/aws4_request";

		$canonical_uri = '/' . rawurlencode($bucket) . self::encodeKey($key);

		$params = array(
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $creds['access_key'] . '/' . $scope,
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Expires'       => (string)$expires,
			'X-Amz-SignedHeaders' => 'host',
		);
		foreach ($query as $k => $v) {
			$params[(string)$k] = (string)$v;
		}
		ksort($params, SORT_STRING);
		$canonical_qs = '';
		foreach ($params as $k => $v) {
			if ($canonical_qs !== '') { $canonical_qs .= '&'; }
			$canonical_qs .= rawurlencode($k) . '=' . rawurlencode($v);
		}

		$canonical_headers = "host:{$host}\n";
		$canonical_request = "{$method}\n{$canonical_uri}\n{$canonical_qs}\n{$canonical_headers}\nhost\nUNSIGNED-PAYLOAD";
		$string_to_sign    = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash('sha256', $canonical_request);

		$k_date    = hash_hmac('sha256', $date_stamp, 'AWS4' . $creds['secret_key'], true);
		$k_region  = hash_hmac('sha256', $region, $k_date, true);
		$k_service = hash_hmac('sha256', self::SERVICE, $k_region, true);
		$k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
		$signature = hash_hmac('sha256', $string_to_sign, $k_signing);

		return $scheme . '://' . $host . $canonical_uri . '?' . $canonical_qs
			. '&X-Amz-Signature=' . $signature;
	}

	/** The five shapes the broker signs, by name. */
	public static function put(array $creds, string $bucket, string $key, int $expires = 3600): string {
		return self::presign($creds, $bucket, $key, 'PUT', array(), $expires);
	}

	public static function get(array $creds, string $bucket, string $key, int $expires = 3600): string {
		return self::presign($creds, $bucket, $key, 'GET', array(), $expires);
	}

	public static function multipartCreate(array $creds, string $bucket, string $key, int $expires = 3600): string {
		return self::presign($creds, $bucket, $key, 'POST', array('uploads' => ''), $expires);
	}

	public static function multipartPart(array $creds, string $bucket, string $key, string $upload_id, int $part_number, int $expires = 3600): string {
		return self::presign($creds, $bucket, $key, 'PUT',
			array('partNumber' => (string)$part_number, 'uploadId' => $upload_id), $expires);
	}

	public static function multipartComplete(array $creds, string $bucket, string $key, string $upload_id, int $expires = 3600): string {
		return self::presign($creds, $bucket, $key, 'POST', array('uploadId' => $upload_id), $expires);
	}

	/** Each path segment percent-encoded on its own, slashes kept. */
	private static function encodeKey(string $key): string {
		$key = '/' . ltrim($key, '/');
		return implode('/', array_map('rawurlencode', explode('/', $key)));
	}
}

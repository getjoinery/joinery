<?php
/**
 * CloudStorageS3Driver
 *
 * The file store's driver over S3Signer, the one S3 client: AWS S3, Backblaze
 * B2, Cloudflare R2, Wasabi, DigitalOcean Spaces, Linode, MinIO and the rest.
 * Addressing (path style, or the bucket as a host label on Amazon) is the
 * signer's, from the provider catalogue.
 *
 * Keys are full object keys: the driver adds nothing to them. A row records
 * the key its object was written under (CloudFileStore::key()), so a reader
 * hands that key back unchanged.
 *
 * @version 2.1 - a ranged get answered with the whole object (200) is cut to the span asked for
 * @version 2.0 - on S3Signer instead of the AWS SDK; keys are full keys, the folder is the caller's
 *                (specs/storage_targets.md WP6); putMany() is gone, the engine pushes one object at a time
 * @version 1.2 - one private store: no public base URL option; url() is the bucket's own address for an
 *                object, derived from endpoint + bucket (the endpoint's port kept), read only by the
 *                privacy gate's anonymous probe; the raw-host and CDN inspections are gone
 * @version 1.1 - head(): HeadObject as size and ETag, the interface's presence check; size() reads it
 * @version 1.0
 */

class CloudStorageS3Driver implements CloudStorageDriver {

	/** @var array S3Signer credential: access_key, secret_key, region, endpoint */
	private $creds;
	private $bucket;

	/**
	 * @param array $opts endpoint, region, bucket, access_key, secret_key
	 */
	public function __construct(array $opts) {
		foreach (['endpoint', 'bucket', 'access_key', 'secret_key'] as $field) {
			if (trim((string)($opts[$field] ?? '')) === '') {
				throw new RuntimeException('CloudStorageS3Driver requires endpoint, bucket, access_key, secret_key.');
			}
		}
		$this->bucket = (string)$opts['bucket'];
		$this->creds = [
			'access_key' => (string)$opts['access_key'],
			'secret_key' => (string)$opts['secret_key'],
			// A store with no region signs as us-east-1, which every
			// S3-compatible service that ignores regions accepts.
			'region'     => trim((string)($opts['region'] ?? '')) !== '' ? trim((string)$opts['region']) : 'us-east-1',
			'endpoint'   => (string)$opts['endpoint'],
		];
	}

	public function put(string $local_path, string $remote_key, string $content_type): void {
		$r = $this->call('put', $remote_key, function ($path) use ($local_path, $content_type) {
			return S3Signer::put_file($this->creds, $this->bucket, $path, $local_path, $content_type ?: 'application/octet-stream');
		});
		if ($r['status'] < 200 || $r['status'] >= 300) {
			throw new RuntimeException('S3 put failed for ' . $remote_key . ': ' . self::why($r));
		}
	}

	public function get(string $remote_key, string $local_path): void {
		self::ensure_parent($local_path);
		$r = $this->call('get', $remote_key, function ($path) use ($local_path) {
			return S3Signer::get_to_file($this->creds, $this->bucket, $path, $local_path);
		});
		if ($r['status'] !== 200) {
			throw new RuntimeException('S3 get failed for ' . $remote_key . ': ' . self::why($r));
		}
	}

	public function get_range(string $remote_key, string $local_path, int $start, int $end): void {
		self::ensure_parent($local_path);
		$r = $this->call('ranged get', $remote_key, function ($path) use ($local_path, $start, $end) {
			return S3Signer::get_range_to_file($this->creds, $this->bucket, $path, $local_path, $start, $end);
		});
		if ($r['status'] !== 206 && $r['status'] !== 200) {
			throw new RuntimeException('S3 ranged get failed for ' . $remote_key . ': ' . self::why($r));
		}
		// A provider that ignores the range answers 200 with the whole object;
		// the caller asked for the span and is handed the span.
		if ($r['status'] === 200 && (int)filesize($local_path) !== $end - $start + 1) {
			self::cut_to_span($local_path, $start, $end);
		}
	}

	/** Keep only bytes $start..$end of a downloaded file, read a chunk at a time. */
	private static function cut_to_span(string $path, int $start, int $end): void {
		$part = $path . '.span';
		$in = fopen($path, 'rb');
		$out = fopen($part, 'wb');
		if (!$in || !$out || fseek($in, $start) !== 0) {
			throw new RuntimeException('Could not cut the requested span out of ' . $path);
		}
		$left = $end - $start + 1;
		while ($left > 0 && !feof($in)) {
			$chunk = fread($in, min(1048576, $left));
			if ($chunk === false || $chunk === '') { break; }
			fwrite($out, $chunk);
			$left -= strlen($chunk);
		}
		fclose($in);
		fclose($out);
		rename($part, $path);
	}

	public function delete(string $remote_key): void {
		$r = $this->call('delete', $remote_key, function ($path) {
			return S3Signer::delete($this->creds, $this->bucket, $path);
		});
		// Deleting what is not there leaves it not there.
		if (($r['status'] < 200 || $r['status'] >= 300) && $r['status'] !== 404) {
			throw new RuntimeException('S3 delete failed for ' . $remote_key . ': ' . self::why($r));
		}
	}

	/**
	 * HeadObject: size and ETag, or null when the object is absent or the
	 * bucket could not answer. An absent object and an unanswered question are
	 * the same fact to every caller — the bytes cannot be served from here.
	 */
	public function head(string $remote_key): ?array {
		try {
			$r = S3Signer::head($this->creds, $this->bucket, '/' . ltrim($remote_key, '/'));
		} catch (Exception $e) {
			error_log('CloudStorageS3Driver::head failed for ' . $remote_key . ': ' . $e->getMessage());
			return null;
		}
		if ((int)$r['status'] !== 200) {
			if ((int)$r['status'] !== 404) {
				error_log('CloudStorageS3Driver::head for ' . $remote_key . ' answered HTTP ' . (int)$r['status']);
			}
			return null;
		}
		$len = $r['headers']['content-length'] ?? null;
		if ($len === null || $len === '') {
			return null;
		}
		return ['size' => (int)$len, 'etag' => trim((string)($r['headers']['etag'] ?? ''), '"')];
	}

	public function url(string $remote_key): string {
		return S3Signer::object_url($this->creds['endpoint'], $this->bucket, $remote_key);
	}

	/** Can the key list the bucket? One object asked for, nothing downloaded. */
	public function ping(): array {
		try {
			S3Signer::list($this->creds, $this->bucket, '', 1);
			return ['ok' => true, 'message' => 'The bucket answered'];
		} catch (Exception $e) {
			return ['ok' => false, 'message' => $e->getMessage()];
		}
	}

	/**
	 * Byte size of a stored object — metadata only, no download. Null when the
	 * object is missing or the head fails.
	 */
	public function size(string $remote_key): ?int {
		$head = $this->head($remote_key);
		return $head === null ? null : (int)$head['size'];
	}

	/** One signer call for a key, a transport failure turned into the driver's exception. */
	private function call(string $what, string $remote_key, callable $fn): array {
		try {
			return $fn('/' . ltrim($remote_key, '/'));
		} catch (S3SignerException $e) {
			throw new RuntimeException('S3 ' . $what . ' failed for ' . $remote_key . ': ' . $e->getMessage(), 0, $e);
		}
	}

	private static function why(array $r): string {
		return S3Signer::extract_error((string)($r['body'] ?? '')) ?: ('HTTP ' . (int)$r['status']);
	}

	private static function ensure_parent(string $local_path): void {
		$dir = dirname($local_path);
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
	}
}

<?php
/**
 * CloudStorageS3Driver
 *
 * S3-compatible cloud storage driver. Speaks the AWS S3 API, so it
 * works with AWS S3, Backblaze B2, Cloudflare R2, Wasabi, DigitalOcean
 * Spaces, MinIO, and similar services.
 *
 * Path-style vs virtual-hosted addressing is auto-detected from the
 * endpoint hostname (AWS → virtual-hosted, everything else → path-style).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));
require_once(PathHelper::getComposerAutoloadPath());

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise;

class CloudStorageS3Driver implements CloudStorageDriver {

	private $client;
	private $bucket;
	private $public_base_url;

	public function __construct(array $opts = []) {
		$settings = Globalvars::get_instance();
		$endpoint   = $opts['endpoint']        ?? $settings->get_setting('cloud_storage_endpoint');
		$region     = $opts['region']          ?? $settings->get_setting('cloud_storage_region');
		$bucket     = $opts['bucket']          ?? $settings->get_setting('cloud_storage_bucket');
		$access_key = $opts['access_key']      ?? $settings->get_setting('cloud_storage_access_key');
		$secret_key = $opts['secret_key']      ?? $settings->get_setting('cloud_storage_secret_key');
		$public_url = $opts['public_base_url'] ?? $settings->get_setting('cloud_storage_public_base_url');

		if (!$endpoint || !$bucket || !$access_key || !$secret_key) {
			throw new RuntimeException('CloudStorageS3Driver requires endpoint, bucket, access_key, secret_key.');
		}

		$this->bucket = $bucket;

		$endpoint_url = self::normalizeEndpointUrl($endpoint);
		$endpoint_host = parse_url($endpoint_url, PHP_URL_HOST) ?? $endpoint;

		// AWS uses virtual-hosted addressing; everything else uses path-style.
		$path_style = !preg_match('/\.amazonaws\.com$/i', $endpoint_host);

		$this->client = new S3Client([
			'version'                 => 'latest',
			'region'                  => $region ?: 'us-east-1',
			'endpoint'                => $endpoint_url,
			'use_path_style_endpoint' => $path_style,
			'credentials' => [
				'key'    => $access_key,
				'secret' => $secret_key,
			],
		]);

		// Auto-derive a public base URL when none is configured. The customer
		// only fills cloud_storage_public_base_url if they have a CDN/custom domain.
		$this->public_base_url = $public_url
			? rtrim($public_url, '/')
			: self::derivePublicBaseUrl($endpoint_url, $endpoint_host, $bucket, $path_style);
	}

	/**
	 * Push original + multiple variants concurrently in a single round trip.
	 * Returns array of [remote_key => true|Exception]. Used by sync tasks
	 * to avoid N sequential RTTs per row.
	 *
	 * @param array $items  Each item: ['local_path' => str, 'remote_key' => str, 'content_type' => str]
	 * @return array        Map of remote_key → true on success or Exception on failure.
	 */
	public function putMany(array $items): array {
		$promises = [];
		foreach ($items as $item) {
			$promises[$item['remote_key']] = $this->client->putObjectAsync([
				'Bucket'      => $this->bucket,
				'Key'         => self::pathPrefix() . '/' . ltrim($item['remote_key'], '/'),
				'Body'        => fopen($item['local_path'], 'rb'),
				'ContentType' => $item['content_type'] ?: 'application/octet-stream',
			]);
		}
		$results = Promise\Utils::settle($promises)->wait();
		$out = [];
		foreach ($results as $key => $r) {
			$out[$key] = ($r['state'] === 'fulfilled') ? true : ($r['reason'] instanceof Throwable ? $r['reason'] : new RuntimeException('unknown error'));
		}
		return $out;
	}

	public function put(string $local_path, string $remote_key, string $content_type): void {
		try {
			$this->client->putObject([
				'Bucket'      => $this->bucket,
				'Key'         => self::pathPrefix() . '/' . ltrim($remote_key, '/'),
				'SourceFile'  => $local_path,
				'ContentType' => $content_type ?: 'application/octet-stream',
			]);
		} catch (S3Exception $e) {
			throw new RuntimeException('S3 put failed for ' . $remote_key . ': ' . $e->getAwsErrorMessage(), 0, $e);
		}
	}

	public function get(string $remote_key, string $local_path): void {
		$dir = dirname($local_path);
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		try {
			$this->client->getObject([
				'Bucket' => $this->bucket,
				'Key'    => self::pathPrefix() . '/' . ltrim($remote_key, '/'),
				'SaveAs' => $local_path,
			]);
		} catch (S3Exception $e) {
			throw new RuntimeException('S3 get failed for ' . $remote_key . ': ' . $e->getAwsErrorMessage(), 0, $e);
		}
	}

	public function delete(string $remote_key): void {
		try {
			$this->client->deleteObject([
				'Bucket' => $this->bucket,
				'Key'    => self::pathPrefix() . '/' . ltrim($remote_key, '/'),
			]);
		} catch (S3Exception $e) {
			$code = $e->getAwsErrorCode();
			if ($code === 'NoSuchKey' || $code === 'NotFound') {
				return;
			}
			throw new RuntimeException('S3 delete failed for ' . $remote_key . ': ' . $e->getAwsErrorMessage(), 0, $e);
		}
	}

	public function url(string $remote_key): string {
		return $this->public_base_url . '/' . self::pathPrefix() . '/' . ltrim($remote_key, '/');
	}

	public function ping(): array {
		try {
			$this->client->headBucket(['Bucket' => $this->bucket]);
			return ['ok' => true, 'message' => 'HeadBucket OK'];
		} catch (S3Exception $e) {
			return ['ok' => false, 'message' => $e->getAwsErrorMessage() ?: $e->getMessage()];
		} catch (Exception $e) {
			return ['ok' => false, 'message' => $e->getMessage()];
		}
	}

	/**
	 * Bucket key prefix derived from the site_template setting. Stable per
	 * install — changing it would orphan every existing object in the bucket,
	 * so the empty/slash guard hard-fails rather than silently re-deriving.
	 */
	private static function pathPrefix(): string {
		$template = strtolower(Globalvars::get_instance()->get_setting('site_template') ?? '');
		if ($template === '' || strpos($template, '/') !== false) {
			throw new RuntimeException(
				'site_template is empty or contains a slash; '
				. 'refusing to derive cloud storage path prefix.');
		}
		$sanitized = preg_replace('/[^a-z0-9-]/', '-', $template);
		return trim(preg_replace('/-+/', '-', $sanitized), '-');
	}

	/**
	 * Public-facing path prefix (same as pathPrefix(), exposed for callers
	 * that need to construct paths against the public base URL — e.g. the
	 * admin Test Connection probe URL).
	 */
	public static function getPathPrefix(): string {
		return self::pathPrefix();
	}

	/**
	 * Public base URL exposed so the admin Test Connection HEAD probe can
	 * reach the scratch object without re-deriving the URL.
	 */
	public function getPublicBaseUrl(): string {
		return $this->public_base_url;
	}

	/**
	 * Hostname-pattern check (instant, no network) — returns provider label
	 * if the URL looks like a raw bucket hostname, null otherwise.
	 *
	 * Used to surface the egress-cost warning before the admin clicks Save.
	 * Custom-domain-CNAMEd-to-raw-bucket cases are caught later by the
	 * response-header check during Test Connection.
	 */
	public static function looksLikeRawBucketHost(string $public_base_url): ?string {
		$host = strtolower(parse_url($public_base_url, PHP_URL_HOST) ?? '');
		if (!$host) return null;

		static $raw_patterns = [
			'/\.amazonaws\.com$/'          => 'AWS S3',
			'/\.backblazeb2\.com$/'        => 'Backblaze B2',
			'/\.wasabisys\.com$/'          => 'Wasabi',
			'/\.digitaloceanspaces\.com$/' => 'DigitalOcean Spaces',
		];
		foreach ($raw_patterns as $pattern => $label) {
			if (preg_match($pattern, $host)) return $label;
		}
		return null;
	}

	/**
	 * Response-header check (definitive, runs during Test Connection). Looks
	 * for positive CDN markers first; falls back to raw-bucket markers.
	 *
	 * @param string $probe_url  Full public URL to a scratch probe object.
	 * @return array  ['reachable' => bool, 'cdn' => ?string, 'raw_provider' => ?string]
	 */
	public static function inspectPublicUrl(string $probe_url): array {
		$context = stream_context_create([
			'http' => ['method' => 'HEAD', 'timeout' => 5, 'ignore_errors' => true],
		]);
		$raw = @get_headers($probe_url, true, $context);
		if ($raw === false) {
			return ['reachable' => false, 'cdn' => null, 'raw_provider' => null];
		}
		$h = [];
		foreach ($raw as $k => $v) {
			if (is_string($k)) $h[strtolower($k)] = is_array($v) ? end($v) : $v;
		}

		// Positive CDN markers — these win over raw markers (CDN sits in front of bucket).
		if (isset($h['cf-ray'])
			|| (isset($h['server']) && stripos($h['server'], 'cloudflare') !== false)) {
			return ['reachable' => true, 'cdn' => 'Cloudflare', 'raw_provider' => null];
		}
		if (isset($h['x-amz-cf-id']) || isset($h['x-amz-cf-pop'])) {
			return ['reachable' => true, 'cdn' => 'CloudFront', 'raw_provider' => null];
		}
		if (isset($h['x-bunnycdn-pop']) || isset($h['cdn-cachekey'])) {
			return ['reachable' => true, 'cdn' => 'Bunny', 'raw_provider' => null];
		}
		if (isset($h['x-served-by']) && stripos($h['x-served-by'], 'cache-') !== false) {
			return ['reachable' => true, 'cdn' => 'Fastly', 'raw_provider' => null];
		}
		if (isset($h['x-vercel-cache'])) {
			return ['reachable' => true, 'cdn' => 'Vercel', 'raw_provider' => null];
		}

		// Raw-bucket markers (no CDN detected above).
		if (isset($h['x-bz-file-id']) || isset($h['x-bz-content-sha1'])) {
			return ['reachable' => true, 'cdn' => null, 'raw_provider' => 'Backblaze B2'];
		}
		if (isset($h['x-amz-id-2']) || isset($h['x-amz-request-id'])) {
			return ['reachable' => true, 'cdn' => null, 'raw_provider' => 'AWS S3 / S3-compatible'];
		}

		return ['reachable' => true, 'cdn' => null, 'raw_provider' => null];
	}

	/**
	 * Accept either a hostname (s3.us-west-002.backblazeb2.com) or a full URL
	 * for the endpoint setting; always return a scheme-prefixed URL for the SDK.
	 */
	private static function normalizeEndpointUrl(string $endpoint): string {
		if (preg_match('#^https?://#i', $endpoint)) {
			return rtrim($endpoint, '/');
		}
		return 'https://' . rtrim($endpoint, '/');
	}

	/**
	 * Auto-derived public base URL when none is configured. Points at the
	 * bucket root, not the path prefix; URL generation appends the prefix
	 * per-key.
	 */
	private static function derivePublicBaseUrl(string $endpoint_url, string $endpoint_host, string $bucket, bool $path_style): string {
		$scheme = parse_url($endpoint_url, PHP_URL_SCHEME) ?: 'https';
		if (!$path_style) {
			// AWS virtual-hosted: https://{bucket}.s3.{region}.amazonaws.com
			return $scheme . '://' . $bucket . '.' . $endpoint_host;
		}
		return $scheme . '://' . $endpoint_host . '/' . $bucket;
	}
}

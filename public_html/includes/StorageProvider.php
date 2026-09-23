<?php
/**
 * StorageProvider — what the platform knows about each bucket provider.
 *
 * A form asks a person to pick a provider, and only the fields that provider
 * needs are shown: Amazon, Wasabi, DigitalOcean and Linode name their S3
 * endpoint from the region, Cloudflare R2 has one endpoint per account and
 * no region to speak of, and Backblaze tells us both once it has seen the
 * key. A generic S3 compatible service (MinIO, anything self-hosted) asks
 * for everything. Every provider is spoken to over S3; this class only
 * decides what to ask and fills in the rest.
 *
 * complete() is what a save runs: given the provider and what the form
 * posted, it returns the endpoint and region the store will use, or a
 * sentence saying what is missing.
 *
 * @version 1.0 - specs/implemented/cloud_storage_provider_picker.md
 */

class StorageProvider {

	const GENERIC = 'generic';

	/**
	 * The catalogue. Per provider:
	 *   label          what the picker shows
	 *   asks           which of endpoint, region the form asks for
	 *   endpoint       the endpoint pattern ({region} filled in), '' when asked or found from the key
	 *   region         a fixed region, '' when asked or found from the key
	 *   host           a pattern that recognises this provider's endpoint
	 *   endpoint_help  help for the endpoint field, when asked
	 *   region_help    help for the region field, when asked
	 *   example        an example for the field that is asked
	 */
	private static $catalogue = array(
		'generic' => array(
			'label'         => 'Generic S3 compatible bucket',
			'asks'          => array('endpoint', 'region'),
			'endpoint'      => '',
			'region'        => '',
			'host'          => '',
			'endpoint_help' => 'The service\'s S3 hostname, with or without https://.',
			'region_help'   => 'What the service calls the bucket\'s region. Leave empty if it has none.',
			'example'       => array('endpoint' => 's3.example.com', 'region' => 'us-east-1'),
		),
		'b2' => array(
			'label'         => 'Backblaze B2',
			'asks'          => array(),
			'endpoint'      => '',
			'region'        => '',
			'host'          => '/\.backblazeb2\.com$/i',
			'endpoint_help' => '',
			'region_help'   => '',
			'example'       => array(),
		),
		's3' => array(
			'label'         => 'Amazon S3',
			'asks'          => array('region'),
			'endpoint'      => 's3.{region}.amazonaws.com',
			'region'        => '',
			'host'          => '/\.amazonaws\.com$/i',
			'endpoint_help' => '',
			'region_help'   => 'The bucket\'s region, shown beside it in the S3 console.',
			'example'       => array('region' => 'us-east-1'),
		),
		'r2' => array(
			'label'         => 'Cloudflare R2',
			'asks'          => array('endpoint'),
			'endpoint'      => '',
			'region'        => 'auto',
			'host'          => '/\.r2\.cloudflarestorage\.com$/i',
			'endpoint_help' => 'Your account\'s R2 endpoint, shown on the R2 overview page.',
			'region_help'   => '',
			'example'       => array('endpoint' => 'your-account-id.r2.cloudflarestorage.com'),
		),
		'wasabi' => array(
			'label'         => 'Wasabi',
			'asks'          => array('region'),
			'endpoint'      => 's3.{region}.wasabisys.com',
			'region'        => '',
			'host'          => '/\.wasabisys\.com$/i',
			'endpoint_help' => '',
			'region_help'   => 'The bucket\'s region, shown beside it in the Wasabi console.',
			'example'       => array('region' => 'us-east-1'),
		),
		'digitalocean' => array(
			'label'         => 'DigitalOcean Spaces',
			'asks'          => array('region'),
			'endpoint'      => '{region}.digitaloceanspaces.com',
			'region'        => '',
			'host'          => '/\.digitaloceanspaces\.com$/i',
			'endpoint_help' => '',
			'region_help'   => 'The Space\'s datacenter.',
			'example'       => array('region' => 'nyc3'),
		),
		'linode' => array(
			'label'         => 'Linode Object Storage',
			'asks'          => array('region'),
			'endpoint'      => '{region}.linodeobjects.com',
			'region'        => '',
			'host'          => '/\.linodeobjects\.com$/i',
			'endpoint_help' => '',
			'region_help'   => 'The cluster the bucket is in.',
			'example'       => array('region' => 'us-east-1'),
		),
	);

	/** slug => label, the generic choice first. For the provider select's options_from. */
	public static function options(): array {
		$out = array();
		foreach (self::$catalogue as $slug => $p) {
			$out[$slug] = $p['label'];
		}
		return $out;
	}

	/** True for a slug the catalogue knows. */
	public static function known($slug): bool {
		return isset(self::$catalogue[(string)$slug]);
	}

	/** The slug to use for a stored or posted value: what was given if known, else generic. */
	public static function normalise($slug): string {
		return self::known($slug) ? (string)$slug : self::GENERIC;
	}

	public static function label($slug): string {
		return self::$catalogue[self::normalise($slug)]['label'];
	}

	/** Which of endpoint and region the form asks for. */
	public static function asks($slug): array {
		return self::$catalogue[self::normalise($slug)]['asks'];
	}

	/**
	 * The whole catalogue, for a page's script: what each provider asks, its
	 * endpoint pattern, its fixed region and its help.
	 */
	public static function catalogue(): array {
		$out = array();
		foreach (self::$catalogue as $slug => $p) {
			$out[$slug] = array(
				'label'         => $p['label'],
				'asks'          => $p['asks'],
				'endpoint'      => $p['endpoint'],
				'region'        => $p['region'],
				'endpoint_help' => $p['endpoint_help'],
				'region_help'   => $p['region_help'],
				'example'       => (object)$p['example'],
			);
		}
		return $out;
	}

	/**
	 * The endpoint a provider names from a region, or '' when the provider
	 * asks for the endpoint or finds it from the key.
	 */
	public static function endpoint_for($slug, $region): string {
		$pattern = self::$catalogue[self::normalise($slug)]['endpoint'];
		$region = trim((string)$region);
		if ($pattern === '' || $region === '') {
			return '';
		}
		return str_replace('{region}', $region, $pattern);
	}

	/** The provider an endpoint belongs to, by its host; generic when none is recognised. */
	public static function detect($endpoint): string {
		$host = self::host($endpoint);
		if ($host === '') {
			return self::GENERIC;
		}
		foreach (self::$catalogue as $slug => $p) {
			if ($p['host'] !== '' && preg_match($p['host'], $host)) {
				return $slug;
			}
		}
		return self::GENERIC;
	}

	/**
	 * The provider a stored binding shows as: what was stored, unless that is
	 * generic and the endpoint is a provider the catalogue recognises.
	 */
	public static function effective($stored, $endpoint): string {
		$slug = self::normalise($stored);
		return $slug === self::GENERIC ? self::detect($endpoint) : $slug;
	}

	/**
	 * Fill in the endpoint and region a provider decides, from what the form
	 * posted. Returns ['ok' => bool, 'opts' => the same array with endpoint,
	 * region and provider settled, 'message' => what is missing when not ok].
	 *
	 * $opts: provider, endpoint, region, access_key, secret_key.
	 */
	public static function complete(array $opts): array {
		$slug = self::normalise($opts['provider'] ?? '');
		$p = self::$catalogue[$slug];
		$opts['provider'] = $slug;
		$endpoint = trim((string)($opts['endpoint'] ?? ''));
		$region = trim((string)($opts['region'] ?? ''));
		$fail = function ($message) use ($opts) {
			return array('ok' => false, 'opts' => $opts, 'message' => $message);
		};

		if ($slug === 'b2') {
			// Backblaze names the S3 endpoint, region included, once it has
			// seen the key; the key is the same one the S3 calls sign with.
			if (trim((string)($opts['access_key'] ?? '')) === '' || trim((string)($opts['secret_key'] ?? '')) === '') {
				return $fail('Backblaze needs the key id and application key; the endpoint and region come from them.');
			}
			try {
				$allowed = BucketCheck::b2_allowed($opts['access_key'], $opts['secret_key']);
			} catch (Exception $e) {
				return $fail('Backblaze refused the key (' . $e->getMessage() . '). Check the key id and application key.');
			}
			$host = self::host($allowed['s3_endpoint'] ?? '');
			if (!preg_match('/^s3\.([a-z0-9-]+)\.backblazeb2\.com$/i', $host, $m)) {
				return $fail('Backblaze did not name an S3 endpoint for this key.');
			}
			$opts['endpoint'] = $host;
			$opts['region'] = $m[1];
			return array('ok' => true, 'opts' => $opts, 'message' => '');
		}

		if (in_array('region', $p['asks'], true)) {
			if ($region === '' && $slug !== self::GENERIC) {
				return $fail('Region is required for ' . $p['label'] . '.');
			}
			$opts['region'] = $region;
		} else {
			$opts['region'] = $p['region'];
		}

		if (in_array('endpoint', $p['asks'], true)) {
			if ($endpoint === '') {
				return $fail('Endpoint is required for ' . $p['label'] . '.');
			}
			$opts['endpoint'] = $endpoint;
		} else {
			$opts['endpoint'] = self::endpoint_for($slug, $opts['region']);
		}
		return array('ok' => true, 'opts' => $opts, 'message' => '');
	}

	/** The host of an endpoint given as a hostname or a URL. */
	public static function host($endpoint): string {
		$endpoint = trim((string)$endpoint);
		if ($endpoint === '') {
			return '';
		}
		$url = strpos($endpoint, '://') === false ? 'https://' . $endpoint : $endpoint;
		return strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
	}
}

<?php
/**
 * CloudStorageDriverFactory
 *
 * Resolves the configured cloud storage driver. Returns null when a store is
 * disabled or unconfigured — callers must check before use.
 *
 * Visibility is a first-class storage dimension: forVisibility() maps a
 * 'public'/'private' string to a driver against that store's bucket binding.
 * The public store is the retained default() path; the private store is one
 * more bucket on the SAME credentials (shared endpoint/region/keys + the
 * private bucket name), usable only once the privacy gate has latched
 * cloud_storage_private_enabled true.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));

class CloudStorageDriverFactory {

	private static $cached_default = false; // tri-state: false = uncached, null = no driver, instance = driver
	private static $cached_private = false; // tri-state, same convention

	/**
	 * Default (public-store) driver per current settings. Returns null when:
	 * - cloud_storage_enabled is off, OR
	 * - any required setting is missing.
	 */
	public static function default(): ?CloudStorageDriver {
		if (self::$cached_default !== false) {
			return self::$cached_default;
		}
		$settings = Globalvars::get_instance();
		if (!$settings->get_setting('cloud_storage_enabled')) {
			return self::$cached_default = null;
		}
		try {
			require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageS3Driver.php'));
			self::$cached_default = new CloudStorageS3Driver();
		} catch (Exception $e) {
			error_log('CloudStorageDriverFactory: failed to construct driver — ' . $e->getMessage());
			self::$cached_default = null;
		}
		return self::$cached_default;
	}

	/**
	 * Driver for a store identified by visibility. 'public' is the retained
	 * default() path. 'private' returns non-null only when the private bucket
	 * is configured AND the privacy gate has latched cloud_storage_private_enabled
	 * true — a non-null return is the single signal "there is a usable,
	 * proven-private store." Read mode is derived from visibility by the
	 * consumer, not from a separate knob.
	 */
	public static function forVisibility(string $visibility): ?CloudStorageDriver {
		if ($visibility === 'public') {
			return self::default();
		}
		if ($visibility === 'private') {
			if (self::$cached_private !== false) {
				return self::$cached_private;
			}
			$settings = Globalvars::get_instance();
			if (!$settings->get_setting('cloud_storage_private_enabled')) {
				return self::$cached_private = null;
			}
			$binding = self::bindingFor('private');
			if (empty($binding['bucket'])) {
				return self::$cached_private = null;
			}
			try {
				self::$cached_private = self::fromOptions($binding);
			} catch (Exception $e) {
				error_log('CloudStorageDriverFactory: failed to construct private driver — ' . $e->getMessage());
				self::$cached_private = null;
			}
			return self::$cached_private;
		}
		return null;
	}

	/**
	 * Build a store's driver from its raw binding, IGNORING the enabled latch.
	 * Used by the reverse (pull-back) path, which runs right after an admin
	 * disables a store — so the latched forVisibility() would return null even
	 * though the binding is still valid. Returns null only if required creds
	 * are missing.
	 */
	public static function forVisibilityUnlatched(string $visibility): ?CloudStorageDriver {
		$binding = self::bindingFor($visibility);
		foreach (['endpoint', 'bucket', 'access_key', 'secret_key'] as $req) {
			if (empty($binding[$req])) {
				return null;
			}
		}
		try {
			return self::fromOptions($binding);
		} catch (Exception $e) {
			error_log('CloudStorageDriverFactory: failed to construct unlatched ' . $visibility . ' driver — ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * The driver to use for request-time byte I/O (read / write / delete /
	 * pull-back) against a store: the latched driver when the store is enabled,
	 * otherwise the unlatched binding so I/O still works while the store is
	 * paused or mid-drain. This is the resolver every consumer should use for
	 * touching bytes — forVisibility() alone would go null during a drain and
	 * silently break reads. Null only when the store is entirely unconfigured.
	 */
	public static function forVisibilityWithFallback(string $visibility): ?CloudStorageDriver {
		return self::forVisibility($visibility) ?? self::forVisibilityUnlatched($visibility);
	}

	/**
	 * The settings-resolved bucket binding for a store. Single source of truth
	 * for which settings map to which visibility. The private store shares the
	 * public store's endpoint/region/credentials and differs only in bucket;
	 * it has no public_base_url (bytes are never served via url()), so the
	 * driver derives the bucket URL from endpoint+bucket — used only by the
	 * one-time privacy gate probe.
	 */
	public static function bindingFor(string $visibility): array {
		$s = Globalvars::get_instance();
		if ($visibility === 'private') {
			return [
				'endpoint'        => $s->get_setting('cloud_storage_endpoint'),
				'region'          => $s->get_setting('cloud_storage_region'),
				'bucket'          => $s->get_setting('cloud_storage_private_bucket'),
				'access_key'      => $s->get_setting('cloud_storage_access_key'),
				'secret_key'      => $s->get_setting('cloud_storage_secret_key'),
				'public_base_url' => '',
			];
		}
		return [
			'endpoint'        => $s->get_setting('cloud_storage_endpoint'),
			'region'          => $s->get_setting('cloud_storage_region'),
			'bucket'          => $s->get_setting('cloud_storage_bucket'),
			'access_key'      => $s->get_setting('cloud_storage_access_key'),
			'secret_key'      => $s->get_setting('cloud_storage_secret_key'),
			'public_base_url' => $s->get_setting('cloud_storage_public_base_url'),
		];
	}

	/**
	 * Build a driver instance from explicit options (used by Test Connection
	 * before settings are persisted, and by the per-visibility resolvers).
	 */
	public static function fromOptions(array $opts): CloudStorageDriver {
		require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageS3Driver.php'));
		return new CloudStorageS3Driver($opts);
	}

	/**
	 * Reset the cached drivers (used after settings change). Clears both the
	 * public and private caches.
	 */
	public static function reset(): void {
		self::$cached_default = false;
		self::$cached_private = false;
	}
}

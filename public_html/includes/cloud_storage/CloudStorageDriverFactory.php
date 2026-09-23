<?php
/**
 * CloudStorageDriverFactory
 *
 * Resolves the driver for the file store: one private bucket, one binding.
 * Returns null when the store is disabled or unconfigured — callers must
 * check before use.
 *
 * driver() honours the enabled latch (cloud_storage_enabled), so a non-null
 * answer is the single signal "there is a usable, proven-private store".
 * driverUnlatched() builds from the raw binding regardless, for the paths that
 * run while the store is paused or draining; driverWithFallback() is the one
 * every consumer uses for request-time byte I/O.
 *
 * @version 2.0 - one binding: driver(), driverUnlatched(), driverWithFallback(), binding(); no visibility
 *                argument, no default(), no public_base_url (specs/implemented/cloud_storage_private_only.md)
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));

class CloudStorageDriverFactory {

	private static $cached = false; // tri-state: false = uncached, null = no driver, instance = driver

	/**
	 * The store's driver per current settings. Returns null when:
	 * - cloud_storage_enabled is off, OR
	 * - any required setting is missing.
	 */
	public static function driver(): ?CloudStorageDriver {
		if (self::$cached !== false) {
			return self::$cached;
		}
		$settings = Globalvars::get_instance();
		if (!$settings->get_setting('cloud_storage_enabled')) {
			return self::$cached = null;
		}
		$binding = self::binding();
		if (empty($binding['bucket'])) {
			return self::$cached = null;
		}
		try {
			self::$cached = self::fromOptions($binding);
		} catch (Exception $e) {
			error_log('CloudStorageDriverFactory: failed to construct driver — ' . $e->getMessage());
			self::$cached = null;
		}
		return self::$cached;
	}

	/**
	 * Build the store's driver from its raw binding, IGNORING the enabled latch.
	 * Used by the reverse (pull-back) path, which runs right after an admin
	 * disables the store — so the latched driver() would return null even
	 * though the binding is still valid. Returns null only if required creds
	 * are missing.
	 */
	public static function driverUnlatched(): ?CloudStorageDriver {
		$binding = self::binding();
		foreach (['endpoint', 'bucket', 'access_key', 'secret_key'] as $req) {
			if (empty($binding[$req])) {
				return null;
			}
		}
		try {
			return self::fromOptions($binding);
		} catch (Exception $e) {
			error_log('CloudStorageDriverFactory: failed to construct unlatched driver — ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * The driver to use for request-time byte I/O (read / write / delete /
	 * pull-back): the latched driver when the store is enabled, otherwise the
	 * unlatched binding so I/O still works while the store is paused or
	 * mid-drain. This is the resolver every consumer should use for touching
	 * bytes — driver() alone would go null during a drain and silently break
	 * reads. Null only when the store is entirely unconfigured.
	 */
	public static function driverWithFallback(): ?CloudStorageDriver {
		return self::driver() ?? self::driverUnlatched();
	}

	/**
	 * The settings-resolved bucket binding. Single source of truth for which
	 * settings make up the store. Nothing is read from the bucket by URL; the
	 * driver derives the bucket URL from endpoint + bucket, used only by the
	 * privacy gate's anonymous probe.
	 */
	public static function binding(): array {
		$s = Globalvars::get_instance();
		return [
			'endpoint'   => $s->get_setting('cloud_storage_endpoint'),
			'region'     => $s->get_setting('cloud_storage_region'),
			'bucket'     => $s->get_setting('cloud_storage_bucket'),
			'access_key' => $s->get_setting('cloud_storage_access_key'),
			'secret_key' => $s->get_setting('cloud_storage_secret_key'),
		];
	}

	/**
	 * Build a driver instance from explicit options (used by the Save check
	 * before settings are persisted, and by the resolvers above).
	 */
	public static function fromOptions(array $opts): CloudStorageDriver {
		require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageS3Driver.php'));
		return new CloudStorageS3Driver($opts);
	}

	/** Reset the cached driver (used after settings change). */
	public static function reset(): void {
		self::$cached = false;
	}
}

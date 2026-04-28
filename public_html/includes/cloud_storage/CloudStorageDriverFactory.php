<?php
/**
 * CloudStorageDriverFactory
 *
 * Resolves the configured cloud storage driver. Returns null when cloud
 * storage is disabled or unconfigured — callers must check before use.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));

class CloudStorageDriverFactory {

	private static $cached_default = false; // tri-state: false = uncached, null = no driver, instance = driver

	/**
	 * Default driver per current settings. Returns null when:
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
	 * Build a driver instance from explicit options (used by Test Connection
	 * before settings are persisted).
	 */
	public static function fromOptions(array $opts): CloudStorageDriver {
		require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageS3Driver.php'));
		return new CloudStorageS3Driver($opts);
	}

	/**
	 * Reset the cached default driver (used after settings change).
	 */
	public static function reset(): void {
		self::$cached_default = false;
	}
}

<?php
/**
 * CloudStorageDriverFactory
 *
 * Resolves the file store's drivers. The file store is a target row
 * (bkt_purpose 'files'), and there can be more than one: the one
 * file_store_target_id names takes new offloads; an older one keeps serving
 * the files whose records name it until Move files carries them across.
 *
 * - current() is where a new offload goes: the named store, while the
 *   enabled latch (cloud_storage_enabled) is on. Null otherwise.
 * - currentUnlatched() is the named store whatever the latch says, for the
 *   page's health check and a move's destination.
 * - forTarget() is the driver for the store a row names. Every read, delete,
 *   pull-back and move follows the row, with the latch on or off.
 *
 * @version 3.0 - the file store is a target row (specs/storage_targets.md WP6): current(), currentUnlatched(),
 *                currentTarget(), forTarget(), fromTarget(); the binding settings, driver(),
 *                driverUnlatched(), driverWithFallback() and binding() are gone
 * @version 2.0 - one binding: driver(), driverUnlatched(), driverWithFallback(), binding(); no visibility
 *                argument, no default(), no public_base_url (specs/implemented/cloud_storage_private_only.md)
 * @version 1.2
 */

class CloudStorageDriverFactory {

	/** @var array target id => CloudStorageDriver|null, built once per process */
	private static $by_target = [];

	/**
	 * Tests only: target id => a driver standing in for that store's bucket.
	 * @var array
	 */
	public static $test_drivers = [];

	/** The file store target file_store_target_id names, or null when none is set up. */
	public static function currentTarget(): ?BackupTarget {
		$id = (int)Globalvars::get_instance()->get_setting(BackupTarget::FILE_STORE_SETTING, false, true);
		if ($id <= 0) {
			return null;
		}
		$target = new BackupTarget($id, TRUE);
		if (!$target->key || $target->get('bkt_delete_time') || !$target->is_file_store()) {
			return null;
		}
		return $target;
	}

	/** Where a new offload goes: the named store while the latch is on, else null. */
	public static function current(): ?CloudFileStore {
		if (!Globalvars::get_instance()->get_setting('cloud_storage_enabled')) {
			return null;
		}
		return self::currentUnlatched();
	}

	/** The named store whatever the latch says, or null when none is set up or it has no driver. */
	public static function currentUnlatched(): ?CloudFileStore {
		$target = self::currentTarget();
		if (!$target) {
			return null;
		}
		$driver = self::forTarget((int)$target->key);
		return $driver ? new CloudFileStore((int)$target->key, $target->prefix(), $driver) : null;
	}

	/**
	 * The driver for the store a row names, or null when the target is gone or
	 * its key cannot be read. Built once per process.
	 */
	public static function forTarget(?int $target_id): ?CloudStorageDriver {
		$target_id = (int)$target_id;
		if ($target_id <= 0) {
			return null;
		}
		if (isset(self::$test_drivers[$target_id])) {
			return self::$test_drivers[$target_id];
		}
		if (array_key_exists($target_id, self::$by_target)) {
			return self::$by_target[$target_id];
		}
		$driver = null;
		$target = new BackupTarget($target_id, TRUE);
		if ($target->key && $target->is_file_store()) {
			try {
				$driver = self::fromTarget($target);
			} catch (Exception $e) {
				error_log('CloudStorageDriverFactory: no driver for file store "' . $target->get('bkt_name') . '" — ' . $e->getMessage());
			}
		}
		return self::$by_target[$target_id] = $driver;
	}

	/** A driver for a target row, from its sealed credentials. */
	public static function fromTarget(BackupTarget $target): CloudStorageDriver {
		return self::fromOptions(self::options($target));
	}

	/** What a driver is built from, for a target: its credentials and bucket. */
	public static function options(BackupTarget $target): array {
		$creds = $target->get_credentials() ?: [];
		return [
			'endpoint'   => (string)($creds['endpoint'] ?? ''),
			'region'     => (string)($creds['region'] ?? ''),
			'bucket'     => (string)$target->get('bkt_bucket'),
			'access_key' => (string)($creds['access_key'] ?? ''),
			'secret_key' => (string)($creds['secret_key'] ?? ''),
		];
	}

	/** Build a driver from explicit options (the Save check, before anything is stored). */
	public static function fromOptions(array $opts): CloudStorageDriver {
		return new CloudStorageS3Driver($opts);
	}

	/** Forget the built drivers (after a store's key changes). */
	public static function reset(): void {
		self::$by_target = [];
	}
}

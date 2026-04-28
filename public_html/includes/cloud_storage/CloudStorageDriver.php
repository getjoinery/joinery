<?php
/**
 * CloudStorageDriver
 *
 * Interface for cloud object storage backends (S3-compatible).
 * The driver handles only cloud-side operations — local file handling
 * stays in the existing File / RouteHelper code paths.
 *
 * @version 1.0
 */

interface CloudStorageDriver {
	/**
	 * Push a local file to the bucket at the given object key.
	 *
	 * @param string $local_path    Filesystem path of source file.
	 * @param string $remote_key    Bucket key (without prefix; the driver applies its own prefix).
	 * @param string $content_type  MIME type for the object.
	 * @throws RuntimeException on PUT failure.
	 */
	public function put(string $local_path, string $remote_key, string $content_type): void;

	/**
	 * Pull an object from the bucket to a local path.
	 *
	 * @param string $remote_key  Bucket key (without prefix).
	 * @param string $local_path  Destination filesystem path.
	 * @throws RuntimeException on GET failure.
	 */
	public function get(string $remote_key, string $local_path): void;

	/**
	 * Delete an object. No-op if the key does not exist.
	 *
	 * @param string $remote_key  Bucket key (without prefix).
	 * @throws RuntimeException on hard delete failure (other than not-found).
	 */
	public function delete(string $remote_key): void;

	/**
	 * Public URL for an object (CDN domain or bucket URL).
	 *
	 * @param string $remote_key  Bucket key (without prefix).
	 * @return string  Absolute URL.
	 */
	public function url(string $remote_key): string;

	/**
	 * Quick credential probe used by Test Connection / health checks.
	 * Performs a lightweight HeadBucket call.
	 *
	 * @return array  ['ok' => bool, 'message' => string]
	 */
	public function ping(): array;
}

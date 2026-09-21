<?php
/**
 * CloudStorageDriver
 *
 * Interface for cloud object storage backends (S3-compatible).
 * The driver handles only cloud-side operations — local file handling
 * stays in the existing File / RouteHelper code paths.
 *
 * @version 1.2 - url() is the bucket's own address, read only by the privacy gate
 * @version 1.1 - head(): does the bucket hold this object, and at what size — the question the
 *                backup's object restore and the file-store inventory ask without moving bytes
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
	 * Pull one byte span of an object to a local path.
	 *
	 * A resuming download asks for the tail of a file it already half has;
	 * pulling the whole object to answer that would move the bytes twice and
	 * cost egress on every retry. S3 and every compatible service answer a
	 * ranged GetObject natively, so the range goes to the service.
	 *
	 * @param string $remote_key  Bucket key (without prefix).
	 * @param string $local_path  Destination filesystem path (receives ONLY the span).
	 * @param int    $start       First byte offset, inclusive.
	 * @param int    $end         Last byte offset, inclusive.
	 * @throws RuntimeException on GET failure.
	 */
	public function get_range(string $remote_key, string $local_path, int $start, int $end): void;

	/**
	 * Delete an object. No-op if the key does not exist.
	 *
	 * @param string $remote_key  Bucket key (without prefix).
	 * @throws RuntimeException on hard delete failure (other than not-found).
	 */
	public function delete(string $remote_key): void;

	/**
	 * Does the bucket hold this object? Metadata only, never a download.
	 *
	 * @param string $remote_key  Bucket key (without prefix).
	 * @return array|null  ['size' => int, 'etag' => string] when present; null when absent
	 *                     or when the bucket could not answer (the caller treats
	 *                     both as "cannot be served from here").
	 */
	public function head(string $remote_key): ?array;

	/**
	 * The bucket's own address for an object — what a public bucket would
	 * serve it from. Nothing is served by it; the privacy gate fetches it
	 * anonymously to prove the bucket refuses.
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

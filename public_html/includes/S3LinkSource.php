<?php
/**
 * S3LinkSource — what S3Signer is handed in place of a credential when the
 * machine making the requests holds no key (specs/storage_targets.md §6).
 *
 * Every request S3Signer would sign is instead asked of the source as one
 * signed link for that verb, key and query; S3Signer then makes the request
 * itself, retries it on the same link, and tells the source each write that
 * finished with the bytes and sha256 it sent. So put_file(), put_stream() and
 * their multipart paths work unchanged whether the signature comes from a key
 * on this machine or from the owner of the bucket (BackupBroker).
 *
 * @version 1.1 - a link may name headers signed into it (object lock, specs/storage_targets.md F8)
 * @version 1.0
 */
interface S3LinkSource {

	/**
	 * One signed URL for one request. $path is the object key with a leading
	 * slash, $query the request's query parameters (['uploads' => ''],
	 * ['partNumber' => '3', 'uploadId' => …]), $bytes the body length.
	 * Answers the URL, or ['url' => …, 'headers' => [name => value]] when the
	 * link signs headers the request must carry (an object lock).
	 *
	 * @return string|array
	 * @throws S3SignerException when the source will not sign it, or
	 *         S3ObjectExistsException when the key is already written and is
	 *         never written twice
	 */
	public function link(string $method, string $path, array $query, int $bytes);

	/** A write finished: the object at $path is $bytes bytes whose sha256 is $sha256. */
	public function completed(string $path, int $bytes, string $sha256): void;
}

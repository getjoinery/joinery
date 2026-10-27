<?php
/**
 * S3LinkReader — a link source that also reads: the machine holds no key, and
 * whoever signs its writes also lists for it and signs its downloads
 * (specs/easy_signup_completion.md WP4). S3Signer::list() asks it for the
 * listing instead of making a ListObjectsV2 request, and S3Signer::presign()
 * of a GET asks it for the link; get(), get_to_file() and a ranged read already
 * go through S3LinkSource::link(). So every reader of a backup target — the
 * Backups page, the verify launcher, retention's records — works the same
 * whether the target's owner is this machine or getjoinery.
 *
 * @version 1.1 - read_link(): a read link with the life the caller asks for (a verify's links outlast an hour)
 * @version 1.0
 */
interface S3LinkReader extends S3LinkSource {

	/**
	 * Every object whose key starts with $prefix, as S3Signer::list() answers:
	 * [['key' => …, 'size' => int, 'last_modified' => …], …]. $truncated is set
	 * true when $max stopped the listing short.
	 *
	 * @throws S3SignerException when the listing cannot be had
	 */
	public function list_objects(string $prefix, int $max, &$truncated): array;

	/**
	 * A signed GET link for one key, alive for about $expires seconds (the
	 * signer may cap it).
	 *
	 * @throws S3SignerException when the source will not sign it
	 */
	public function read_link(string $path, int $expires): string;
}

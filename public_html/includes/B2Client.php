<?php
/**
 * B2Client — Backblaze B2's own API, for what S3 cannot say.
 *
 * Every read and write of a backup goes through S3Signer against B2's
 * S3-compatible endpoint. What the S3 API has no equivalent for is asking a
 * key about itself: b2_authorize_account answers the account's S3 endpoint
 * (so a Backblaze target needs no endpoint typed in) and what the key may do
 * (its capabilities, the bucket and prefix it is pinned to), which the target
 * check reads (StorageProvider R5).
 *
 * @version 1.3 - authorize() only: per-run key minting (createKey, bucketId, s3CredentialFor) is gone, since a
 *                Managed node writes through the management node's broker and is handed no key
 *                (specs/storage_targets.md WP5)
 * @version 1.2 - a minted key's region comes from the one Backblaze region rule (StorageProvider::b2_location());
 *                deleteKey() and countKeys(), which nothing called, are gone
 * @version 1.1 - authorize() keeps what the key is allowed to do (capabilities, pinned bucket, prefix)
 * @version 1.0
 */

require_once(PathHelper::getComposerAutoloadPath());

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class B2Exception extends Exception {}

class B2Client {

	const AUTHORIZE_URL = 'https://api.backblazeb2.com/b2api/v3/b2_authorize_account';

	/** @var string */
	private $key_id;
	/** @var string */
	private $application_key;
	/** @var Client */
	private $http;
	/** @var array|null Cached authorize response for this instance. */
	private $auth = null;

	public function __construct(string $key_id, string $application_key, ?Client $http = null) {
		$this->key_id = $key_id;
		$this->application_key = $application_key;
		$this->http = $http ?: new Client(array('timeout' => 20, 'connect_timeout' => 10));
	}

	/**
	 * Authorize, once per instance. Returns the account id, the API base for
	 * key operations, and the S3-compatible endpoint the archives themselves
	 * travel to.
	 *
	 * @return array{account_id:string, api_url:string, s3_endpoint:string, token:string, allowed:array}
	 */
	public function authorize(): array {
		if ($this->auth !== null) {
			return $this->auth;
		}
		try {
			$response = $this->http->request('GET', self::AUTHORIZE_URL, array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode($this->key_id . ':' . $this->application_key),
					'Accept'        => 'application/json',
				),
			));
		} catch (RequestException $e) {
			$status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
			throw new B2Exception('B2 authorize failed (' . $status . '): ' . self::reason($e), $status, $e);
		}
		$data = json_decode((string)$response->getBody(), true);
		if (!is_array($data) || empty($data['authorizationToken'])) {
			throw new B2Exception('B2 authorize returned no token.');
		}
		// What the key may do, as Backblaze states it: its capabilities and
		// the one bucket it is pinned to (bucketName empty for a key that
		// opens every bucket on the account). The v3 answer carries these
		// directly under apiInfo.storageApi; v2 carried them under allowed.
		// BucketCheck reads this to say what a key can reach before anything
		// is saved.
		$storage = $data['apiInfo']['storageApi'] ?? array();
		$allowed = isset($storage['capabilities']) ? $storage : (array)($data['allowed'] ?? array());
		$this->auth = array(
			'account_id'  => (string)($data['accountId'] ?? ''),
			'api_url'     => rtrim((string)($data['apiInfo']['storageApi']['apiUrl'] ?? ''), '/'),
			's3_endpoint' => (string)($data['apiInfo']['storageApi']['s3ApiUrl'] ?? ''),
			'token'       => (string)$data['authorizationToken'],
			'allowed'     => array(
				'capabilities' => array_values((array)($allowed['capabilities'] ?? array())),
				'bucketId'     => (string)($allowed['bucketId'] ?? ''),
				'bucketName'   => (string)($allowed['bucketName'] ?? ''),
				'namePrefix'   => (string)($allowed['namePrefix'] ?? ''),
			),
		);
		if ($this->auth['api_url'] === '') {
			throw new B2Exception('B2 authorize returned no storage API address.');
		}
		return $this->auth;
	}

	private static function reason(RequestException $e): string {
		if (!$e->getResponse()) {
			return $e->getMessage();
		}
		$decoded = json_decode((string)$e->getResponse()->getBody(), true);
		if (is_array($decoded) && !empty($decoded['message'])) {
			return (string)$decoded['message'];
		}
		return $e->getMessage();
	}
}

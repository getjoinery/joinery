<?php
/**
 * B2Client — Backblaze B2's own API, for the one thing S3 cannot express.
 *
 * Every read and write of a backup goes through S3Signer against B2's
 * S3-compatible endpoint, and nothing here changes that. What the S3 API has no
 * equivalent for is MINTING A KEY: B2 application keys can be pinned to one
 * bucket, one name prefix, a named set of capabilities and a lifetime, in one
 * call. That is the whole reason this class exists.
 *
 * WHY THAT MATTERS. A backup target holds one write-only credential and hands
 * the same one to every node in the fleet. On a machine somebody else
 * administers — a hosted customer's box, where they are permission 10 — that
 * shared key can write anywhere in the fleet's bucket. Minting per run turns
 * "a key that can write the fleet's shelf" into "a key that can add objects
 * under this node's own prefix, for as long as this run takes"
 * (specs/hosted_trial_provisioning.md §4.5). The blast radius of a key read off
 * a customer's box goes from the fleet to that customer's own directory, and
 * expires.
 *
 * THE KEY IS MINTED AT PICKUP, NOT AT BUILD. A key's lifetime starts when it is
 * created, and the moment that matters is when the agent actually holds it —
 * a job that sat in the queue for an hour would otherwise arrive with an
 * expired credential and read as a bucket error rather than as a stale key.
 *
 * A B2 application key's secret is returned exactly ONCE, by the call that
 * creates it. Nothing here stores one; the caller hands it straight to the job
 * being dispatched.
 *
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
	 * @return array{account_id:string, api_url:string, s3_endpoint:string, token:string}
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
		$this->auth = array(
			'account_id'  => (string)($data['accountId'] ?? ''),
			'api_url'     => rtrim((string)($data['apiInfo']['storageApi']['apiUrl'] ?? ''), '/'),
			's3_endpoint' => (string)($data['apiInfo']['storageApi']['s3ApiUrl'] ?? ''),
			'token'       => (string)$data['authorizationToken'],
		);
		if ($this->auth['api_url'] === '') {
			throw new B2Exception('B2 authorize returned no storage API address.');
		}
		return $this->auth;
	}

	/**
	 * The bucket's B2 id, which key creation needs and the S3 API never uses.
	 *
	 * Looked up by name because a bucket name is what an operator configured on
	 * the target; the id is B2's own handle for it.
	 */
	public function bucketId(string $bucket_name): string {
		$auth = $this->authorize();
		$data = $this->call('b2_list_buckets', array(
			'accountId'  => $auth['account_id'],
			'bucketName' => $bucket_name,
		));
		foreach ((array)($data['buckets'] ?? array()) as $bucket) {
			if ((string)($bucket['bucketName'] ?? '') === $bucket_name) {
				return (string)($bucket['bucketId'] ?? '');
			}
		}
		throw new B2Exception('B2 has no bucket named "' . $bucket_name . '" on this account.');
	}

	/**
	 * Mint a key pinned to one bucket, one prefix, one capability set and a
	 * lifetime.
	 *
	 * $capabilities is B2's own vocabulary — 'writeFiles' for a backup run,
	 * 'listFiles' plus 'readFiles' for a restore. Nothing here defaults it: a
	 * caller that has not said what the key may do is a caller that has not
	 * thought about it, and the safe default for that is a refusal.
	 *
	 * @return array{key_id:string, application_key:string, expires_time:string}
	 */
	public function createKey(string $bucket_id, string $name_prefix, array $capabilities,
			int $valid_seconds, string $label): array {
		if (!$capabilities) {
			throw new B2Exception('A minted key needs an explicit capability list.');
		}
		if ($valid_seconds < 60) {
			throw new B2Exception('A minted key needs a lifetime of at least a minute.');
		}
		// B2's own ceiling on a key lifetime is 1000 days; the caller's number
		// is derived from a job timeout and is nowhere near it, but a bad
		// derivation should fail here rather than at the provider.
		if ($valid_seconds > 86400 * 7) {
			throw new B2Exception('A per-run key lives for the length of a run, not ' . $valid_seconds . ' seconds.');
		}
		$auth = $this->authorize();
		$data = $this->call('b2_create_key', array(
			'accountId'             => $auth['account_id'],
			'capabilities'          => array_values($capabilities),
			'keyName'               => self::safeKeyName($label),
			'validDurationInSeconds' => $valid_seconds,
			'bucketId'              => $bucket_id,
			'namePrefix'            => $name_prefix,
		));
		$key_id = (string)($data['applicationKeyId'] ?? '');
		$secret = (string)($data['applicationKey'] ?? '');
		if ($key_id === '' || $secret === '') {
			throw new B2Exception('B2 created a key but did not return it.');
		}
		return array(
			'key_id'          => $key_id,
			'application_key' => $secret,
			'expires_time'    => gmdate('Y-m-d H:i:s', time() + $valid_seconds),
		);
	}

	/**
	 * Delete a key by id. Best-effort by design: a minted key expires on its
	 * own, so failing to delete one early is untidy rather than dangerous, and
	 * a caller that treated it as fatal would fail a finished backup over
	 * housekeeping.
	 */
	public function deleteKey(string $key_id): void {
		$this->call('b2_delete_key', array('applicationKeyId' => $key_id));
	}

	/** How many application keys this account currently holds. */
	public function countKeys(): int {
		$auth = $this->authorize();
		$data = $this->call('b2_list_keys', array(
			'accountId'    => $auth['account_id'],
			'maxKeyCount'  => 10000,
		));
		return count((array)($data['keys'] ?? array()));
	}

	/**
	 * The credential array S3Signer takes, for a minted key. Region is derived
	 * from the S3 endpoint the same way the target's own credential was.
	 */
	public function s3CredentialFor(array $minted): array {
		$auth = $this->authorize();
		$region = '';
		if (preg_match('#^https?://s3\.([^.]+)\.backblazeb2\.com#', $auth['s3_endpoint'], $m)) {
			$region = $m[1];
		}
		return array(
			'access_key' => $minted['key_id'],
			'secret_key' => $minted['application_key'],
			'region'     => $region,
			'endpoint'   => $auth['s3_endpoint'],
		);
	}

	/** One authorized B2 API call. */
	private function call(string $operation, array $body): array {
		$auth = $this->authorize();
		try {
			$response = $this->http->request('POST', $auth['api_url'] . '/b2api/v3/' . $operation, array(
				'headers' => array(
					'Authorization' => $auth['token'],
					'Accept'        => 'application/json',
				),
				'json' => $body,
			));
		} catch (RequestException $e) {
			$status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
			throw new B2Exception('B2 ' . $operation . ' failed (' . $status . '): ' . self::reason($e), $status, $e);
		}
		$decoded = json_decode((string)$response->getBody(), true);
		return is_array($decoded) ? $decoded : array();
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

	/** B2 key names are letters, digits and hyphens, up to 100 characters. */
	private static function safeKeyName(string $label): string {
		$name = preg_replace('/[^A-Za-z0-9-]/', '-', $label);
		$name = preg_replace('/-+/', '-', (string)$name);
		$name = trim((string)$name, '-');
		if ($name === '') { $name = 'joinery'; }
		return substr($name, 0, 100);
	}
}

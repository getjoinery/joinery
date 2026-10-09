<?php
/**
 * BackupBroker — a Managed node's side of the management node's backup broker
 * (specs/storage_targets.md R4, WP5).
 *
 * The node holds no key to the bucket its backups go to. Its job carries, in
 * the credential slot, the broker's address, a run the management node opened
 * for this job, and the run's token. This class is what S3Signer is handed in
 * place of a credential (S3LinkSource): every upload request becomes one call
 * to the broker for a signed link, then the request itself on that link. So
 * the backup engine streams and uploads exactly as it does to a site's own
 * target, and the management node signs, records and limits every write.
 *
 *   begin()   where the run writes: the storage space, the base key, the bucket
 *             and the target's name. Asked once, before the run decides which
 *             chain it extends: a chain stays in one space.
 *   link()    one signed link per request. Writes only, and only inside the
 *             run's base key. A key the broker already holds as written is
 *             answered with S3ObjectExistsException: nothing is written twice.
 *   finish()  every object the run wrote, with its size and sha256, so the
 *             management node holds the hash of each, and the chain.
 *   abort()   the run failed; what it signed is cancelled.
 *
 * Only writes: a Managed node reads its backups back through links the
 * management node signs into the job (download, verify, restore), never
 * through this.
 *
 * @version 1.2 - a link the broker signed an object lock into carries its headers (F8)
 * @version 1.1 - a lost reply is asked again: every call is safe to repeat, and the test transport takes the
 *                same retry path as HTTP
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/S3LinkSource.php'));
require_once(PathHelper::getIncludePath('includes/S3Signer.php'));

class BackupBrokerException extends Exception {}

class BackupBroker implements S3LinkSource {

	/** The header the run's token travels in; NodeBroker::TOKEN_HEADER on the management node. */
	const TOKEN_HEADER = 'X-Joinery-Broker-Token';

	const TIMEOUT_SECONDS = 60;
	const MAX_ATTEMPTS = 4;

	/** Tests only: fn(string $action, array $body): array — answers a call instead of HTTP. */
	public static $transport_for_tests = null;

	private $url;
	private $run_id;
	private $token;
	private $begun = null;
	/** upload id => [part number => url], the batch the broker signed last */
	private $parts = array();
	/** name => ['name', 'bytes', 'sha256'] for every write that finished */
	private $written = array();

	/**
	 * Whether a decoded credential slot is a broker run rather than a key:
	 * {broker, run_id, token}.
	 */
	public static function is_slot($credentials) {
		return is_array($credentials) && isset($credentials['broker'], $credentials['run_id'], $credentials['token']);
	}

	public function __construct(array $slot) {
		$url = trim((string)($slot['broker'] ?? ''));
		if (!preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?/#', $url) && self::$transport_for_tests === null) {
			throw new BackupBrokerException('The backup broker named with this run is not an https address.');
		}
		$this->url = rtrim($url, '/');
		$this->run_id = (int)($slot['run_id'] ?? 0);
		$this->token = (string)($slot['token'] ?? '');
		if ($this->run_id <= 0 || !preg_match('/^[0-9a-f]{64}$/', $this->token)) {
			throw new BackupBrokerException('The backup broker run handed with this job is not readable.');
		}
	}

	/** Where the run writes; asked of the broker once. */
	public function begin() {
		if ($this->begun === null) {
			$data = $this->call('begin', array());
			foreach (array('space_id', 'base_key', 'bucket', 'target_name') as $field) {
				if (!isset($data[$field]) || $data[$field] === '') {
					throw new BackupBrokerException('The backup broker did not say where this run writes (' . $field . ').');
				}
			}
			$this->begun = $data;
		}
		return $this->begun;
	}

	public function run_id()      { return $this->run_id; }
	public function space_id()    { return (int)$this->begin()['space_id']; }
	public function base_key()    { return (string)$this->begin()['base_key']; }
	public function bucket()      { return (string)$this->begin()['bucket']; }
	public function target_name() { return (string)$this->begin()['target_name']; }

	/** Every write that finished, as finish() reports them. */
	public function written() {
		return array_values($this->written);
	}

	// ------------------------------------------------------------- S3LinkSource

	public function link(string $method, string $path, array $query, int $bytes) {
		$name = $this->name_of($path);
		$method = strtoupper($method);
		if ($method === 'PUT' && !$query) {
			return $this->signed($name, 'put', array('bytes' => $bytes));
		}
		if ($method === 'POST' && array_key_exists('uploads', $query)) {
			return $this->signed($name, 'multipart_create', array());
		}
		if ($method === 'PUT' && isset($query['partNumber'], $query['uploadId'])) {
			$n = (int)$query['partNumber'];
			$upload_id = (string)$query['uploadId'];
			if (!isset($this->parts[$upload_id][$n])) {
				$data = $this->call('sign', array('name' => $name, 'operation' => 'multipart_parts',
					'upload_id' => $upload_id, 'first' => $n, 'count' => 10));
				$this->parts[$upload_id] = array();
				foreach ((array)($data['urls'] ?? array()) as $part => $url) {
					$this->parts[$upload_id][(int)$part] = (string)$url;
				}
				if (!isset($this->parts[$upload_id][$n])) {
					throw new S3SignerException('The backup broker signed no link for part ' . $n . ' of ' . $name . '.');
				}
			}
			return $this->parts[$upload_id][$n];
		}
		if ($method === 'POST' && isset($query['uploadId'])) {
			unset($this->parts[(string)$query['uploadId']]);
			return $this->signed($name, 'multipart_complete', array('upload_id' => (string)$query['uploadId']));
		}
		throw new S3SignerException('A Managed node only writes to backup storage through the broker; it does not '
			. strtolower($method) . ' ' . $name . '.');
	}

	public function completed(string $path, int $bytes, string $sha256): void {
		$name = $this->name_of($path);
		$this->written[$name] = array('name' => $name, 'bytes' => $bytes, 'sha256' => strtolower($sha256));
	}

	// ------------------------------------------------------------ the run's end

	/** The run is done: every write with its size and hash, and the chain it extended. */
	public function finish($chain = '') {
		return $this->call('finish', array('completed' => $this->written(), 'chain' => (string)$chain));
	}

	/** The run failed. Best effort: the broker aborts an expired run on its own. */
	public function abort($cause) {
		try {
			$this->call('abort', array('cause' => mb_substr((string)$cause, 0, 1000)));
		} catch (\Throwable $e) {
			error_log('BackupBroker: could not tell the broker run ' . $this->run_id . ' failed: ' . $e->getMessage());
		}
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * A link for one write — with the headers the broker signed into it, an
	 * object lock the write must carry — or S3ObjectExistsException for a key
	 * already written.
	 */
	private function signed($name, $operation, array $args) {
		$data = $this->call('sign', array('name' => $name, 'operation' => $operation) + $args);
		if (!empty($data['exists'])) {
			throw new S3ObjectExistsException((string)($data['key'] ?? $name), (int)($data['bytes'] ?? 0), (string)($data['sha256'] ?? ''));
		}
		if (empty($data['url'])) {
			throw new S3SignerException('The backup broker signed no link for ' . $name . '.');
		}
		$headers = array();
		foreach ((array)($data['headers'] ?? array()) as $k => $v) {
			if (preg_match('/^x-amz-object-lock-(mode|retain-until-date)$/i', (string)$k)) {
				$headers[strtolower((string)$k)] = (string)$v;
			}
		}
		return $headers ? array('url' => (string)$data['url'], 'headers' => $headers) : (string)$data['url'];
	}

	/** A key named relative to the run's base key, or a refusal: nothing outside it is the run's to write. */
	private function name_of($path) {
		$key = ltrim((string)$path, '/');
		$base = $this->base_key();
		if (strpos($key, $base) !== 0 || strlen($key) === strlen($base)) {
			throw new S3SignerException('That key is outside this run\'s part of backup storage.');
		}
		return substr($key, strlen($base));
	}

	/**
	 * One call to the broker. A refusal (4xx) is thrown with the broker's
	 * sentence; a transport failure or 5xx is tried again a few times. Every
	 * call is safe to repeat: a sign signs again, and a finish or an abort
	 * asked again after a lost reply is answered with what was recorded.
	 */
	private function call($action, array $body) {
		$body['run_id'] = $this->run_id;
		$last = '';
		for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
			list($status, $decoded, $err) = $this->attempt($action, $body);
			if ($status >= 200 && $status < 300 && is_array($decoded) && array_key_exists('data', $decoded)) {
				return (array)$decoded['data'];
			}
			if ($status >= 400 && $status < 500 && $status !== 429) {
				$why = is_array($decoded) ? (string)($decoded['error'] ?? '') : '';
				throw new S3SignerException('The backup broker refused: ' . ($why !== '' ? $why : 'HTTP ' . $status));
			}
			$last = $err !== '' ? $err : ($status ? 'HTTP ' . $status : 'no answer');
			if ($attempt < self::MAX_ATTEMPTS && self::$transport_for_tests === null) {
				sleep(2 * $attempt);
			}
		}
		throw new S3SignerException('The backup broker could not be reached (' . $last . ').');
	}

	/**
	 * One request: [HTTP status (0 when none came back), decoded body, transport error].
	 * A test transport answers ['status', 'data'|'error'], or throws for a lost reply.
	 */
	private function attempt($action, array $body) {
		if (self::$transport_for_tests !== null) {
			try {
				$answer = call_user_func(self::$transport_for_tests, $action, $body, $this->token);
			} catch (\Throwable $e) {
				return array(0, null, $e->getMessage());
			}
			$status = (int)($answer['status'] ?? (isset($answer['error']) ? 409 : 200));
			return array($status, isset($answer['error']) ? array('error' => $answer['error']) : array('data' => $answer['data'] ?? array()), '');
		}
		$ch = curl_init($this->url . '/' . $action);
		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode($body),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
			CURLOPT_HTTPHEADER     => array('Content-Type: application/json', self::TOKEN_HEADER . ': ' . $this->token),
		));
		$raw = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = (string)curl_error($ch);
		curl_close($ch);
		if ($raw === false) {
			return array(0, null, $err);
		}
		$decoded = json_decode((string)$raw, true);
		return array($status, is_array($decoded) ? $decoded : null, is_array($decoded) ? '' : 'an answer that is not the broker\'s');
	}
}

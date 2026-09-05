<?php
/**
 * RelayClient - how the plane speaks to a relay without a shell.
 *
 * specs/relay_without_a_shell.md § The plane side. One curl handle, keep-alive
 * on, against https://<mrl_public_ip>/, with the relay's identity PINNED
 * (CURLOPT_PINNEDPUBLICKEY over the SPKI fingerprint the birth report
 * carried). Peer and host verification are off because the pin IS the
 * verification and the plane connects by IP, with no server name: a wrong pin
 * fails the TLS connection before a request is sent (curl error 90). Every
 * request is signed with the relay client identity (RelayProtocol), so the
 * relay knows who is asking and nothing on the wire is a shared secret.
 *
 * It does not go through SafeHttpClient, for the reason that spec gives for
 * trusted infrastructure endpoints: the target is a pinned key at a known
 * address, not a URL a user supplied.
 *
 * Failures are CLASSED, not just reported, because the class is the diagnosis:
 * a dead machine (refused, unreachable), an updated one whose new pin has not
 * landed (pin_mismatch), a stale key or clock (signature_refused), or a relay
 * that answered something this code cannot read. MailboxRelay::pollHealth
 * records the class so the Setup tab can say which.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayProtocol.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_client_identity_class.php'));

class RelayClientException extends Exception {
	/** @var string one of RelayClient::FAIL_* */
	public $failure_class;
	/** @var int the HTTP status when the relay answered, else 0 */
	public $http_status;

	public function __construct(string $failure_class, string $message, int $http_status = 0) {
		parent::__construct($message);
		$this->failure_class = $failure_class;
		$this->http_status = $http_status;
	}
}

class RelayClient {

	const FAIL_REFUSED           = 'refused';           // TCP refused: the machine is up, nothing listens
	const FAIL_UNREACHABLE       = 'unreachable';       // no route, DNS-less IP down, or connect timeout
	const FAIL_TIMEOUT           = 'timeout';           // connected, then no answer in time
	const FAIL_PIN_MISMATCH      = 'pin_mismatch';      // a different identity answered (an updated relay?)
	const FAIL_SIGNATURE_REFUSED = 'signature_refused'; // 401: key unknown, stale clock, replay
	const FAIL_HTTP              = 'http_error';        // any other non-2xx
	const FAIL_UNREADABLE        = 'unreadable';        // 2xx but not the JSON expected

	/** Timeouts, in seconds: the numbers the ssh commands carried. */
	const CONNECT_TIMEOUT = 15;
	const REQUEST_TIMEOUT = 60;
	/** A fragment merge waits on root's path unit; the relay itself gives up at 30 s. */
	const FRAGMENT_TIMEOUT = 45;
	/** A spool artifact can be tens of MB. */
	const FETCH_TIMEOUT = 300;

	/**
	 * Test seam: ip => base URL. A test relay listens on a loopback port, and
	 * the plane connects to an IP on 443; this maps the one to the other
	 * without a port column nothing in production would ever set.
	 * @var array
	 */
	public static $base_url_override = array();

	/** @var string */
	private $ip;
	/** @var string curl pin, sha256//... */
	private $pin;
	/** @var string tenant slug the envelope names */
	private $tenant;
	/** @var string RelayClientIdentity::KIND_* */
	private $identity_kind;
	/** @var resource|CurlHandle|null one handle per client: keep-alive */
	private $curl = null;

	public function __construct(string $public_ip, string $identity_fingerprint, string $tenant,
			string $identity_kind = RelayClientIdentity::KIND_CLIENT) {
		$this->ip = trim($public_ip);
		$this->pin = RelayProtocol::curlPin($identity_fingerprint);
		$this->tenant = $tenant;
		$this->identity_kind = $identity_kind;
	}

	/** The client for a relay row that carries an identity pin. */
	public static function forRelay(MailboxRelay $relay): RelayClient {
		return new self((string)$relay->get('mrl_public_ip'), (string)$relay->get('mrl_identity_fingerprint'),
			$relay->tenantSlug(), RelayClientIdentity::KIND_CLIENT);
	}

	/** The operator's client for a shard: the tenant routes, and the ping. */
	public static function forOperator(string $public_ip, string $identity_fingerprint): RelayClient {
		return new self($public_ip, $identity_fingerprint, RelayProtocol::OPERATOR_TENANT,
			RelayClientIdentity::KIND_OPERATOR);
	}

	public function __destruct() {
		if ($this->curl !== null) {
			curl_close($this->curl);
		}
	}

	private function baseUrl(): string {
		if (isset(self::$base_url_override[$this->ip])) {
			return rtrim(self::$base_url_override[$this->ip], '/');
		}
		return 'https://' . $this->ip;
	}

	// ------------------------------------------------------------------ routes

	/** GET /relay/ping: the whole health object. */
	public function ping(): array {
		return $this->json('GET', '/relay/ping');
	}

	/**
	 * GET /relay/spool: complete entries, oldest first.
	 * @return array{entries: array<array{id:string,kind:string,size:int}>, more: bool}
	 */
	public function spoolList(string $after = '', int $limit = 200): array {
		$query = 'limit=' . intval($limit) . ($after !== '' ? '&after=' . rawurlencode($after) : '');
		$page = $this->json('GET', '/relay/spool?' . $query);
		if (!isset($page['entries']) || !is_array($page['entries'])) {
			throw new RelayClientException(self::FAIL_UNREADABLE, 'The relay answered a spool listing without entries.');
		}
		return array('entries' => $page['entries'], 'more' => !empty($page['more']));
	}

	/** GET /relay/spool/{id}.{kind} straight to a file. Returns bytes written. */
	public function spoolFetch(string $id, string $kind, string $dest_path): int {
		if (!preg_match('/^[A-Za-z0-9._-]+$/', $id) || !in_array($kind, array('seal', 'direct', 'meta'), true)) {
			throw new RelayClientException(self::FAIL_HTTP, 'Refusing to fetch a malformed spool name.');
		}
		$fh = fopen($dest_path, 'wb');
		if ($fh === false) {
			throw new RelayClientException(self::FAIL_UNREADABLE, 'Cannot open ' . $dest_path . ' for the spool fetch.');
		}
		try {
			$this->request('GET', '/relay/spool/' . $id . '.' . $kind, '', self::FETCH_TIMEOUT, $fh);
		} catch (\Throwable $e) {
			fclose($fh);
			@unlink($dest_path);
			throw $e;
		}
		fclose($fh);
		return (int)filesize($dest_path);
	}

	/** POST /relay/spool/ack. Returns how many entries the relay removed. */
	public function spoolAck(array $ids): int {
		if (empty($ids)) {
			return 0;
		}
		$answer = $this->json('POST', '/relay/spool/ack', json_encode(array('ids' => array_values($ids))));
		return intval($answer['acked'] ?? 0);
	}

	/**
	 * PUT /relay/fragment. Returns the verdict object the relay's root applier
	 * wrote: {id, status: ok|rejected|error|timeout, reason?, merge?: {...}}.
	 * A rejected or errored merge is a verdict, not a transport failure, so it
	 * is returned rather than thrown.
	 */
	public function putFragment(string $fragment_json): array {
		list($status, $body) = $this->request('PUT', '/relay/fragment', $fragment_json, self::FRAGMENT_TIMEOUT,
			null, array(200, 422, 500, 504));
		$verdict = json_decode($body, true);
		if (!is_array($verdict) || !isset($verdict['status'])) {
			throw new RelayClientException(self::FAIL_UNREADABLE,
				'The relay answered the fragment push with no verdict (HTTP ' . $status . ').', $status);
		}
		return $verdict;
	}

	/**
	 * POST /egress: the relay makes a box-signed Direct request on this
	 * deployment's behalf. Returns the upstream's status and body, or null when
	 * the relay could not or would not make the request: it could not reach the
	 * upstream (502), or refused the target under its own rules (400, 403). A
	 * refused signature still throws - that is about this deployment, not the
	 * target.
	 * @return array{status:int,body:string}|null
	 */
	public function egress(string $target_url, string $body, string $content_type): ?array {
		list($status, $answer, $headers) = $this->requestWithHeaders('POST', '/egress', $body, self::REQUEST_TIMEOUT,
			array('X-Joinery-Direct-Target: ' . $target_url, 'Content-Type: ' . $content_type),
			array(200, 400, 403, 502));
		if ($status !== 200) {
			return null;
		}
		if (preg_match('/^X-Joinery-Direct-Status:\s*(\d+)/mi', $headers, $m)) {
			return array('status' => (int)$m[1], 'body' => $answer);
		}
		return null;
	}

	/** POST /relay/tenants/{slug}: operator only. Returns the verdict. */
	public function tenantAdd(string $slug, string $public_key, array $domains, array $limits = array()): array {
		return $this->verdict('POST', '/relay/tenants/' . rawurlencode($slug), json_encode(array(
			'public_key' => $public_key, 'domains' => array_values($domains), 'limits' => (object)$limits,
		)));
	}

	/** PUT /relay/tenants/{slug}/domains: operator only. */
	public function tenantSetDomains(string $slug, array $domains): array {
		return $this->verdict('PUT', '/relay/tenants/' . rawurlencode($slug) . '/domains',
			json_encode(array('domains' => array_values($domains))));
	}

	/** DELETE /relay/tenants/{slug}: operator only; the spool must be empty. */
	public function tenantRemove(string $slug): array {
		return $this->verdict('DELETE', '/relay/tenants/' . rawurlencode($slug), '');
	}

	private function verdict(string $method, string $uri, string $body): array {
		list($status, $answer) = $this->request($method, $uri, $body, self::FRAGMENT_TIMEOUT, null, array(200, 422, 500, 504));
		$verdict = json_decode($answer, true);
		if (!is_array($verdict) || !isset($verdict['status'])) {
			throw new RelayClientException(self::FAIL_UNREADABLE, 'The relay answered with no verdict (HTTP ' . $status . ').', $status);
		}
		return $verdict;
	}

	// ---------------------------------------------------------------- transport

	/** A JSON-answering route; 2xx only. */
	private function json(string $method, string $uri, string $body = ''): array {
		list($status, $answer) = $this->request($method, $uri, $body, self::REQUEST_TIMEOUT);
		$decoded = json_decode($answer, true);
		if (!is_array($decoded)) {
			throw new RelayClientException(self::FAIL_UNREADABLE,
				'The relay answered ' . $uri . ' with something this server cannot read (HTTP ' . $status . ').', $status);
		}
		return $decoded;
	}

	/**
	 * One signed, pinned request. Returns [status, body]. Throws a classed
	 * RelayClientException on a transport failure, on 401 (the signature was
	 * refused), and on any status not in $accept.
	 *
	 * @param resource|null $sink write the body here instead of returning it
	 * @param int[] $accept statuses returned rather than thrown (2xx always)
	 */
	private function request(string $method, string $uri, string $body, int $timeout, $sink = null, array $accept = array()): array {
		list($status, $answer) = $this->requestWithHeaders($method, $uri, $body, $timeout, array(), $accept, $sink);
		return array($status, $answer);
	}

	private function requestWithHeaders(string $method, string $uri, string $body, int $timeout, array $extra_headers,
			array $accept, $sink = null): array {
		$env = RelayProtocol::envelope($this->tenant, $method, $uri, $body);
		$signature = RelayClientIdentity::sign($this->identity_kind, RelayProtocol::requestSigningBytes($env));
		$headers = array_merge(array(
			RelayProtocol::AUTH_HEADER . ': ' . RelayProtocol::authHeaderValue($env, $signature),
			'Expect:',
		), $extra_headers);
		if ($body !== '' && !$this->hasContentType($extra_headers)) {
			$headers[] = 'Content-Type: application/json';
		}

		if ($this->curl === null) {
			$this->curl = curl_init();
		}
		$ch = $this->curl;
		curl_reset($ch);
		$header_buf = '';
		curl_setopt_array($ch, array(
			CURLOPT_URL             => $this->baseUrl() . $uri,
			CURLOPT_CUSTOMREQUEST   => $method,
			CURLOPT_POSTFIELDS      => $body,
			CURLOPT_HTTPHEADER      => $headers,
			CURLOPT_SSL_VERIFYPEER  => false,
			CURLOPT_SSL_VERIFYHOST  => 0,
			CURLOPT_PINNEDPUBLICKEY => $this->pin,
			CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT         => $timeout,
			CURLOPT_FOLLOWLOCATION  => false,
			CURLOPT_TCP_KEEPALIVE   => 1,
			CURLOPT_HEADERFUNCTION  => function ($ch, $line) use (&$header_buf) {
				$header_buf .= $line;
				return strlen($line);
			},
		));
		if ($sink !== null) {
			curl_setopt($ch, CURLOPT_FILE, $sink);
		} else {
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		}
		$raw = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($errno !== 0) {
			throw new RelayClientException(self::classify($errno), 'Relay ' . $this->ip . ': ' . $error . ' (curl ' . $errno . ')');
		}
		$answer = ($sink !== null) ? '' : (string)$raw;
		if ($status === 401) {
			throw new RelayClientException(self::FAIL_SIGNATURE_REFUSED,
				'Relay ' . $this->ip . ' refused this server\'s signature: ' . self::reason($answer), 401);
		}
		if (($status < 200 || $status > 299) && !in_array($status, $accept, true)) {
			throw new RelayClientException(self::FAIL_HTTP,
				'Relay ' . $this->ip . ' answered HTTP ' . $status . ' to ' . $method . ' ' . $uri . ': ' . self::reason($answer), $status);
		}
		return array($status, $answer, $header_buf);
	}

	private function hasContentType(array $headers): bool {
		foreach ($headers as $h) {
			if (stripos($h, 'Content-Type:') === 0) { return true; }
		}
		return false;
	}

	/** Map a curl errno to a failure class. */
	public static function classify(int $errno): string {
		switch ($errno) {
			case CURLE_SSL_PINNEDPUBKEYNOTMATCH:  // 90
				return self::FAIL_PIN_MISMATCH;
			case CURLE_OPERATION_TIMEOUTED:       // 28
				return self::FAIL_TIMEOUT;
			case CURLE_COULDNT_CONNECT:           // 7
				return self::FAIL_REFUSED;
			case CURLE_COULDNT_RESOLVE_HOST:
			case CURLE_COULDNT_RESOLVE_PROXY:
			case CURLE_SEND_ERROR:
			case CURLE_RECV_ERROR:
			case CURLE_GOT_NOTHING:
				return self::FAIL_UNREACHABLE;
		}
		return self::FAIL_UNREACHABLE;
	}

	/** The relay's short error string out of a JSON refusal, for a message. */
	private static function reason(string $body): string {
		$decoded = json_decode($body, true);
		if (is_array($decoded)) {
			return (string)($decoded['error'] ?? $decoded['reason'] ?? substr($body, 0, 200));
		}
		return substr(trim($body), 0, 200);
	}

	/** One plain sentence per failure class, for the Setup tab and the log. */
	public static function describeFailure(string $class): string {
		switch ($class) {
			case self::FAIL_REFUSED:
				return 'The machine answers on the network but nothing is listening on 443 — the relay service is down or the machine is being re-imaged.';
			case self::FAIL_UNREACHABLE:
				return 'The machine did not answer at all — it is off, has no route, or its firewall dropped the connection.';
			case self::FAIL_TIMEOUT:
				return 'The relay accepted the connection but did not answer in time.';
			case self::FAIL_PIN_MISMATCH:
				return 'Something answered on 443 with a different identity than the one this server pinned — an updated relay whose new pin has not landed here, or a different machine at that address.';
			case self::FAIL_SIGNATURE_REFUSED:
				return 'The relay refused this server\'s signature — the relay does not hold this server\'s key, or one of the two clocks is wrong.';
			case self::FAIL_UNREADABLE:
				return 'The relay answered something this server cannot read.';
		}
		return 'The relay answered with an error.';
	}
}
?>

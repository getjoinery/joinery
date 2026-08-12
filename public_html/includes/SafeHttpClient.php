<?php
/**
 * SafeHttpClient - the outbound HTTP path for any fetch whose destination is
 * chosen by someone below the trusted-operator line (specs/safe_http_client.md).
 *
 * A request parameter, a remote party's DNS record, or a row a low-privilege
 * user can write are all destinations an attacker can aim at 127.0.0.1, cloud
 * metadata, or an internal admin panel. UrlSafetyValidator already decides
 * correctly whether a URL is safe; this class is the client that makes using it
 * the default rather than a per-callsite discipline. Nothing about the validator
 * changes — this wraps it.
 *
 * Every request, always: validate before a socket opens, pin the connection to
 * the exact IPs just validated (CURLOPT_RESOLVE, so the real hostname is still
 * used for SNI, Host and certificate verification), never hand redirect
 * following to curl, keep TLS verification on with no per-call escape hatch, and
 * cap the response size so attacker-controlled content cannot be buffered
 * without bound.
 *
 * The port policy accepts two shapes, because a list cannot express an open
 * range and Joinery Direct needs one (443 plus any port >= 1024, so a
 * deployment can run a dedicated listener later without opening the
 * SSH/SMTP/DNS-class targets below 1024):
 *
 *   'allowed_ports' => [80, 443]                 // plain list (the default)
 *   'allowed_ports' => ['allow' => [443], 'min' => 1024]
 *   'allowed_ports' => null                      // any port (rarely correct)
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/UrlSafetyValidator.php'));

/** A transport-level failure: connect, timeout, TLS, oversized body. */
class SafeHttpException extends Exception {}

/** One HTTP response. `headers` is a lowercased name => value map. */
class SafeHttpResponse {

	/** @var int */
	public $status;
	/** @var array<string,string> */
	public $headers;
	/** @var string */
	public $body;
	/** @var string The URL the body actually came from (differs after a walked redirect). */
	public $final_url;

	public function __construct(int $status, array $headers, string $body, string $final_url) {
		$this->status    = $status;
		$this->headers   = $headers;
		$this->body      = $body;
		$this->final_url = $final_url;
	}

	/** The first value of one header, case-insensitively; '' when absent. */
	public function header(string $name): string {
		return (string)($this->headers[strtolower($name)] ?? '');
	}

	public function isSuccess(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/** The decoded JSON body, or null when the body is not a JSON object/array. */
	public function json() {
		$decoded = json_decode($this->body, true);
		return is_array($decoded) ? $decoded : null;
	}
}

class SafeHttpClient {

	/** @var array */
	private $policy;

	public function __construct(array $policy = array()) {
		$this->policy = array_merge(array(
			'allowed_ports'      => array(80, 443),
			'allow_redirects'    => false,
			'max_redirects'      => 3,
			'connect_timeout'    => 5,
			'timeout'            => 15,
			'max_response_bytes' => 5000000,
			'user_agent'         => 'Joinery/SafeHttpClient',
		), $policy);
	}

	/**
	 * The port policy Joinery Direct uses: 443, or any unprivileged port.
	 *
	 * Named rather than repeated at the callsite because the reasoning belongs
	 * with the value — the Direct design deliberately keeps a dedicated listener
	 * on a high port open as a later option, while ports below 1024 are the
	 * SSH/SMTP/DNS-class targets an attacker-chosen SRV record would aim at.
	 */
	public static function directPortPolicy(): array {
		return array('allow' => array(443), 'min' => 1024);
	}

	public function get(string $url, array $headers = array()): SafeHttpResponse {
		return $this->request('GET', $url, null, $headers);
	}

	public function post(string $url, string $body, array $headers = array()): SafeHttpResponse {
		return $this->request('POST', $url, $body, $headers);
	}

	/**
	 * @throws UnsafeUrlException when the URL (or any redirect hop) must not be fetched
	 * @throws SafeHttpException  on a transport failure
	 */
	public function request(string $method, string $url, ?string $body, array $headers = array()): SafeHttpResponse {
		$max_hops = $this->policy['allow_redirects'] ? max(0, (int)$this->policy['max_redirects']) : 0;
		$current  = $url;

		for ($hop = 0; $hop <= $max_hops; $hop++) {
			$response = $this->requestOnce($method, $current, $body, $headers);

			if ($response->status < 300 || $response->status >= 400 || !$this->policy['allow_redirects']) {
				return $response;
			}

			$location = $response->header('location');
			if ($location === '') {
				throw new SafeHttpException('Redirect response with no Location header (status ' . $response->status . ').');
			}
			// Every hop is re-validated and re-pinned from scratch: the first hop
			// passing the guard is exactly how a pinned fetch gets escaped.
			$current = self::resolveRelative($current, $location);

			// A redirected POST becomes a GET, as every browser and HTTP client does
			// for 301/302/303. Replaying a body to a host the caller never named is
			// how a redirect turns into a write somewhere else.
			if ($method !== 'GET' && $response->status !== 307 && $response->status !== 308) {
				$method = 'GET';
				$body = null;
			}
		}

		throw new SafeHttpException('Too many redirects (>' . $max_hops . ').');
	}

	/** One validated, pinned, non-following request. */
	private function requestOnce(string $method, string $url, ?string $body, array $headers): SafeHttpResponse {
		$pin = UrlSafetyValidator::checkAndResolve($url, array(
			'allowed_ports' => $this->portAllowlistFor($url),
		));

		$ch = curl_init();
		if ($ch === false) {
			throw new SafeHttpException('Could not initialise curl.');
		}

		$header_lines = array();
		foreach ($headers as $name => $value) {
			$header_lines[] = $name . ': ' . $value;
		}

		$response_headers = array();
		$received = 0;
		$max_bytes = (int)$this->policy['max_response_bytes'];
		$overflowed = false;
		$chunks = array();
		// The body is capped; the RESPONSE HEADERS must be too, or a hostile peer
		// floods megabytes of header lines and exhausts memory before a single body
		// byte is counted. 64 KiB is far past any legitimate header set.
		$header_bytes = 0;
		$max_header_bytes = 65536;

		curl_setopt_array($ch, array(
			CURLOPT_URL            => $url,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => false,
			// Redirects are NEVER delegated to curl: it re-resolves the hostname,
			// which reopens the rebinding window the pin just closed.
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => (int)$this->policy['connect_timeout'],
			CURLOPT_TIMEOUT        => (int)$this->policy['timeout'],
			CURLOPT_USERAGENT      => (string)$this->policy['user_agent'],
			CURLOPT_HTTPHEADER     => $header_lines,
			CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$response_headers, &$header_bytes, $max_header_bytes) {
				$header_bytes += strlen($line);
				if ($header_bytes > $max_header_bytes) {
					return 0; // aborts the transfer — a header flood cannot exhaust memory
				}
				$parts = explode(':', $line, 2);
				if (count($parts) === 2) {
					$response_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
				}
				return strlen($line);
			},
			// Streamed rather than buffered so the transfer can be ABORTED past the
			// cap instead of discovering the size after it has all arrived.
			CURLOPT_WRITEFUNCTION  => function ($handle, $chunk) use (&$chunks, &$received, &$overflowed, $max_bytes) {
				$received += strlen($chunk);
				if ($max_bytes > 0 && $received > $max_bytes) {
					$overflowed = true;
					return 0; // aborts the transfer
				}
				$chunks[] = $chunk;
				return strlen($chunk);
			},
		));

		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		if (!empty($pin['ips'])) {
			curl_setopt($ch, CURLOPT_RESOLVE, array(
				$pin['host'] . ':' . $pin['port'] . ':' . implode(',', $pin['ips']),
			));
		}

		$ok     = curl_exec($ch);
		$errno  = curl_errno($ch);
		$error  = curl_error($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($overflowed) {
			throw new SafeHttpException('Response exceeded ' . $max_bytes . ' bytes; transfer aborted.');
		}
		if ($ok === false && $errno !== 0) {
			throw new SafeHttpException('HTTP transport error (' . $errno . '): ' . $error);
		}

		return new SafeHttpResponse($status, $response_headers, implode('', $chunks), $url);
	}

	/**
	 * The concrete single-port allowlist to hand the validator for this URL.
	 *
	 * The policy may name an open range, which no list can express, so the port
	 * is resolved here and the validator is then asked to permit exactly it —
	 * the validator keeps deciding, and its policy table is untouched.
	 *
	 * @return int[]|null
	 */
	private function portAllowlistFor(string $url): ?array {
		$policy = $this->policy['allowed_ports'];
		if ($policy === null) {
			return null; // any port
		}

		$parts  = parse_url($url);
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$port   = (int)($parts['port'] ?? ($scheme === 'http' ? 80 : 443));

		if (is_array($policy) && (isset($policy['allow']) || isset($policy['min']))) {
			$allow = isset($policy['allow']) ? array_map('intval', (array)$policy['allow']) : array();
			$min   = isset($policy['min']) ? (int)$policy['min'] : null;
			if (in_array($port, $allow, true) || ($min !== null && $port >= $min)) {
				return array($port);
			}
			throw new UnsafeUrlException('Port ' . $port . ' is not allowed.');
		}

		return array_map('intval', (array)$policy);
	}

	/** Absolute-ise a Location header against the URL it came from. */
	private static function resolveRelative(string $base, string $location): string {
		if (preg_match('#^https?://#i', $location)) {
			return $location;
		}
		$parts = parse_url($base);
		if (!$parts) {
			throw new UnsafeUrlException('Cannot resolve redirect against malformed base URL.');
		}
		$authority = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
			. (isset($parts['port']) ? ':' . $parts['port'] : '');
		if (substr($location, 0, 1) === '/') {
			return $authority . $location;
		}
		$path = $parts['path'] ?? '/';
		$dir  = substr($path, 0, strrpos($path, '/') + 1);
		return $authority . $dir . $location;
	}
}

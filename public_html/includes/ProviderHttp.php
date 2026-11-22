<?php
/**
 * ProviderHttp — the one way an email or DNS provider talks to its service.
 *
 * Every provider class sends its requests through request(), so one place
 * owns the timeouts, the retry rule, the error shape, the test seam and
 * keeping credentials out of logs. It is a thin layer over Guzzle.
 *
 * THE RULES:
 *
 *  1. An HTTP error status never throws. The caller reads ->status and
 *     decides, because each service has its own words for "not found" and
 *     "wrong key". Only a failure with no response at all (DNS, connect, TLS,
 *     timeout) throws ProviderHttpException.
 *
 *  2. Credentials stay on the wire. Request headers, the basic-auth pair and
 *     the query string never reach a log or an exception message. Redirects
 *     are not followed: Guzzle strips only Authorization on a cross-host hop,
 *     so following one would carry a key header to wherever it points. A 3xx
 *     comes back to the caller like any other status.
 *
 *  3. A retry never risks a second delivery unless the caller opts in. By
 *     default only a 429, or a transport failure where the service cannot
 *     have read the request (curl 6 could not resolve, 7 could not connect,
 *     35 TLS handshake), is retried. Curl 28 (timed out) and 52 (empty reply)
 *     can happen after the service read the whole message, so they never are.
 *     A 5xx is retried only with 'retry_5xx' => true; 'retry_if' adds a
 *     service's own "try again" signal. Backoff is 1 s then 2 s and ignores
 *     Retry-After on purpose: a page waiting on a send should not sleep 30 s.
 *
 *  4. Signing happens on every attempt. 'sign' is called once per attempt
 *     with the headers the caller set and the body, and its headers are
 *     added, so a retry never carries an earlier attempt's signature.
 *
 *  5. One test seam: useTestHandler() answers every request with a Guzzle
 *     handler (a MockHandler plus history middleware). CLI only.
 *
 * @version 1.1 - an error Guzzle raises before sending (a header value it refuses) is a ProviderHttpException naming
 *   the host and the error's class only, never its message (reviewer2 B14)
 * @version 1.0 - first version (specs/email_provider_sdk_removal.md, WP1)
 */

require_once(PathHelper::getComposerAutoloadPath());

class ProviderHttp {

    /** Curl error numbers where the service cannot have read the request. */
    const PRE_SEND_ERRNOS = array(6, 7, 35);

    /** Seconds slept before retry 1, 2, ... (the last value repeats). */
    const BACKOFF = array(1, 2);

    /** @var callable|null a test process's stand-in for the network */
    private static $test_handler = null;

    /** @var callable|null a test process's stand-in for sleep() */
    private static $test_sleep = null;

    /**
     * One request. Options:
     *   headers          name => value
     *   json | form | multipart | body   at most one body form
     *   auth             [user, pass] HTTP basic
     *   query            name => value
     *   retries          more attempts after the first (default 0)
     *   retry_5xx        a 5xx counts as retryable (default false)
     *   retry_if         fn(ProviderHttpResponse): bool
     *   sign             fn(array $headers, string $body): array headers to add
     *   timeout, connect_timeout   seconds (30, 10)
     *
     * @throws ProviderHttpException when no response came back at all
     */
    public static function request(string $method, string $url, array $options = array()): ProviderHttpResponse {
        $method  = strtoupper($method);
        $headers = (array)($options['headers'] ?? array());
        $retries = max(0, (int)($options['retries'] ?? 0));
        $retry_5xx = !empty($options['retry_5xx']);
        $retry_if  = $options['retry_if'] ?? null;
        $sign      = $options['sign'] ?? null;

        $guzzle = array(
            'http_errors'     => false,
            'allow_redirects' => false,
            'timeout'         => (float)($options['timeout'] ?? 30),
            'connect_timeout' => (float)($options['connect_timeout'] ?? 10),
        );
        if (isset($options['auth'])) {
            $guzzle['auth'] = array_values((array)$options['auth']);
        }
        if (!empty($options['query'])) {
            $guzzle['query'] = self::buildQuery((array)$options['query']);
        }

        // The body is fixed before the first attempt, so a signer sees exactly
        // the bytes that go out. Multipart is the one form whose bytes Guzzle
        // builds itself (with a random boundary); it is never signed.
        $body = '';
        if (array_key_exists('json', $options)) {
            $body = json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                throw new ProviderHttpException('The request body could not be encoded as JSON: ' . json_last_error_msg());
            }
            self::setDefaultHeader($headers, 'Content-Type', 'application/json');
            $guzzle['body'] = $body;
        } elseif (array_key_exists('form', $options)) {
            $body = http_build_query((array)$options['form'], '', '&', PHP_QUERY_RFC3986);
            self::setDefaultHeader($headers, 'Content-Type', 'application/x-www-form-urlencoded');
            $guzzle['body'] = $body;
        } elseif (array_key_exists('multipart', $options)) {
            if ($sign !== null) {
                throw new ProviderHttpException('A multipart request cannot be signed.');
            }
            $guzzle['multipart'] = $options['multipart'];
        } elseif (array_key_exists('body', $options)) {
            $body = (string)$options['body'];
            $guzzle['body'] = $body;
        }
        self::setDefaultHeader($headers, 'Accept', 'application/json');

        $client = self::client();
        $attempt = 0;
        while (true) {
            $attempt_headers = $headers;
            if ($sign !== null) {
                foreach ((array)$sign($headers, $body) as $name => $value) {
                    $attempt_headers[$name] = $value;
                }
            }
            $guzzle['headers'] = $attempt_headers;

            try {
                $raw = $client->request($method, $url, $guzzle);
            } catch (\GuzzleHttp\Exception\TransferException $e) {
                $errno = self::curlErrno($e);
                if ($attempt < $retries && in_array($errno, self::PRE_SEND_ERRNOS, true)) {
                    self::pause($attempt++);
                    continue;
                }
                throw new ProviderHttpException(self::describeFailure($method, $url, $errno, $e), $errno);
            } catch (\Throwable $e) {
                // Raised before anything was sent: a header value Guzzle
                // refuses, a malformed URL. Its message can quote the value
                // (a key with a stray control character), so only the class
                // is named.
                throw new ProviderHttpException($method . ' ' . (string)parse_url($url, PHP_URL_HOST)
                    . ' was not sent: ' . get_class($e), 0);
            }

            $resp = new ProviderHttpResponse(
                $raw->getStatusCode(),
                (string)$raw->getBody(),
                $raw->getHeaders()
            );
            if ($attempt < $retries && self::shouldRetry($resp, $retry_5xx, $retry_if)) {
                self::pause($attempt++);
                continue;
            }
            return $resp;
        }
    }

    /**
     * Answer every request with $handler (a Guzzle handler) instead of the
     * network, for the rest of this process; null restores the network.
     * Sleeping between retries is skipped while a handler is set.
     *
     * @throws LogicException outside the CLI
     */
    public static function useTestHandler(?callable $handler): void {
        if (php_sapi_name() !== 'cli') {
            throw new LogicException('ProviderHttp::useTestHandler is for test processes only.');
        }
        self::$test_handler = $handler;
    }

    /** True while a test handler answers requests. */
    public static function testHandlerActive(): bool {
        return self::$test_handler !== null;
    }

    /** Record backoff waits instead of sleeping (test only). */
    public static function useTestSleep(?callable $sleep): void {
        if (php_sapi_name() !== 'cli') {
            throw new LogicException('ProviderHttp::useTestSleep is for test processes only.');
        }
        self::$test_sleep = $sleep;
    }

    /**
     * A query string from name => value, RFC 3986 encoded. A list value
     * repeats its name (Mailgun's to=a&to=b), never name[0]=.
     */
    public static function buildQuery(array $query): string {
        $parts = array();
        foreach ($query as $name => $value) {
            foreach ((is_array($value) ? $value : array($value)) as $v) {
                if (is_bool($v)) { $v = $v ? 'true' : 'false'; }
                $parts[] = rawurlencode((string)$name) . '=' . rawurlencode((string)$v);
            }
        }
        return implode('&', $parts);
    }

    // ── internals ───────────────────────────────────────────────────────

    private static function client(): \GuzzleHttp\Client {
        if (self::$test_handler !== null) {
            return new \GuzzleHttp\Client(array('handler' => \GuzzleHttp\HandlerStack::create(self::$test_handler)));
        }
        return new \GuzzleHttp\Client();
    }

    private static function shouldRetry(ProviderHttpResponse $resp, bool $retry_5xx, $retry_if): bool {
        if ($resp->status === 429) {
            return true;
        }
        if ($retry_5xx && $resp->status >= 500 && $resp->status <= 599) {
            return true;
        }
        return $retry_if !== null && (bool)$retry_if($resp);
    }

    private static function pause(int $attempt): void {
        $seconds = self::BACKOFF[min($attempt, count(self::BACKOFF) - 1)];
        if (self::$test_sleep !== null) {
            (self::$test_sleep)($seconds);
            return;
        }
        if (self::$test_handler !== null) {
            return;
        }
        sleep($seconds);
    }

    /** The curl error number from a transfer failure's handler context, or 0. */
    private static function curlErrno(\Throwable $e): int {
        if (method_exists($e, 'getHandlerContext')) {
            $ctx = $e->getHandlerContext();
            if (isset($ctx['errno'])) {
                return (int)$ctx['errno'];
            }
        }
        return 0;
    }

    /**
     * Words for a failure with no response, naming the host only. Guzzle's
     * own message carries the full URL (query string included), so it is
     * never passed on.
     */
    private static function describeFailure(string $method, string $url, int $errno, \Throwable $e): string {
        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($errno > 0) {
            $why = function_exists('curl_strerror') ? (string)curl_strerror($errno) : '';
            return $method . ' ' . $host . ' failed: curl error ' . $errno . ($why !== '' ? ' (' . $why . ')' : '');
        }
        return $method . ' ' . $host . ' failed: ' . get_class($e);
    }

    private static function setDefaultHeader(array &$headers, string $name, string $value): void {
        foreach ($headers as $k => $_) {
            if (strcasecmp((string)$k, $name) === 0) {
                return;
            }
        }
        $headers[$name] = $value;
    }
}

/** A response from ProviderHttp::request(): status, body and headers. */
class ProviderHttpResponse {
    /** @var int */
    public $status;
    /** @var string */
    public $body;
    /** @var array lowercased name => list of values */
    private $headers = array();

    public function __construct(int $status, string $body, array $headers = array()) {
        $this->status = $status;
        $this->body = $body;
        foreach ($headers as $name => $values) {
            $this->headers[strtolower((string)$name)] = array_values((array)$values);
        }
    }

    /** The decoded JSON body, or null when the body is not a JSON object or list. */
    public function json(): ?array {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** One header's first value, or null. Case does not matter. */
    public function header(string $name): ?string {
        $values = $this->headers[strtolower($name)] ?? null;
        return $values ? (string)$values[0] : null;
    }

    public function ok(): bool {
        return $this->status >= 200 && $this->status <= 299;
    }
}

/** No response came back at all (DNS, connect, TLS, timeout). The code is the curl errno, or 0. */
class ProviderHttpException extends RuntimeException {
}

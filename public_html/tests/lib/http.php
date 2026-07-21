<?php
/**
 * Shared HTTP client for tests — one request function, one cookie/CSRF surface.
 *
 * Every test that talks to the site over HTTP goes through harness_request().
 * It covers the whole union the estate needs: JSON / form / raw-byte /
 * multipart bodies, a cookie jar with extra cookies, redirect control, response
 * header capture, and origin pinning.
 *
 * Requires tests/lib/harness.php (loaded here) for the assertion surface and
 * teardown registry; jars created via harness_jar_new() are removed by the
 * harness's LIFO teardown, so suites never clean them up by hand.
 *
 * ---- Target resolution ----------------------------------------------------
 * The base URL comes from the `webDir` setting, so a test run targets whatever
 * site the code it lives in serves. Nothing is hardcoded and nothing new needs
 * configuring.
 *
 * Requests to that site are pinned to the origin IP so they bypass Cloudflare
 * and REMOTE_ADDR stays stable (the API's per-IP rate limiter needs this).
 * The origin is this machine: tests run on the box that serves the site, so the
 * outbound interface address IS the origin. Pinning engages only when the target
 * host matches `webDir` — point a suite at another host and it resolves through
 * DNS normally, so a prod base URL can never be silently served by this box.
 */

if (!defined('JOINERY_HARNESS_HTTP_LOADED')) {
	define('JOINERY_HARNESS_HTTP_LOADED', 1);

	require_once(__DIR__ . '/harness.php');

	// ---- shared mutable state (global script scope) ------------------------
	$GLOBALS['__harness_http'] = array(
		'base_url'  => null,   // null = derive from webDir on first use
		'origin_ip' => null,   // null = probe on first use; false = unavailable
	);
}

// ==========================================================================
// Target resolution
// ==========================================================================

/**
 * The site under test, e.g. "https://dev.getjoinery.com" (no trailing slash).
 *
 * Derived from `webDir` unless harness_http_configure() overrode it. The scheme
 * follows `protocol_mode`; 'auto' means "depends on the request" and there is no
 * request on the CLI, so anything but an explicit 'http' resolves to https.
 */
function harness_http_base_url() {
	if ($GLOBALS['__harness_http']['base_url'] === null) {
		$settings = Globalvars::get_instance();
		$host = preg_replace('#^https?://#', '', (string)$settings->get_setting('webDir'));
		$host = rtrim($host, '/');
		if ($host === '') {
			throw new RuntimeException(
				'Cannot derive the test base URL: the webDir setting is empty. '
				. 'Pass a base URL explicitly via harness_http_configure().'
			);
		}
		$scheme = ($settings->get_setting('protocol_mode') === 'http') ? 'http' : 'https';
		$GLOBALS['__harness_http']['base_url'] = $scheme . '://' . $host;
	}
	return $GLOBALS['__harness_http']['base_url'];
}

/**
 * This machine's outbound address — the origin behind Cloudflare. Returns null
 * when it cannot be determined, in which case requests resolve through DNS.
 *
 * Asking the kernel which source address it would use to reach a public address
 * is the reliable way to get this: no packet is sent (UDP connect only sets the
 * route), and it sidesteps hostname lookups, which resolve to loopback here, and
 * interface enumeration, which would surface the Tailscale and private addresses
 * alongside the public one.
 */
function harness_http_origin_ip() {
	if ($GLOBALS['__harness_http']['origin_ip'] === null) {
		$ip = false;
		if (function_exists('socket_create')) {
			$sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
			if ($sock) {
				if (@socket_connect($sock, '8.8.8.8', 53) && @socket_getsockname($sock, $addr)) {
					$ip = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $addr : false;
				}
				socket_close($sock);
			}
		}
		$GLOBALS['__harness_http']['origin_ip'] = $ip;
	}
	return $GLOBALS['__harness_http']['origin_ip'] ?: null;
}

/**
 * Override the derived target. Pass null for either to leave it derived.
 */
function harness_http_configure($base_url = null, $origin_ip = null) {
	if ($base_url !== null) {
		$GLOBALS['__harness_http']['base_url'] = rtrim($base_url, '/');
	}
	if ($origin_ip !== null) {
		$GLOBALS['__harness_http']['origin_ip'] = $origin_ip;
	}
}

/**
 * Read the optional positional [base_url] [origin_ip] out of $argv.
 *
 * Runner flags (--json, --timeout=…) are skipped, so a flag can never be
 * mistaken for a base URL — a bug that once pointed every request at "--json"
 * and turned a whole suite's statuses into 0.
 */
function harness_http_boot($argv) {
	$positional = array_values(array_filter(array_slice((array)$argv, 1), function ($a) {
		return strpos($a, '--') !== 0;
	}));
	harness_http_configure(
		isset($positional[0]) ? $positional[0] : null,
		isset($positional[1]) ? $positional[1] : null
	);
}

// ==========================================================================
// The request
// ==========================================================================

/**
 * Make an HTTP request. $url is a path ("/api/v1/ping") against the base URL, or
 * an absolute URL (signed links point at a full URL of their own).
 *
 * Options:
 *   headers     array   ['Name: value', ...]
 *   body        mixed   array → encoded per 'encode'; string → sent verbatim
 *   encode      string  json (default for arrays) | form | raw | multipart
 *   files       array   ['field' => ['path'=>…, 'name'=>…, 'type'=>…]] (multipart)
 *   jar         string  cookie jar path from harness_jar_new(); read AND written
 *   cookies     string  extra 'a=1; b=2' sent alongside the jar
 *   accept      string  Accept header; null to omit (default application/json)
 *   follow      mixed   false (default) | true | int max redirects
 *   timeout     int     seconds, default 30
 *   pin         bool    force origin pinning on/off (default: auto)
 *   user_agent  string
 *   insecure    bool    skip TLS verification
 *
 * Returns:
 *   status         int     HTTP code, 0 on a transport failure
 *   body / raw     string  response body ('raw' is an alias; both are the body)
 *   json           array|null  decoded body, null when not JSON
 *   headers        array   response header lines, final hop only
 *   header_string  string  those lines joined
 *   content_type   string
 *   redirect_url   string  Location target when not following
 *   error          string  cURL error, '' on success
 */
function harness_request($method, $url, array $opts = array()) {
	$absolute = (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0);
	$full = $absolute ? $url : harness_http_base_url() . $url;
	$host = parse_url($full, PHP_URL_HOST);

	$headers = isset($opts['headers']) ? $opts['headers'] : array();
	$accept = array_key_exists('accept', $opts) ? $opts['accept'] : 'application/json';
	if ($accept !== null) {
		$headers[] = 'Accept: ' . $accept;
	}

	$ch = curl_init($full);

	// ---- body -------------------------------------------------------------
	$body = isset($opts['body']) ? $opts['body'] : null;
	$files = isset($opts['files']) ? $opts['files'] : array();
	$encode = isset($opts['encode'])
		? $opts['encode']
		: ($files ? 'multipart' : (is_array($body) ? 'json' : 'raw'));

	if ($body !== null || $files) {
		if ($encode === 'multipart') {
			// curl builds the multipart boundary itself; a manual Content-Type
			// would override it with one that has no boundary.
			$fields = is_array($body) ? $body : array();
			foreach ($files as $field => $f) {
				$fields[$field] = new CURLFile($f['path'], $f['type'], $f['name']);
			}
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
		} else {
			if ($encode === 'form') {
				$payload = is_array($body) ? http_build_query($body) : (string)$body;
				$headers[] = 'Content-Type: application/x-www-form-urlencoded';
			} elseif ($encode === 'json') {
				$payload = json_encode($body);
				$headers[] = 'Content-Type: application/json';
			} else {
				// raw: bytes go out untouched (chunked uploads must not be re-encoded)
				$payload = (string)$body;
			}
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		}
	}

	// ---- response headers -------------------------------------------------
	// Collected via callback rather than CURLOPT_HEADER so RETURNTRANSFER yields
	// the body alone — no header-size arithmetic, and each redirect hop resets
	// the set so the caller sees the response it actually landed on.
	$response_headers = array();
	curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$response_headers) {
		$trimmed = trim($line);
		if (stripos($trimmed, 'HTTP/') === 0) {
			$response_headers = array();
		}
		if ($trimmed !== '') {
			$response_headers[] = $trimmed;
		}
		return strlen($line);
	});

	curl_setopt_array($ch, array(
		CURLOPT_CUSTOMREQUEST  => strtoupper($method),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER     => $headers,
		CURLOPT_TIMEOUT        => isset($opts['timeout']) ? $opts['timeout'] : 30,
		CURLOPT_CONNECTTIMEOUT => isset($opts['connect_timeout']) ? $opts['connect_timeout'] : 10,
	));

	// ---- cookies ----------------------------------------------------------
	if (!empty($opts['jar'])) {
		curl_setopt($ch, CURLOPT_COOKIEJAR, $opts['jar']);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $opts['jar']);
	}
	if (!empty($opts['cookies'])) {
		curl_setopt($ch, CURLOPT_COOKIE, $opts['cookies']);
	}

	// ---- redirects --------------------------------------------------------
	$follow = isset($opts['follow']) ? $opts['follow'] : false;
	if ($follow !== false) {
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, is_int($follow) ? $follow : 5);
	} else {
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
	}

	// ---- origin pinning ---------------------------------------------------
	$pin = array_key_exists('pin', $opts) ? $opts['pin'] : null;
	if ($pin === null) {
		// Auto: pin only the site this box serves. Comparing against the derived
		// base URL (not the configured one) keeps an explicit --base_url override
		// pointed at another host resolving through DNS.
		$pin = ($host === parse_url(harness_http_base_url(), PHP_URL_HOST));
	}
	if ($pin) {
		$origin = harness_http_origin_ip();
		if ($origin) {
			curl_setopt($ch, CURLOPT_RESOLVE, array(
				$host . ':443:' . $origin,
				$host . ':80:' . $origin,
			));
		}
	}

	if (!empty($opts['user_agent'])) {
		curl_setopt($ch, CURLOPT_USERAGENT, $opts['user_agent']);
	}
	if (!empty($opts['insecure'])) {
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	}

	$raw = curl_exec($ch);
	$error = curl_error($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$content_type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	$redirect_url = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
	curl_close($ch);

	$raw = ($raw === false) ? '' : (string)$raw;

	return array(
		'status'        => $status,
		'body'          => $raw,
		'raw'           => $raw,
		'json'          => json_decode($raw, true),
		'headers'       => $response_headers,
		'header_string' => implode("\n", $response_headers),
		'content_type'  => $content_type,
		'redirect_url'  => $redirect_url,
		'error'         => $error,
	);
}

/**
 * Raw-body PUT of one upload chunk to the chunk transport. The bytes go out
 * untouched (a JSON/form encoder would corrupt them). $content_range is the
 * "bytes A-B/T" value; $key_headers are the API credential headers.
 */
function harness_put_chunk($path, array $key_headers, $content_range, $body) {
	return harness_request('PUT', $path, array(
		'headers' => array_merge($key_headers, array(
			'Content-Range: ' . $content_range,
			'Content-Type: application/octet-stream',
		)),
		'body'   => $body,
		'encode' => 'raw',
	));
}

/**
 * True when any response header line matches $pattern (a preg pattern).
 */
function harness_header_matches(array $headers, $pattern) {
	foreach ($headers as $line) {
		if (preg_match($pattern, $line)) {
			return true;
		}
	}
	return false;
}

// ==========================================================================
// Cookies, CSRF, login
// ==========================================================================

/**
 * A fresh cookie jar, deleted at teardown.
 */
function harness_jar_new($prefix = 'jyjar') {
	$jar = tempnam(sys_get_temp_dir(), $prefix);
	harness_defer(function () use ($jar) {
		if (file_exists($jar)) {
			@unlink($jar);
		}
	});
	return $jar;
}

/**
 * Read a cookie value out of a jar, or null if absent.
 *
 * Jars are Netscape format: tab-separated, domain first, name in field 6 and
 * value in field 7.
 *
 * An HttpOnly cookie — the session id, among others — is written with its domain
 * prefixed by "#HttpOnly_", so treating every line starting with "#" as a comment
 * makes exactly those cookies unreadable. That reads as "no such cookie" rather
 * than as an error, which turns an assertion about a session id into null === null.
 */
function harness_jar_cookie($jar, $name) {
	if (!$jar || !file_exists($jar)) {
		return null;
	}
	foreach (explode("\n", (string)file_get_contents($jar)) as $line) {
		if ($line === '' || (strpos($line, '#') === 0 && strpos($line, '#HttpOnly_') !== 0)) {
			continue;
		}
		$parts = explode("\t", trim($line));
		if (count($parts) >= 7 && $parts[5] === $name) {
			return $parts[6];
		}
	}
	return null;
}

/**
 * The CSRF token the server handed this jar.
 *
 * Two places carry the token and they are not interchangeable: the
 * `joinery_api_csrf` cookie (present for anyone, including anonymous callers)
 * and the `joinery-api-csrf` meta tag (rendered only into a logged-in page).
 * This reads the cookie; harness_meta_csrf() reads the tag.
 */
function harness_jar_csrf($jar) {
	return harness_jar_cookie($jar, 'joinery_api_csrf');
}

/**
 * The CSRF token embedded in a rendered page, or null if the page carries none.
 */
function harness_meta_csrf($html) {
	if (preg_match('/<meta name="joinery-api-csrf" content="([0-9a-f]{64})"/', (string)$html, $m)) {
		return $m[1];
	}
	return null;
}

/**
 * The header the API expects a browser-session caller to send.
 */
function harness_csrf_header($token) {
	return array('X-Joinery-Csrf: ' . $token);
}

/**
 * Log a user in over the real form-post path, leaving the session in $jar.
 *
 * Returns the CSRF token from the resulting logged-in page, or null when login
 * did not take — callers should assert on that rather than push on.
 */
function harness_web_login($jar, $email, $password) {
	$login = harness_request('POST', '/login', array(
		'jar'    => $jar,
		'body'   => array('email' => $email, 'password' => $password),
		'encode' => 'form',
	));
	// A successful login redirects; a failed one re-renders the form with a 200.
	if (!in_array($login['status'], array(301, 302, 303), true)) {
		return null;
	}
	$page = harness_request('GET', '/', array('jar' => $jar, 'accept' => null));
	return harness_meta_csrf($page['body']);
}

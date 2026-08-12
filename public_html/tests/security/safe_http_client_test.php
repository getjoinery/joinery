<?php
/** @joinery-test
 * name: safe_http_client
 * tier: db
 * env: any
 * needs: []
 */

/**
 * The one SSRF-safe outbound path (specs/safe_http_client.md).
 *
 * Reaching out to a URL is dangerous whenever any part of the destination is
 * chosen below the trusted-operator line — a request parameter, a row a
 * low-privilege user can write, or a remote party's DNS record. Joinery Direct
 * is the last of those and the reason this client exists: the SRV target a
 * sender connects to is published by whoever controls the recipient's domain,
 * so a hostile domain could aim it at loopback, at cloud metadata, or at an
 * internal admin port.
 *
 * These checks pin the policy, not the transport: which hosts and ports are
 * refused before a socket opens, that a validated hostname is pinned to its
 * validated IPs, and that redirects are never handed to curl. The network is
 * never actually reached — a URL that must be refused is refused during
 * validation, which is the whole point.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/SafeHttpClient.php'));

/** Does this client refuse $url before opening a socket? */
function refuses(SafeHttpClient $client, string $url): bool {
	try {
		$client->get($url);
		return false;
	} catch (UnsafeUrlException $e) {
		return true;
	} catch (SafeHttpException $e) {
		// A transport failure means validation PASSED and the connection was
		// attempted — the opposite of what a refusal assertion wants to see.
		return false;
	} catch (Throwable $e) {
		return false;
	}
}

$direct = new SafeHttpClient(array(
	'allowed_ports'   => SafeHttpClient::directPortPolicy(),
	'allow_redirects' => false,
	'connect_timeout' => 1,
	'timeout'         => 2,
));

// ---------------------------------------------------------------------------
section('Private, loopback and reserved addresses are refused');
// ---------------------------------------------------------------------------

$blocked = array(
	'https://127.0.0.1/x'          => 'loopback',
	'https://[::1]/x'              => 'IPv6 loopback',
	'https://10.1.2.3/x'           => 'RFC1918 10/8',
	'https://192.168.4.5/x'        => 'RFC1918 192.168/16',
	'https://172.16.9.9/x'         => 'RFC1918 172.16/12',
	'https://169.254.169.254/x'    => 'link-local — the cloud metadata address',
	'https://0.0.0.0/x'            => '0.0.0.0/8, which routes to loopback on Linux',
	'https://100.64.1.1/x'         => 'CGNAT',
	'https://localhost/x'          => 'the localhost hostname literal',
);
foreach ($blocked as $url => $why) {
	check(refuses($direct, $url), $why . ' is refused before a socket opens');
}

// ---------------------------------------------------------------------------
section('Only http and https, and only the ports the policy names');
// ---------------------------------------------------------------------------

foreach (array('file:///etc/passwd', 'gopher://example.com/', 'dict://example.com/', 'ftp://example.com/') as $url) {
	check(refuses($direct, $url), parse_url($url, PHP_URL_SCHEME) . ':// is refused');
}

// The Direct port policy: 443, or any unprivileged port. Privileged ports other
// than 443 are the SSH/SMTP/DNS-class targets an attacker-chosen SRV record
// would aim at, and a dedicated Direct listener never runs on one.
foreach (array(22, 25, 53, 80, 111, 1023) as $port) {
	check(refuses($direct, 'https://example.com:' . $port . '/x'),
		'port ' . $port . ' is refused — below 1024 and not 443');
}

// The open range above 1024 is deliberate: the design keeps a dedicated
// listener on a high port available as a later option, so a 443-only policy
// would foreclose a stated goal.
$reflect = new ReflectionMethod('SafeHttpClient', 'portAllowlistFor');
$reflect->setAccessible(true);
foreach (array(443, 1024, 8443, 65535) as $port) {
	$allowed = true;
	try {
		$reflect->invoke($direct, 'https://example.com:' . $port . '/x');
	} catch (UnsafeUrlException $e) {
		$allowed = false;
	}
	check($allowed, 'port ' . $port . ' is permitted by the Direct policy');
}

// The default policy is narrower, and a caller has to opt out of it explicitly.
$default = new SafeHttpClient(array('connect_timeout' => 1, 'timeout' => 2));
$default_ports = new ReflectionProperty('SafeHttpClient', 'policy');
$default_ports->setAccessible(true);
$policy = $default_ports->getValue($default);
check($policy['allowed_ports'] === array(80, 443), 'the default port policy is 80 and 443');
check($policy['allow_redirects'] === false, 'redirects are off by default');
check($policy['max_response_bytes'] > 0, 'and a response size cap is always set');

// ---------------------------------------------------------------------------
section('The policy shape can express an open range, which a list cannot');
// ---------------------------------------------------------------------------

$shape = SafeHttpClient::directPortPolicy();
check(isset($shape['allow']) && in_array(443, $shape['allow'], true) && (int)$shape['min'] === 1024,
	'the Direct policy is expressed as 443 plus a 1024 floor');

$anything = new SafeHttpClient(array('allowed_ports' => null, 'connect_timeout' => 1, 'timeout' => 2));
$any_allowed = true;
try {
	$reflect->invoke($anything, 'https://example.com:7777/x');
} catch (UnsafeUrlException $e) {
	$any_allowed = false;
}
check($any_allowed, 'a null policy permits any port, for the rare caller that genuinely needs it');

// ---------------------------------------------------------------------------
section('A malformed destination is refused rather than guessed at');
// ---------------------------------------------------------------------------

foreach (array('', 'not-a-url', '//example.com/x', 'https:///x') as $url) {
	check(refuses($direct, $url), 'a malformed URL (' . ($url === '' ? 'empty' : $url) . ') is refused');
}

// ---------------------------------------------------------------------------
section('Redirects are walked, never delegated');
// ---------------------------------------------------------------------------

// Following redirects is how a pinned fetch gets escaped: the first hop passes
// the guard and the Location header sends the client somewhere internal. The
// client never sets CURLOPT_FOLLOWLOCATION, so the only way a redirect is
// followed is the manual loop — which re-validates and re-pins every hop.
$source = file_get_contents(PathHelper::getIncludePath('includes/SafeHttpClient.php'));
check(strpos($source, 'CURLOPT_FOLLOWLOCATION => false') !== false,
	'CURLOPT_FOLLOWLOCATION is explicitly off on every handle');
check(substr_count($source, 'CURLOPT_FOLLOWLOCATION') === 1,
	'and it is never set to anything else anywhere in the client');
check(strpos($source, 'CURLOPT_SSL_VERIFYPEER => true') !== false
	&& strpos($source, 'CURLOPT_SSL_VERIFYHOST => 2') !== false,
	'TLS verification is always on — there is no per-call insecure flag to reach for');
check(strpos($source, 'CURLOPT_RESOLVE') !== false,
	'the connection is pinned to the validated IPs, closing the resolve-to-connect rebinding window');
check(strpos($source, 'checkAndResolve') !== false,
	'and every request runs through the shared validator rather than a bespoke guard');

harness_finish();

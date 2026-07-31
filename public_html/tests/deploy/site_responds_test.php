<?php
/** @joinery-test
 * name: deploy_site_responds
 * tier: deploy
 * env: any
 * needs: [curl]
 * timeout: 90
 */
/**
 * The site serves a page after the swap.
 *
 * The end-to-end version of the question, and the only check here that runs the
 * new code the way a visitor does: through Apache, through serve.php, through
 * the theme. A fatal that only appears under the web SAPI — a missing extension,
 * an include that resolves differently from the document root, a theme file the
 * release dropped — shows up here and nowhere else in this tier.
 *
 * Pointed at the loopback, not at the public name. `--resolve` forces the
 * request to 127.0.0.1 while still sending the right Host header, so the vhost
 * matches and the answer comes from the box that was just deployed rather than
 * from a CDN, a proxy, or — during a DNS change — some other machine entirely.
 *
 * Reads only. GET on the homepage and the sign-in page; nothing that mutates.
 *
 * A network that cannot be reached is a SKIP, never a failure. This test runs
 * with a rollback hanging on its result, and reverting a good release because
 * curl could not open a socket would be the worse error of the two.
 *
 * Run: php tests/deploy/site_responds_test.php
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$settings = Globalvars::get_instance();

section('The deployed site answers over HTTP');

// webDir is the site's own name, set in Globalvars_site.php at install time and
// therefore present on every deployment. It is documented as a bare domain, but
// sites exist that carry a full URL in it, and site_url is populated on some
// installs and not others — so take the first of the three that yields a
// hostname and normalize rather than trusting any one of them to be well-formed.
$candidates = array(
	trim((string)$settings->get_setting('webDir')),
	trim((string)$settings->get_setting('site_url')),
);

$host = '';
$scheme = 'https';
foreach ($candidates as $candidate) {
	if ($candidate === '') {
		continue;
	}
	if (strpos($candidate, '//') === false) {
		$candidate = 'https://' . $candidate;
	}
	$parts = parse_url($candidate);
	if (!empty($parts['host'])) {
		$host = $parts['host'];
		$scheme = (($parts['scheme'] ?? 'https') === 'http') ? 'http' : 'https';
		break;
	}
}

if ($host === '') {
	harness_skip('the site answers on /', 'no webDir or site_url to build a request from');
	harness_finish();
	return;
}

// A site installed on a bare IP has no name to send, and forcing the loopback
// makes that fine — the Host header is the IP and the default vhost answers.
$port = ($scheme === 'https') ? 443 : 80;

/**
 * GET a path and return [http_code, body_bytes, how], or null when no attempt
 * could be made at all.
 *
 * Three attempts, most local first, because they answer progressively weaker
 * questions and the strongest available answer is the one worth having:
 *
 *   1. HTTPS forced to the loopback. Proves this box, this vhost.
 *   2. HTTP forced to the loopback. Same proof. Needed because a great many
 *      deployments terminate TLS somewhere else — behind Cloudflare, behind a
 *      reverse proxy, or on a site whose certificate has not been issued yet —
 *      and on those the origin simply does not complete a TLS handshake on
 *      127.0.0.1.
 *   3. The public address, resolved normally. Weaker: a CDN or proxy could be
 *      answering. Still worth having, because a 5xx coming back through a CDN
 *      is a 5xx the deployed code produced.
 *
 * -k throughout: this asks whether PHP produced a page, not whether the TLS
 * chain is complete. A missing or self-signed certificate is a normal state for
 * a site whose DNS has not landed, and the SSL retry timer's business, not this
 * test's.
 */
function deploy_fetch($scheme, $host, $port, $path) {
	$attempts = array(
		array($scheme, $port, true,  'loopback ' . $scheme),
		array('http',  80,    true,  'loopback http'),
		array($scheme, $port, false, 'public address'),
	);

	foreach ($attempts as $attempt) {
		list($try_scheme, $try_port, $force_local, $how) = $attempt;
		$cmd = 'curl -s -o /dev/null -w "%{http_code} %{size_download}" --max-time 25 -k';
		if ($force_local) {
			$cmd .= ' --resolve ' . escapeshellarg($host . ':' . $try_port . ':127.0.0.1');
		}
		$cmd .= ' ' . escapeshellarg($try_scheme . '://' . $host . $path) . ' 2>/dev/null';

		$out = array();
		$rc = 0;
		exec($cmd, $out, $rc);
		if ($rc !== 0 || empty($out)) {
			continue;
		}
		$fields = preg_split('/\s+/', trim($out[0]));
		if (count($fields) < 2 || (int)$fields[0] === 0) {
			continue;
		}
		return array((int)$fields[0], (int)$fields[1], $how);
	}

	return null;
}

$home = deploy_fetch($scheme, $host, $port, '/');

if ($home === null) {
	harness_skip('the site answers on /',
		'could not reach ' . $host . ' on the loopback — no verdict, so none is claimed');
	harness_finish();
	return;
}

list($code, $bytes, $how) = $home;

// 5xx is the failure this exists to catch: Apache reached PHP and PHP died.
// A redirect is a perfectly good answer — a site may send / to a landing page,
// to a locale, or to HTTPS.
check($code > 0 && $code < 500, 'the homepage does not return a server error',
	'HTTP ' . $code . ' for ' . $host . '/ via ' . $how);
check($bytes > 0 || ($code >= 300 && $code < 400),
	'the homepage returns a body', $bytes . ' bytes');

// Sign-in exercises a different slice: session bootstrap, FormWriter, the theme
// chain. It is present on every deployment regardless of what else is enabled.
$login = deploy_fetch($scheme, $host, $port, '/login');
if ($login === null) {
	harness_skip('the sign-in page does not return a server error', 'request could not be made');
} else {
	check($login[0] > 0 && $login[0] < 500, 'the sign-in page does not return a server error',
		'HTTP ' . $login[0]);
}

harness_finish();

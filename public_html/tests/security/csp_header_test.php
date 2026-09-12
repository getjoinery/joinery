<?php
/** @joinery-test
 * name: csp_header
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Content-Security-Policy — sent according to two settings, and says what the
 * spec says (specs/implemented/content_security_policy.md, Phase 1).
 *
 * enable_csp off (factory default): no header, zero change for a deployment.
 * On with csp_report_only on: Content-Security-Policy-Report-Only. On with
 * report-only off: Content-Security-Policy, enforcing. The policy keeps
 * 'unsafe-inline' for scripts and styles on purpose — the strict policy is a
 * separate project — and closes what it can: unlisted script and frame hosts,
 * plugins/objects, framing by other sites.
 *
 * The builder is checked directly for all three states; the live site is
 * checked once, for whichever state its settings are in, so the header on the
 * wire is the builder's output and not a second opinion.
 *
 * Run: php tests/run.php safe --filter=csp_header
 *
 * @version 1.1 - no CDN, hosts not schemes, and the tree sweep that keeps the inventory current
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/http.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));

section('Off by default means no header');
check(PublicPageBase::csp_header(false, true) === null, 'disabled + report-only → nothing');
check(PublicPageBase::csp_header(false, false) === null, 'disabled + enforcing → nothing');

section('On: report-only first, enforcing when asked');
$ro = PublicPageBase::csp_header(true, true);
$en = PublicPageBase::csp_header(true, false);
check(is_array($ro) && $ro[0] === 'Content-Security-Policy-Report-Only', 'report-only header name');
check(is_array($en) && $en[0] === 'Content-Security-Policy', 'enforcing header name');
check($ro[1] === $en[1], 'the policy text is identical either way — only enforcement changes');

section('The policy');
$policy = PublicPageBase::csp_policy();
$value  = $en[1];
$has = function ($directive, $source) use ($policy) {
	return isset($policy[$directive]) && in_array($source, $policy[$directive], true);
};
foreach (array('default-src', 'script-src', 'style-src', 'img-src', 'font-src', 'connect-src', 'frame-ancestors') as $d) {
	check(isset($policy[$d]), "spec directive present: {$d}");
}
check($has('default-src', "'self'"), "default-src 'self'");
check($has('script-src', "'unsafe-inline'") && $has('style-src', "'unsafe-inline'"), "Phase 1 keeps 'unsafe-inline' for scripts and styles");
check($has('script-src', 'https://js.stripe.com') && $has('frame-src', 'https://js.stripe.com') && $has('frame-src', 'https://hooks.stripe.com'),
	'Stripe: script and its frames');
check($has('script-src', 'https://www.paypal.com') && $has('frame-src', 'https://www.paypal.com') && $has('form-action', 'https://www.paypal.com'),
	'PayPal: script, frames and the redirect form');
check($has('script-src', 'https://*.hcaptcha.com') && $has('frame-src', 'https://*.hcaptcha.com'), 'hCaptcha: script and frame');
check($has('script-src', 'https://www.google.com') && $has('frame-src', 'https://www.google.com'), 'reCAPTCHA: script and frame');
check($has('frame-src', 'https://www.youtube.com') && $has('frame-src', 'https://www.youtube-nocookie.com'), 'YouTube embeds');
check($has('style-src', 'https://fonts.googleapis.com') && $has('font-src', 'https://fonts.gstatic.com'), 'Google Fonts: stylesheet host and font-file host');
check($has('frame-src', 'https://player.vimeo.com'), 'Vimeo player');
check($has('script-src', 'https://static.cloudflareinsights.com') && $has('connect-src', 'https://cloudflareinsights.com'),
	'Cloudflare Web Analytics beacon (injected at the edge, invisible to the tree sweep)');
foreach (array('https://fast.wistia.net', 'https://www.loom.com', 'https://player.twitch.tv', 'https://open.spotify.com',
	'https://w.soundcloud.com', 'https://calendly.com', 'https://*.typeform.com', 'https://docs.google.com',
	'https://calendar.google.com', 'https://www.eventbrite.com', 'https://www.openstreetmap.org', 'https://codepen.io') as $embed) {
	check($has('frame-src', $embed) && !$has('script-src', $embed), "embeddable product is a frame host only: {$embed}");
}
check($has('connect-src', 'https://api.stripe.com') && $has('connect-src', 'https://www.paypal.com') && $has('connect-src', 'https://*.hcaptcha.com'),
	'connect-src: the payment and captcha APIs the embedded scripts call');
foreach (array('script-src', 'style-src', 'font-src', 'connect-src', 'frame-src') as $d) {
	check(!$has($d, 'https:') && !$has($d, 'wss:') && !$has($d, '*'), "{$d} names hosts, never a bare scheme");
}
foreach (array('cdn.tailwindcss.com', 'cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com', 'code.jquery.com', 'esm.sh') as $cdn) {
	check(strpos($value, $cdn) === false, "no general-purpose CDN: {$cdn}");
}
check($has('img-src', 'data:') && $has('img-src', 'blob:') && $has('img-src', 'https:'), 'images: data:, blob: and any https host');
check($has('object-src', "'none'"), "object-src 'none' — plugins and embeds are closed");
check($has('base-uri', "'self'"), "base-uri 'self' — no <base> hijack");
check($has('frame-ancestors', "'self'"), "frame-ancestors 'self' — the CSP form of X-Frame-Options");
check(strpos($value, 'http:') === false, 'no plain-http source anywhere');
check(!preg_match('/\*(?![.-])/', str_replace("'", '', $value)) || strpos($value, ' * ') === false, "no bare wildcard source");
check(substr_count($value, ';') === count($policy) - 1 && strpos($value, 'default-src ') === 0, 'serialized as "directive sources; ..." starting with default-src');
check(!preg_match('/[\r\n]/', $value), 'single header line');

section('Every external host the tree loads is in the policy (the standing inventory)');
// Walk the browser-facing code for <script src>, stylesheet <link>, @import,
// <iframe src> and CSS url() that name another https host, and hold each one
// against the directive it belongs to. A new third party fails here until
// csp_policy() lists it or the asset ships locally; docs and tests are not
// swept, and neither is anything under a vendor directory.
$root = realpath(__DIR__ . '/../..');
$skip = '#/(\.git|node_modules|docs|specs|tests|theme-sources|cache|logs|uploads|backups|\.claude|\.playwright-mcp|vendor|content_staging)(/|$)#';
$allowed = function ($directive, $host) use ($policy) {
	foreach ($policy[$directive] as $src) {
		if ($src === "https://{$host}") return true;
		if (strpos($src, 'https://*.') === 0 && substr($host, -strlen(substr($src, 9))) === substr($src, 9)) return true;
	}
	return false;
};
$found = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
	$path = $file->getPathname();
	if (preg_match($skip, $path) || !preg_match('#\.(php|js|css|html)$#', $path)) continue;
	$src = @file_get_contents($path);
	if ($src === false) continue;
	$rel = substr($path, strlen($root) + 1);
	$pairs = array(
		'script-src' => '#<script[^>]+src=["\']https://([a-z0-9.-]+)#i',
		'style-src'  => '#(?:<link[^>]+rel=["\']stylesheet["\'][^>]+href=|<link[^>]+href=["\'][^"\']*\.css[^>]*?|@import\s+(?:url\()?["\']?)https://([a-z0-9.-]+)#i',
		'frame-src'  => '#<iframe[^>]+src=["\']https://([a-z0-9.-]+)#i',
	);
	foreach ($pairs as $directive => $re) {
		if (preg_match_all($re, $src, $m)) {
			foreach ($m[1] as $host) $found[$directive][strtolower($host)][] = $rel;
		}
	}
}
foreach (array('script-src', 'style-src', 'frame-src') as $directive) {
	$hosts = isset($found[$directive]) ? $found[$directive] : array();
	ksort($hosts);
	foreach ($hosts as $host => $files) {
		check($allowed($directive, $host), "{$directive} allows {$host}", 'loaded by ' . implode(', ', array_unique($files)));
	}
	check(count($hosts) > 0 || $directive === 'frame-src', "the sweep saw at least one external {$directive} load (it is looking at the right tree)");
}

section('Every OAuth provider consent host is in form-action (a POST redirects there)');
foreach (glob($root . '/includes/oauth/providers/*OAuthProvider.php') as $pf) {
	if (preg_match_all('#https://([a-z0-9.-]+)/[^\'"\s]*(?:/authorize|/auth)(?:[?\'"\s]|$)#i', file_get_contents($pf), $m)) {
		foreach (array_unique($m[1]) as $host) {
			check($allowed('form-action', strtolower($host)), "form-action allows {$host}", basename($pf));
		}
	}
}
check(count(glob($root . '/includes/oauth/providers/*OAuthProvider.php')) >= 3, 'the provider sweep saw the provider catalog');

section('The live site sends what the builder says, for the settings it has');
$settings = Globalvars::get_instance();
$expected = PublicPageBase::csp_header(
	(bool)$settings->get_setting('enable_csp', false, true),
	(bool)$settings->get_setting('csp_report_only', false, true)
);
$r = harness_request('GET', '/', array('accept' => 'text/html', 'follow' => 3));
check($r['status'] > 0 && $r['status'] < 500, 'homepage answers (' . $r['status'] . ')');
$seen = array();
foreach ((array)$r['headers'] as $line) {
	if (preg_match('/^(Content-Security-Policy(?:-Report-Only)?):\s*(.*)$/i', $line, $m)) {
		$seen[$m[1]] = $m[2];
	}
}
if ($expected === null) {
	check(empty($seen), 'enable_csp is off here and the site sends no CSP header', json_encode(array_keys($seen)));
} else {
	check(isset($seen[$expected[0]]) && trim($seen[$expected[0]]) === $expected[1],
		'the site sends ' . $expected[0] . ' with the builder\'s policy', json_encode($seen));
	check(count($seen) === 1, 'and only that one CSP header');
}

harness_finish();

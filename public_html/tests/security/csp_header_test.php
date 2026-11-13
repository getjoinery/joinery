<?php
/** @joinery-test
 * name: csp_header
 * tier: safe
 * parallel: true
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
 * @version 1.12 - a report-only header carries no frame-ancestors
 * @version 1.11 - the enforced strict script-src holds only 'self', hosts, 'wasm-unsafe-eval' and 'report-sample'
 * @version 1.10 - the option patterns name event handlers only, so an array of names ("onedrive") is not counted
 * @version 1.9 - the sweep also counts a handler or custom_script set by assignment ($opts['onclick'] = ...)
 * @version 1.8 - the strict policy names no nonce (Q5); the sweep counts nonced script tags
 * @version 1.7 - the sweep counts handler options; a missing ceilings file fails (--baseline makes one)
 * @version 1.6 - the strict policy (csp_strict_scripts): CspNonce, csp_headers() in its three modes, the live check takes both headers
 * @version 1.5 - the inline-script ratchet: every file held to its count of inline handlers, scripts and javascript: URLs
 * @version 1.4 - a page may add one origin to form-action (allow_form_action_origin), for a POST answered by a redirect
 * @version 1.3 - a page may add one origin to connect-src (allow_connect_origin)
 * @version 1.2 - 'wasm-unsafe-eval' in script-src, never 'unsafe-eval'
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
// Report-only leaves out frame-ancestors, which browsers ignore there; nothing else differs.
$no_fa = function ($v) { return implode('; ', array_values(array_filter(explode('; ', $v), function ($d) { return strpos($d, 'frame-ancestors') !== 0; }))); };
check($ro[1] === $no_fa($en[1]), 'the policy text is the same either way apart from frame-ancestors — only enforcement changes');

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
check($has('script-src', "'wasm-unsafe-eval'") && !$has('script-src', "'unsafe-eval'"),
	"script-src allows compiling WebAssembly (the vault passphrase's Argon2id) and still refuses eval()");
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

section('The strict policy: no inline script at all, beside the standard one or instead of it');
// specs/strict_script_policy.md WP2 (Q5: no nonce).
$strict = PublicPageBase::csp_policy(array(), array(), true);
check(!in_array("'unsafe-inline'", $strict['script-src'], true), "strict script-src drops 'unsafe-inline'");
check(preg_grep("/^'nonce-/", $strict['script-src']) === array() && preg_grep("/^'(?:sha256|sha384|sha512)-/", $strict['script-src']) === array(),
	'and names no nonce and no hash: no inline script runs, whoever wrote it');
check(in_array("'report-sample'", $strict['script-src'], true), 'and asks for a sample of what it refuses');
check(!in_array("'strict-dynamic'", $strict['script-src'], true) && !in_array("'unsafe-eval'", $strict['script-src'], true),
	"no 'strict-dynamic' and no 'unsafe-eval'");
check(in_array("'unsafe-inline'", $strict['style-src'], true), 'styles keep unsafe-inline (out of scope)');
$rest_same = true;
foreach ($policy_base = PublicPageBase::csp_policy() as $d => $sources) {
	if ($d !== 'script-src' && $strict[$d] !== $sources) $rest_same = false;
}
check($rest_same && array_values(array_diff($policy_base['script-src'], array("'unsafe-inline'"))) === array_values(array_diff($strict['script-src'], array("'report-sample'"))),
	'nothing else in the policy moves: the same hosts, the same directives');

check(PublicPageBase::csp_headers(false, false, 'enforce') === array(), 'enable_csp off: nothing, whatever the strict setting');
check(PublicPageBase::csp_headers(true, false, 'off') === array(PublicPageBase::csp_header(true, false)), 'off: the standard header alone');
check(PublicPageBase::csp_headers(true, false, 'nonsense') === array(PublicPageBase::csp_header(true, false)), 'an unknown value is off');
$rep = PublicPageBase::csp_headers(true, false, 'report');
check(count($rep) === 2 && $rep[0] === PublicPageBase::csp_header(true, false) && $rep[1][0] === 'Content-Security-Policy-Report-Only',
	'report: the standard header unchanged, the strict one beside it, report-only');
$strict_script_src = preg_match('/(?:^|;\s*)script-src ([^;]*)/', $rep[1][1], $sm) ? $sm[1] : '';
check($strict_script_src !== '' && strpos($strict_script_src, "'unsafe-inline'") === false
	&& strpos($rep[1][1], 'report-uri ' . PublicPageBase::CSP_REPORT_PATH) !== false,
	"the strict header's script-src has no 'unsafe-inline', and it says where to report", $strict_script_src);
foreach (array('off', 'report', 'enforce') as $mode) {
	foreach (PublicPageBase::csp_headers(true, false, $mode) as $h) {
		check(strpos($h[1], "'nonce-") === false, "$mode: no CSP header names a nonce ({$h[0]})");
	}
}
$enf = PublicPageBase::csp_headers(true, false, 'enforce');
check(count($enf) === 1 && $enf[0][0] === 'Content-Security-Policy' && $no_fa($enf[0][1]) === $rep[1][1],
	'enforce: the strict policy in place of the standard one');
// With every inline block and handler gone from the tree (the sweep's
// ceilings file is empty), the enforced script-src holds only the site,
// hosts, and the two keywords that let nothing inline run: no
// 'unsafe-inline', no 'unsafe-eval', no nonce, no hash (WP6).
$enf_script = preg_match('/(?:^|;\s*)script-src ([^;]*)/', $enf[0][1], $em) ? preg_split('/\s+/', trim($em[1])) : array();
$keywords = array_values(array_filter($enf_script, function ($src) { return strpos($src, "'") === 0; }));
sort($keywords);
$hosts_ok = true;
foreach ($enf_script as $src) {
	if (strpos($src, "'") === 0) continue;
	if (!preg_match('#^https://[a-z0-9.*-]+(?::\d+)?(?:/[^\s]*)?$#i', $src)) $hosts_ok = false;
}
check($keywords === array("'report-sample'", "'self'", "'wasm-unsafe-eval'") && $hosts_ok,
	"enforced script-src: 'self', https hosts, 'wasm-unsafe-eval' and 'report-sample', nothing else", implode(' ', $enf_script));
$enf_ro = PublicPageBase::csp_headers(true, true, 'enforce');
check(count($enf_ro) === 1 && $enf_ro[0][0] === 'Content-Security-Policy-Report-Only', 'enforce stays report-only while csp_report_only is on');
// Browsers ignore frame-ancestors in a report-only policy (Safari says so on
// every page), so a report-only header leaves it out; an enforced one keeps it.
$fa = function ($h) { return strpos($h[1], 'frame-ancestors') !== false; };
check(!$fa($rep[1]) && $fa($rep[0]), 'report mode: the strict report-only header has no frame-ancestors, the enforced standard one does');
check($fa($enf[0]) && !$fa($enf_ro[0]), 'enforce: frame-ancestors when enforced, none while report-only');
check(!$fa(PublicPageBase::csp_header(true, true)) && $fa(PublicPageBase::csp_header(true, false)), 'csp_report_only: the standard policy leaves it out too');
$with_extra = PublicPageBase::csp_headers(true, false, 'report', array('https://models.example.test'));
check(strpos($with_extra[0][1], 'https://models.example.test') !== false && strpos($with_extra[1][1], 'https://models.example.test') !== false,
	"a page's own connect origin reaches both policies");

section('A page may add one origin to connect-src, and nothing else moves');
// The mailbox reader names the model host its owner registered
// (specs/fortress_mail_device_ai.md § R7): allow_connect_origin() on that one
// page, csp_policy($extra) underneath.
$origin = 'https://models.example.test:8443';
$with = PublicPageBase::csp_policy(array($origin));
check(in_array($origin, $with['connect-src'], true), 'the registered origin is in connect-src');
check(array_values(array_diff($with['connect-src'], $policy['connect-src'])) === array($origin),
	'connect-src gains exactly that origin');
$others_same = true;
foreach ($policy as $d => $sources) {
	if ($d !== 'connect-src' && $with[$d] !== $sources) $others_same = false;
}
check($others_same && count($with) === count($policy), 'every other directive is unchanged');
check(PublicPageBase::csp_policy() === $policy && PublicPageBase::csp_policy(array()) === $policy,
	'with no registered origin the policy is the standard one');
$hdr = PublicPageBase::csp_header(true, false, array($origin));
check(strpos($hdr[1], 'connect-src ' . implode(' ', $with['connect-src']) . ';') !== false, 'the header carries it in connect-src');
check(PublicPageBase::csp_policy(array('http://localhost:11434'))['connect-src'] === array_merge($policy['connect-src'], array('http://localhost:11434')),
	'a plain-http origin is allowed only when a page adds it (a model on this computer)');
foreach (array('https://evil.test/path', 'https://*.evil.test', "https://evil.test; script-src *", 'https:', '*', "'self'",
	'javascript:alert(1)', "https://evil.test\r\nSet-Cookie: x=1", 'https://evil test', 'ftp://evil.test', 'https://user@evil.test') as $bad) {
	check(PublicPageBase::csp_policy(array($bad))['connect-src'] === $policy['connect-src'],
		'refused, never reaches the header: ' . json_encode($bad));
}
check(PublicPageBase::normalize_connect_origin('HTTPS://Models.Example.TEST') === 'https://models.example.test', 'origins are lower-cased');
check(PublicPageBase::normalize_connect_origin('http://[::1]:11434') === 'http://[::1]:11434', 'an IPv6 loopback origin is accepted');

section('A page may add one origin to form-action, and nothing else moves');
// The services Connect approval: its POST answers with a redirect to the
// linking site, which form-action governs (allow_form_action_origin()).
$site = 'https://pmn.example.test';
$with_form = PublicPageBase::csp_policy(array(), array($site));
check(array_values(array_diff($with_form['form-action'], $policy['form-action'])) === array($site),
	'form-action gains exactly that origin');
$others_same = true;
foreach ($policy as $d => $sources) {
	if ($d !== 'form-action' && $with_form[$d] !== $sources) $others_same = false;
}
check($others_same, 'every other directive is unchanged');
foreach (array('https://*.evil.test', "https://evil.test; script-src *", '*', "'self'", 'https://evil.test/path') as $bad) {
	check(PublicPageBase::csp_policy(array(), array($bad))['form-action'] === $policy['form-action'],
		'refused in form-action too: ' . json_encode($bad));
}
$hdr = PublicPageBase::csp_header(true, false, array(), array($site));
check(strpos($hdr[1], 'form-action ' . implode(' ', $with_form['form-action'])) !== false, 'the header carries it in form-action');

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

section('No new inline script in the tree (the ratchet toward a strict policy)');
// specs/strict_script_policy.md WP6. What a script-src without 'unsafe-inline'
// refuses, counted in the source the same way the page walk
// (inline_script_walk_test.php) counts it in rendered pages: an inline event
// handler attribute, a <script> with no src that is not a data block, and a
// javascript: URL, and a handler passed to a writer as an option
// ('onclick' => ...). The walk sees only pages it can open with no record id;
// this sees every file. Each file is held to its line in
// inline_script_sweep_ceilings.json (no line means none); over it fails, and
// a file under it is lowered by running this test directly with --lower
// (--baseline alone makes the first ceilings; a missing file fails). A
// line that matches but is no inline script (a parser's pattern, a doc
// string) says so with "jy-allow-script: why" and is not counted.
$sweep_ceilings_file = __DIR__ . '/inline_script_sweep_ceilings.json';
// Only --baseline takes every file's count as its ceiling. A missing file
// fails: deleting it must not reset the ratchet.
$sweep_first = in_array('--baseline', (array)$argv, true);
$sweep_doc = is_file($sweep_ceilings_file) ? json_decode((string)file_get_contents($sweep_ceilings_file), true) : null;
check($sweep_first || is_array($sweep_doc), 'the sweep\'s ceilings file is there to hold the files to',
	"$sweep_ceilings_file is missing or unreadable; only --baseline makes a new one");
$sweep_ceilings = $sweep_first ? array() : (array)($sweep_doc['files'] ?? array());
$sweep_res = array(
	'handler' => '/(?<![\w.$-])on[a-z]{3,}\s*=\s*\\\\?["\']/i',
	'script'  => '/<script\b(?![^>]*\bsrc\s*=)(?![^>]*type\s*=\s*\\\\?["\']?(?:application\/(?:ld\+)?json|text\/template|text\/html|text\/x-))[^>]*>/i',
	'url'     => '/(?:href|src|action|formaction)\s*=\s*\\\\?["\']\s*javascript:/i',
	// A handler handed to a writer as an option ('onclick' => '...'), which
	// the writer prints as an attribute.
	// Event handler names only: an array of app names ("onedrive" => ...) is data.
	'option'  => '/["\'](?:on(?:click|dblclick|change|input|submit|reset|focus|blur|key(?:down|up|press)|mouse[a-z]+|pointer[a-z]+|touch[a-z]+|load|unload|error|select|scroll|wheel|drag[a-z]*|drop|contextmenu|toggle|invalid|beforeunload|paste|copy|cut)|custom_script)["\']\s*=>/i',
	// The same handed over by assignment ($opts['onclick'] = '...'), which
	// the pattern above never saw (reviewer2 B2: the Server Manager copy tab).
	'assign'  => '/\[\s*["\'](?:on(?:click|dblclick|change|input|submit|reset|focus|blur|key(?:down|up|press)|mouse[a-z]+|pointer[a-z]+|touch[a-z]+|load|unload|error|select|scroll|wheel|drag[a-z]*|drop|contextmenu|toggle|invalid|beforeunload|paste|copy|cut)|custom_script)["\']\s*\]\s*=(?![=>])/i',
);
$sweep_counts = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
	$path = $file->getPathname();
	if (preg_match($skip, $path) || !preg_match('#\.(php|js)$#', $path) || preg_match('#\.min\.js$#', $path)) continue;
	$src = @file_get_contents($path);
	if ($src === false) continue;
	$n = 0;
	foreach (explode("\n", $src) as $line) {
		if (strpos($line, 'jy-allow-script:') !== false) continue;
		foreach ($sweep_res as $re) {
			$n += preg_match_all($re, $line);
		}
	}
	if ($n > 0) {
		$sweep_counts[substr($path, strlen($root) + 1)] = $n;
	}
}
ksort($sweep_counts);
$sweep_over = array();
$sweep_under = array();
foreach ($sweep_counts as $rel => $n) {
	$ceiling = (int)($sweep_ceilings[$rel] ?? 0);
	if ($n > $ceiling) $sweep_over[] = "$rel: $n, ceiling $ceiling";
}
foreach ($sweep_ceilings as $rel => $ceiling) {
	$n = (int)($sweep_counts[$rel] ?? 0);
	if ($n < (int)$ceiling) $sweep_under[] = "$rel: $n, ceiling $ceiling";
}
check($sweep_first || (is_array($sweep_doc) && $sweep_over === array()),
	count($sweep_counts) . ' files still carry inline script (' . array_sum($sweep_counts) . ' items), none above its ceiling',
	"move the new handler or block into a script file (see the spec), or mark a line that is not inline script with jy-allow-script:\n" . implode("\n", $sweep_over));
check(!preg_grep('#\.js$#', array_keys($sweep_counts)),
	'no JS file builds an inline handler or script into HTML', implode(', ', preg_grep('#\.js$#', array_keys($sweep_counts))));
if ($sweep_under && !in_array('--lower', (array)$argv, true) && php_sapi_name() === 'cli' && !harness_wants_json()) {
	echo '  NOTE: ' . count($sweep_under) . " files are under their ceiling; lower them with --lower:\n    " . implode("\n    ", $sweep_under) . "\n";
}
if (in_array('--lower', (array)$argv, true) || $sweep_first) {
	$next = array();
	foreach ($sweep_first ? $sweep_counts : $sweep_ceilings as $rel => $ceiling) {
		$n = (int)($sweep_counts[$rel] ?? 0);
		if ($n > 0) $next[$rel] = min($n, (int)$ceiling);
	}
	ksort($next);
	file_put_contents($sweep_ceilings_file, json_encode(array(
		'about' => 'Ceilings for the inline-script sweep in tests/security/csp_header_test.php: per file, how many inline handlers, inline scripts with no src and javascript: URLs it may still hold. Only ever lowered (run the test with --lower). A file not listed must hold none.',
		'files' => $next,
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	echo 'Wrote ' . count($next) . " ceilings to $sweep_ceilings_file\n";
}

section('The live site sends what the builder says, for the settings it has');
$settings = Globalvars::get_instance();
$strict_mode = (string)$settings->get_setting('csp_strict_scripts', false, true);
$expected_all = PublicPageBase::csp_headers(
	(bool)$settings->get_setting('enable_csp', false, true),
	(bool)$settings->get_setting('csp_report_only', false, true),
	$strict_mode
);
$fold = function ($v) { return trim($v); };
$r = harness_request('GET', '/', array('accept' => 'text/html', 'follow' => 3));
check($r['status'] > 0 && $r['status'] < 500, 'homepage answers (' . $r['status'] . ')');
$seen = array();
foreach ((array)$r['headers'] as $line) {
	if (preg_match('/^(Content-Security-Policy(?:-Report-Only)?):\s*(.*)$/i', $line, $m)) {
		$seen[] = array($m[1], $fold($m[2]));
	}
}
$want = array_map(function ($h) use ($fold) { return array($h[0], $fold($h[1])); }, $expected_all);
if ($want === array()) {
	check(empty($seen), 'enable_csp is off here and the site sends no CSP header', json_encode($seen));
} else {
	check($seen === $want, 'the site sends exactly the builder\'s CSP headers (strict policy: ' . ($strict_mode ?: 'off') . ')',
		json_encode(array('sent' => $seen, 'expected' => $want)));
}

harness_finish();

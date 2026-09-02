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
 * @version 1.0
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
check($has('script-src', 'https://js.hcaptcha.com') && $has('frame-src', 'https://*.hcaptcha.com'), 'hCaptcha: script and frame');
check($has('script-src', 'https://www.google.com') && $has('frame-src', 'https://www.google.com'), 'reCAPTCHA: script and frame');
check($has('frame-src', 'https://www.youtube.com') && $has('frame-src', 'https://www.youtube-nocookie.com'), 'YouTube embeds');
check($has('style-src', 'https://fonts.googleapis.com') && $has('font-src', 'https:'), 'Google Fonts');
foreach (array('https://cdn.tailwindcss.com', 'https://cdnjs.cloudflare.com', 'https://cdn.jsdelivr.net') as $cdn) {
	check($has('script-src', $cdn), "theme script CDN: {$cdn}");
}
check($has('img-src', 'data:') && $has('img-src', 'blob:') && $has('img-src', 'https:'), 'images: data:, blob: and any https host');
check($has('object-src', "'none'"), "object-src 'none' — plugins and embeds are closed");
check($has('base-uri', "'self'"), "base-uri 'self' — no <base> hijack");
check($has('frame-ancestors', "'self'"), "frame-ancestors 'self' — the CSP form of X-Frame-Options");
check(strpos($value, 'http:') === false, 'no plain-http source anywhere');
check(!preg_match('/\*(?![.-])/', str_replace("'", '', $value)) || strpos($value, ' * ') === false, "no bare wildcard source");
check(substr_count($value, ';') === count($policy) - 1 && strpos($value, 'default-src ') === 0, 'serialized as "directive sources; ..." starting with default-src');
check(!preg_match('/[\r\n]/', $value), 'single header line');

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

<?php
/** @joinery-test
 * name: absolute_url_protocol
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Which protocol generated URLs use, especially where there is no request.
 *
 * `protocol_mode = auto` means "work it out from the request", which is fine in a
 * browser and meaningless in cron. It used to answer http there, so every link the
 * platform mailed from a scheduled task pointed at plain HTTP — on sites that only
 * serve HTTPS.
 *
 * The fix is to observe rather than guess: real requests record the scheme they
 * arrived on, and headless code reads that back. The property that matters is that
 * this is not a bias toward HTTPS — an HTTP-only deployment observes http and keeps
 * getting http. Both are measured here, because getting one right by assuming is
 * not the same as getting both right by observing.
 *
 * Run: php tests/run.php db --filter=absolute_url_protocol
 *
 * @version 1.1 - asks the pure resolver (LibraryFunctions::resolve_scheme) instead of flipping the live
 *                site's protocol_mode, which raced every other HTTP-touching suite in a parallel run
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$db = DbConnector::get_instance()->get_db_link();

$original_observed = get_setting_raw('protocol_observed_scheme');
harness_defer(function () use ($original_observed) {
	set_setting_raw('protocol_observed_scheme', $original_observed);
});

// The rule is a pure function of the two settings and the request, so it is
// asked directly. This test used to flip protocol_mode on the LIVE site to
// 'http' and ask a subprocess — which, in a parallel run, sent every other
// HTTP-touching suite to plain http for the duration and made this suite fail
// only when run alongside the rest.
$headless = function (string $mode, string $observed): string {
	return LibraryFunctions::resolve_scheme($mode, $observed, null);
};

section('Headless URL protocol under auto');

check($headless('auto', 'https') === 'https', 'a TLS site: cron builds an https link');
check($headless('auto', 'http') === 'http', 'an HTTP-ONLY site: cron builds an http link, not an https one it could not reach');
check($headless('auto', '') === 'http', 'never served a request: falls back to http, which redirects rather than failing');
check($headless('', '') === 'http' && $headless('', 'https') === 'https', "an empty mode means auto");
check($headless('auto', ' HTTPS ') === 'https', 'a recorded scheme is read case- and space-insensitively');
check($headless('auto', 'gopher') === 'http', 'a recorded value that is not a scheme is ignored');

section('An explicit protocol_mode still wins');

check($headless('https', 'http') === 'https', 'protocol_mode https overrides what was observed');
check($headless('https_redirect', 'http') === 'https', 'protocol_mode https_redirect too');
check($headless('http', 'https') === 'http', 'protocol_mode http overrides what was observed');

section('A request being served knows its own scheme');

check(LibraryFunctions::resolve_scheme('auto', 'http', 'https') === 'https', 'an https request under auto builds https links whatever was recorded');
check(LibraryFunctions::resolve_scheme('auto', 'https', 'http') === 'http', 'an http request under auto builds http links');
check(LibraryFunctions::resolve_scheme('http', '', 'https') === 'http', 'and an explicit mode still overrides the request');

// The live function reads the live settings through the same rule: with no
// request context, what it builds is what the resolver says for this site.
$settings = Globalvars::get_instance();
$live = LibraryFunctions::get_absolute_url('/x');
$want = LibraryFunctions::resolve_scheme((string)$settings->get_setting('protocol_mode'),
	(string)$settings->get_setting('protocol_observed_scheme', true, true), null);
check(strpos($live, $want . '://') === 0, 'get_absolute_url() headless agrees with the resolver for this site\'s settings', $live);

section('Observation');

// Recording cannot be faked from here. observe_protocol() refuses to treat a CLI
// process as a web request — deliberately, since believing otherwise is how a
// scheduled task would record its own non-existent scheme — so the only honest way
// to test it is to make a real request and read back what the site wrote down.
check(!LibraryFunctions::has_request_context(),
	'a CLI process is never mistaken for a web request');

$base = trim((string)Globalvars::get_instance()->get_setting('webDir'));
if ($base === '') {
	harness_skip('observation: no webDir configured to make a request against');
} else {
	if (($settings->get_setting('protocol_mode') ?: 'auto') !== 'auto') {
		harness_skip('observation: protocol_mode is not auto on this site, so nothing is recorded');
		harness_finish();
	}
	set_setting_raw('protocol_observed_scheme', '');

	$url = 'https://' . preg_replace('#^https?://#', '', rtrim($base, '/')) . '/login';
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_NOBODY         => false,
	));
	$body = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

	if ($body === false || $status === 0) {
		harness_skip('observation: ' . $url . ' was not reachable from this host');
	} else {
		check(get_setting_raw('protocol_observed_scheme') === 'https',
			'a real request over TLS teaches the site its own scheme',
			'recorded: ' . var_export(get_setting_raw('protocol_observed_scheme'), true));

		// And once taught, headless code uses it — the whole point of recording.
		check(LibraryFunctions::resolve_scheme('auto', get_setting_raw('protocol_observed_scheme'), null) === 'https',
			'cron then builds links on the scheme that was observed');
	}
}

harness_finish();

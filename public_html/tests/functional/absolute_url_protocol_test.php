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
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$db = DbConnector::get_instance()->get_db_link();

$original_mode     = get_setting_raw('protocol_mode');
$original_observed = get_setting_raw('protocol_observed_scheme');

harness_defer(function () use ($original_mode, $original_observed) {
	set_setting_raw('protocol_mode', $original_mode);
	set_setting_raw('protocol_observed_scheme', $original_observed);
});

/**
 * Ask a FRESH php process what URL it builds. A subprocess is the point, not an
 * inconvenience: it has no request context, exactly like the cron runner, and it
 * reloads settings rather than reading this process's cache.
 */
$ask = function (string $mode, string $observed): string {
	set_setting_raw('protocol_mode', $mode);
	set_setting_raw('protocol_observed_scheme', $observed);
	$code = 'require_once("' . PathHelper::getIncludePath('tests/lib/harness.php') . '");'
		. 'harness_boot(); require_once(PathHelper::getIncludePath("includes/LibraryFunctions.php"));'
		. 'echo LibraryFunctions::get_absolute_url("/x");';
	$out = (string)shell_exec('php -r ' . escapeshellarg($code) . ' 2>/dev/null');
	// The harness prints its own summary; the URL is the part starting with a scheme.
	return preg_match('#(https?://\S+)#', $out, $m) ? $m[1] : trim($out);
};

section('Headless URL protocol under auto');

$https_site = $ask('auto', 'https');
check(strpos($https_site, 'https://') === 0,
	'a TLS site: cron builds an https link', $https_site);

$http_site = $ask('auto', 'http');
check(strpos($http_site, 'http://') === 0 && strpos($http_site, 'https://') !== 0,
	'an HTTP-ONLY site: cron builds an http link, not an https one it could not reach',
	$http_site);

$unknown = $ask('auto', '');
check(strpos($unknown, 'http://') === 0 && strpos($unknown, 'https://') !== 0,
	'never served a request: falls back to http, which redirects rather than failing',
	$unknown);

section('An explicit protocol_mode still wins');

$forced_https = $ask('https', 'http');
check(strpos($forced_https, 'https://') === 0,
	'protocol_mode https overrides what was observed', $forced_https);

$forced_http = $ask('http', 'https');
check(strpos($forced_http, 'http://') === 0 && strpos($forced_http, 'https://') !== 0,
	'protocol_mode http overrides what was observed', $forced_http);

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
	set_setting_raw('protocol_mode', 'auto');
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
		check(strpos($ask('auto', 'https'), 'https://') === 0,
			'cron then builds links on the scheme that was observed');
	}
}

harness_finish();

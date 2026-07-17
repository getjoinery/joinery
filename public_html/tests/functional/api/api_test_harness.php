<?php
/**
 * REST API functional-suite boot.
 *
 * The assertion surface, fixtures, teardown and result contract come from the
 * shared harness (tests/lib/harness.php); the HTTP client, cookie jar and CSRF
 * helpers come from tests/lib/http.php. This file adds only what is specific to
 * the API suites: the boot sequence, the key-header shape, and the rate-limiter
 * reset.
 *
 * Each suite requires this file, declares its @joinery-test header (tier: db,
 * env: dev-only), calls api_test_boot($argv), creates fixtures, runs its
 * sections, and in a finally calls harness_teardown_data() then harness_finish().
 *
 * CLI only. Requests to the site under test are pinned to its origin so they
 * bypass Cloudflare and REMOTE_ADDR stays stable (see tests/lib/http.php), and
 * custom headers are sent in hyphen form because Apache→FPM drops header names
 * containing underscores (the API accepts both).
 */

if (php_sapi_name() !== 'cli') {
	echo "This harness must be run from the command line.\n";
	exit(1);
}

require_once(__DIR__ . '/../../lib/http.php');
// API suites reference User / ApiKey directly (not only through the harness
// fixture factories), so load them up front.
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

// ---- shared mutable state (global script scope) ---------------------------
// The target lives in tests/lib/http.php; these mirror it for suites that print
// or pass the values around. api_test_boot() populates them.
$BASE_URL = null;
$ORIGIN_IP = null;
$TEST_START_UTC = gmdate('Y-m-d H:i:s');

/**
 * Boot an API suite: resolve the target from [base_url] [origin_ip] in $argv
 * (defaulting to the site this code serves), then hand off to the shared harness
 * with the calling suite's @joinery-test metadata so its env gate and result
 * contract apply.
 */
function api_test_boot($argv) {
	global $BASE_URL, $ORIGIN_IP, $TEST_START_UTC;

	harness_http_boot($argv);
	$BASE_URL = harness_http_base_url();
	$ORIGIN_IP = harness_http_origin_ip();
	$TEST_START_UTC = gmdate('Y-m-d H:i:s');

	$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
	$meta = ($caller[0]['file'] ?? '') ? (harness_parse_metadata($caller[0]['file']) ?: array()) : array();
	harness_boot($meta);

	// The API's failed-auth limiter counts credential-less requests per IP in a
	// shared window (api_auth_rate_limit_requests per api_auth_rate_limit_window).
	// Suites probe unauthenticated paths deliberately, so an earlier suite — or an
	// earlier run — would exhaust the budget and turn every later check into a 429.
	// Each suite starts with a clean counter.
	$db = DbConnector::get_instance()->get_db_link();
	$db->prepare("DELETE FROM rql_request_logs WHERE rql_feature = 'api_auth'")->execute();
}

/**
 * HTTP helper. Returns the shared client's response array, of which the API
 * suites use ['status' => int, 'json' => array|null, 'raw' => string].
 *
 * $body as array → JSON body by default. Pass $form = true to send it as
 * application/x-www-form-urlencoded instead — required for the CRUD POST create
 * path, which reads $_POST (PHP populates $_POST only for form-encoded bodies).
 * $headers as ['Name: value', ...].
 */
function api_request($method, $path, $headers = array(), $body = null, $form = false) {
	return harness_request($method, $path, array(
		'headers' => $headers,
		'body'    => $body,
		'encode'  => $form ? 'form' : 'json',
	));
}

function key_headers($public_key, $secret_key) {
	// Hyphen form survives Apache→FPM; the API normalizes both spellings
	return array('public-key: ' . $public_key, 'secret-key: ' . $secret_key);
}

/**
 * Prod-safety gate for header-LESS tooling scripts (the iOS setup helpers:
 * setting_ctl.php, menu_probe.php, phase3_fixtures.php,
 * phase3_conversation_fixtures.php). Those are excluded from test discovery and
 * never call harness_boot(), so they do not get the header-driven env gate;
 * this is their equivalent. Tests themselves get the gate from harness_boot()
 * via their @joinery-test env declaration and must NOT call this.
 *
 * The `debug` setting is the platform's dev-vs-prod discriminator (1 on dev, 0
 * on prod). Refuse to run when it is off so these mutating helpers never touch
 * production.
 */
function harness_require_debug_mode() {
	if (!Globalvars::get_instance()->get_setting('debug')) {
		echo "SKIP: this tooling runs only where the 'debug' setting is on (dev/test). "
			. "It is off here — refusing to run so production is never touched.\n";
		exit(1);
	}
}

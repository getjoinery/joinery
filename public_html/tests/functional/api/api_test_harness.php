<?php
/**
 * REST API functional-suite harness — a thin layer over the shared harness
 * (tests/lib/harness.php) adding only the HTTP-specific pieces the API suites
 * need: the cURL request helper, key headers, and origin-IP pinning.
 *
 * Everything else — the assertion surface (check/section), fixture factories
 * (make_user/make_machine_key), LIFO teardown, settings raw accessors, env
 * enforcement, and the result contract — comes from the shared harness.
 *
 * Each suite requires this file, declares its @joinery-test header (tier: db,
 * env: dev-only), calls api_test_boot($argv), creates fixtures, runs its
 * sections, and in a finally calls harness_teardown_data() then harness_finish().
 *
 * CLI only. Requests are pinned to the origin IP so they bypass Cloudflare
 * (stable REMOTE_ADDR), and custom headers are sent in hyphen form because
 * Apache→FPM drops header names containing underscores (the API accepts both).
 */

if (php_sapi_name() !== 'cli') {
	echo "This harness must be run from the command line.\n";
	exit(1);
}

require_once(__DIR__ . '/../../lib/harness.php');
// API suites reference User / ApiKey directly (not only through the harness
// fixture factories), so load them up front as the original harness did.
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

// ---- shared mutable state (global script scope) ---------------------------
$BASE_URL = 'https://dev.getjoinery.com';
$ORIGIN_IP = '69.164.209.253';
$TEST_START_UTC = gmdate('Y-m-d H:i:s');

/**
 * Boot an API suite. Reads [base_url] and [origin_ip] from $argv (positional 1
 * and 2; defaults target dev pinned to its origin), then hands off to the
 * shared harness with the calling suite's @joinery-test metadata so its env
 * gate (dev-only) and result contract apply.
 */
function api_test_boot($argv) {
	global $BASE_URL, $ORIGIN_IP, $TEST_START_UTC;
	// Positional args are [base_url] [origin_ip]; skip flags like --json so the
	// shared harness's output flags never get mistaken for a base URL.
	$positional = array_values(array_filter(array_slice($argv, 1), function ($a) {
		return strpos($a, '--') !== 0;
	}));
	if (isset($positional[0])) $BASE_URL = rtrim($positional[0], '/');
	if (isset($positional[1])) $ORIGIN_IP = $positional[1];
	$TEST_START_UTC = gmdate('Y-m-d H:i:s');

	$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
	$meta = ($caller[0]['file'] ?? '') ? (harness_parse_metadata($caller[0]['file']) ?: array()) : array();
	harness_boot($meta);
}

/**
 * HTTP helper. Returns ['status' => int, 'json' => array|null, 'raw' => string].
 * $body as array → JSON body by default. Pass $form = true to send it as
 * application/x-www-form-urlencoded instead — required for the CRUD POST create
 * path, which reads $_POST (PHP populates $_POST only for form-encoded bodies).
 * $headers as ['Name: value', ...].
 */
function api_request($method, $path, $headers = array(), $body = null, $form = false) {
	global $BASE_URL, $ORIGIN_IP;
	$ch = curl_init($BASE_URL . $path);
	$headers[] = 'Accept: application/json';
	if ($body !== null) {
		if ($form) {
			$payload = http_build_query($body);
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		} else {
			$payload = json_encode($body);
			$headers[] = 'Content-Type: application/json';
		}
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
	}
	$host = parse_url($BASE_URL, PHP_URL_HOST);
	curl_setopt_array($ch, array(
		CURLOPT_CUSTOMREQUEST => strtoupper($method),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_TIMEOUT => 30,
		// Pin DNS to the origin so requests bypass Cloudflare
		CURLOPT_RESOLVE => array($host . ':443:' . $ORIGIN_IP, $host . ':80:' . $ORIGIN_IP),
	));
	$raw = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	return array('status' => $status, 'json' => json_decode((string)$raw, true), 'raw' => (string)$raw);
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

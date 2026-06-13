<?php
/**
 * Shared harness for the REST API functional suites
 * (session_keys_test.php, crud_authorization_test.php).
 *
 * Provides the bootstrap, the assertion + HTTP helpers, fixture factories
 * (test users / API keys), and a self-cleaning teardown that removes every
 * row, key, and user a suite registers. Each suite requires this file, calls
 * harness_boot($argv), creates fixtures (registering them for cleanup), runs
 * its own sections, then in a finally calls harness_teardown_data() and after
 * the try/finally calls harness_finish().
 *
 * CLI only. Requests are pinned to the origin IP so they bypass Cloudflare
 * (stable REMOTE_ADDR), and custom headers are sent in hyphen form because
 * Apache→FPM drops header names containing underscores (the API accepts both).
 */

if (php_sapi_name() !== 'cli') {
	echo "This harness must be run from the command line.\n";
	exit(1);
}

require_once('/var/www/html/joinerytest/public_html/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

// ---- shared mutable state (global script scope) ---------------------------
$BASE_URL = 'https://dev.getjoinery.com';
$ORIGIN_IP = '69.164.209.253';
$TEST_START_UTC = gmdate('Y-m-d H:i:s');

$passed = 0;
$failed = 0;

$harness_cleanup_users = array();   // User objects
$harness_cleanup_key_ids = array(); // apk_api_key_id values
$harness_cleanup_rows = array();    // [table, pkey_column, id] tuples, deleted LIFO

/**
 * Read [base_url] and [origin_ip] from $argv (positional 1 and 2). Defaults
 * target dev.getjoinery.com pinned to its origin. Sets the run-start timestamp.
 */
function harness_boot($argv) {
	global $BASE_URL, $ORIGIN_IP, $TEST_START_UTC;
	if (isset($argv[1])) $BASE_URL = rtrim($argv[1], '/');
	if (isset($argv[2])) $ORIGIN_IP = $argv[2];
	$TEST_START_UTC = gmdate('Y-m-d H:i:s');
	harness_require_debug_mode();
}

/**
 * Prod-safety gate. These suites create and delete users/keys/rows, so they must
 * never run against a production deployment. The `debug` setting is the system's
 * master "this is a dev/debug environment" switch — it is 1 on dev and must be 0
 * on prod (StripeHelper keys live-vs-test payments off it), so it is a reliable
 * dev-vs-prod discriminator. Refuse to run unless it is on.
 *
 * NOTE: this guards against running on prod. It does NOT isolate writes to the
 * test database — the suites still mutate whatever DB the target serves (dev's
 * live DB). True test-DB isolation would require in-process tests under
 * DbConnector::set_test_mode(); see specs discussion / git history.
 */
function harness_require_debug_mode() {
	if (!Globalvars::get_instance()->get_setting('debug')) {
		echo "SKIP: API test suites run only where the 'debug' setting is on (dev/test). "
			. "It is off here — refusing to run so live/production data is never touched.\n";
		exit(0);
	}
}

function check($condition, $label, $detail = '') {
	global $passed, $failed;
	if ($condition) {
		$passed++;
		echo "  PASS: $label\n";
	} else {
		$failed++;
		echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
	}
	return (bool)$condition;
}

function section($title) {
	echo "\n== $title ==\n";
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
 * Create a test user at the given permission level and register it for cleanup.
 * Email is unique per run via $suffix, so suites never collide.
 */
function make_user($suffix, $permission = 0) {
	global $harness_cleanup_users;
	$user = new User(NULL);
	$user->set('usr_first_name', 'ApiTest');
	$user->set('usr_last_name', 'User' . $suffix);
	$user->set('usr_email', 'apitest_' . strtolower($suffix) . '@getjoinery.com');
	$user->set('usr_password', User::GeneratePassword('TestPassword_' . $suffix));
	$user->set('usr_permission', $permission);
	$user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
	$user->save();
	$user->load();
	$harness_cleanup_users[] = $user;
	return $user;
}

/**
 * Create a machine API key for $user_id with the given capability ($permission:
 * 1=read-only, 2=write-only, 3=read+write, 4=+delete) and register it for
 * cleanup. Returns ['api_key' => ApiKey, 'secret_key' => plaintext].
 */
function make_machine_key($user_id, $name, $permission = 4) {
	global $harness_cleanup_key_ids;
	$secret_plaintext = 'secret_' . LibraryFunctions::random_string(16);
	$key = new ApiKey(NULL);
	$key->set('apk_usr_user_id', $user_id);
	$key->set('apk_name', $name);
	$key->set('apk_public_key', 'public_' . LibraryFunctions::random_string(16));
	$key->set('apk_secret_key', ApiKey::GenerateKey($secret_plaintext));
	$key->set('apk_type', ApiKey::TYPE_MACHINE);
	$key->set('apk_permission', $permission);
	$key->set('apk_is_active', TRUE);
	$key->save();
	$key->load();
	$harness_cleanup_key_ids[] = $key->key;
	return array('api_key' => $key, 'secret_key' => $secret_plaintext);
}

// Register an arbitrary created row for LIFO teardown (children before parents).
function harness_register_row($table, $pkey_column, $id) {
	global $harness_cleanup_rows;
	$harness_cleanup_rows[] = array($table, $pkey_column, $id);
}

function harness_register_key_id($id) {
	global $harness_cleanup_key_ids;
	$harness_cleanup_key_ids[] = $id;
}

// Register a User object the suite created outside make_user() (e.g. one created
// through the API) so teardown removes it too.
function harness_register_user($user) {
	global $harness_cleanup_users;
	$harness_cleanup_users[] = $user;
}

function get_setting_raw($name) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = ?");
	$q->execute([$name]);
	$row = $q->fetch(PDO::FETCH_ASSOC);
	return $row ? $row['stg_value'] : null;
}

function set_setting_raw($name, $value) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?");
	$q->execute([$value, $name]);
}

/**
 * Remove everything a suite registered: arbitrary rows (LIFO), then API keys,
 * then users (permanent_delete with a soft-delete fallback so a test account can
 * never be logged into). Safe to call from a finally even after an exception.
 */
function harness_teardown_data() {
	global $harness_cleanup_users, $harness_cleanup_key_ids, $harness_cleanup_rows;
	$db = DbConnector::get_instance()->get_db_link();

	foreach (array_reverse($harness_cleanup_rows) as $row) {
		list($table, $col, $id) = $row;
		try {
			$q = $db->prepare("DELETE FROM $table WHERE $col = ?");
			$q->execute([$id]);
		} catch (Exception $e) {
			echo "  WARNING: could not delete $table row $id: " . $e->getMessage() . "\n";
		}
	}
	if ($harness_cleanup_rows) echo "  Removed " . count($harness_cleanup_rows) . " test rows\n";

	foreach ($harness_cleanup_key_ids as $key_id) {
		try {
			$q = $db->prepare("DELETE FROM apk_api_keys WHERE apk_api_key_id = ?");
			$q->execute([$key_id]);
		} catch (Exception $e) {
			echo "  WARNING: could not delete api key $key_id: " . $e->getMessage() . "\n";
		}
	}
	echo "  Removed " . count($harness_cleanup_key_ids) . " test API keys\n";

	foreach ($harness_cleanup_users as $user) {
		try {
			$user->permanent_delete();
		} catch (Exception $e) {
			// permanent_delete can fail on FK sweep issues; roll back its aborted
			// transaction, then soft-delete so the account can never be logged into.
			echo "  WARNING: could not permanently delete user " . $user->key . " (" . $e->getMessage() . "); soft-deleting\n";
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			try {
				$q = $db->prepare("UPDATE usr_users SET usr_delete_time = now() WHERE usr_user_id = ?");
				$q->execute([$user->key]);
			} catch (Exception $e2) {
				echo "  WARNING: soft delete also failed for user " . $user->key . ": " . $e2->getMessage() . "\n";
			}
		}
	}
	echo "  Removed " . count($harness_cleanup_users) . " test users\n";
}

/** Print the pass/fail summary and exit with a CI-friendly status code. */
function harness_finish() {
	global $passed, $failed;
	echo "\n================================\n";
	echo "PASSED: $passed   FAILED: $failed\n";
	exit($failed > 0 ? 1 : 0);
}

<?php
/** @joinery-test
 * name: signed_urls
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Signed URL functional test (docs/file_signed_urls.md).
 *
 * Creates a private file, mints signed URLs, and fetches them over HTTP with
 * no session: valid signature serves, expired/tampered/size-swapped fall back
 * to the ownership gate (404 for a stranger), and signed responses carry
 * Cache-Control: private, no-store. Owner access is asserted at the model
 * layer (is_viewable). Self-cleaning: the fixture file is permanently
 * deleted in the finally block.
 *
 * Usage: php signed_urls_test.php [base_url] [origin_ip]
 * Defaults target dev.getjoinery.com pinned to its origin IP (bypasses
 * Cloudflare so headers/status are the app's own).
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') {
	echo "This test must be run from the command line.\n";
	exit(1);
}

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

$positional = array_values(array_filter(array_slice($argv, 1), function ($a) { return strpos($a, '--') !== 0; }));
$BASE_URL  = isset($positional[0]) ? rtrim($positional[0], '/') : 'https://dev.getjoinery.com';
$ORIGIN_IP = isset($positional[1]) ? $positional[1] : '69.164.209.253';

/** GET a path with no cookies/session, pinned to the origin IP. */
function http_get($path) {
	global $BASE_URL, $ORIGIN_IP;
	$host = parse_url($BASE_URL, PHP_URL_HOST);
	$ch = curl_init($BASE_URL . $path);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER         => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_RESOLVE        => array($host . ':443:' . $ORIGIN_IP),
	));
	$raw = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	if ($raw === false) {
		return array('status' => 0, 'headers' => '', 'body' => '');
	}
	return array(
		'status'  => $status,
		'headers' => substr($raw, 0, $header_size),
		'body'    => substr($raw, $header_size),
	);
}

$file = null;
try {
	// ---- Fixture: a private file owned by user 1 -------------------------
	$bytes = 'signed-url-test-' . bin2hex(random_bytes(8));
	$name = 'signed_url_test_' . bin2hex(random_bytes(6)) . '.txt';
	$file = File::createFromBytes($bytes, $name, 'text/plain', 1, array('fil_private' => true));
	ok('fixture: private file created', $file && $file->key);

	// Sanity: unsigned anonymous request is denied (404, existing behavior).
	$plain_url = $file->get_url();
	$r = http_get($plain_url);
	ok('unsigned request without session is denied', $r['status'] == 404, 'status ' . $r['status']);

	// ---- Valid signature serves with no session --------------------------
	$signed = $file->mintSignedUrl('original', 120);
	$r = http_get($signed);
	ok('signed URL serves without session', $r['status'] == 200, 'status ' . $r['status']);
	ok('signed response body matches file bytes', $r['body'] === $bytes);
	ok('signed response is private, no-store',
		stripos($r['headers'], 'Cache-Control: private, no-store') !== false,
		trim($r['headers']) === '' ? 'no headers' : 'headers lack private, no-store');

	// ---- Tampered signature falls back to the gate (stranger: 404) -------
	$tampered = preg_replace_callback('/(sig=)([0-9a-f])/', function ($m) {
		return $m[1] . ($m[2] === '0' ? '1' : '0');
	}, $signed, 1);
	$r = http_get($tampered);
	ok('tampered signature is denied', $r['status'] == 404, 'status ' . $r['status']);

	// ---- Extended expiry breaks the signature -----------------------------
	parse_str(parse_url($signed, PHP_URL_QUERY), $q);
	$extended = parse_url($signed, PHP_URL_PATH) . '?expires=' . ($q['expires'] + 86400) . '&sig=' . $q['sig'];
	$r = http_get($extended);
	ok('extended expiry is denied', $r['status'] == 404, 'status ' . $r['status']);

	// ---- Expired signature falls back to the gate -------------------------
	$short = $file->mintSignedUrl('original', 1);
	sleep(2);
	$r = http_get($short);
	ok('expired signature is denied', $r['status'] == 404, 'status ' . $r['status']);

	// ---- Size key is part of the signed material ---------------------------
	// Re-point an 'original' signature at a size path: signature no longer
	// matches, so the gate applies (404 for a stranger).
	$swapped = '/uploads/thumb/' . $name . '?expires=' . $q['expires'] . '&sig=' . $q['sig'];
	$r = http_get($swapped);
	ok('size-swapped signature is denied', $r['status'] == 404, 'status ' . $r['status']);

	// ---- Owner still passes the normal gate either way ---------------------
	$owner_session = new class {
		function get_user_id() { return 1; }
		function get_permission() { return 10; }
		function is_logged_in() { return true; }
	};
	ok('owner passes is_viewable regardless of signatures', $file->is_viewable($owner_session) == true);

	// ---- Cloud-private never-302 note --------------------------------------
	if ($file->get('fil_storage_driver') === 'cloud') {
		$r = http_get($file->mintSignedUrl('original', 120));
		ok('cloud-private signed response streams (no redirect)', $r['status'] == 200);
	} else {
		echo "NOTE - fixture stored locally; cloud-private stream path exercised by code path parity (same gate variable)\n";
	}
} finally {
	if ($file && $file->key) {
		$file->permanent_delete();
		echo "cleanup: fixture file removed\n";
	}
}

harness_finish();

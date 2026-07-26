<?php
/** @joinery-test
 * name: s3signer_retry
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * S3Signer retry — a transient storage-provider failure must not strand a backup.
 *
 * The failure this pins: a node finished its database dump, then Backblaze B2
 * answered the upload with `500 internal incident` (their generic transient
 * error). The uploader had no retry, so the job failed with the archive sitting
 * local-only on the node and no way to push it up short of running the whole
 * backup again.
 *
 * Two things have to hold.
 *
 * The retry policy has to be right: 5xx / 429 / dead-connection errors are worth
 * another go, and a 403 or 404 is not — retrying a deterministic error only burns
 * the time budget and buries the real message.
 *
 * And the replay has to actually resend the file. curl consumes the upload stream
 * on the first attempt while CURLOPT_INFILESIZE still claims the full length, so a
 * retry that forgets to rewind sends nothing and then blocks waiting for data that
 * never comes. That failure is silent — a hang, not an error — which is why it is
 * tested against a real HTTP server that fails once and then succeeds, asserting
 * the second attempt carried the whole body.
 *
 * Run: php plugins/server_manager/tests/s3signer_retry_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/S3Signer.php'));

// ─────────────────────────────────────────────────────────────────────────────
section('Retry policy: what counts as transient');

check(S3Signer::is_retryable(500), 'HTTP 500 is retried',
	'B2 answers a blip with 500 internal incident — the exact case that stranded the backup');
check(S3Signer::is_retryable(502), 'HTTP 502 is retried');
check(S3Signer::is_retryable(503), 'HTTP 503 is retried');
check(S3Signer::is_retryable(504), 'HTTP 504 is retried');
check(S3Signer::is_retryable(429), 'HTTP 429 (throttled) is retried');
check(S3Signer::is_retryable(408), 'HTTP 408 (request timeout) is retried');

check(!S3Signer::is_retryable(200), 'HTTP 200 is not retried');
check(!S3Signer::is_retryable(204), 'HTTP 204 is not retried');
check(!S3Signer::is_retryable(400), 'HTTP 400 is not retried', 'malformed request will stay malformed');
check(!S3Signer::is_retryable(403), 'HTTP 403 is not retried', 'a bad signature or revoked key is deterministic');
check(!S3Signer::is_retryable(404), 'HTTP 404 is not retried');

check(S3Signer::is_retryable(0, CURLE_OPERATION_TIMEDOUT), 'curl timeout is retried');
check(S3Signer::is_retryable(0, CURLE_COULDNT_CONNECT), 'curl connect failure is retried');
check(S3Signer::is_retryable(0, CURLE_RECV_ERROR), 'curl recv error is retried');
check(S3Signer::is_retryable(0, CURLE_PARTIAL_FILE), 'curl partial transfer is retried');
check(!S3Signer::is_retryable(0, CURLE_UNSUPPORTED_PROTOCOL), 'a protocol error is not retried');

// ─────────────────────────────────────────────────────────────────────────────
section('Retry budget is bounded and matches the job step timeout');

check(S3Signer::MAX_ATTEMPTS >= 2 && S3Signer::MAX_ATTEMPTS <= 5,
	'MAX_ATTEMPTS is a small bounded number', 'got ' . S3Signer::MAX_ATTEMPTS);
check(S3Signer::transfer_budget_seconds() > S3Signer::TRANSFER_TIMEOUT_SECONDS,
	'the transfer budget leaves room beyond one full-length attempt',
	S3Signer::transfer_budget_seconds() . 's vs ' . S3Signer::TRANSFER_TIMEOUT_SECONDS . 's');

// The agent kills a step at its declared timeout. If that ceiling were below the
// signer's own budget a retry would be cut off part-way, so the upload step has to
// derive its timeout from the budget rather than carry its own number. Checked at
// the source level because building a real step needs a node and a live target.
$builder_src = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
check(strpos($builder_src, 'S3Signer::transfer_budget_seconds()') !== false,
	'the upload step sizes its timeout from the retry budget',
	'a hardcoded number there silently re-introduces the mid-retry kill');

// ─────────────────────────────────────────────────────────────────────────────
section('A retried upload resends the whole body');

$fixture = s3signer_start_fixture(1); // fail the first request, then succeed

if ($fixture === null) {
	harness_skip('retried upload resends the whole body',
		'could not start a local PHP HTTP server on 127.0.0.1 — no network fixture available');
} else {
	harness_defer(function() use ($fixture) { s3signer_stop_fixture($fixture); });

	// Small body on purpose: over ~1KB curl adds `Expect: 100-continue`, which the
	// built-in server does not answer, adding a second of dead wait per attempt.
	$payload = str_repeat('J', 512);
	$local = tempnam(sys_get_temp_dir(), 's3retry');
	file_put_contents($local, $payload);
	harness_defer(function() use ($local) { @unlink($local); });

	$creds = [
		'access_key' => 'TESTKEY',
		'secret_key' => 'TESTSECRET',
		'region'     => 'us-east-005',
		'endpoint'   => 'http://127.0.0.1:' . $fixture['port'],
	];

	$started = microtime(true);
	$resp = S3Signer::put_file($creds, 'test-bucket', '/joinery-backups/slug/archive.sql.gz.enc', $local);
	$elapsed = microtime(true) - $started;

	check((int)$resp['status'] === 200, 'the upload succeeds despite the first 500',
		'status ' . $resp['status']);
	check((int)$resp['attempts'] === 2, 'it took exactly two attempts',
		'attempts=' . ($resp['attempts'] ?? 'missing'));
	check(!empty($resp['retry_log']), 'the retry is reported, not swallowed',
		implode(' | ', $resp['retry_log'] ?? []));

	$lengths = s3signer_fixture_lengths($fixture);
	check(count($lengths) === 2, 'the fixture saw two requests', 'saw ' . count($lengths));
	check(isset($lengths[0]) && $lengths[0] === strlen($payload),
		'attempt 1 carried the full body', 'got ' . ($lengths[0] ?? 'nothing'));
	// The regression guard: without rewind() this is 0 (or the request hangs).
	check(isset($lengths[1]) && $lengths[1] === strlen($payload),
		'attempt 2 re-sent the full body from byte zero',
		'got ' . ($lengths[1] ?? 'nothing') . ' — a non-rewound stream sends 0 bytes here');

	check($elapsed >= S3Signer::RETRY_BASE_DELAY_SECONDS,
		'the retry backed off before trying again', round($elapsed, 1) . 's elapsed');
}

// ─────────────────────────────────────────────────────────────────────────────
section('A deterministic error is returned immediately, not retried');

// Answers 403 to the first request — which must also be the only request.
$fixture2 = s3signer_start_fixture(1, 403);
if ($fixture2 === null) {
	harness_skip('deterministic error is not retried', 'no network fixture available');
} else {
	harness_defer(function() use ($fixture2) { s3signer_stop_fixture($fixture2); });

	$local2 = tempnam(sys_get_temp_dir(), 's3retry');
	file_put_contents($local2, 'x');
	harness_defer(function() use ($local2) { @unlink($local2); });

	$creds2 = [
		'access_key' => 'TESTKEY', 'secret_key' => 'TESTSECRET',
		'region' => 'us-east-005', 'endpoint' => 'http://127.0.0.1:' . $fixture2['port'],
	];
	$resp2 = S3Signer::put_file($creds2, 'test-bucket', '/k', $local2);

	check((int)$resp2['status'] === 403, 'the 403 is handed straight back', 'status ' . $resp2['status']);
	check((int)$resp2['attempts'] === 1, 'it was tried exactly once',
		'attempts=' . ($resp2['attempts'] ?? 'missing'));
	check(count(s3signer_fixture_lengths($fixture2)) === 1, 'the fixture saw one request');
}

harness_finish();


// ─────────────────────────────────────────────────────────────────────────────
// Fixture: a local HTTP server that fails the first $fail_count requests with
// $fail_status and then answers 200, recording each request's body length.
// ─────────────────────────────────────────────────────────────────────────────

function s3signer_start_fixture($fail_count, $fail_status = 500) {
	$port = s3signer_free_port();
	if ($port === null) return null;

	$dir = sys_get_temp_dir() . '/s3signer_fixture_' . getmypid() . '_' . $port;
	@mkdir($dir, 0777, true);
	if (!is_dir($dir)) return null;

	$router = $dir . '/router.php';
	file_put_contents($router, '<?php
$dir = __DIR__;
$n = (int)@file_get_contents($dir . "/count") + 1;
file_put_contents($dir . "/count", (string)$n);
$body = file_get_contents("php://input");
file_put_contents($dir . "/len." . $n, (string)strlen($body));
if ($n <= (int)getenv("FIXTURE_FAIL_COUNT")) {
	http_response_code((int)getenv("FIXTURE_FAIL_STATUS"));
	header("Content-Type: application/xml");
	echo "<?xml version=\"1.0\"?><Error><Code>InternalError</Code><Message>internal incident</Message></Error>";
	return true;
}
http_response_code(200);
return true;
');

	$cmd = sprintf(
		'FIXTURE_FAIL_COUNT=%d FIXTURE_FAIL_STATUS=%d exec php -S 127.0.0.1:%d %s',
		(int)$fail_count, (int)$fail_status, $port, escapeshellarg($router)
	);
	$descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
	$proc = proc_open(['bash', '-c', $cmd], $descriptors, $pipes);
	if (!is_resource($proc)) return null;

	// Wait for the listener to come up rather than assuming a fixed delay.
	for ($i = 0; $i < 100; $i++) {
		$sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
		if ($sock) { fclose($sock); return ['proc' => $proc, 'port' => $port, 'dir' => $dir]; }
		usleep(50000);
	}
	proc_terminate($proc, SIGKILL);
	proc_close($proc);
	return null;
}

/** Body lengths the fixture recorded, in request order. */
function s3signer_fixture_lengths($fixture) {
	$out = [];
	for ($n = 1; $n <= 20; $n++) {
		$f = $fixture['dir'] . '/len.' . $n;
		if (!is_file($f)) break;
		$out[] = (int)file_get_contents($f);
	}
	return $out;
}

function s3signer_stop_fixture($fixture) {
	if (is_resource($fixture['proc'])) {
		proc_terminate($fixture['proc'], SIGKILL);
		proc_close($fixture['proc']);
	}
	foreach (glob($fixture['dir'] . '/*') as $f) @unlink($f);
	@rmdir($fixture['dir']);
}

/** Ask the OS for a free port, then release it for the server to claim. */
function s3signer_free_port() {
	$sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
	if (!$sock) return null;
	$name = stream_socket_get_name($sock, false);
	fclose($sock);
	$parts = explode(':', $name);
	$port = (int)end($parts);
	return $port > 0 ? $port : null;
}

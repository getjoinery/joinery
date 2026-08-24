<?php
/** @joinery-test
 * name: s3signer_multipart
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * S3Signer multipart — an artifact over the 5 GB single-PUT ceiling must still
 * reach the bucket, and a failed multipart upload must leave nothing claimable.
 *
 * The failure this pins: jeremytunnell.com's nightly incremental hit 5.9 GB
 * after a mail import and Backblaze refused the PUT with `File size too big` —
 * and every later run would have refused the same way, because a fresh full was
 * over the ceiling too. put_file() now switches to the S3 multipart API above
 * MULTIPART_THRESHOLD_BYTES.
 *
 * Three families of check:
 *
 * The arithmetic (pure): part planning covers every byte exactly once, the last
 * part takes the remainder, and the 10,000-part API cap refuses loudly.
 *
 * The trap (pure): CompleteMultipartUpload can answer HTTP 200 with an <Error>
 * document in the body. complete_body_ok() is what stands between that response
 * and a backup recorded as offsite that does not exist.
 *
 * The flow (local fixture): against a real HTTP server, the create/parts/
 * complete sequence reassembles the exact source bytes; a failed part returns
 * the provider's error and aborts the upload; a persistent 200-with-Error
 * complete comes back as a 5xx, never as success, and also aborts.
 *
 * Run: php tests/backups/s3signer_multipart_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/S3Signer.php'));

// ─────────────────────────────────────────────────────────────────────────────
section('Part planning covers every byte exactly once');

$plan = S3Signer::plan_parts(250, 100);
check(count($plan) === 3, 'a 250-byte object in 100-byte parts is 3 parts', 'got ' . count($plan));
check($plan[0] === ['number' => 1, 'offset' => 0, 'bytes' => 100], 'part 1 starts at byte 0');
check($plan[2] === ['number' => 3, 'offset' => 200, 'bytes' => 50], 'the last part takes the remainder');

$sum = 0;
foreach ($plan as $p) { $sum += $p['bytes']; }
check($sum === 250, 'the parts sum to the object size', 'summed ' . $sum);

$exact = S3Signer::plan_parts(200, 100);
check(count($exact) === 2 && $exact[1]['bytes'] === 100,
	'an exact multiple produces no empty trailing part', count($exact) . ' parts, last ' . $exact[count($exact) - 1]['bytes'] . ' bytes');

$one = S3Signer::plan_parts(1, 100);
check(count($one) === 1 && $one[0]['bytes'] === 1, 'a 1-byte object is one 1-byte part');

$threw = false;
try { S3Signer::plan_parts(0); } catch (S3SignerException $e) { $threw = true; }
check($threw, 'a zero-byte object refuses to plan');

$threw = false;
try { S3Signer::plan_parts(S3Signer::MULTIPART_MAX_PARTS * 100 + 1, 100); } catch (S3SignerException $e) { $threw = true; }
check($threw, 'over the 10,000-part API cap refuses loudly',
	'a silent truncation here would upload a corrupt object');

check(S3Signer::MULTIPART_THRESHOLD_BYTES > S3Signer::MULTIPART_PART_BYTES,
	'the threshold is above one part, so multipart always has at least two parts of work');
check(S3Signer::MULTIPART_THRESHOLD_BYTES < 5000000000,
	'the threshold sits under every provider\'s 5 GB single-PUT cap',
	'a threshold above the cap would leave a band of sizes no path can upload');

// ─────────────────────────────────────────────────────────────────────────────
section('Complete XML carries every part in order');

$xml = S3Signer::build_complete_xml([2 => '"bbb"', 1 => '"aaa"']);
check(strpos($xml, '<PartNumber>1</PartNumber><ETag>&quot;aaa&quot;</ETag>')
	< strpos($xml, '<PartNumber>2</PartNumber><ETag>&quot;bbb&quot;</ETag>'),
	'parts are emitted in part-number order regardless of insertion order');
check(substr($xml, 0, 25) === '<CompleteMultipartUpload>'
	&& substr($xml, -26) === '</CompleteMultipartUpload>', 'the document is well-formed at both ends');

// ─────────────────────────────────────────────────────────────────────────────
section('The 200-with-Error completion trap');

check(S3Signer::complete_body_ok(
	'<?xml version="1.0"?><CompleteMultipartUploadResult><ETag>"x"</ETag></CompleteMultipartUploadResult>'),
	'a genuine completion body reads as complete');
check(!S3Signer::complete_body_ok(
	'<?xml version="1.0"?><Error><Code>InternalError</Code><Message>We encountered an internal error.</Message></Error>'),
	'an <Error> body is not completion, whatever the HTTP status said',
	'this is the response S3 documents sending with a 200 status line');
check(!S3Signer::complete_body_ok(''), 'an empty body is not completion');
check(!S3Signer::complete_body_ok(null), 'a missing body is not completion');

// ─────────────────────────────────────────────────────────────────────────────
section('The full flow against a local provider');

$fixture = s3mp_start_fixture();
if ($fixture === null) {
	harness_skip('multipart flow', 'could not start a local PHP HTTP server on 127.0.0.1');
} else {
	harness_defer(function() use ($fixture) { s3mp_stop_fixture($fixture); });

	$creds = [
		'access_key' => 'TESTKEY', 'secret_key' => 'TESTSECRET',
		'region' => 'us-east-005', 'endpoint' => 'http://127.0.0.1:' . $fixture['port'],
	];

	// Parts stay under 1KB: over that curl adds `Expect: 100-continue`, which
	// the built-in server never answers, costing a second of dead wait each.
	$payload = random_bytes(2100);
	$local = tempnam(sys_get_temp_dir(), 's3mp');
	file_put_contents($local, $payload);
	harness_defer(function() use ($local) { @unlink($local); });

	$resp = S3Signer::put_file_multipart($creds, 'test-bucket', '/joinery-backups/slug/files-0000.tar.gz.enc',
		$local, 'application/octet-stream', 800);

	check((int)$resp['status'] === 200, 'the multipart upload succeeds', 'status ' . $resp['status']);
	$assembled = s3mp_fixture_assembled($fixture);
	check($assembled === $payload, 'the parts reassemble to the exact source bytes',
		'got ' . strlen((string)$assembled) . ' of ' . strlen($payload) . ' bytes'
		. ($assembled !== null && strlen($assembled) === strlen($payload) ? ', content differs' : ''));
	check(s3mp_fixture_count($fixture, 'abort') === 0, 'nothing was aborted on the happy path');
	check(s3mp_fixture_count($fixture, 'complete') === 1, 'complete was called exactly once');

	// A part refused with a deterministic error: the provider's response comes
	// back to the caller and the upload is aborted, leaving nothing claimable.
	$fixture2 = s3mp_start_fixture(['FIXTURE_FAIL_PART' => 2]);
	if ($fixture2 === null) {
		harness_skip('part failure aborts', 'no fixture');
	} else {
		harness_defer(function() use ($fixture2) { s3mp_stop_fixture($fixture2); });
		$creds2 = array_merge($creds, ['endpoint' => 'http://127.0.0.1:' . $fixture2['port']]);
		$resp2 = S3Signer::put_file_multipart($creds2, 'test-bucket', '/k', $local, 'application/octet-stream', 800);

		check((int)$resp2['status'] === 400, 'the failed part\'s status is handed back', 'status ' . $resp2['status']);
		check(S3Signer::extract_error($resp2['body']) === 'part refused',
			'the provider\'s own message survives for the failure report',
			'got: ' . var_export(S3Signer::extract_error($resp2['body']), true));
		check(s3mp_fixture_count($fixture2, 'abort') === 1, 'the upload was aborted',
			'an unaborted upload accrues invisible storage charges until a lifecycle rule notices');
		check(s3mp_fixture_count($fixture2, 'complete') === 0, 'complete was never attempted');
	}

	// The trap, end to end: every complete answers 200 with an <Error> body.
	// The caller must see a failure — a forced 5xx — and the upload must abort.
	$fixture3 = s3mp_start_fixture(['FIXTURE_COMPLETE_ERRORS' => 99]);
	if ($fixture3 === null) {
		harness_skip('200-with-Error complete fails the upload', 'no fixture');
	} else {
		harness_defer(function() use ($fixture3) { s3mp_stop_fixture($fixture3); });
		$creds3 = array_merge($creds, ['endpoint' => 'http://127.0.0.1:' . $fixture3['port']]);
		$resp3 = S3Signer::put_file_multipart($creds3, 'test-bucket', '/k', $local, 'application/octet-stream', 800);

		check((int)$resp3['status'] >= 500, 'a persistent 200-with-Error comes back as a server failure',
			'status ' . $resp3['status'] . ' — a 200 here records a backup that does not exist');
		check(s3mp_fixture_count($fixture3, 'complete') === S3Signer::MAX_ATTEMPTS,
			'the retryable trap was retried on the ordinary budget first',
			'saw ' . s3mp_fixture_count($fixture3, 'complete'));
		check(s3mp_fixture_count($fixture3, 'abort') === 1, 'the upload was aborted');
	}
}

harness_finish();


// ─────────────────────────────────────────────────────────────────────────────
// Fixture: a local HTTP server speaking just enough of the S3 multipart API —
// create returns an UploadId, each part is stored under its part number and
// answered with an ETag, complete reassembles (or answers the 200-with-Error
// trap), abort is recorded. Call counts and part bodies land in files.
// ─────────────────────────────────────────────────────────────────────────────

function s3mp_start_fixture(array $env = []) {
	$port = s3mp_free_port();
	if ($port === null) return null;

	$dir = sys_get_temp_dir() . '/s3mp_fixture_' . getmypid() . '_' . $port;
	@mkdir($dir, 0777, true);
	if (!is_dir($dir)) return null;

	$router = $dir . '/router.php';
	file_put_contents($router, '<?php
$dir = __DIR__;
$q = $_GET;
$method = $_SERVER["REQUEST_METHOD"];
$bump = function($name) use ($dir) {
	$n = (int)@file_get_contents($dir . "/" . $name . ".count") + 1;
	file_put_contents($dir . "/" . $name . ".count", (string)$n);
	return $n;
};
header("Content-Type: application/xml");
if ($method === "POST" && array_key_exists("uploads", $q)) {
	$bump("create");
	echo "<?xml version=\"1.0\"?><InitiateMultipartUploadResult><UploadId>fixture-upload-1</UploadId></InitiateMultipartUploadResult>";
	return true;
}
if ($method === "PUT" && isset($q["partNumber"], $q["uploadId"])) {
	$n = (int)$q["partNumber"];
	$bump("part");
	if ($n === (int)getenv("FIXTURE_FAIL_PART")) {
		http_response_code(400);
		echo "<?xml version=\"1.0\"?><Error><Code>InvalidPart</Code><Message>part refused</Message></Error>";
		return true;
	}
	file_put_contents($dir . "/part." . $n, file_get_contents("php://input"));
	header("ETag: \"etag-part-" . $n . "\"");
	return true;
}
if ($method === "POST" && isset($q["uploadId"])) {
	$n = $bump("complete");
	if ($n <= (int)getenv("FIXTURE_COMPLETE_ERRORS")) {
		echo "<?xml version=\"1.0\"?><Error><Code>InternalError</Code><Message>We encountered an internal error. Please try again.</Message></Error>";
		return true;
	}
	$assembled = "";
	for ($i = 1; is_file($dir . "/part." . $i); $i++) {
		$assembled .= file_get_contents($dir . "/part." . $i);
	}
	file_put_contents($dir . "/assembled", $assembled);
	echo "<?xml version=\"1.0\"?><CompleteMultipartUploadResult><ETag>\"final\"</ETag></CompleteMultipartUploadResult>";
	return true;
}
if ($method === "DELETE" && isset($q["uploadId"])) {
	$bump("abort");
	http_response_code(204);
	return true;
}
http_response_code(500);
echo "<?xml version=\"1.0\"?><Error><Code>Unexpected</Code><Message>fixture saw an unexpected request</Message></Error>";
return true;
');

	$env_str = '';
	foreach ($env as $k => $v) { $env_str .= $k . '=' . (int)$v . ' '; }
	$cmd = sprintf('%sexec php -S 127.0.0.1:%d %s', $env_str, $port, escapeshellarg($router));
	$descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
	$proc = proc_open(['bash', '-c', $cmd], $descriptors, $pipes);
	if (!is_resource($proc)) return null;

	for ($i = 0; $i < 100; $i++) {
		$sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
		if ($sock) { fclose($sock); return ['proc' => $proc, 'port' => $port, 'dir' => $dir]; }
		usleep(50000);
	}
	proc_terminate($proc);
	return null;
}

function s3mp_stop_fixture($fixture) {
	if (is_resource($fixture['proc'])) { proc_terminate($fixture['proc']); proc_close($fixture['proc']); }
	foreach (glob($fixture['dir'] . '/*') ?: [] as $f) { @unlink($f); }
	@rmdir($fixture['dir']);
}

function s3mp_fixture_count($fixture, $name) {
	return (int)@file_get_contents($fixture['dir'] . '/' . $name . '.count');
}

function s3mp_fixture_assembled($fixture) {
	$v = @file_get_contents($fixture['dir'] . '/assembled');
	return ($v === false) ? null : $v;
}

function s3mp_free_port() {
	$sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
	if (!$sock) return null;
	$name = stream_socket_get_name($sock, false);
	fclose($sock);
	$port = (int)substr((string)$name, strrpos((string)$name, ':') + 1);
	return $port > 0 ? $port : null;
}

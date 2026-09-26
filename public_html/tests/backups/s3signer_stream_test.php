<?php
/** @joinery-test
 * name: s3signer_stream
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * S3Signer::put_stream() — an archive that never lands on disk.
 *
 * A backup engine's stdout is a pipe: unknown length, cannot be re-read. The
 * signer has to take it through the multipart path, hashing and counting as it
 * goes, and it has to let the caller decide AFTER the stream closes whether the
 * object should exist at all (tar's exit status arrives after the bytes).
 *
 * Against a local provider:
 *   - a stream shorter than a part goes as one PUT, bytes and hash reported
 *   - a longer one is multipart and reassembles to the exact bytes
 *   - a stream of exactly one part is still one PUT (the one-byte peek)
 *   - a failed part aborts and leaves nothing claimable
 *   - a caller-declined completion aborts (multipart) or sends nothing (small)
 *   - a caller-accepted completion lands the object, in both shapes
 *   - a pipe from a real child process streams through
 *   - an upload's peak memory is bounded — the runner's and the stand-in's —
 *     at the real part size, so a backup fits a 1 GB box beside the site
 *
 * Run: php tests/backups/s3signer_stream_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/s3_fixtures.php');

// Parts stay under 1KB: over that curl adds `Expect: 100-continue`, which the
// built-in server never answers, costing a second of dead wait each.
const ST_PART = 800;

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('put_stream', 'could not start a local PHP HTTP server on 127.0.0.1');
	harness_finish();
}
harness_defer(function() use ($fx) { s3fx_stop($fx); });
$creds = s3fx_creds($fx);

$as_stream = function ($bytes) {
	$fh = fopen('php://temp', 'w+b');
	fwrite($fh, $bytes);
	rewind($fh);
	return $fh;
};

// ─────────────────────────────────────────────────────────────────────────────
section('A stream shorter than a part is one PUT');

$small = random_bytes(300);
$resp = S3Signer::put_stream($creds, 'bkt', '/short.bin', $as_stream($small), 'application/octet-stream', true, ST_PART);
check((int)$resp['status'] === 200, 'the upload succeeds', 'status ' . $resp['status']);
check($resp['bytes'] === 300, 'bytes counts what was read', 'got ' . $resp['bytes']);
check($resp['sha256'] === hash('sha256', $small), 'sha256 is of the streamed bytes');
check(s3fx_object($fx, 'bkt', '/short.bin') === $small, 'the object holds the exact bytes');
check(s3fx_count($fx, 'put') === 1 && s3fx_count($fx, 'create') === 0, 'one plain PUT, no multipart',
	'put=' . s3fx_count($fx, 'put') . ' create=' . s3fx_count($fx, 'create'));

// ─────────────────────────────────────────────────────────────────────────────
section('A stream of exactly one part is still one PUT');

$exact = random_bytes(ST_PART);
$resp = S3Signer::put_stream($creds, 'bkt', '/exact.bin', $as_stream($exact), 'application/octet-stream', true, ST_PART);
check((int)$resp['status'] === 200 && s3fx_object($fx, 'bkt', '/exact.bin') === $exact, 'the exact-part object lands whole');
check(s3fx_count($fx, 'create') === 0, 'no multipart upload was opened for it',
	'the one-byte peek saw end of stream');

// ─────────────────────────────────────────────────────────────────────────────
section('A longer stream is multipart and reassembles exactly');

$big = random_bytes(ST_PART * 2 + 137);
$resp = S3Signer::put_stream($creds, 'bkt', '/chain-1/files-0000.tar.gz.enc', $as_stream($big), 'application/octet-stream', true, ST_PART);
check((int)$resp['status'] === 200, 'the multipart upload succeeds', 'status ' . $resp['status']);
check($resp['bytes'] === strlen($big), 'bytes counts every part', 'got ' . $resp['bytes']);
check($resp['sha256'] === hash('sha256', $big), 'sha256 covers every part');
check(s3fx_object($fx, 'bkt', '/chain-1/files-0000.tar.gz.enc') === $big, 'the parts reassemble to the exact source bytes');
check(s3fx_count($fx, 'create') === 1 && s3fx_count($fx, 'part') === 3 && s3fx_count($fx, 'complete') === 1,
	'create, three parts, one complete', 'create=' . s3fx_count($fx, 'create') . ' part=' . s3fx_count($fx, 'part') . ' complete=' . s3fx_count($fx, 'complete'));
check(s3fx_count($fx, 'abort') === 0, 'nothing was aborted on the happy path');

$even = random_bytes(ST_PART * 2);
$resp = S3Signer::put_stream($creds, 'bkt', '/even.bin', $as_stream($even), 'application/octet-stream', true, ST_PART);
check((int)$resp['status'] === 200 && s3fx_object($fx, 'bkt', '/even.bin') === $even,
	'an exact multiple of the part size produces no empty trailing part');
check(s3fx_count($fx, 'part') === 5, 'two parts for two parts\' worth', 'part total ' . s3fx_count($fx, 'part'));

// ─────────────────────────────────────────────────────────────────────────────
section('Deferred completion: nothing is in backup storage until the caller says so');

$resp = S3Signer::put_stream($creds, 'bkt', '/deferred-big.bin', $as_stream($big), 'application/octet-stream', false, ST_PART);
check(isset($resp['pending']) && (int)$resp['status'] === 0, 'a deferred multipart returns a pending handle and no status');
check($resp['bytes'] === strlen($big) && $resp['sha256'] === hash('sha256', $big), 'bytes and hash are already known');
check(s3fx_object($fx, 'bkt', '/deferred-big.bin') === null, 'the object does not exist yet');
$completes_before = s3fx_count($fx, 'complete');
$final = S3Signer::complete_stream($resp['pending']);
check((int)$final['status'] === 200 && s3fx_object($fx, 'bkt', '/deferred-big.bin') === $big, 'complete_stream() lands it whole');
check($final['bytes'] === strlen($big) && $final['sha256'] === hash('sha256', $big), 'the completed result carries the totals');
check(s3fx_count($fx, 'complete') === $completes_before + 1, 'exactly one complete was issued');

$aborts_before = s3fx_count($fx, 'abort');
$resp = S3Signer::put_stream($creds, 'bkt', '/declined-big.bin', $as_stream($big), 'application/octet-stream', false, ST_PART);
S3Signer::abort_stream($resp['pending']);
check(s3fx_count($fx, 'abort') === $aborts_before + 1, 'a declined multipart is aborted');
check(s3fx_object($fx, 'bkt', '/declined-big.bin') === null, 'and nothing of it is claimable');

$puts_before = s3fx_count($fx, 'put');
$resp = S3Signer::put_stream($creds, 'bkt', '/deferred-small.bin', $as_stream($small), 'application/octet-stream', false, ST_PART);
check(isset($resp['pending']) && s3fx_count($fx, 'put') === $puts_before, 'a deferred small stream sends nothing yet');
check($resp['bytes'] === 300 && $resp['sha256'] === hash('sha256', $small), 'but its bytes and hash are known');
$final = S3Signer::complete_stream($resp['pending']);
check((int)$final['status'] === 200 && s3fx_object($fx, 'bkt', '/deferred-small.bin') === $small, 'completing it is the single PUT');

$resp = S3Signer::put_stream($creds, 'bkt', '/declined-small.bin', $as_stream($small), 'application/octet-stream', false, ST_PART);
S3Signer::abort_stream($resp['pending']);
check(s3fx_object($fx, 'bkt', '/declined-small.bin') === null && s3fx_count($fx, 'abort') === $aborts_before + 1,
	'a declined small stream sends nothing and aborts nothing');

$resp = S3Signer::put_stream($creds, 'bkt', '/empty.bin', $as_stream(''), 'application/octet-stream', false, ST_PART);
check(isset($resp['pending']) && $resp['bytes'] === 0 && $resp['sha256'] === hash('sha256', ''),
	'an empty stream is reported as zero bytes for the caller to refuse');
S3Signer::abort_stream($resp['pending']);
check(s3fx_object($fx, 'bkt', '/empty.bin') === null, 'and declining it leaves nothing');

// ─────────────────────────────────────────────────────────────────────────────
section('A failed part aborts and leaves nothing claimable');

$fx2 = s3fx_start(array('FIXTURE_FAIL_PART' => 2));
if ($fx2 === null) {
	harness_skip('part failure aborts', 'no fixture');
} else {
	harness_defer(function() use ($fx2) { s3fx_stop($fx2); });
	$resp = S3Signer::put_stream(s3fx_creds($fx2), 'bkt', '/k', $as_stream($big), 'application/octet-stream', true, ST_PART);
	check((int)$resp['status'] === 400, 'the failed part\'s status is handed back', 'status ' . $resp['status']);
	check(S3Signer::extract_error($resp['body']) === 'part refused', 'the provider\'s message survives');
	check(!isset($resp['pending']), 'no pending handle on failure');
	check(s3fx_count($fx2, 'abort') === 1, 'the upload was aborted');
	check(s3fx_count($fx2, 'complete') === 0 && s3fx_object($fx2, 'bkt', '/k') === null, 'nothing was completed or stored');

	$resp = S3Signer::put_stream(s3fx_creds($fx2), 'bkt', '/k2', $as_stream($big), 'application/octet-stream', false, ST_PART);
	check((int)$resp['status'] === 400 && !isset($resp['pending']) && s3fx_count($fx2, 'abort') === 2,
		'a deferred upload whose part fails is aborted inside put_stream()');
}

$fx3 = s3fx_start(array('FIXTURE_COMPLETE_ERRORS' => 99));
if ($fx3 === null) {
	harness_skip('200-with-Error complete', 'no fixture');
} else {
	harness_defer(function() use ($fx3) { s3fx_stop($fx3); });
	$resp = S3Signer::put_stream(s3fx_creds($fx3), 'bkt', '/k', $as_stream($big), 'application/octet-stream', false, ST_PART);
	$final = S3Signer::complete_stream($resp['pending']);
	check((int)$final['status'] >= 500, 'a persistent 200-with-Error complete comes back as a server failure', 'status ' . $final['status']);
	check(s3fx_count($fx3, 'complete') === S3Signer::MAX_ATTEMPTS, 'retried on the ordinary budget first');
	check(s3fx_count($fx3, 'abort') === 1, 'then aborted');
}

// ─────────────────────────────────────────────────────────────────────────────
section('A real child process pipe streams through');

$n = ST_PART * 3 + 41;
$proc = proc_open(array('bash', '-c', 'head -c ' . $n . ' /dev/zero | tr "\\0" "x"'),
	array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
if (!is_resource($proc)) {
	harness_skip('child pipe', 'could not start bash');
} else {
	$resp = S3Signer::put_stream($creds, 'bkt', '/piped.bin', $pipes[1], 'application/octet-stream', true, ST_PART);
	fclose($pipes[1]);
	$rc = proc_close($proc);
	check($rc === 0, 'the child exited cleanly after its stdout drained', 'rc ' . $rc);
	check((int)$resp['status'] === 200 && $resp['bytes'] === $n, 'every byte of the pipe went up', 'bytes ' . $resp['bytes']);
	check(s3fx_object($fx, 'bkt', '/piped.bin') === str_repeat('x', $n), 'and the object is exactly the pipe\'s output');
}

// ─────────────────────────────────────────────────────────────────────────────
section('An upload\'s peak memory is bounded');

// A backup uploads beside PostgreSQL, the web server and the mail filter on a
// 1 GB box. A 100 MiB part held twice (248 MB for the runner) beside a
// stand-in that joined an archive in memory (745 MB) froze such a box on its
// first backup. Measured here at the real part size, in a separate process so
// the peak is the uploader's own: five parts of incompressible bytes.
check(S3Signer::MULTIPART_PART_BYTES <= 33554432, 'a part is at most 32 MiB — the part size is the upload\'s memory budget',
	(string)S3Signer::MULTIPART_PART_BYTES);
$fx4 = s3fx_start();
if ($fx4 === null) {
	harness_skip('peak memory', 'could not start a second local server');
} else {
	$mib = (int)(5 * S3Signer::MULTIPART_PART_BYTES / 1048576);
	$child = '$fh = popen("head -c ' . ($mib * 1048576) . ' /dev/urandom", "rb");'
		. '$r = S3Signer::put_stream(' . var_export(s3fx_creds($fx4), true) . ', "bkt", "/peak.enc", $fh);'
		. 'pclose($fh);'
		. 'preg_match("/VmHWM:\\s+(\\d+)/", file_get_contents("/proc/self/status"), $m);'
		. 'echo json_encode(array("status" => $r["status"], "bytes" => $r["bytes"], "sha256" => $r["sha256"], '
		. '"heap" => memory_get_peak_usage(true), "hwm_kb" => (int)$m[1]));';
	$boot = 'require ' . var_export(PathHelper::getIncludePath('includes/PathHelper.php'), true) . ';'
		. 'require_once PathHelper::getIncludePath("includes/S3Signer.php");';
	$out = shell_exec('php -r ' . escapeshellarg($boot . $child) . ' 2>&1');
	$peak = json_decode((string)$out, true) ?: array();
	$stored = s3fx_object($fx4, 'bkt', '/peak.enc');
	check(($peak['status'] ?? 0) === 200 && strlen((string)$stored) === $mib * 1048576
		&& hash('sha256', (string)$stored) === ($peak['sha256'] ?? ''), 'the ' . $mib . ' MiB upload lands whole', (string)$out);
	$runner_mb = (int)(($peak['hwm_kb'] ?? 0) / 1024);
	check($runner_mb > 0 && $runner_mb < 160, 'the uploading process peaks under 160 MB (measured about 110)', $runner_mb . ' MB');
	check(($peak['heap'] ?? PHP_INT_MAX) < 2.5 * S3Signer::MULTIPART_PART_BYTES,
		'its PHP heap holds about one part at a time, not two', (int)(($peak['heap'] ?? 0) / 1048576) . ' MiB');
	$pid = (int)(proc_get_status($fx4['proc'])['pid'] ?? 0);
	$standin_mb = preg_match('/VmHWM:\s+(\d+)/', (string)@file_get_contents('/proc/' . $pid . '/status'), $sm) ? (int)($sm[1] / 1024) : 0;
	check($standin_mb > 0 && $standin_mb < 150, 'the stand-in peaks under 150 MB: it writes to disk as bytes arrive',
		$standin_mb . ' MB');
	s3fx_stop($fx4);
}

harness_finish();

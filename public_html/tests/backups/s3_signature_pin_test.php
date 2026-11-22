<?php
/** @joinery-test
 * name: s3_signature_pin
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * S3Signer's signatures, pinned (specs/email_provider_sdk_removal.md, WP6).
 *
 * At a fixed clock, the Authorization header every request type sends and
 * the URL presign() hands out are recorded byte for byte. They were recorded
 * from S3Signer's own inline signing before it moved onto AwsSigV4, so the
 * move is proven to change nothing: a wrong signature would fail every
 * backup with a 403. No network: the requests go to a test transport.
 *
 * Run: php tests/run.php --filter=s3_signature_pin
 * Re-record (only when a signing change is intended): S3_PIN_RECORD=1 php tests/backups/s3_signature_pin_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$GLOBALS['pin_sent'] = array();
S3Signer::useTestClock(gmmktime(12, 0, 0, 3, 14, 2026));
S3Signer::useTestTransport(function ($method, $url, array $headers) {
	$GLOBALS['pin_sent'][] = array('method' => $method, 'url' => $url, 'headers' => $headers);
	return array('status' => 200, 'body' => '', 'headers' => array());
});
harness_defer(function () { S3Signer::useTestClock(null); S3Signer::useTestTransport(null); });

$creds = array(
	'access_key' => '004EXAMPLEKEYID0000000001',
	'secret_key' => 'K004ExampleSecretKeyForPinningOnly00',
	'region'     => 'us-west-004',
	'endpoint'   => 'https://s3.us-west-004.backblazeb2.com',
);
$private = new ReflectionMethod('S3Signer', 'request');

/** The one request a call made: its URL and its Authorization header. */
function pin_last(): array {
	$req = end($GLOBALS['pin_sent']);
	$auth = '';
	foreach ($req['headers'] as $line) {
		if (stripos($line, 'Authorization: ') === 0) { $auth = substr($line, 15); }
	}
	$GLOBALS['pin_sent'] = array();
	return array('url' => $req['url'], 'auth' => $auth, 'headers' => $req['headers']);
}

$cases = array();

S3Signer::get($creds, 'pin-bucket', '/backups/site.tar', array());
$cases['plain GET'] = pin_last();

$private->invoke(null, 'PUT', $creds, 'pin-bucket', '/manifests/one.json', array(), '{"a":1}', 7, 'application/json');
$cases['PUT with a string body'] = pin_last();

$tmp = harness_scratch_dir('s3pin') . '/part.bin';
file_put_contents($tmp, str_repeat('x', 1000));
S3Signer::put_file($creds, 'pin-bucket', '/backups/streamed.bin', $tmp);
$cases['streaming PUT'] = pin_last();

S3Signer::get($creds, 'pin-bucket', '/backups/my file+v2 (1).tar', array());
$cases['key with spaces and a plus'] = pin_last();

$private->invoke(null, 'PUT', S3Signer::with_lock($creds, 30), 'pin-bucket', '/locked/obj.bin', array(), 'locked-bytes', 12, 'application/octet-stream');
$cases['object-lock PUT'] = pin_last();

$S3Signer_list = S3Signer::get($creds, 'pin-bucket', '/', array('list-type' => '2', 'prefix' => 'backups/', 'continuation-token' => 'abc+/='));
$cases['list with query'] = pin_last();

$presigns = array(
	'presign GET' => S3Signer::presign($creds, 'pin-bucket', '/backups/site.tar', 'GET', array(), 3600),
	'presign PUT part, key with space' => S3Signer::presign($creds, 'pin-bucket', '/backups/my file+.tar', 'PUT',
		array('partNumber' => '3', 'uploadId' => 'up/id+1'), 900),
	'presign PUT with lock headers' => S3Signer::presign($creds, 'pin-bucket', '/locked/obj.bin', 'PUT', array(), 600,
		array('x-amz-object-lock-mode' => 'COMPLIANCE', 'x-amz-object-lock-retain-until-date' => '2026-04-13T12:00:00Z')),
);

if (getenv('S3_PIN_RECORD')) {
	foreach ($cases as $name => $c) { echo var_export($name, true), ' => ', var_export(array($c['url'], $c['auth']), true), ",\n"; }
	foreach ($presigns as $name => $u) { echo var_export($name, true), ' => ', var_export($u, true), ",\n"; }
}

// Recorded from S3Signer 1.16's inline signing (before AwsSigV4).
$pinned_requests = array(
	'plain GET' => array (
	  0 => 'https://s3.us-west-004.backblazeb2.com/pin-bucket/backups/site.tar',
	  1 => 'AWS4-HMAC-SHA256 Credential=004EXAMPLEKEYID0000000001/20260314/us-west-004/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=420e617bc2df88e5cdb74e3a992aa68b7c800eaab39091a73496f0ba9ad282e0',
	),
	'PUT with a string body' => array (
	  0 => 'https://s3.us-west-004.backblazeb2.com/pin-bucket/manifests/one.json',
	  1 => 'AWS4-HMAC-SHA256 Credential=004EXAMPLEKEYID0000000001/20260314/us-west-004/s3/aws4_request, SignedHeaders=content-length;content-type;host;x-amz-content-sha256;x-amz-date, Signature=caf248843d7c4d1a0b6a60097a4abc3c4dbb741d2156275c4476a9db50a5fd3f',
	),
	'streaming PUT' => array (
	  0 => 'https://s3.us-west-004.backblazeb2.com/pin-bucket/backups/streamed.bin',
	  1 => 'AWS4-HMAC-SHA256 Credential=004EXAMPLEKEYID0000000001/20260314/us-west-004/s3/aws4_request, SignedHeaders=content-length;content-type;host;x-amz-content-sha256;x-amz-date, Signature=16a008b9a91f70f657ae2f0be1695b5d46b853d1643ac1a921dbce26a709e66a',
	),
	'key with spaces and a plus' => array (
	  0 => 'https://s3.us-west-004.backblazeb2.com/pin-bucket/backups/my%20file%2Bv2%20%281%29.tar',
	  1 => 'AWS4-HMAC-SHA256 Credential=004EXAMPLEKEYID0000000001/20260314/us-west-004/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=08c5be354803dbfaf5dbfc111b229088dac85648c79ff94aab3a9d4cdfc4751d',
	),
	'object-lock PUT' => array (
	  0 => 'https://s3.us-west-004.backblazeb2.com/pin-bucket/locked/obj.bin',
	  1 => 'AWS4-HMAC-SHA256 Credential=004EXAMPLEKEYID0000000001/20260314/us-west-004/s3/aws4_request, SignedHeaders=content-length;content-md5;content-type;host;x-amz-content-sha256;x-amz-date;x-amz-object-lock-mode;x-amz-object-lock-retain-until-date, Signature=bde2c7b61b5d6ccfa087d3fb86be916741e32eb86fe09a2c4f8ad8bff08a5b7a',
	),
	'list with query' => array (
	  0 => 'https://s3.us-west-004.backblazeb2.com/pin-bucket/?continuation-token=abc%2B%2F%3D&list-type=2&prefix=backups%2F',
	  1 => 'AWS4-HMAC-SHA256 Credential=004EXAMPLEKEYID0000000001/20260314/us-west-004/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=652de73f3b48bdeb738f15c8ebacd3b6fbeb58c3c44f33bfc04100f97907494e',
	),
);
$pinned_presigns = array(
	'presign GET' => 'https://s3.us-west-004.backblazeb2.com/pin-bucket/backups/site.tar?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=004EXAMPLEKEYID0000000001%2F20260314%2Fus-west-004%2Fs3%2Faws4_request&X-Amz-Date=20260314T120000Z&X-Amz-Expires=3600&X-Amz-SignedHeaders=host&X-Amz-Signature=e5c2b81b08b1a587d748210fe78cc92bf06e35e2a0a17daf7d8a11ed9d3658e3',
	'presign PUT part, key with space' => 'https://s3.us-west-004.backblazeb2.com/pin-bucket/backups/my%20file%2B.tar?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=004EXAMPLEKEYID0000000001%2F20260314%2Fus-west-004%2Fs3%2Faws4_request&X-Amz-Date=20260314T120000Z&X-Amz-Expires=900&X-Amz-SignedHeaders=host&partNumber=3&uploadId=up%2Fid%2B1&X-Amz-Signature=05fa60fcc442e9bbe0e48b2b0a0a17c144edcde86905657939fa00fd1a583e0e',
	'presign PUT with lock headers' => 'https://s3.us-west-004.backblazeb2.com/pin-bucket/locked/obj.bin?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=004EXAMPLEKEYID0000000001%2F20260314%2Fus-west-004%2Fs3%2Faws4_request&X-Amz-Date=20260314T120000Z&X-Amz-Expires=600&X-Amz-SignedHeaders=host%3Bx-amz-object-lock-mode%3Bx-amz-object-lock-retain-until-date&X-Amz-Signature=c121ee90130617bca8433fd7b639ad7dae215fc91c64daddde76150a1a6072e8',
);

section('Header-signed requests are byte-identical');
foreach ($pinned_requests as $name => $want) {
	$got = $cases[$name] ?? array('url' => '', 'auth' => '');
	check($got['url'] === $want[0], $name . ': URL unchanged', $got['url']);
	check($got['auth'] === $want[1], $name . ': Authorization unchanged', $got['auth']);
}

section('Presigned URLs are byte-identical');
foreach ($pinned_presigns as $name => $want) {
	check(($presigns[$name] ?? '') === $want, $name . ' unchanged', $presigns[$name] ?? '');
}

section('What the streamed and locked requests carry');
$stream_headers = implode("\n", $cases['streaming PUT']['headers']);
check(strpos($stream_headers, 'x-amz-content-sha256: UNSIGNED-PAYLOAD') !== false, 'a streamed PUT sends UNSIGNED-PAYLOAD');
$lock_headers = implode("\n", $cases['object-lock PUT']['headers']);
check(strpos($lock_headers, 'x-amz-object-lock-mode: COMPLIANCE') !== false
	&& strpos($lock_headers, 'content-md5: ') !== false, 'a locked PUT sends its lock and its MD5');
check(strpos($cases['object-lock PUT']['auth'], 'x-amz-object-lock-mode') !== false, 'the lock headers are signed');

harness_finish();

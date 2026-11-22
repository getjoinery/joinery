<?php
/** @joinery-test
 * name: aws_sigv4
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * AwsSigV4, the platform's one SigV4 signer (specs/email_provider_sdk_removal.md, WP3).
 *
 * Checked against AWS's own published answers: the SigV4 test suite
 * (get-vanilla, get-vanilla-query-order-key-case, post-x-www-form-urlencoded,
 * post-sts-header-after) and the S3 documentation's header-signed GET and
 * presigned GET examples. Then the two switches that set S3 apart: the path
 * encoded twice for every other service, and x-amz-content-sha256 signed.
 *
 * Run: php tests/run.php --filter=aws_sigv4
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** The SigV4 test suite's fixed request context. */
function sigv4_suite(array $extra): array {
	return $extra + array(
		'host' => 'example.amazonaws.com', 'service' => 'service', 'region' => 'us-east-1',
		'access_key' => 'AKIDEXAMPLE', 'secret_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
		'time' => gmmktime(12, 36, 0, 8, 30, 2015), 'double_encode_path' => true,
	);
}

/** The S3 documentation examples' fixed context. */
function sigv4_s3(array $extra): array {
	return $extra + array(
		'host' => 'examplebucket.s3.amazonaws.com', 'service' => 's3', 'region' => 'us-east-1',
		'access_key' => 'AKIAIOSFODNN7EXAMPLE', 'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
		'time' => gmmktime(0, 0, 0, 5, 24, 2013),
	);
}

function sigv4_sig(string $auth): string {
	return preg_match('/Signature=([0-9a-f]{64})$/', $auth, $m) ? $m[1] : '';
}

section('AWS SigV4 test suite vectors');

$h = AwsSigV4::signHeaders(sigv4_suite(array('method' => 'GET', 'path' => '/')));
check(sigv4_sig($h['Authorization']) === '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
	'get-vanilla signature');
check(strpos($h['Authorization'], 'SignedHeaders=host;x-amz-date,') !== false, 'get-vanilla signs host;x-amz-date');
check(($h['x-amz-date'] ?? '') === '20150830T123600Z', 'x-amz-date comes from the caller\'s clock');
check(!isset($h['x-amz-content-sha256']), 'no x-amz-content-sha256 unless asked');

$h = AwsSigV4::signHeaders(sigv4_suite(array('method' => 'GET', 'path' => '/',
	'query' => array('Param2' => 'value2', 'Param1' => 'value1'))));
check(sigv4_sig($h['Authorization']) === 'b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500',
	'get-vanilla-query-order-key-case signature (query sorted)');

$h = AwsSigV4::signHeaders(sigv4_suite(array('method' => 'POST', 'path' => '/',
	'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
	'payload_hash' => hash('sha256', 'Param1=value1'))));
check(sigv4_sig($h['Authorization']) === 'ff11897932ad3f4e8b18135d722051e5ac45fc38421b1da7b9d196a0fe09473a',
	'post-x-www-form-urlencoded signature');

$token = 'AQoDYXdzEPT//////////wEXAMPLEtc764bNrC9SAPBSM22wDOk4x4HIZ8j4FZTwdQWLWsKWHGBuFqwAeMicRXmxfpSPfIeoIYRqTflfKD8YUuwthAx7mSEI/qkPpKPi/kMcGdQrmGdeehM4IC1NtBmUpp2wUE8phUZampKsburEDy0KPkyQDYwT7WZ0wq5VSXDvp75YU9HFvlRd8Tx6q6fE8YQcHNVXAkiY9q6d+xo0rKwT38xVqr7ZD0u0iPPkUL64lIZbqBAz+scqKmlzm8FDrypNC9Yjc8fPOLn9FX9KSYvKTr4rvx3iSIlTJabIQwj2ICCR/oLxBA==';
$h = AwsSigV4::signHeaders(sigv4_suite(array('method' => 'POST', 'path' => '/', 'session_token' => $token)));
check(sigv4_sig($h['Authorization']) === '85d96828115b5dc0cfc3bd16ad9e210dd772bbebba041836c64533a82be05ead',
	'post-sts-header-after signature (session token signed)');
check(($h['x-amz-security-token'] ?? '') === $token, 'the session token is returned as a header to send');

section('S3 documentation examples');

$h = AwsSigV4::signHeaders(sigv4_s3(array('method' => 'GET', 'path' => '/test.txt',
	'headers' => array('Range' => 'bytes=0-9'), 'sign_content_sha256' => true)));
check(sigv4_sig($h['Authorization']) === 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
	'S3 GET object with Range, x-amz-content-sha256 signed');
check(($h['x-amz-content-sha256'] ?? '') === hash('sha256', ''), 'x-amz-content-sha256 is returned to send');

$qs = AwsSigV4::presignQuery(sigv4_s3(array('method' => 'GET', 'path' => '/test.txt', 'expires' => 86400)));
check(str_ends_with($qs, '&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404'),
	'S3 presigned GET signature');
check(str_starts_with($qs, 'X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request&'),
	'presigned query string is the canonical one, encoded');

section('Path encoding: twice for every service but S3');

// A path segment holding an encoded reserved character, as an SES identity
// read for a URL-encoded address sends it.
$wire = '/v2/email/identities/a%40b.example';
$ctx = sigv4_suite(array('method' => 'GET', 'path' => $wire));
$double = sigv4_sig(AwsSigV4::signHeaders($ctx)['Authorization']);
$single = sigv4_sig(AwsSigV4::signHeaders(array('double_encode_path' => false) + $ctx)['Authorization']);
check($double !== $single, 'the two path modes sign differently once a segment holds a %');

// Recompute what the double-encoded canonical request must be, by hand.
$amz = '20150830T123600Z';
$canon = "GET\n/v2/email/identities/a%2540b.example\n\nhost:example.amazonaws.com\nx-amz-date:$amz\n\nhost;x-amz-date\n" . hash('sha256', '');
$sts = "AWS4-HMAC-SHA256\n$amz\n20150830/us-east-1/service/aws4_request\n" . hash('sha256', $canon);
$k = hash_hmac('sha256', '20150830', 'AWS4wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', true);
$k = hash_hmac('sha256', 'us-east-1', $k, true);
$k = hash_hmac('sha256', 'service', $k, true);
$k = hash_hmac('sha256', 'aws4_request', $k, true);
check($double === hash_hmac('sha256', $sts, $k), 'a %40 in the path signs as %2540 for a non-S3 service');

section('Inputs');

$threw = false;
try { AwsSigV4::signHeaders(array('method' => 'GET', 'path' => '/')); }
catch (InvalidArgumentException $e) { $threw = true; }
check($threw, 'missing inputs throw instead of signing a partial request');

check(AwsSigV4::canonicalQuery(array('b' => 'x y', 'a' => 'é')) === 'a=%C3%A9&b=x%20y',
	'canonicalQuery sorts by name and RFC 3986 encodes');

harness_finish();

<?php
/** @joinery-test
 * name: route_empty_response
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * A route handler's empty answer is served when it meant one.
 *
 * RouteHelper turns an empty 200 from a handler that returned true into a 500,
 * because a page that renders nothing is a bug nobody sees. A zero-byte file
 * is also empty: /uploads answers it with 200 and Content-Length: 0, and a
 * range past its end with 416. Both are answers, and refusing them made a
 * zero-byte file impossible to download.
 *
 * Run: php tests/unit/route_empty_response_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$deliberate = function (array $headers, $status, $method = 'GET') {
	return RouteHelper::empty_response_is_deliberate($headers, $status, $method);
};

section('The silent failure is still refused');

check(!$deliberate(array('Content-Type: text/html; charset=utf-8'), 200),
	'an empty 200 that says nothing else is refused');
check(!$deliberate(array(), false),
	'and so is one with no headers at all (no status set, as on the command line)');

section('An empty answer the handler meant');

check($deliberate(array('Content-Type: text/plain', 'Content-Length: 0'), 200),
	'a zero-byte file: 200 with Content-Length: 0');
check($deliberate(array('Content-Range: bytes */10', 'Content-Length: 0'), 416),
	'a range past the end: 416');
check($deliberate(array(), 304), 'not modified: 304');
check($deliberate(array(), 204), 'no content: 204');
check($deliberate(array('Location: /login'), 302), 'a redirect');
check($deliberate(array('location: /elsewhere'), 200), 'a Location header in any case');
check($deliberate(array('Content-Type: text/plain'), 200, 'HEAD'), 'an answer to HEAD');

harness_finish();

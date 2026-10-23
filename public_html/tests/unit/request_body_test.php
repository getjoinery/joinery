<?php
/** @joinery-test
 * name: request_body
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A request whose declared body is missing is told apart by size, and a
 * request row carries how long PHP spent on it.
 *
 * Two events leave PHP with an empty body and a Content-Length: a body over
 * post_max_size (PHP discards it) and a body that never arrived (Apache gave
 * up waiting). The second used to be logged as the first, "Content-Length 2
 * exceeds post_max_size 32M", which hid stalled connections on
 * jeremytunnell.com (specs/slow_request_diagnosis_words.md WP1 B4). And every
 * API action row had no duration, because the action path read a start time
 * from $GLOBALS that the router's closure never put there (B5).
 *
 * Run: php tests/unit/request_body_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('INI sizes in bytes');

check(RequestBody::ini_bytes('32M') === 33554432, '32M is 33554432 bytes');
check(RequestBody::ini_bytes('1G') === 1073741824, '1G is 1073741824 bytes');
check(RequestBody::ini_bytes('8k') === 8192, 'a lower-case suffix counts');
check(RequestBody::ini_bytes('8192') === 8192, 'a bare number is bytes');
check(RequestBody::ini_bytes('') === 0 && RequestBody::ini_bytes('0') === 0, 'unset or 0 is no limit');
check(RequestBody::ini_bytes('lots') === 0, 'a value that is not a size is no limit, never a guess');

section('A missing body is named for what happened');

$limit = RequestBody::ini_bytes(ini_get('post_max_size'));
$small = RequestBody::missing_body_refusal(2);
check($small['error_type'] === 'RequestBodyIncomplete' && $small['status'] === 400,
	'a 2-byte body that is not there did not arrive: RequestBodyIncomplete, 400',
	json_encode($small));
check(strpos($small['note'], 'exceeds') === false, 'its note does not claim a limit was exceeded', $small['note']);

if ($limit > 0) {
	$big = RequestBody::missing_body_refusal($limit + 1);
	check($big['error_type'] === 'RequestTooLarge' && $big['status'] === 413,
		'one byte over post_max_size is RequestTooLarge, 413', json_encode($big));
	$edge = RequestBody::missing_body_refusal($limit);
	check($edge['error_type'] === 'RequestBodyIncomplete', 'exactly post_max_size is not over it');
} else {
	check(true, 'post_max_size is unlimited here: only the incomplete case applies');
}

section('Both endpoints use it, and only for an empty body');

$logic   = file_get_contents(PathHelper::getIncludePath('includes/ApiLogicEndpoint.php'));
$channel = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/AgentChannelEndpoint.php'));
check(strpos($logic, 'RequestBody::missing_body_refusal($content_length)') !== false,
	'the API action path asks RequestBody');
check(strpos($logic, "&& \$raw_input === '')") !== false,
	'a request with a body (a text/plain one included) never reaches the refusal');
check(strpos($channel, 'RequestBody::missing_body_refusal($declared)') !== false,
	'the agent channel asks RequestBody');
check(strpos($logic, "\$GLOBALS['api_start_time']") === false,
	'the action path no longer reads a start time the router never sets');

section('A request row carries PHP\'s time on the request');

check(RequestLogger::elapsed_ms() === null,
	'from the CLI the figure is null: a scheduled task\'s lifetime is not a request\'s');
$logger = file_get_contents(PathHelper::getIncludePath('includes/RequestLogger.php'));
check(strpos($logger, "isset(\$options['response_ms']) ? \$options['response_ms'] : self::elapsed_ms()") !== false,
	'a caller\'s own measure wins; without one the row takes elapsed_ms()');

harness_finish();

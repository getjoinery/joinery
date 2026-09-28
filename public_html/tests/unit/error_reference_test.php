<?php
/** @joinery-test
 * name: error_reference
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Every error a member sees can be reported, and the report names the error.
 *
 * A recorded error gets an error reference: its err_general_errors row id and
 * a grouping hash. The error page, the JSON error envelopes and the error
 * flash messages carry it, and the "Report this problem" link points at the
 * report page with it. Guests get no link: they cannot send a report.
 *
 * Also pins the API fix that came with it: an exception escaping a logic
 * action is recorded like any uncaught error, and the caller is told a
 * user-safe message, never the raw exception text.
 *
 * Writes err_general_errors rows and a fixture user, and removes them.
 * Run: php tests/unit/error_reference_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

if (session_id() === '') { @session_start(); }

$session = SessionControl::get_instance();

/** An exception thrown at a fixed line, so two calls hash alike. */
function er_throw_at_fixed_line($message) {
	return new RuntimeException($message);
}

// ------------------------------------------------------------------ the hash

section('The grouping hash');

$a = er_throw_at_fixed_line('Row 17 is missing');
$b = er_throw_at_fixed_line('Row 942 is missing');
$c = er_throw_at_fixed_line('Something else entirely');
check(ErrorReference::hash($a) === ErrorReference::hash($b),
	'Two errors that differ only in their numbers share a hash');
check(ErrorReference::hash($a) !== ErrorReference::hash($c),
	'A different message is a different hash');
check(ErrorReference::relativeFile(__FILE__) === 'tests/unit/error_reference_test.php',
	'The hash uses the file path relative to public_html, so installs in different directories group together',
	ErrorReference::relativeFile(__FILE__));

// --------------------------------------------------------------- recording

section('Recording an error makes it the reference');

ErrorReference::reset();
check(ErrorReference::current() === null, 'No reference before anything is recorded');

$logged = new RuntimeException('error_reference_test fixture error');
$id = ErrorReference::log($logged);
if ($id) {
	harness_register_row('err_general_errors', 'err_general_error_id', $id);
}
check(is_int($id) && $id > 0, 'Recording an error returns the saved row id', var_export($id, true));

$row = new GeneralError($id, TRUE);
check($row->get('err_message') === 'error_reference_test fixture error',
	'The id names the row that was saved');

$current = ErrorReference::current();
check($current !== null && $current['id'] === $id && $current['hash'] === ErrorReference::hash($logged),
	'The recorded error is this request\'s reference, with its hash');

$direct = (new GeneralError(NULL))->logError(new RuntimeException('error_reference_test direct'), array(), array());
if ($direct) {
	harness_register_row('err_general_errors', 'err_general_error_id', $direct);
}
check(is_int($direct) && $direct > 0 && $direct !== $id, 'GeneralError::logError() returns the new row id');

// ---------------------------------------------------- guest vs signed-in

section('The report link is for signed-in members only');

$session->clear_api_user();
check(!ErrorReference::reporterSignedIn(), 'The test starts signed out');
$env = ErrorReference::forEnvelope();
check($env !== null && $env['id'] === $id && !isset($env['report_url']),
	'A guest\'s envelope carries the reference but no report URL');
check(ErrorReference::reportLinkHtml($id) === '', 'A guest gets no report link');

$member = make_user('errref');
$session->set_api_user($member->key);
check(ErrorReference::reporterSignedIn(), 'A signed-in member may report');
$env = ErrorReference::forEnvelope();
check(isset($env['report_url']) && strpos($env['report_url'], '/report_problem?ref=' . $id) === 0,
	'A member\'s envelope carries the report URL with the row id', $env['report_url'] ?? '');
check(strpos(ErrorReference::reportLinkHtml($id), 'href="/report_problem?ref=' . $id) !== false,
	'A member gets a report link naming the row');

$no_row = ErrorReference::reportUrl(null, '/profile/thing?x=1', 'Could not save the thing');
parse_str((string)parse_url($no_row, PHP_URL_QUERY), $q);
check(!isset($q['ref']) && $q['from'] === '/profile/thing?x=1' && $q['msg'] === 'Could not save the thing',
	'With no error row, the link carries the page and the message instead');
$long = ErrorReference::reportUrl(null, null, str_repeat('x', 1000));
parse_str((string)parse_url($long, PHP_URL_QUERY), $q);
check(mb_strlen($q['msg']) === ErrorReference::MESSAGE_CAP, 'The carried message is capped');

// --------------------------------------------------------- the error pages

section('Error pages');

$exception = new RuntimeException('page failure');
$ctx_member = new ErrorContext(array('user_id' => (int)$member->key, 'request_uri' => '/some/page'));
$ctx_member->setErrorReference($id, ErrorReference::hash($exception));
$ctx_guest = new ErrorContext(array('user_id' => null, 'request_uri' => '/some/page'));
$ctx_guest->setErrorReference($id, ErrorReference::hash($exception));

foreach (array('WebErrorHandler', 'AdminErrorHandler') as $class) {
	$handler = new $class();
	$member_html = $handler->handle($exception, $ctx_member)->getContent();
	$guest_html = $handler->handle($exception, $ctx_guest)->getContent();
	check(strpos($member_html, 'Report this problem') !== false
			&& strpos($member_html, '/report_problem?ref=' . $id) !== false,
		$class . ': a member sees "Report this problem" linking to the row');
	check(strpos($guest_html, 'Report this problem') === false,
		$class . ': a guest sees no report button');
	check(stripos($member_html, 'has been notified') === false && stripos($guest_html, 'has been notified') === false,
		$class . ': the page does not claim that anyone was notified');
}

// ------------------------------------------------------- the JSON envelopes

section('JSON error envelopes');

ErrorReference::record($id, ErrorReference::hash($exception));
$api = json_decode((new ApiErrorHandler())->handle($exception, $ctx_member)->getContent(), true);
check(isset($api['error_ref']['id']) && $api['error_ref']['id'] === $id,
	'The API envelope carries error_ref');
check(is_string($api['error'] ?? null), 'The API envelope keeps error as a string');
$ajax = json_decode((new AjaxErrorHandler())->handle($exception, $ctx_member)->getContent(), true);
check(isset($ajax['error_ref']['id']) && $ajax['error_ref']['id'] === $id,
	'The AJAX envelope carries error_ref');

ErrorReference::reset();
$api = json_decode((new ApiErrorHandler())->handle($exception, $ctx_member)->getContent(), true);
check(!array_key_exists('error_ref', $api), 'No recorded error, no error_ref');

// ------------------------------------------------------------ flash messages

section('Error flash messages');

$error_msg = new DisplayMessage('Could not save the widget', 'Error', NULL, DisplayMessage::MESSAGE_ERROR);
check(strpos($error_msg->report_link_html(), 'Report a problem') !== false,
	'An error message with no row still gets the link');
check(strpos($error_msg->report_link_html(), 'msg=Could+not+save+the+widget') !== false,
	'Without a row, the link carries the message');
$error_msg->error_ref = $id;
check(strpos($error_msg->report_link_html(), 'ref=' . $id) !== false,
	'An error message with a row links to it');
$notice = new DisplayMessage('Saved', 'Done', NULL, DisplayMessage::MESSAGE_ANNOUNCEMENT);
check($notice->report_link_html() === '', 'A success message gets no link');

// ------------------------------------------------- API action exceptions (B2)

section('An exception escaping a logic action');

$safe = new ReflectionMethod('ApiLogicEndpoint', 'userSafeMessage');
$safe->setAccessible(true);
harness_set_setting_mem('show_errors', '0');
check($safe->invoke(null, new RuntimeException('SQLSTATE[42P01]: relation "x" does not exist'), 'Something went wrong while doing that.')
		=== 'Something went wrong while doing that.',
	'A plain exception\'s raw text does not reach the caller');
check($safe->invoke(null, new SystemDisplayableError('That name is taken.'), 'generic') === 'That name is taken.',
	'A Displayable exception keeps its message');
check($safe->invoke(null, new AuthorizationException('internal detail'), 'generic')
		=== 'You do not have permission to perform this action.',
	'A displayable BaseException shows its user message, not its internal one');
harness_set_setting_mem('show_errors', '1');
check($safe->invoke(null, new RuntimeException('raw detail'), 'generic') === 'raw detail',
	'A site that shows errors shows the raw text');

$record = new ReflectionMethod('ApiLogicEndpoint', 'recordException');
$record->setAccessible(true);
ErrorReference::reset();
$record->invoke(null, new SystemDisplayableErrorNoLog('expected refusal'));
check(ErrorReference::current() === null, 'An expected (NoLog) refusal is not recorded');
$record->invoke(null, new RuntimeException('error_reference_test action failure'));
$recorded = ErrorReference::current();
if ($recorded && $recorded['id']) {
	harness_register_row('err_general_errors', 'err_general_error_id', $recorded['id']);
}
check($recorded !== null && $recorded['id'] > 0, 'Any other exception is recorded and becomes the reference');

$session->clear_api_user();
ErrorReference::reset();

harness_finish();

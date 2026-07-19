<?php
/** @joinery-test
 * name: logic_result
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The contract every page uses to get data out of its logic file.
 *
 * `process_logic(foo_logic($_GET, $_POST))` is the first line of most views,
 * so the handful of rules encoded here decide what a page does with a failure
 * before any page-specific code runs: which errors stop the page dead, which
 * ones render the page with a message on top, and what a view can assume is
 * present in the array it gets back.
 *
 * The distinction that carries the most weight is between an error that has
 * something to show and one that does not. A logic function returning an error
 * with no data and no field-level failures has nothing to render, so
 * process_logic raises and the error page takes over. The same error carrying
 * data means the page can still be drawn — the message is queued and the view
 * renders with what it has. Getting this backwards in either direction is a
 * bad day: either a recoverable form error becomes an error page, or a fatal
 * one renders an empty page with no explanation.
 *
 * Runs offline, no DB.
 * Run: php tests/unit/logic_result_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

if (session_id() === '') { @session_start(); }

/** Queued display messages, cleared so each check counts only its own. */
function lr_reset_messages() {
	$_SESSION['saved_messages'] = array();
}
function lr_message_count() {
	return count($_SESSION['saved_messages'] ?? array());
}
/** Run process_logic and report what it did rather than what it returned. */
function lr_process($result) {
	try {
		return array('threw' => false, 'value' => process_logic($result));
	} catch (Throwable $e) {
		return array('threw' => true, 'class' => get_class($e), 'message' => $e->getMessage());
	}
}

$saved_messages = $_SESSION['saved_messages'] ?? null;


section('What each factory builds');

$r = LogicResult::render(array('title' => 'Hello'));
check($r->data === array('title' => 'Hello'), 'render() carries the view data');
check($r->redirect === null, 'render() does not redirect');
check($r->error === null, 'render() is not an error');
check($r->validation_errors === array(), 'render() starts with no validation errors');

$r = LogicResult::redirect('/somewhere');
check($r->redirect === '/somewhere', 'redirect() carries the destination');
check($r->error === null, 'redirect() is not an error');

$r = LogicResult::error('Something broke');
check($r->error === 'Something broke', 'error() carries the message');
check($r->redirect === null, 'error() does not redirect');
check($r->data === array(), 'error() defaults to no data');

// An error that still has something to draw is a distinct, supported case —
// it is what lets a form redisplay with the submitted values intact.
$r = LogicResult::error('Bad input', array('old' => 'value'));
check($r->data === array('old' => 'value'), 'error() can carry data for redisplay');


section('Field-level validation errors');

$r = LogicResult::render(array());
check($r->hasValidationErrors() === false, 'A fresh result has no validation errors');

$r->addValidationError('email', 'Enter a valid email.');
check($r->hasValidationErrors() === true, 'Adding one is reported');
check($r->validation_errors['email'] === 'Enter a valid email.', 'The message is filed under its field');

// Adding a field error promotes the result to an error, so a caller cannot
// end up with per-field messages and a result that still looks successful.
check($r->error === 'Please correct the errors below',
	'The first field error supplies a general error message', (string)$r->error);

// But a message the logic already chose is more specific than the generic one
// and must win.
$r2 = LogicResult::error('That coupon has expired.');
$r2->addValidationError('coupon', 'Expired.');
check($r2->error === 'That coupon has expired.',
	'An error message already set is not overwritten by the generic one', (string)$r2->error);

$r2->addValidationError('quantity', 'Too many.');
check(count($r2->validation_errors) === 2, 'Errors accumulate across fields');


section('process_logic: what reaches the view');

lr_reset_messages();
$out = lr_process(LogicResult::render(array('title' => 'Hello')));
check($out['threw'] === false, 'A plain render does not raise');
check($out['value']['title'] === 'Hello', 'The view data is returned directly');

// Every view can read $page_vars['validation_errors'] without checking whether
// it exists, which is why templates index it unguarded.
check(array_key_exists('validation_errors', $out['value']),
	'validation_errors is always present, even with none');
check($out['value']['validation_errors'] === array(), 'and is empty when there are none');
check(lr_message_count() === 0, 'A successful render queues no message');

// Anything that is not a LogicResult passes straight through. Older logic
// files return a bare array, and process_logic is wrapped around them too.
$legacy = array('a' => 1);
$out = lr_process($legacy);
check($out['value'] === $legacy, 'A plain array is returned untouched');
check(!array_key_exists('validation_errors', $out['value']),
	'A plain array is not given a validation_errors key it never had');
$out = lr_process(null);
check($out['value'] === null, 'Null passes through');


section('process_logic: which errors stop the page');

// Nothing to render — the page cannot be drawn, so the error page takes over.
lr_reset_messages();
$out = lr_process(LogicResult::error('No such record.'));
check($out['threw'] === true, 'An error with nothing to show raises');
check(isset($out['class']) && $out['class'] === 'SystemDisplayableError',
	'It raises the displayable error type, not a bare exception',
	isset($out['class']) ? $out['class'] : '');
check(isset($out['message']) && $out['message'] === 'No such record.',
	'The logic function\'s message reaches the error page');
check(lr_message_count() === 0, 'The raising path does not also queue a message');

// Something to render — the page draws, with the message on top.
lr_reset_messages();
$out = lr_process(LogicResult::error('Could not save.', array('form' => 'values')));
check($out['threw'] === false, 'An error carrying data does not raise');
check($out['value']['form'] === 'values', 'The data still reaches the view');
check(lr_message_count() === 1, 'The error is queued as a display message instead');

// Field errors alone are enough to keep the page alive: a form with per-field
// messages and no other data must redisplay, not become an error page.
lr_reset_messages();
$r = LogicResult::render(array());
$r->addValidationError('email', 'Enter a valid email.');
$out = lr_process($r);
check($out['threw'] === false, 'Field errors alone keep the page renderable');
check($out['value']['validation_errors']['email'] === 'Enter a valid email.',
	'The field errors reach the view');
check(lr_message_count() === 1, 'The general message is queued alongside them');

// The redirect branch is deliberately not exercised: process_logic calls
// LibraryFunctions::redirect() and then exit(), which would end the test
// process. That is the whole reason tests call logic functions directly and
// assert on ->redirect rather than going through process_logic — see
// tests/lib/logic.php. What is checkable here is that a redirect result is
// distinguishable before process_logic ever sees it.
$r = LogicResult::redirect('/profile');
check($r->redirect === '/profile' && $r->error === null,
	'A redirect result is identifiable without invoking the exiting path');

if ($saved_messages === null) {
	unset($_SESSION['saved_messages']);
} else {
	$_SESSION['saved_messages'] = $saved_messages;
}

harness_finish();

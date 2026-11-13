<?php
/**
 * Report a problem — the page a member reaches from an error.
 *
 * GET /report_problem?ref={error id}&from={path}&msg={message}
 *
 * Builds the problem report's bundle for this reporter and these inputs and
 * hands it to the view, which shows it in full before anything is sent: the
 * reporter reads what will leave, then decides. Sending is the
 * report_problem_submit action. A guest is sent to sign in and brought back.
 *
 * The page's script posts the form to that action. A form posted to this page
 * itself (sent before the script ran, or with no script) is handed to the same
 * action here, so the report is never lost. A sent report comes back to the
 * page by redirect, which says what happened once; a refused one shows its
 * error with the form.
 *
 * See specs/implemented/bug_reports.md.
 *
 * @version 1.1.1 - a sent report redirects to the page, which says so once (no resend on reload)
 * @version 1.1.0 - a form posted to the page itself is submitted through report_problem_submit
 * @version 1.0.0
 */

function report_problem_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();

	$reporter = ProblemReport::reporter($session);
	$inputs = ProblemReport::inputs($input);
	$bundle = ProblemReport::buildBundle($reporter, $inputs);

	// A ref the reporter may not attach (another member's error) is dropped
	// from the page's form too, so what is shown and what is sent agree.
	if ($inputs['ref'] !== null && ($bundle['error']['id'] ?? null) === null) {
		$inputs['ref'] = null;
	}

	$submit_message = null;
	$submit_error = null;
	if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && array_key_exists('comment', $input)) {
		require_once(PathHelper::getIncludePath('logic/report_problem_submit_logic.php'));
		$sent = report_problem_submit_logic($input);
		if ($sent->error !== null) {
			$submit_error = (string)$sent->error;
		} else {
			// Sent: back to the page by GET, so a reload cannot send it again.
			$_SESSION['report_problem_sent'] = (string)($sent->data['message'] ?? 'Thank you. Your report is saved.');
			return LogicResult::redirect('/report_problem');
		}
	} elseif (isset($_SESSION['report_problem_sent'])) {
		$submit_message = (string)$_SESSION['report_problem_sent'];
		unset($_SESSION['report_problem_sent']);
	}

	return LogicResult::render(array(
		'submit_message'  => $submit_message,
		'submit_error'    => $submit_error,
		'page_title'      => 'Report a problem',
		'inputs'          => $inputs,
		'bundle'          => $bundle,
		'sections'        => ProblemReportBundle::displayRows($bundle),
		'operator'        => ($bundle['scope'] ?? '') === 'operator',
		'destination'     => ProblemReport::destinationHost(),
		'sending_enabled' => ProblemReport::sendingEnabled(),
		'comment_max'     => ProblemReport::COMMENT_MAX,
	));
}

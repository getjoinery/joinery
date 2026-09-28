<?php
/**
 * API action: report_problem_submit — save a problem report and send it.
 *
 * POST /api/v1/action/report_problem_submit as multipart form data: comment
 * (required), ref, from and msg (what the report page was opened with), and
 * an optional `image` file. The bundle is rebuilt here from the same inputs
 * the page showed, saved with the comment and image, and sent once to the
 * upgrade source. The answer says what happened: sent, queued for another
 * try, or kept on this site because the operator switched sending off.
 *
 * See specs/implemented/bug_reports.md.
 *
 * @version 1.0.0
 */

function report_problem_submit_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$reporter = ProblemReport::reporter($session);
	if ($reporter['user_id'] <= 0) {
		return LogicResult::error('Sign in to report a problem.');
	}

	try {
		$report = ProblemReport::submit($reporter, ProblemReport::inputs($input),
			(string)($input['comment'] ?? ''), $_FILES['image'] ?? null);
	} catch (ProblemReportException $e) {
		return LogicResult::error($e->getMessage());
	}

	$status = (string)$report->get('prr_status');
	$host = (string)$report->get('prr_destination');
	switch ($status) {
		case ProblemReport::STATUS_SENT:
			$message = 'Thank you. Your report was sent to ' . $host . '.';
			break;
		case ProblemReport::STATUS_KEPT:
			$message = 'Thank you. This site keeps problem reports here only, so the site\'s administrator will see it.';
			break;
		default:
			$message = 'Thank you. Your report is saved, and this site will keep trying to send it to ' . $host . '.';
	}

	return LogicResult::render(array(
		'report_id' => (int)$report->key,
		'status'    => $status,
		'message'   => $message,
	));
}

function report_problem_submit_logic_descriptor(): array {
	return array(
		'description' => 'Save a problem report (comment, optional image, and the error reference the report page was opened with) and send it to the upgrade source. Multipart form data; the image field is `image`.',
		'mutates'     => true,
		'auth'        => array(
			'requires_browser_session' => true,
		),
		'input'       => array(
			'comment' => array('type' => 'text', 'required' => true, 'label' => 'Description', 'max_length' => 5000),
			'ref'     => array('type' => 'int', 'label' => 'Error reference'),
			'from'    => array('type' => 'string', 'label' => 'Page', 'max_length' => 2000),
			'msg'     => array('type' => 'string', 'label' => 'Message', 'max_length' => 300),
		),
	);
}

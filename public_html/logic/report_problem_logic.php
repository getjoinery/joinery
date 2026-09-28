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
 * See specs/bug_reports.md.
 *
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

	return LogicResult::render(array(
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

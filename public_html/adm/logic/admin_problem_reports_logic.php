<?php
/**
 * Admin: Problem Reports — the reports this site's members sent, or tried to.
 *
 * Lists every report with its status and, for one that did not go through,
 * the reason. One report opens to its full bundle, comment and image. "Send
 * now" (POST) tries a queued or failed report again at once instead of
 * waiting for the hourly task.
 *
 * See specs/bug_reports.md.
 *
 * @version 1.0.0
 */

function admin_problem_reports_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(9);
	$session->set_return();

	// Action first, before anything renders.
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($input['action'] ?? '') === 'send_now') {
		$report = new ProblemReport((int)($input['prr_problem_report_id'] ?? 0), TRUE);
		if (!$report->key) {
			return LogicResult::error('That problem report no longer exists.');
		}
		$ok = $report->send();
		$session->save_message(new DisplayMessage(
			$ok ? 'The report was sent.' : 'The report was not sent: ' . $report->get('prr_last_reason'),
			$ok ? 'Sent' : 'Not sent', NULL,
			$ok ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_WARNING));
		return LogicResult::redirect('/admin/admin_problem_reports?prr_problem_report_id=' . (int)$report->key);
	}

	$page_vars = array(
		'session'         => $session,
		'destination'     => ProblemReport::destinationHost(),
		'sending_enabled' => ProblemReport::sendingEnabled(),
		'report'          => null,
	);

	$id = (int)($input['prr_problem_report_id'] ?? 0);
	if ($id > 0) {
		$report = new ProblemReport($id, TRUE);
		if (!$report->key) {
			return LogicResult::error('That problem report no longer exists.');
		}
		$page_vars['report'] = $report;
		$page_vars['reporter'] = new User((int)$report->get('prr_usr_user_id'), TRUE);
		$page_vars['sections'] = ProblemReportBundle::displayRows($report->bundle());
		$file_id = (int)$report->get('prr_fil_file_id');
		$page_vars['image'] = ($file_id > 0 && File::check_if_exists($file_id)) ? new File($file_id, TRUE) : null;
		return LogicResult::render($page_vars);
	}

	$numperpage = 30;
	$offset = (int)LibraryFunctions::fetch_variable_local($input, 'offset', 0);
	$filter = (string)LibraryFunctions::fetch_variable_local($input, 'filter', '');
	$options = array();
	if (in_array($filter, array(ProblemReport::STATUS_QUEUED, ProblemReport::STATUS_SENT,
			ProblemReport::STATUS_FAILED, ProblemReport::STATUS_KEPT), true)) {
		$options['status'] = $filter;
	}
	$reports = new MultiProblemReport($options, array('prr_create_time' => 'DESC'), $numperpage, $offset);
	$page_vars['reports'] = $reports;
	$page_vars['numrecords'] = $reports->count_all();
	$page_vars['numperpage'] = $numperpage;
	return LogicResult::render($page_vars);
}

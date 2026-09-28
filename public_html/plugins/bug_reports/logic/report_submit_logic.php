<?php
/**
 * API action: bug_reports/report_submit — receive a problem report from a
 * site that upgrades from this one.
 *
 * POST /api/v1/action/bug_reports/report_submit as multipart form data:
 * `bundle` (the report's JSON), `comment` (the reporter's words) and an
 * optional `image` file. Sessionless: the sending site has no account here.
 * Its own rate bucket applies first (bug_reports_rate_limit_requests per hour
 * per address, on top of the general API bucket); BugReportIntake does the
 * rest and says why when it refuses. Answers {report_id}, which the sender
 * keeps as the remote id.
 *
 * See specs/implemented/bug_reports.md and plugins/bug_reports/docs/overview.md.
 *
 * @version 1.1.0 - comment optional here: an automatic report has none
 * @version 1.0.0
 */

function report_submit_logic(array $input): LogicResult {
	$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
	$limit = max(1, (int)Globalvars::get_instance()->get_setting('bug_reports_rate_limit_requests', true, true) ?: 20);
	$state = RequestLogger::rate_limit_state('bug_reports', $limit, 3600);
	if (!$state['allowed']) {
		if (function_exists('api_rate_limited')) {
			api_rate_limited($state, 'This address', $limit, 3600, 'problem reports');
		}
		return LogicResult::error('Too many problem reports from this address. Try again later.');
	}

	$image = null;
	$image_problem = null;
	$upload = $_FILES['image'] ?? null;
	if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
		if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
			$image_problem = 'The image did not arrive whole and was not kept.';
		} elseif ((int)$upload['size'] > BugReportIntake::IMAGE_MAX_BYTES) {
			$image_problem = 'The image was larger than 5 MB and was not kept.';
		} else {
			$image = (string)file_get_contents($upload['tmp_name']);
		}
	}

	try {
		$report = BugReportIntake::receive((string)($input['bundle'] ?? ''), (string)($input['comment'] ?? ''), $image, $ip, $image_problem);
	} catch (BugReportRefusal $e) {
		RequestLogger::log('bug_reports', 'report_submit', false, array('status_code' => $e->status, 'note' => $e->getMessage()));
		if ($e->status === 429 && function_exists('api_error')) {
			api_error($e->getMessage(), 'RateLimitError', 429);
		}
		return LogicResult::error($e->getMessage());
	}

	RequestLogger::log('bug_reports', 'report_submit', true, array('status_code' => 200, 'note' => 'report ' . (int)$report->key));
	return LogicResult::render(array('report_id' => (int)$report->key));
}

function report_submit_logic_descriptor(): array {
	return array(
		'description'      => 'Receive a problem report from a site that upgrades from this one. Multipart form data: bundle (JSON), comment, optional image. Sessionless and rate-limited per address.',
		'requires_session' => false,
		'mutates'          => true,
		'input'            => array(
			'bundle'  => array('type' => 'text', 'required' => true, 'label' => 'Bundle', 'max_length' => 262144),
			// Required of a member's report, not an automatic one; the intake decides.
			'comment' => array('type' => 'text', 'required' => false, 'label' => 'Comment', 'max_length' => 5000),
		),
	);
}

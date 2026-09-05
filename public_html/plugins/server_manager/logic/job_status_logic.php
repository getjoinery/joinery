<?php
/**
 * server_manager/job_status — live job output polling.
 *
 * Input: job_id, output_offset. Returns status, new output tail, step counts,
 * and (once the job settles) the processed result. Superadmin only (floor 10).
 *
 * @version 1.3.0 - polls $job->transcript(), the same text the job page renders, so offsets agree
 * @version 1.2.0 - full-output redaction with offsets in redacted coordinates (no chunk-boundary leak)
 * @version 1.1.0
 */

function job_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmSecretRedactor.php'));

	$job_id        = isset($input['job_id']) ? (int) $input['job_id'] : 0;
	$output_offset = isset($input['output_offset']) ? (int) $input['output_offset'] : 0;

	if (!$job_id) {
		return LogicResult::render(['success' => false, 'message' => 'Missing job_id']);
	}

	try {
		$job = new ManagementJob($job_id, TRUE);
	} catch (Exception $e) {
		return LogicResult::render(['success' => false, 'message' => 'Job not found']);
	}

	// Redact the FULL output and keep offsets in redacted coordinates: slicing
	// first would let a secret straddling a poll-chunk boundary escape the
	// pattern match (its second half arrives unredacted). Redaction is
	// deterministic, so redacted offsets are stable across polls; the one edge
	// (a secret only half-written at the previous poll) can reflow a few
	// display characters, never leak.
	$full_output = SmSecretRedactor::redact($job->transcript() ?: '');
	$new_output = '';
	if ($output_offset < strlen($full_output)) {
		$new_output = substr($full_output, $output_offset);
	}

	$response = [
		'success'       => true,
		'status'        => $job->get('mjb_status'),
		'new_output'    => $new_output,
		'output_offset' => strlen($full_output),
		'current_step'  => intval($job->get('mjb_current_step')),
		'total_steps'   => intval($job->get('mjb_total_steps')),
		'error_message' => $job->get('mjb_error_message'),
	];

	if ($job->get('mjb_status') === 'completed' || $job->get('mjb_status') === 'failed') {
		if (!$job->get('mjb_result')) {
			JobResultProcessor::process($job);
			$job->load();
		}
		$result = $job->get('mjb_result');
		$response['result'] = $result ? json_decode($result, true) : null;
		$response['completed_time'] = $job->get('mjb_completed_time');
	}

	return LogicResult::render($response);
}

function job_status_logic_descriptor(): array {
	return [
		'description' => 'Poll a management job\'s live output and status.',
		'mutates'     => true,
		'requires_session'        => true,
		'auth'        => ['min_user_permission' => 10],
		'input'       => [
			'job_id'        => ['type' => 'int', 'required' => false, 'label' => 'Job ID'],
			'output_offset' => ['type' => 'int', 'required' => false, 'label' => 'Output offset'],
		],
	];
}
?>

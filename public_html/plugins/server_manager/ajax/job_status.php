<?php
/**
 * AJAX endpoint for live job output polling.
 *
 * Input: job_id (int), output_offset (int, character position)
 * Output: JSON with status, new_output, current_step, total_steps, result
 *
 * @version 1.0
 */
header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));

$session = SessionControl::get_instance();
if ($session->get_permission() < 10) {
	echo json_encode(['success' => false, 'message' => 'Permission denied']);
	exit;
}

$job_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
$output_offset = isset($_GET['output_offset']) ? intval($_GET['output_offset']) : 0;

if (!$job_id) {
	echo json_encode(['success' => false, 'message' => 'Missing job_id']);
	exit;
}

try {
	$job = new ManagementJob($job_id, TRUE);
} catch (Exception $e) {
	echo json_encode(['success' => false, 'message' => 'Job not found']);
	exit;
}

$full_output = $job->get('mjb_output') ?: '';
$new_output = '';
if ($output_offset < strlen($full_output)) {
	$new_output = substr($full_output, $output_offset);
}

$response = [
	'success'      => true,
	'status'       => $job->get('mjb_status'),
	'new_output'   => $new_output,
	'output_offset'=> strlen($full_output),
	'current_step' => intval($job->get('mjb_current_step')),
	'total_steps'  => intval($job->get('mjb_total_steps')),
	'error_message'=> $job->get('mjb_error_message'),
];

// If job just completed, run result processor and include result
if ($job->get('mjb_status') === 'completed' || $job->get('mjb_status') === 'failed') {
	if ($job->get('mjb_status') === 'completed' && !$job->get('mjb_result')) {
		JobResultProcessor::process($job);
		$job->load();
	}
	$result = $job->get('mjb_result');
	$response['result'] = $result ? json_decode($result, true) : null;
	$response['completed_time'] = $job->get('mjb_completed_time');
}

echo json_encode($response);
exit;
?>

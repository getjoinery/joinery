<?php
/**
 * AJAX endpoint: Create or poll a discover_nodes job.
 *
 * POST: Creates a new discovery job. Input: host, ssh_user, ssh_key_path, ssh_port
 *       Returns: {success: true, job_id: N}
 *
 * GET with job_id: Polls an existing discovery job for results.
 *       Returns: {success: true, status: ..., result: ...}
 *
 * @version 1.1
 */
header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));

$session = SessionControl::get_instance();
if ($session->get_permission() < 10) {
	echo json_encode(['success' => false, 'message' => 'Permission denied']);
	exit;
}

// GET: Poll for results of an existing discovery job
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['job_id'])) {
	$job_id = intval($_GET['job_id']);
	try {
		$job = new ManagementJob($job_id, TRUE);
	} catch (Exception $e) {
		echo json_encode(['success' => false, 'message' => 'Job not found']);
		exit;
	}

	$status = $job->get('mjb_status');

	if ($status === 'completed' && !$job->get('mjb_result')) {
		JobResultProcessor::process($job);
		$job->load();
	}

	$response = [
		'success' => true,
		'status' => $status,
	];

	if ($status === 'completed') {
		$result = $job->get('mjb_result');
		$response['result'] = $result ? json_decode($result, true) : null;
	} elseif ($status === 'failed') {
		$response['error_message'] = $job->get('mjb_error_message');
		$response['output'] = $job->get('mjb_output');
	}

	echo json_encode($response);
	exit;
}

// POST: Create a new discovery job
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	echo json_encode(['success' => false, 'message' => 'Use POST to create a discovery job, GET with job_id to poll']);
	exit;
}

$host = trim($_POST['host'] ?? '');
$ssh_user = trim($_POST['ssh_user'] ?? 'root');
$ssh_key_path = trim($_POST['ssh_key_path'] ?? '');
$ssh_port = intval($_POST['ssh_port'] ?? 22) ?: 22;

if (!$host) {
	echo json_encode(['success' => false, 'message' => 'Host is required']);
	exit;
}
if (!$ssh_key_path) {
	echo json_encode(['success' => false, 'message' => 'SSH key path is required']);
	exit;
}

// Basic input validation
if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $host) && !filter_var($host, FILTER_VALIDATE_IP)) {
	echo json_encode(['success' => false, 'message' => 'Invalid host format']);
	exit;
}

$params = [
	'host' => $host,
	'ssh_user' => $ssh_user,
	'ssh_key_path' => $ssh_key_path,
	'ssh_port' => $ssh_port,
];

$steps = JobCommandBuilder::build_discover_nodes($params);
$job = ManagementJob::createJob(null, 'discover_nodes', $steps, $params, $session->get_user_id());

echo json_encode(['success' => true, 'job_id' => $job->key]);
exit;
?>

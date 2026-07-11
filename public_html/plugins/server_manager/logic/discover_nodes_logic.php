<?php
/**
 * server_manager/discover_nodes — create or poll a node-discovery job.
 *
 * With job_id: poll an existing discovery job for results. Otherwise: create a
 * new discovery job from host/ssh_user/ssh_key_path/ssh_port. Superadmin only
 * (floor 10).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function discover_nodes_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));

	$session = SessionControl::get_instance();

	// Poll an existing discovery job.
	if (!empty($input['job_id'])) {
		$job_id = (int) $input['job_id'];
		try {
			$job = new ManagementJob($job_id, TRUE);
		} catch (Exception $e) {
			return LogicResult::render(['success' => false, 'message' => 'Job not found']);
		}

		$status = $job->get('mjb_status');
		if ($status === 'completed' && !$job->get('mjb_result')) {
			JobResultProcessor::process($job);
			$job->load();
		}

		$response = ['success' => true, 'status' => $status];
		if ($status === 'completed') {
			$result = $job->get('mjb_result');
			$response['result'] = $result ? json_decode($result, true) : null;
		} elseif ($status === 'failed') {
			$response['error_message'] = $job->get('mjb_error_message');
			$response['output'] = $job->get('mjb_output');
		}
		return LogicResult::render($response);
	}

	// Create a new discovery job.
	$host         = trim((string) ($input['host'] ?? ''));
	$ssh_user     = trim((string) ($input['ssh_user'] ?? 'root'));
	$ssh_key_path = trim((string) ($input['ssh_key_path'] ?? ''));
	$ssh_port     = intval($input['ssh_port'] ?? 22) ?: 22;

	if (!$host) {
		return LogicResult::render(['success' => false, 'message' => 'Host is required']);
	}
	if (!$ssh_key_path) {
		return LogicResult::render(['success' => false, 'message' => 'SSH key path is required']);
	}
	if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $host) && !filter_var($host, FILTER_VALIDATE_IP)) {
		return LogicResult::render(['success' => false, 'message' => 'Invalid host format']);
	}

	$params = [
		'host'         => $host,
		'ssh_user'     => $ssh_user,
		'ssh_key_path' => $ssh_key_path,
		'ssh_port'     => $ssh_port,
	];

	$steps = JobCommandBuilder::build_discover_nodes($params);
	$job = ManagementJob::createJob(null, 'discover_nodes', $steps, $params, $session->get_user_id());

	return LogicResult::render(['success' => true, 'job_id' => $job->key]);
}

function discover_nodes_logic_descriptor(): array {
	return [
		'description' => 'Create or poll a node-discovery job (host + SSH details, or job_id to poll).',
		'mutates'     => true,
		'auth'        => ['requires_session' => true, 'min_user_permission' => 10],
		'input'       => [
			'job_id'       => ['type' => 'int',    'required' => false, 'label' => 'Job ID (poll)'],
			'host'         => ['type' => 'string', 'required' => false, 'label' => 'Host'],
			'ssh_user'     => ['type' => 'string', 'required' => false, 'label' => 'SSH user'],
			'ssh_key_path' => ['type' => 'string', 'required' => false, 'label' => 'SSH key path'],
			'ssh_port'     => ['type' => 'int',    'required' => false, 'label' => 'SSH port'],
		],
	];
}
?>

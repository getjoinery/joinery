<?php
/**
 * AJAX endpoint for backup browser actions.
 *
 * Actions (via GET 'action'):
 *   refresh_list — creates a list_backups job, returns job_id
 *   delete_file  — creates a delete_backup job, returns job_id
 *   list_status  — returns cached backup list from node record
 *
 * @version 1.0
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

$action = isset($_GET['action']) ? $_GET['action'] : '';
$node_id = isset($_GET['node_id']) ? intval($_GET['node_id']) : 0;

if (!$node_id) {
	echo json_encode(['success' => false, 'message' => 'Missing node_id']);
	exit;
}

try {
	$node = new ManagedNode($node_id, TRUE);
} catch (Exception $e) {
	echo json_encode(['success' => false, 'message' => 'Node not found']);
	exit;
}

if ($action === 'refresh_list') {
	$steps = JobCommandBuilder::build_list_backups($node);
	$job = ManagementJob::createJob($node->key, 'list_backups', $steps, null, $session->get_user_id());
	echo json_encode(['success' => true, 'job_id' => $job->key]);
	exit;
}

if ($action === 'delete_file') {
	$target = isset($_GET['target']) ? $_GET['target'] : 'local';
	$local_path = isset($_GET['local_path']) ? $_GET['local_path'] : '';
	$cloud_path = isset($_GET['cloud_path']) ? $_GET['cloud_path'] : '';

	if (!$local_path && !$cloud_path) {
		echo json_encode(['success' => false, 'message' => 'No file path provided']);
		exit;
	}

	// Validate local_path is within /backups/ to prevent arbitrary file deletion
	if ($local_path && !preg_match('#^/backups/[^/]+$#', $local_path)) {
		echo json_encode(['success' => false, 'message' => 'Invalid local path']);
		exit;
	}

	$params = [
		'target' => $target,
		'local_path' => $local_path,
		'cloud_path' => $cloud_path,
		'filename' => basename($local_path ?: $cloud_path),
	];

	$steps = JobCommandBuilder::build_delete_backup($node, $params);
	$job = ManagementJob::createJob($node->key, 'delete_backup', $steps, $params, $session->get_user_id());
	echo json_encode(['success' => true, 'job_id' => $job->key]);
	exit;
}

if ($action === 'list_status') {
	$job_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;

	// If a job_id is given, check if it's done and process it
	if ($job_id) {
		try {
			$job = new ManagementJob($job_id, TRUE);
			$status = $job->get('mjb_status');
			if ($status === 'completed' && !$job->get('mjb_result')) {
				JobResultProcessor::process($job);
				$node->load(); // refresh cached data
			}
			if ($status !== 'pending' && $status !== 'running') {
				require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupListHelper.php'));
				$bl = BackupListHelper::get_for_node($node);
				echo json_encode([
					'success' => true,
					'status' => 'complete',
					'backup_list' => ['files' => $bl['files']],
					'last_scan' => $bl['last_scan'],
					'cloud_error' => $bl['cloud_error'],
				]);
				exit;
			}
			// Still running
			echo json_encode(['success' => true, 'status' => $status]);
			exit;
		} catch (Exception $e) {
			echo json_encode(['success' => false, 'message' => 'Job not found']);
			exit;
		}
	}

	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupListHelper.php'));
	$bl = BackupListHelper::get_for_node($node);
	echo json_encode([
		'success' => true,
		'status' => 'cached',
		'backup_list' => ['files' => $bl['files']],
		'last_scan' => $bl['last_scan'],
		'cloud_error' => $bl['cloud_error'],
	]);
	exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
exit;
?>

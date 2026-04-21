<?php
/**
 * AJAX endpoint: GET /ajax/refresh_node_status?node_id=N
 *
 * Dashboard auto-refresh. Calls /api/v1/management/stats on the node,
 * persists the parsed result to the node record, and returns the derived
 * badge color and version-compare state so the client can swap them in
 * without a page reload. No job record is created.
 *
 * Requires superadmin (level 10) — same gate as the Server Manager admin UI.
 *
 * @version 1.0
 */
header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

$session = SessionControl::get_instance();
if ($session->get_permission() < 10) {
	http_response_code(403);
	echo json_encode(['ok' => false, 'message' => 'Permission denied', 'reason' => 'auth']);
	exit;
}

$node_id = isset($_GET['node_id']) ? intval($_GET['node_id']) : 0;
if (!$node_id) {
	http_response_code(400);
	echo json_encode(['ok' => false, 'message' => 'Missing node_id', 'reason' => 'input']);
	exit;
}

try {
	$node = new ManagedNode($node_id, TRUE);
} catch (Exception $e) {
	http_response_code(404);
	echo json_encode(['ok' => false, 'message' => 'Node not found', 'reason' => 'input']);
	exit;
}

if (!JobCommandBuilder::has_api_creds($node)) {
	echo json_encode([
		'ok' => false, 'message' => 'No API credentials configured', 'reason' => 'config',
	]);
	exit;
}

$result = JobCommandBuilder::fetch_status_via_api($node, 5);

$response = [
	'ok'         => $result['ok'],
	'elapsed_ms' => $result['elapsed_ms'],
	'message'    => $result['message'],
	'reason'     => $result['reason'],
];

if ($result['ok']) {
	$data = $result['data'];
	$response['status_color'] = JobCommandBuilder::status_color_from_data($data);
	$response['version']      = $data['joinery_version'] ?? null;
	$response['last_check']   = LibraryFunctions::time_ago_or_time(
		$node->get('mgn_last_status_check'), 'UTC', $session->get_timezone(), 'M j, g:i A'
	);

	$cp_version = LibraryFunctions::get_joinery_version();
	$response['cp_version']  = $cp_version;
	$response['version_cmp'] = null;
	if ($response['version'] && $cp_version !== '' && preg_match('/^\d+\.\d+\.\d+$/', $response['version'])) {
		$response['version_cmp'] = version_compare($response['version'], $cp_version);
	}
}

echo json_encode($response);
exit;
?>

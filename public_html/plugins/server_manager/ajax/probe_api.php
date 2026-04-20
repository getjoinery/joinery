<?php
/**
 * AJAX endpoint: GET /ajax/probe_api?node_id=N
 *
 * Dashboard "API" indicator. Calls the node's /api/v1/management/health
 * endpoint and returns a plain result. Stores nothing — async probe, result
 * is consumed by dashboard JS after page render.
 *
 * Requires superadmin (level 10) — same gate as the Server Manager admin UI.
 *
 * @version 1.0
 */
header('Content-Type: application/json');

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
		'ok'         => false,
		'elapsed_ms' => 0,
		'message'    => 'No API credentials configured',
		'reason'     => 'config',
	]);
	exit;
}

$result = JobCommandBuilder::probe_api_health($node, 2);
echo json_encode($result);
exit;
?>

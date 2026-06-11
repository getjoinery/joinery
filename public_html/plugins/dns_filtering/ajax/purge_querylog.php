<?php
/**
 * Purge Query Log AJAX endpoint
 * Truncates a device's DNS query log via the ScrollDaddy DNS server API.
 *
 * Method: POST
 * Parameters: device_id (integer)
 *
 * Thin wrapper over purge_querylog_logic() — one copy of the rules, shared
 * with POST /api/v1/action/dns_filtering/purge_querylog.
 *
 * @version 2.0
 */

header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/logic/purge_querylog_logic.php'));

$result = purge_querylog_logic($_POST);

if ($result->error) {
	echo json_encode(array('success' => false, 'message' => $result->error));
	exit;
}

echo json_encode(array('success' => true));
exit;

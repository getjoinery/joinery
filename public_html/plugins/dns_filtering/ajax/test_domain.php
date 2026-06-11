<?php
/**
 * Test Domain AJAX endpoint
 * Proxies domain test requests to the ScrollDaddy DNS server's /test endpoint.
 *
 * Method: GET
 * Parameters: device_id, domain
 *
 * Thin wrapper over test_domain_logic() — one copy of the rules, shared
 * with POST /api/v1/action/dns_filtering/test_domain. This endpoint keeps
 * its GET contract for the web editor's JS.
 *
 * @version 2.0
 */

header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/logic/test_domain_logic.php'));

$result = test_domain_logic($_GET);

if ($result->error) {
	echo json_encode(array('success' => false, 'message' => $result->error));
	exit;
}

echo json_encode(array_merge(array('success' => true), $result->data));
exit;

<?php
/**
 * Scan URL AJAX endpoint
 * Fetches a web page, extracts all external domains from HTML resource
 * references, and checks each one against the ScrollDaddy DNS filter.
 *
 * Method: POST
 * Parameters: device_id, url
 *
 * Thin wrapper over scan_url_logic() — one copy of the rules (including the
 * SSRF target/redirect validation), shared with
 * POST /api/v1/action/dns_filtering/scan_url.
 *
 * @version 2.0
 */

header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/logic/scan_url_logic.php'));

$result = scan_url_logic($_POST);

if ($result->error) {
	echo json_encode(array('success' => false, 'message' => $result->error));
	exit;
}

echo json_encode(array_merge(array('success' => true), $result->data));
exit;

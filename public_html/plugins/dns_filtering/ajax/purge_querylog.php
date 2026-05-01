<?php
/**
 * Purge Query Log AJAX endpoint
 * Truncates a device's DNS query log via the ScrollDaddy DNS server API.
 *
 * Method: POST
 * Parameters: device_id (integer)
 *
 * @version 1.0
 */

header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/devices_class.php'));

$session  = SessionControl::get_instance();
$settings = Globalvars::get_instance();

if (!$session->get_user_id()) {
	echo json_encode(array('success' => false, 'message' => 'Not logged in'));
	exit;
}

$device_id = isset($_POST['device_id']) ? (int)$_POST['device_id'] : 0;
if (!$device_id) {
	echo json_encode(array('success' => false, 'message' => 'Missing device_id'));
	exit;
}

try {
	$device = new SdDevice($device_id, TRUE);
	$device->authenticate_read(array(
		'current_user_id'         => $session->get_user_id(),
		'current_user_permission' => $session->get_permission(),
	));
} catch (Exception $e) {
	echo json_encode(array('success' => false, 'message' => 'Device not found or access denied'));
	exit;
}

if (!$device->get('sdd_is_active')) {
	echo json_encode(array('success' => false, 'message' => 'Device is not active'));
	exit;
}

if (!$device->get('sdd_log_queries')) {
	echo json_encode(array('success' => false, 'message' => 'Query logging is not enabled for this device'));
	exit;
}

$resolver_uid = $device->get('sdd_resolver_uid');
$dns_url      = $settings->get_setting('dns_filtering_dns_internal_url');
$api_key      = $settings->get_setting('dns_filtering_dns_api_key');

if (!$dns_url || !$resolver_uid) {
	echo json_encode(array('success' => false, 'message' => 'DNS server not configured'));
	exit;
}

$purge_url = rtrim($dns_url, '/') . '/device/' . urlencode($resolver_uid) . '/log/purge';

$ch      = curl_init($purge_url);
$headers = array();
if ($api_key) {
	$headers[] = 'X-API-Key: ' . $api_key;
}
curl_setopt_array($ch, array(
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_POST           => true,
	CURLOPT_POSTFIELDS     => '',
	CURLOPT_TIMEOUT        => 10,
	CURLOPT_HTTPHEADER     => $headers,
));
$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $http_code < 200 || $http_code >= 300) {
	echo json_encode(array('success' => false, 'message' => 'DNS server did not respond. Please try again.'));
	exit;
}

echo json_encode(array('success' => true));
exit;

<?php
/**
 * Truncate a device's DNS query log via the ScrollDaddy DNS server API.
 *
 * Input: device_id.
 *
 * The web editor's page JS calls
 * POST /api/v1/action/dns_filtering/purge_querylog.
 *
 * @version 1.0
 */

function purge_querylog_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/devices_class.php'));

	$session  = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	if (!$session->get_user_id()) {
		return LogicResult::error('Not logged in');
	}

	$device_id = isset($input['device_id']) ? (int)$input['device_id'] : 0;
	if (!$device_id) {
		return LogicResult::error('Missing device_id');
	}

	try {
		$device = new SdDevice($device_id, TRUE);
		$device->authenticate_read(array(
			'current_user_id'         => $session->get_user_id(),
			'current_user_permission' => $session->get_permission(),
		));
	} catch (Exception $e) {
		return LogicResult::error('Device not found or access denied');
	}

	if (!$device->get('sdd_is_active')) {
		return LogicResult::error('Device is not active');
	}

	if (!$device->get('sdd_log_queries')) {
		return LogicResult::error('Query logging is not enabled for this device');
	}

	$resolver_uid = $device->get('sdd_resolver_uid');
	$dns_url      = $settings->get_setting('dns_filtering_dns_internal_url');
	$api_key      = $settings->get_setting('dns_filtering_dns_api_key');

	if (!$dns_url || !$resolver_uid) {
		return LogicResult::error('DNS server not configured');
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
		return LogicResult::error('DNS server did not respond. Please try again.');
	}

	return LogicResult::render(array());
}

function purge_querylog_logic_descriptor(): array {
	return [
		'description' => 'Truncate a device\'s DNS query log (device_id)',
		'mutates'     => true,
		'auth'        => ['requires_session' => true],
		'input'       => [
			'device_id' => ['type' => 'int', 'required' => false, 'label' => 'Device ID'],
		],
	];
}

?>

<?php

function querylog_logic($get_vars, $post_vars) {

	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/devices_class.php'));

	$session  = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	if (!$session->get_user_id()) {
		return LogicResult::redirect('/login');
	}

	$device_id = isset($get_vars['device_id']) ? (int)$get_vars['device_id'] : 0;
	if (!$device_id) {
		return LogicResult::redirect('/profile/devices');
	}

	try {
		$device = new SdDevice($device_id, TRUE);
		$device->authenticate_read(array(
			'current_user_id'         => $session->get_user_id(),
			'current_user_permission' => $session->get_permission(),
		));
	} catch (Exception $e) {
		return LogicResult::redirect('/profile/devices');
	}

	$page_vars = array(
		'device'          => $device,
		'device_name'     => htmlspecialchars($device->get_readable_name()),
		'lines'           => array(),
		'lines_requested' => 100,
		'fetch_error'     => false,
	);

	// Clamp lines_requested to supported values
	$allowed_lines = array(100, 250, 500);
	$requested     = isset($get_vars['lines']) ? (int)$get_vars['lines'] : 100;
	$lines_requested = 100;
	$min_diff        = PHP_INT_MAX;
	foreach ($allowed_lines as $n) {
		$diff = abs($n - $requested);
		if ($diff < $min_diff) {
			$min_diff        = $diff;
			$lines_requested = $n;
		}
	}
	$page_vars['lines_requested'] = $lines_requested;

	// Don't fetch if device is inactive or logging is off
	if (!$device->get('sdd_is_active') || !$device->get('sdd_log_queries')) {
		return LogicResult::render($page_vars);
	}

	$dns_url      = $settings->get_setting('scrolldaddy_dns_internal_url');
	$api_key      = $settings->get_setting('scrolldaddy_dns_api_key');
	$resolver_uid = $device->get('sdd_resolver_uid');

	if (!$dns_url || !$resolver_uid) {
		$page_vars['fetch_error'] = true;
		return LogicResult::render($page_vars);
	}

	$log_url = rtrim($dns_url, '/') . '/device/' . urlencode($resolver_uid) . '/log?lines=' . $lines_requested;

	$ch      = curl_init($log_url);
	$headers = array();
	if ($api_key) {
		$headers[] = 'X-API-Key: ' . $api_key;
	}
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 10,
		CURLOPT_HTTPHEADER     => $headers,
	));
	$response  = curl_exec($ch);
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($response === false || $http_code < 200 || $http_code >= 300 || trim($response) === '') {
		$page_vars['fetch_error'] = true;
		return LogicResult::render($page_vars);
	}

	// Parse tab-separated lines
	$raw_lines = explode("\n", trim($response));
	$parsed    = array();
	foreach ($raw_lines as $raw) {
		$raw = trim($raw);
		if ($raw === '') continue;
		$fields = explode("\t", $raw);
		if (count($fields) < 7) continue;
		$parsed[] = array(
			'timestamp' => $fields[0],
			'domain'    => $fields[1],
			'qtype'     => $fields[2],
			'result'    => $fields[3],
			'reason'    => $fields[4],
			'category'  => $fields[5],
			'cached'    => $fields[6],
		);
	}
	$page_vars['lines'] = $parsed;

	return LogicResult::render($page_vars);
}

?>

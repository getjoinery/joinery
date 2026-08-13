<?php
/**
 * Test one domain against a device's filter by proxying to the ScrollDaddy
 * DNS server's /test endpoint.
 *
 * Input: device_id, domain.
 *
 * The web editor's page JS calls
 * POST /api/v1/action/dns_filtering/test_domain.
 *
 * @version 1.0
 */

function test_domain_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/devices_class.php'));

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	// Must be logged in
	if (!$session->get_user_id()) {
		return LogicResult::error('Not logged in');
	}

	$device_id = isset($input['device_id']) ? (int)$input['device_id'] : 0;
	$domain = isset($input['domain']) ? trim($input['domain']) : '';

	if (!$device_id || $domain === '') {
		return LogicResult::error('Missing device_id or domain');
	}

	// Clean domain input: strip protocol, path, trailing dots, whitespace
	$domain = strtolower($domain);
	$domain = preg_replace('#^https?://#', '', $domain);
	$domain = preg_replace('/[\/\?#].*$/', '', $domain); // strip path/query/fragment
	$domain = rtrim($domain, '.');
	$domain = trim($domain);

	if ($domain === '' || strpos($domain, '.') === false) {
		return LogicResult::error('Invalid domain');
	}

	// Load device and verify ownership
	try {
		$device = new SdDevice($device_id, TRUE);
		$device->assert_can_read($session);
	} catch (Exception $e) {
		return LogicResult::error('Device not found or access denied');
	}

	$resolver_uid = $device->get('sdd_resolver_uid');
	if (!$resolver_uid) {
		return LogicResult::error('Device has not been activated yet');
	}

	// Call DNS server /test endpoint
	$dns_url = $settings->get_setting('dns_filtering_dns_internal_url');
	$api_key = $settings->get_setting('dns_filtering_dns_api_key');

	if (!$dns_url) {
		return LogicResult::error('DNS server not configured');
	}

	$test_url = rtrim($dns_url, '/') . '/test?' . http_build_query(array(
		'uid' => $resolver_uid,
		'domain' => $domain,
	));

	$ch = curl_init($test_url);
	$headers = array();
	if ($api_key) {
		$headers[] = 'X-API-Key: ' . $api_key;
	}
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 10,
		CURLOPT_HTTPHEADER     => $headers,
	));
	$response = curl_exec($ch);
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

	if ($response === false || $http_code < 200 || $http_code >= 300) {
		return LogicResult::error('DNS server is not responding. The test could not be completed.');
	}

	$data = json_decode($response, true);
	if (!$data) {
		return LogicResult::error('Invalid response from DNS server');
	}

	// Category display names
	$category_names = array(
		'ads_small'    => 'Ads (Light)',
		'ads_medium'   => 'Ads (Medium)',
		'ads'          => 'Ads (Strict)',
		'malware'      => 'Malware',
		'ip_malware'   => 'Malware + IP Threats',
		'ai_malware'   => 'Malware + Phishing',
		'typo'         => 'Phishing & Typosquatting',
		'porn'         => 'Adult Content',
		'porn_strict'  => 'Adult Content (Strict)',
		'gambling'     => 'Gambling',
		'social'       => 'Social Media',
		'fakenews'     => 'Disinformation',
		'cryptominers' => 'Cryptomining',
		'dating'       => 'Dating',
		'drugs'        => 'Drugs',
		'games'        => 'Gaming',
		'ddns'         => 'Dynamic DNS',
		'dnsvpn'       => 'DNS/VPN Bypass',
	);

	// Build user-friendly response
	$result = array(
		'domain'  => $data['domain'],
		'result'  => $data['result'],   // BLOCKED, FORWARDED, REFUSED, etc.
		'reason'  => $data['reason'],
	);

	// Reason-specific details
	$reason = $data['reason'];
	if ($reason === 'category_blocklist' && isset($data['category'])) {
		$cat_key = $data['category'];
		$result['detail'] = 'Matched category: ' . (isset($category_names[$cat_key]) ? $category_names[$cat_key] : $cat_key);
	} elseif ($reason === 'custom_block_rule' && isset($data['matched_rule'])) {
		$result['detail'] = 'Matched custom block rule: ' . $data['matched_rule'];
	} elseif ($reason === 'custom_allow_rule' && isset($data['matched_rule'])) {
		$result['detail'] = 'Matched custom allow rule: ' . $data['matched_rule'];
	} elseif ($reason === 'safesearch_rewrite') {
		$result['detail'] = 'SafeSearch is enabled. Domain is rewritten to enforce safe results.';
	} elseif ($reason === 'safeyoutube_rewrite') {
		$result['detail'] = 'SafeYouTube is enabled. Domain is rewritten to restricted mode.';
	} elseif ($reason === 'not_blocked') {
		$result['detail'] = 'Not matched by any filter or rule. Queries are forwarded to upstream DNS.';
	} elseif ($reason === 'unknown_device') {
		$result['detail'] = 'Device not found by DNS server. It may not have synced yet.';
	} elseif ($reason === 'inactive_device') {
		$result['detail'] = 'Device is deactivated.';
	}

	return LogicResult::render($result);
}

function test_domain_logic_descriptor(): array {
	return [
		'description' => 'Test one domain against a device\'s filter (device_id, domain)',
		'mutates'     => false,
		'requires_session'        => true,
		'input'       => [
			'device_id' => ['type' => 'int',    'required' => false, 'label' => 'Device ID'],
			'domain'    => ['type' => 'string', 'required' => false, 'label' => 'Domain'],
		],
	];
}

?>

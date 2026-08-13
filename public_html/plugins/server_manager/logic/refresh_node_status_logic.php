<?php
/**
 * server_manager/refresh_node_status — dashboard auto-refresh.
 *
 * Calls /api/v1/management/stats on a node (or falls back to a plain HTTP status
 * check), persists the parsed result to the node record, and returns the derived
 * badge color and version-compare state so the client can swap them in without a
 * reload. No job record is created. Superadmin only (floor 10).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function refresh_node_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

	$session = SessionControl::get_instance();

	$node_id = isset($input['node_id']) ? (int) $input['node_id'] : 0;
	if (!$node_id) {
		return LogicResult::render(['ok' => false, 'message' => 'Missing node_id', 'reason' => 'input']);
	}

	try {
		$node = new ManagedNode($node_id, TRUE);
	} catch (Exception $e) {
		return LogicResult::render(['ok' => false, 'message' => 'Node not found', 'reason' => 'input']);
	}

	$use_api = JobCommandBuilder::has_api_creds($node) && !$node->get('mgn_skip_joinery_checks');

	if ($use_api) {
		$result = JobCommandBuilder::fetch_status_via_api($node, 5);

		$response = [
			'ok'         => $result['ok'],
			'elapsed_ms' => $result['elapsed_ms'],
			'message'    => $result['message'],
			'reason'     => $result['reason'],
		];

		if ($result['ok']) {
			$data = $result['data'];
			$response['status_color'] = JobCommandBuilder::status_color_for_node($node, $data);
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

		return LogicResult::render($response);
	}

	// No API creds or skip_joinery_checks: fall back to a plain HTTP status check.
	$health_url = trim((string) $node->get('mgn_health_check_url'));
	if ($health_url === '') {
		$site_url = rtrim((string) $node->get('mgn_site_url'), '/');
		$health_url = $site_url !== '' ? $site_url . '/' : '';
	}
	if ($health_url === '') {
		return LogicResult::render(['ok' => false, 'message' => 'No site URL configured', 'reason' => 'config']);
	}

	$start = microtime(true);
	$node_version_from_header = null;
	$ch = curl_init($health_url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_NOBODY         => true,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT        => 5,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS      => 5,
		CURLOPT_SSL_VERIFYPEER => $node->get('mgn_tls_insecure') ? false : true,
		CURLOPT_SSL_VERIFYHOST => $node->get('mgn_tls_insecure') ? 0 : 2,
	]);
	curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$node_version_from_header) {
		if (stripos($header, 'X-Joinery-Version:') === 0) {
			$v = trim(substr($header, strlen('X-Joinery-Version:')));
			if ($v !== '') $node_version_from_header = $v;
		}
		return strlen($header);
	});
	curl_exec($ch);
	$errno      = curl_errno($ch);
	$errmsg     = curl_error($ch);
	$status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$elapsed_ms = intval(round((microtime(true) - $start) * 1000));

	$is_up = !$errno && $status >= 200 && $status < 400;
	if ($is_up) {
		$node->set('mgn_last_status_check', gmdate('Y-m-d H:i:s'));
		if ($node_version_from_header) {
			$node->set('mgn_joinery_version', $node_version_from_header);
		}
		$node->save();
		$response = [
			'ok'           => true,
			'elapsed_ms'   => $elapsed_ms,
			'status_color' => 'success',
			'last_check'   => LibraryFunctions::time_ago_or_time(
				$node->get('mgn_last_status_check'), 'UTC', $session->get_timezone(), 'M j, g:i A'
			),
		];
		if ($node_version_from_header) {
			$cp_version = LibraryFunctions::get_joinery_version();
			$response['version']     = $node_version_from_header;
			$response['cp_version']  = $cp_version;
			$response['version_cmp'] = ($cp_version !== '' && preg_match('/^\d+\.\d+\.\d+$/', $node_version_from_header))
				? version_compare($node_version_from_header, $cp_version)
				: null;
		}
		return LogicResult::render($response);
	}

	return LogicResult::render([
		'ok'         => false,
		'elapsed_ms' => $elapsed_ms,
		'message'    => $errno ? ($errmsg ?: 'transport failure') : 'HTTP ' . $status,
		'reason'     => $errno ? 'transport' : 'status',
	]);
}

function refresh_node_status_logic_descriptor(): array {
	return [
		'description' => 'Refresh a managed node\'s live status badge (management/stats or HTTP fallback).',
		'mutates'     => true,
		'requires_session'        => true,
		'auth'        => ['min_user_permission' => 10],
		'input'       => [
			'node_id' => ['type' => 'int', 'required' => false, 'label' => 'Node ID'],
		],
	];
}
?>

<?php
/**
 * server_manager/refresh_node_status — dashboard auto-refresh.
 *
 * Calls /api/v1/management/stats on a node (or falls back to a plain HTTP status
 * check), folds the parsed result into the node record, and returns the derived
 * badge color and version-compare state so the client can swap them in without a
 * reload. No job record is created. Superadmin only (floor 10).
 *
 * Both branches report "last check" as the time the figures were last MEASURED,
 * read from the status blob's per-key provenance. The fallback branch reads a
 * version header off a HEAD request and learns nothing else about the node, so
 * that is all it stamps.
 *
 * @version 1.1.1 - the badge is derived from the folded node record, not the raw API response:
 *                  the staleness test reads provenance, which a raw response does not carry
 * @version 1.1.0 - the HTTP fallback folds the version it measured instead of stamping
 *                  mgn_last_status_check, which dated every figure in the blob to the click
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
			// The FOLDED blob, not $data. fetch_status_via_api returns what this
			// call measured, which carries no provenance of its own; the badge
			// reads provenance, so handing it the raw response would grey out a
			// node whose figures were taken a moment ago.
			$folded = json_decode((string)$node->get('mgn_last_status_data'), true);
			$response['status_color'] = JobCommandBuilder::status_color_for_node($node,
				is_array($folded) ? $folded : $data);
			$response['version']      = $data['joinery_version'] ?? null;
			// The API call just measured these, so this reads as now — but it
			// reads it from the provenance rather than asserting it, so the one
			// number means the same thing on both branches.
			$response['last_check']   = LibraryFunctions::time_ago_or_time(
				JobResultProcessor::status_last_measured($node->get('mgn_last_status_data'))
					?: $node->get('mgn_last_status_check'),
				'UTC', $session->get_timezone(), 'M j, g:i A'
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
		// This branch reads a header off a HEAD request. It learns the node's
		// version and that the node answers — it reads no disk, no load, no
		// postgres — so it stamps the version and nothing else. Stamping
		// mgn_last_status_check here would date every figure in the node's status
		// blob to this click, which is the same false freshness the uptime probe
		// used to write on every tick.
		if ($node_version_from_header) {
			$node->set('mgn_joinery_version', $node_version_from_header);
			$node->set('mgn_last_status_data', json_encode(
				JobResultProcessor::fold_status_data(
					$node->get('mgn_last_status_data'),
					['joinery_version' => $node_version_from_header],
					'probe')));
		}
		$node->save();
		$response = [
			'ok'           => true,
			'elapsed_ms'   => $elapsed_ms,
			'status_color' => 'success',
			// When the figures were last measured, not when this probe ran.
			'last_check'   => LibraryFunctions::time_ago_or_time(
				JobResultProcessor::status_last_measured($node->get('mgn_last_status_data'))
					?: $node->get('mgn_last_status_check'),
				'UTC', $session->get_timezone(), 'M j, g:i A'
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

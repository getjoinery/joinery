<?php
/**
 * server_manager/probe_api — dashboard "API" indicator.
 *
 * Calls a node's /api/v1/management/health endpoint and returns a plain result
 * (ok/elapsed_ms/message/reason). Stores nothing. Superadmin only (floor 10).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function probe_api_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

	$node_id = isset($input['node_id']) ? (int) $input['node_id'] : 0;
	if (!$node_id) {
		return LogicResult::render(['ok' => false, 'message' => 'Missing node_id', 'reason' => 'input']);
	}

	try {
		$node = new ManagedNode($node_id, TRUE);
	} catch (Exception $e) {
		return LogicResult::render(['ok' => false, 'message' => 'Node not found', 'reason' => 'input']);
	}

	if (!JobCommandBuilder::has_api_creds($node)) {
		return LogicResult::render([
			'ok'         => false,
			'elapsed_ms' => 0,
			'message'    => 'No API credentials configured',
			'reason'     => 'config',
		]);
	}

	return LogicResult::render(JobCommandBuilder::probe_api_health($node, 2));
}

function probe_api_logic_descriptor(): array {
	return [
		'description' => 'Probe a managed node\'s management/health endpoint (dashboard API indicator).',
		'mutates'     => false,
		'auth'        => ['requires_session' => true, 'min_user_permission' => 10],
		'input'       => [
			'node_id' => ['type' => 'int', 'required' => false, 'label' => 'Node ID'],
		],
	];
}
?>

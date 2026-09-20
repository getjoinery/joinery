<?php
/**
 * shelf_begin_run - a site asks the shelf broker to take a backup run.
 *
 * (specs/services_phase2_platform.md §3). Over the connected key. The plane
 * checks the tenant is usable and that the ledger's bytes plus the declared
 * sizes fit inside the allowance, then answers a run id and the run's base
 * key — or refuses with the sentence the site's run history records as its
 * cause. Nothing is signed here; nothing moves.
 *
 * @version 1.0
 */
function shelf_begin_run_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}
	$artifacts = $input['artifacts'] ?? array();
	if (is_string($artifacts)) {
		$artifacts = json_decode($artifacts, true);
	}
	try {
		$row = ShelfBroker::tenantFor($user_id, intval($session->get_api_key_id()));
		$data = ShelfBroker::beginRun($row, trim((string)($input['profile'] ?? 'site')),
			trim((string)($input['chain'] ?? '')), is_array($artifacts) ? $artifacts : array());
	} catch (ShelfBrokerException $e) {
		return LogicResult::error($e->getMessage());
	}
	return LogicResult::render($data);
}

function shelf_begin_run_logic_descriptor(): array {
	return array(
		'description'      => 'Open a backup run on the operator\'s shelf: declares the profile, chain and artifacts (names and sizes); answers a run id and base key, or refuses with the cause.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'profile'   => array('type' => 'string', 'required' => false, 'label' => 'Backup profile (site or manager)'),
			'chain'     => array('type' => 'string', 'required' => false, 'label' => 'Chain id'),
			'artifacts' => array('type' => 'array',  'required' => true,  'label' => 'Artifacts: [{name, bytes}]', 'max_items' => 500,
				'items' => array(
					'name'  => array('type' => 'string',  'required' => true,  'label' => 'Object name'),
					'bytes' => array('type' => 'integer', 'required' => false, 'label' => 'Size in bytes'),
				)),
		),
	);
}
?>

<?php
/**
 * shelf_verified_run - the site reports that a verify of its chain, read
 * through one finished run, passed (specs/storage_targets.md F1).
 *
 * Retention keeps the customer's newest verified chain and everything newer,
 * whatever their age, and a customer with nothing verified for eight days is
 * an incident on this management node.
 *
 * @version 1.0
 */
function shelf_verified_run_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}
	try {
		$row = ShelfBroker::tenantFor($user_id, intval($session->get_api_key_id()));
		$data = ShelfBroker::verifiedRun($row, intval($input['run_id'] ?? 0));
	} catch (ShelfBrokerException $e) {
		return LogicResult::error($e->getMessage());
	}
	return LogicResult::render($data);
}

function shelf_verified_run_logic_descriptor(): array {
	return array(
		'description'      => 'Report that a verify of the chain a finished backup storage run belongs to passed. Retention keeps the newest verified chain and everything newer.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'run_id' => array('type' => 'integer', 'required' => true, 'label' => 'Run id'),
		),
	);
}
?>

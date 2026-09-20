<?php
/**
 * shelf_finish_run - the site closes a run and names what it completed.
 *
 * (specs/services_phase2_platform.md §3). The ledger marks the named
 * objects complete; anything signed for the run and not named stays
 * uncompleted for the prune pass to abort. The tenant's figure is refreshed.
 *
 * @version 1.0
 */
function shelf_finish_run_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}
	$completed = $input['completed'] ?? array();
	if (is_string($completed)) {
		$completed = json_decode($completed, true);
	}
	try {
		$row = ShelfBroker::tenantFor($user_id, intval($session->get_api_key_id()));
		$data = ShelfBroker::finishRun($row, intval($input['run_id'] ?? 0), is_array($completed) ? $completed : array());
	} catch (ShelfBrokerException $e) {
		return LogicResult::error($e->getMessage());
	}
	return LogicResult::render($data);
}

function shelf_finish_run_logic_descriptor(): array {
	return array(
		'description'      => 'Close a shelf run, naming the objects the site completed ([{name, bytes}]). The ledger marks them complete and the figure is refreshed.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'run_id'    => array('type' => 'integer', 'required' => true, 'label' => 'Run id'),
			'completed' => array('type' => 'array',   'required' => true, 'label' => 'Completed objects: [{name, bytes}]', 'max_items' => 500,
				'items' => array(
					'name'  => array('type' => 'string',  'required' => true,  'label' => 'Object name'),
					'bytes' => array('type' => 'integer', 'required' => false, 'label' => 'Size in bytes'),
				)),
		),
	);
}
?>

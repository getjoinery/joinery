<?php
/**
 * shelf_finish_run - the site closes a run and names what it completed.
 *
 * (specs/services_phase2_platform.md §3). The ledger marks each named object
 * complete with the size and sha256 the site reports — one named without its
 * hash is not completed — and cancels everything else signed for the run. A
 * completed key is never signed again. The run's ledger file goes to backup
 * storage and the tenant's figure is refreshed (specs/storage_targets.md §6, F4).
 *
 * @version 1.1 - each completed object carries its sha256
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
		'description'      => 'Close a backup storage run, naming the objects the site completed ([{name, bytes, sha256}]). The ledger marks them complete with their hashes, cancels the rest, and the figure is refreshed.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'run_id'    => array('type' => 'integer', 'required' => true, 'label' => 'Run id'),
			'completed' => array('type' => 'array',   'required' => true, 'label' => 'Completed objects: [{name, bytes, sha256}]', 'max_items' => 500,
				'items' => array(
					'name'   => array('type' => 'string',  'required' => true,  'label' => 'Object name'),
					'bytes'  => array('type' => 'integer', 'required' => false, 'label' => 'Size in bytes'),
					'sha256' => array('type' => 'string',  'required' => true,  'label' => 'The object\'s sha256, hex'),
				)),
		),
	);
}
?>

<?php
/**
 * shelf_sign - one presigned URL (or a batch of part URLs) for one object of
 * an open run.
 *
 * (specs/services_phase2_platform.md §3). A write names an open run and a
 * key inside its base key; anything outside it is refused, as is any
 * operation the broker does not know — there is no delete. A get needs no
 * run: with run_id 0 the name is relative to the site's own prefix, as
 * shelf_list answers it, and it is signed for a suspended or released site
 * for as long as its copies exist. Operations: put, get, multipart_create,
 * multipart_parts (upload_id, first, count ≤ 10), multipart_complete
 * (upload_id). Each URL is good for one hour.
 *
 * @version 1.1 - a get needs no run and stands until the prune
 */
function shelf_sign_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}
	try {
		$row = ShelfBroker::tenantFor($user_id, intval($session->get_api_key_id()));
		$data = ShelfBroker::sign($row, intval($input['run_id'] ?? 0), (string)($input['name'] ?? ''),
			trim((string)($input['operation'] ?? '')), array(
				'bytes'     => intval($input['bytes'] ?? 0),
				'upload_id' => (string)($input['upload_id'] ?? ''),
				'first'     => intval($input['first'] ?? 1),
				'count'     => intval($input['count'] ?? ShelfBroker::PARTS_PER_BATCH),
			));
	} catch (ShelfBrokerException $e) {
		return LogicResult::error($e->getMessage());
	} catch (ShelfPresignerException $e) {
		return LogicResult::error('Backup storage could not sign that request: ' . $e->getMessage());
	}
	return LogicResult::render($data);
}

function shelf_sign_logic_descriptor(): array {
	return array(
		'description'      => 'A presigned URL for one backup storage object: put, multipart_create, multipart_parts (a batch of up to ten part URLs) or multipart_complete inside an open run; get inside the site\'s own prefix (run_id 0) or a run\'s base key, allowed until the site\'s copies are pruned. Never a delete.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'run_id'    => array('type' => 'integer', 'required' => false, 'label' => 'Run id (a write needs an open one; a get may pass 0)'),
			'name'      => array('type' => 'string',  'required' => true,  'label' => 'Object name, relative to the run\'s base key (to the site\'s prefix for a get with run_id 0)'),
			'operation' => array('type' => 'string',  'required' => true,  'label' => 'put | get | multipart_create | multipart_parts | multipart_complete'),
			'bytes'     => array('type' => 'integer', 'required' => false, 'label' => 'Size, for put and multipart_create'),
			'upload_id' => array('type' => 'string',  'required' => false, 'label' => 'Multipart upload id'),
			'first'     => array('type' => 'integer', 'required' => false, 'label' => 'First part number of the batch'),
			'count'     => array('type' => 'integer', 'required' => false, 'label' => 'Parts in the batch (≤ 10)'),
		),
	);
}
?>

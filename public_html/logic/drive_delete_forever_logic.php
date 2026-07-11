<?php

function drive_delete_forever_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));
	require_once(PathHelper::getIncludePath('data/file_changes_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$entity_type = (string)($input['entity_type'] ?? '');
	$entity_id   = (int)($input['entity_id'] ?? 0);
	$confirm     = !empty($input['confirm']);

	if ($entity_type !== DriveHelper::ENTITY_FILE && $entity_type !== DriveHelper::ENTITY_FOLDER) {
		return LogicResult::error('Invalid entity type.');
	}

	$entity = DriveHelper::load_entity($entity_type, $entity_id);
	if (!$entity) {
		return LogicResult::error('Item not found.');
	}
	if (!DriveHelper::owns($entity_type, $entity, $user_id)) {
		return LogicResult::error('You do not have permission to delete this item.');
	}

	$impact = DriveHelper::delete_impact($entity_type, $entity);

	// First call is a dry run: return the impact summary so the UI can confirm.
	if (!$confirm) {
		return LogicResult::render(array(
			'ok'      => true,
			'confirm_required' => true,
			'impact'  => $impact,
		));
	}

	DriveHelper::permanent_delete_tree($entity_type, $entity);
	DriveUsage::recompute($user_id);

	FileChange::record(FileChange::KIND_DELETED, $entity_type, $entity_id, $user_id, $user_id);

	return LogicResult::render(array(
		'ok'      => true,
		'deleted' => true,
		'impact'  => $impact,
	));
}

function drive_delete_forever_logic_descriptor(): array {
	return array(
		'description'      => 'Permanently delete a Drive file or folder subtree. Owner only. Call once for an impact preview, then again with confirm=true.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'entity_type' => array('type' => 'string', 'required' => true, 'enum' => array('file', 'folder'), 'label' => 'Entity type'),
			'entity_id'   => array('type' => 'int', 'required' => true, 'label' => 'Entity id'),
			'confirm'     => array('type' => 'bool', 'required' => false, 'label' => 'Confirm permanent deletion'),
		),
	);
}
?>

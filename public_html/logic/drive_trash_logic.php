<?php

function drive_trash_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
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

	if ($entity_type !== DriveHelper::ENTITY_FILE && $entity_type !== DriveHelper::ENTITY_FOLDER) {
		return LogicResult::error('Invalid entity type.');
	}

	$entity = DriveHelper::load_entity($entity_type, $entity_id);
	if (!$entity) {
		return LogicResult::error('Item not found.');
	}
	// Trash (a soft delete) is owner-only — an editor grant never grants delete.
	if (!DriveHelper::owns($entity_type, $entity, $user_id)) {
		return LogicResult::error('You do not have permission to delete this item.');
	}

	if ($entity_type === DriveHelper::ENTITY_FOLDER) {
		DriveHelper::soft_delete_folder_cascade($entity);
	} else {
		$entity->soft_delete();
	}

	FileChange::record(FileChange::KIND_TRASHED, $entity_type, $entity_id, $user_id, $user_id);

	return LogicResult::render(array('ok' => true));
}

function drive_trash_logic_descriptor(): array {
	return array(
		'description'      => 'Move a Drive file or folder to the trash (soft delete). Owner only.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'entity_type' => array('type' => 'string', 'required' => true, 'enum' => array('file', 'folder'), 'label' => 'Entity type'),
			'entity_id'   => array('type' => 'int', 'required' => true, 'label' => 'Entity id'),
		),
	);
}
?>

<?php

function drive_move_logic(array $input): LogicResult {
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
	$parent_id   = (isset($input['parent_id']) && (int)$input['parent_id'] > 0) ? (int)$input['parent_id'] : 0;

	if ($entity_type !== DriveHelper::ENTITY_FILE && $entity_type !== DriveHelper::ENTITY_FOLDER) {
		return LogicResult::error('Invalid entity type.');
	}

	$entity = DriveHelper::load_entity($entity_type, $entity_id);
	if (!$entity) {
		return LogicResult::error('Item not found.');
	}
	if (!DriveHelper::can_write($entity_type, $entity, $user_id, $session->get_permission())) {
		return LogicResult::error('You do not have permission to move this item.');
	}

	if ($parent_id > 0) {
		$target = DriveHelper::load_folder($parent_id);
		if (!$target) {
			return LogicResult::error('Destination folder not found.');
		}
		if (!DriveHelper::can_write(DriveHelper::ENTITY_FOLDER, $target, $user_id, $session->get_permission())) {
			return LogicResult::error('You do not have access to the destination folder.');
		}
		// Single-owner-tree rule: an item may only live under a folder owned by
		// the item's owner. Without this, the destination owner's trash /
		// delete-forever cascades (which select by folder) would operate on
		// another user's rows.
		if (DriveHelper::owner_id_of($entity_type, $entity) !== (int)$target->get('fol_usr_user_id')) {
			return LogicResult::error('Items can only be moved within their owner\'s Drive.');
		}
	}

	if ($entity_type === DriveHelper::ENTITY_FOLDER) {
		if (DriveHelper::would_create_cycle($entity_id, $parent_id)) {
			return LogicResult::error('You cannot move a folder into itself or one of its subfolders.');
		}
		$base_depth = DriveHelper::depth($parent_id) + 1;
		if ($base_depth + DriveHelper::subtree_height($entity_id) > DriveHelper::max_depth()) {
			return LogicResult::error('That move would exceed the maximum folder depth.');
		}
		$owner_id = (int)$entity->get('fol_usr_user_id');
		if (DriveHelper::folder_name_taken($owner_id, $parent_id, $entity->get('fol_name'), $entity_id)) {
			return LogicResult::error('A folder with that name already exists in the destination.');
		}
		$entity->set('fol_parent_folder_id', $parent_id > 0 ? $parent_id : null);
		$entity->save();
		$owner = $owner_id;
	} else {
		$entity->set('fil_fol_folder_id', $parent_id > 0 ? $parent_id : null);
		$entity->save();
		$owner = (int)$entity->get('fil_usr_user_id');
	}

	FileChange::record(FileChange::KIND_MOVED, $entity_type, $entity_id, $owner, $user_id);

	return LogicResult::render(array(
		'ok'   => true,
		'item' => ($entity_type === DriveHelper::ENTITY_FOLDER)
			? DriveHelper::folder_export($entity)
			: DriveHelper::file_export($entity),
	));
}

function drive_move_logic_descriptor(): array {
	return array(
		'description'      => 'Move a Drive file or folder to another folder (or the root).',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'entity_type' => array('type' => 'string', 'required' => true, 'enum' => array('file', 'folder'), 'label' => 'Entity type'),
			'entity_id'   => array('type' => 'int', 'required' => true, 'label' => 'Entity id'),
			'parent_id'   => array('type' => 'int', 'required' => false, 'label' => 'Destination folder id (omit for root)'),
		),
	);
}
?>

<?php

function drive_restore_logic(array $input): LogicResult {
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
	if (!DriveHelper::owns($entity_type, $entity, $user_id)) {
		return LogicResult::error('You do not have permission to restore this item.');
	}

	if ($entity_type === DriveHelper::ENTITY_FOLDER) {
		// If the folder's parent is itself still trashed, re-root the folder so
		// it doesn't come back inside a folder that remains in the trash (which
		// would make it unreachable in every view). Root sibling-name collisions
		// are resolved with a suffix.
		$parent_id = (int)$entity->get('fol_parent_folder_id');
		if ($parent_id > 0) {
			$parent = DriveHelper::load_folder($parent_id);
			if (!$parent || ($parent->get('fol_delete_time') !== null && $parent->get('fol_delete_time') !== '')) {
				$owner_id = (int)$entity->get('fol_usr_user_id');
				$name = $entity->get('fol_name');
				if (DriveHelper::folder_name_taken($owner_id, 0, $name, (int)$entity->key)) {
					$base = $name . ' (restored)';
					$name = $base;
					for ($i = 2; DriveHelper::folder_name_taken($owner_id, 0, $name, (int)$entity->key) && $i < 100; $i++) {
						$name = $base . ' ' . $i;
					}
					$entity->set('fol_name', substr($name, 0, 255));
				}
				$entity->set('fol_parent_folder_id', null);
				if (!DriveHelper::save_folder_unless_name_taken($entity)) {
					// Something else claimed the free name between the search
					// above and here. The folder is still safely in the trash.
					return LogicResult::error('That name was taken while restoring. Try again.');
				}
			}
		}
		DriveHelper::restore_folder_cascade($entity);
	} else {
		// If the file's folder is itself trashed, restore it to the root so it
		// doesn't reappear inside a folder that is still in the trash.
		$folder_id = (int)$entity->get('fil_fol_folder_id');
		if ($folder_id > 0) {
			$folder = DriveHelper::load_folder($folder_id);
			if ($folder && $folder->get('fol_delete_time') !== null && $folder->get('fol_delete_time') !== '') {
				$entity->set('fil_fol_folder_id', null);
			}
		}
		$entity->undelete();
	}

	FileChange::record(FileChange::KIND_RESTORED, $entity_type, $entity_id, $user_id, $user_id);

	return LogicResult::render(array('ok' => true));
}

function drive_restore_logic_descriptor(): array {
	return array(
		'description'      => 'Restore a Drive file or folder from the trash. Owner only.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'entity_type' => array('type' => 'string', 'required' => true, 'enum' => array('file', 'folder'), 'label' => 'Entity type'),
			'entity_id'   => array('type' => 'int', 'required' => true, 'label' => 'Entity id'),
		),
	);
}
?>

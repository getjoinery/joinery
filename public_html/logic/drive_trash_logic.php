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
		return LogicResult::error('You do not have permission to delete this item.', array('standing' => true));
	}

	// A sync client sends the change it decided this trash at. Anything the
	// trash would take that changed after it -- an edit or a new file that
	// reached the server after the client last read the change feed -- the
	// client never saw, and taking it would leave somebody's work only in the
	// trash. Refused instead; the client reads what changed and decides again
	// (drive_trash_unchanged_since). Checked and marked under the owner's lock,
	// so nothing can land in between.
	$since = (isset($input['unchanged_since']) && $input['unchanged_since'] !== '' && $input['unchanged_since'] !== null)
		? (int)$input['unchanged_since'] : null;
	$owner_id = (int)$entity->get($entity_type === DriveHelper::ENTITY_FOLDER ? 'fol_usr_user_id' : 'fil_usr_user_id');

	DriveHelper::owner_trash_lock($owner_id);
	try {
		if ($since !== null) {
			$newest = DriveHelper::trash_changed_since($entity_type, $entity, $since);
			if ($newest !== null) {
				return LogicResult::error('Something in it changed after you last looked; it was not moved to the trash.',
					array('reason' => 'changed_since', 'newest' => $newest));
			}
		}
		if ($entity_type === DriveHelper::ENTITY_FOLDER) {
			DriveHelper::soft_delete_folder_cascade($entity);
		} else {
			$entity->soft_delete();
		}
		FileChange::record(FileChange::KIND_TRASHED, $entity_type, $entity_id, $user_id, $user_id);
	} finally {
		DriveHelper::owner_trash_unlock($owner_id);
	}

	$out = array('ok' => true);
	if ($since !== null) {
		$out['checked_since'] = $since;
	}
	return LogicResult::render($out);
}

function drive_trash_logic_descriptor(): array {
	return array(
		'description'      => 'Move a Drive file or folder to the trash (soft delete). Owner only.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'input'            => array(
			'entity_type' => array('type' => 'string', 'required' => true, 'enum' => array('file', 'folder'), 'label' => 'Entity type'),
			'entity_id'   => array('type' => 'int', 'required' => true, 'label' => 'Entity id'),
			'unchanged_since' => array('type' => 'int', 'required' => false, 'label' => 'Change the trash was decided at; refused if anything it would take changed after'),
		),
	);
}
?>

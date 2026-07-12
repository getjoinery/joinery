<?php

function drive_rename_logic(array $input): LogicResult {
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
	$name        = trim((string)($input['name'] ?? ''));
	// Opaque client-produced payload for encrypted files (docs/drive_encryption.md):
	// the re-encrypted metadata blob carrying the new name. Passes the boundary
	// untouched (not declared in the input schema).
	$enc_metadata = isset($input['encrypted_metadata']) ? (string)$input['encrypted_metadata'] : '';

	if ($entity_type !== DriveHelper::ENTITY_FILE && $entity_type !== DriveHelper::ENTITY_FOLDER) {
		return LogicResult::error('Invalid entity type.');
	}
	if (mb_strlen($name) > 255) {
		return LogicResult::error('That name is too long.');
	}

	$entity = DriveHelper::load_entity($entity_type, $entity_id);
	if (!$entity) {
		return LogicResult::error('Item not found.');
	}
	if (!DriveHelper::can_write($entity_type, $entity, $user_id, $session->get_permission())) {
		return LogicResult::error('You do not have permission to rename this item.');
	}

	// An encrypted file's display name lives INSIDE fil_encrypted_metadata; its
	// fil_title stays the opaque enc-… value forever. The client decrypts the
	// metadata, swaps the name, re-encrypts with the same file key, and submits
	// the blob — a plaintext name here would leak the secret name to the server.
	$is_encrypted_file = ($entity_type === DriveHelper::ENTITY_FILE) && $entity->is_encrypted();
	if ($is_encrypted_file) {
		if ($name !== '') {
			return LogicResult::error('Encrypted files are renamed via their encrypted metadata, not a plaintext name.');
		}
		if ($enc_metadata === '') {
			return LogicResult::error('Encrypted rename is missing its metadata.');
		}
	} elseif ($name === '') {
		return LogicResult::error('A name is required.');
	}

	if ($entity_type === DriveHelper::ENTITY_FOLDER) {
		$parent_id = (int)$entity->get('fol_parent_folder_id');
		$owner_id  = (int)$entity->get('fol_usr_user_id');
		if (DriveHelper::folder_name_taken($owner_id, $parent_id, $name, (int)$entity->get('fol_folder_id'))) {
			return LogicResult::error('A folder with that name already exists here.');
		}
		$entity->set('fol_name', $name);
		$entity->save();
		$owner = $owner_id;
	} elseif ($is_encrypted_file) {
		$entity->set('fil_encrypted_metadata', $enc_metadata);
		$entity->save();
		$owner = (int)$entity->get('fil_usr_user_id');
	} else {
		$entity->set('fil_title', $name);
		$entity->save();
		$owner = (int)$entity->get('fil_usr_user_id');
	}

	FileChange::record(FileChange::KIND_RENAMED, $entity_type, $entity_id, $owner, $user_id);

	return LogicResult::render(array(
		'ok'   => true,
		'item' => ($entity_type === DriveHelper::ENTITY_FOLDER)
			? DriveHelper::folder_export($entity)
			: DriveHelper::file_export($entity),
	));
}

function drive_rename_logic_descriptor(): array {
	return array(
		'description'      => 'Rename a Drive file or folder. Plaintext items take `name`. An encrypted file takes `encrypted_metadata` instead (the FK-encrypted metadata blob with the new name, produced in the browser; a plaintext `name` is refused so the secret name never reaches the server).',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'entity_type' => array('type' => 'string', 'required' => true, 'enum' => array('file', 'folder'), 'label' => 'Entity type'),
			'entity_id'   => array('type' => 'int', 'required' => true, 'label' => 'Entity id'),
			'name'        => array('type' => 'string', 'required' => false, 'max_length' => 255, 'label' => 'New name (plaintext items)'),
		),
	);
}
?>

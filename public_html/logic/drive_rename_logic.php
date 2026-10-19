<?php

function drive_rename_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/file_changes_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.', array('reason' => 'drive_disabled'));
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.', array('reason' => 'signed_out'));
	}

	$entity_type = (string)($input['entity_type'] ?? '');
	$entity_id   = (int)($input['entity_id'] ?? 0);
	$name        = trim((string)($input['name'] ?? ''));
	// Opaque client-produced payload for encrypted files (docs/drive_encryption.md):
	// the re-encrypted metadata blob carrying the new name. Passes the boundary
	// untouched (not declared in the input schema).
	$enc_metadata = isset($input['encrypted_metadata']) ? (string)$input['encrypted_metadata'] : '';

	if ($entity_type !== DriveHelper::ENTITY_FILE && $entity_type !== DriveHelper::ENTITY_FOLDER) {
		return LogicResult::error('Invalid entity type.', array('reason' => 'invalid_request', 'standing' => true));
	}
	if (mb_strlen($name) > 255) {
		return LogicResult::error('That name is too long.', array('reason' => 'name_too_long', 'standing' => true));
	}

	$entity = DriveHelper::load_entity($entity_type, $entity_id);
	if (!$entity) {
		return LogicResult::error('Item not found.', array('reason' => 'not_found'));
	}
	if (!DriveHelper::can_write($entity_type, $entity, $user_id, $session->get_permission())) {
		return LogicResult::error('You do not have permission to rename this item.', array('reason' => 'forbidden', 'standing' => true));
	}

	// An encrypted file's display name lives INSIDE fil_encrypted_metadata; its
	// fil_title stays the opaque enc-… value forever. The client decrypts the
	// metadata, swaps the name, re-encrypts with the same file key, and submits
	// the blob — a plaintext name here would leak the secret name to the server.
	$is_encrypted_file = ($entity_type === DriveHelper::ENTITY_FILE) && $entity->is_encrypted();
	if ($is_encrypted_file) {
		if ($name !== '') {
			return LogicResult::error('Encrypted files are renamed via their encrypted metadata, not a plaintext name.', array('reason' => 'invalid_request', 'standing' => true));
		}
		if ($enc_metadata === '') {
			return LogicResult::error('Encrypted rename is missing its metadata.', array('reason' => 'invalid_request', 'standing' => true));
		}
	} elseif ($name === '') {
		return LogicResult::error('A name is required.', array('reason' => 'invalid_request', 'standing' => true));
	}

	// The name the client last saw the item under (`from_name`). A client that
	// decided this rename before another one reached the server would otherwise
	// put its name back over that one unseen; refused, it hears the newer name
	// and settles the two as changes made on both sides are settled. A name
	// already the one asked for is no conflict. Plaintext names only: an
	// encrypted file's title is its opaque placeholder whatever it is called.
	$from_name = isset($input['from_name']) ? (string)$input['from_name'] : '';
	if ($from_name !== '' && !$is_encrypted_file) {
		$current_name = (string)$entity->get($entity_type === DriveHelper::ENTITY_FOLDER ? 'fol_name' : 'fil_title');
		if ($current_name !== $from_name && $current_name !== $name) {
			return LogicResult::error('That item has been renamed since.', array('reason' => 'moved_since'));
		}
	}

	if ($entity_type === DriveHelper::ENTITY_FOLDER) {
		$parent_id = (int)$entity->get('fol_parent_folder_id');
		$owner_id  = (int)$entity->get('fol_usr_user_id');
		$holder = DriveHelper::folder_name_holder($owner_id, $parent_id, $name, (int)$entity->get('fol_folder_id'));
		if ($holder !== null) {
			return LogicResult::error('A folder with that name already exists here.', DriveHelper::name_taken_data($holder, $user_id));
		}
		$entity->set('fol_name', $name);
		if (!DriveHelper::save_folder_unless_name_taken($entity)) {
			$holder = DriveHelper::folder_name_holder($owner_id, $parent_id, $name, (int)$entity->get('fol_folder_id'));
			return LogicResult::error('A folder with that name already exists here.', DriveHelper::name_taken_data($holder, $user_id));
		}
		$owner = $owner_id;
	} elseif ($is_encrypted_file) {
		$entity->set('fil_encrypted_metadata', $enc_metadata);
		$entity->save();
		$owner = (int)$entity->get('fil_usr_user_id');
	} else {
		$folder_id = (int)$entity->get('fil_fol_folder_id');
		$owner_id  = (int)$entity->get('fil_usr_user_id');
		$holder = DriveHelper::file_name_holder($owner_id, $folder_id, $name, (int)$entity->key);
		if ($holder !== null) {
			return LogicResult::error('A file with that name already exists here.', DriveHelper::name_taken_data($holder, $user_id));
		}
		$entity->set('fil_title', $name);
		if (!DriveHelper::save_file_unless_name_taken($entity)) {
			$holder = DriveHelper::file_name_holder($owner_id, $folder_id, $name, (int)$entity->key);
			return LogicResult::error('A file with that name already exists here.', DriveHelper::name_taken_data($holder, $user_id));
		}
		$owner = $owner_id;
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
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'input'            => array(
			'entity_type' => array('type' => 'string', 'required' => true, 'enum' => array('file', 'folder'), 'label' => 'Entity type'),
			'entity_id'   => array('type' => 'int', 'required' => true, 'label' => 'Entity id'),
			'name'        => array('type' => 'string', 'required' => false, 'max_length' => 255, 'label' => 'New name (plaintext items)'),
			'from_name'   => array('type' => 'string', 'required' => false, 'max_length' => 255, 'label' => 'Name the item was last seen under (plaintext items); refused as moved_since if it is called something else now'),
		),
	);
}
?>

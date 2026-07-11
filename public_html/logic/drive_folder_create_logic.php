<?php

function drive_folder_create_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/folders_class.php'));
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

	$name = trim((string)($input['name'] ?? ''));
	if ($name === '') {
		return LogicResult::error('Folder name is required.');
	}
	if (mb_strlen($name) > 255) {
		return LogicResult::error('Folder name is too long.');
	}

	$parent_id = (isset($input['parent_id']) && (int)$input['parent_id'] > 0) ? (int)$input['parent_id'] : 0;

	if ($parent_id > 0) {
		$parent = DriveHelper::load_folder($parent_id);
		if (!$parent) {
			return LogicResult::error('Parent folder not found.');
		}
		if (!DriveHelper::can_write(DriveHelper::ENTITY_FOLDER, $parent, $user_id, $session->get_permission())) {
			return LogicResult::error('You do not have access to that folder.');
		}
		if (DriveHelper::depth($parent_id) + 1 > DriveHelper::max_depth()) {
			return LogicResult::error('Maximum folder depth reached.');
		}
	}

	if (DriveHelper::folder_name_taken($user_id, $parent_id, $name)) {
		return LogicResult::error('A folder with that name already exists here.');
	}

	$folder = new Folder(NULL);
	$folder->set('fol_usr_user_id', $user_id);
	if ($parent_id > 0) {
		$folder->set('fol_parent_folder_id', $parent_id);
	}
	$folder->set('fol_name', $name);
	$folder->save();
	$folder->load(); // repopulate serial pkey + default columns for the export

	FileChange::record(FileChange::KIND_CREATED, DriveHelper::ENTITY_FOLDER, $folder->key, $user_id, $user_id);

	return LogicResult::render(array(
		'ok'     => true,
		'folder' => DriveHelper::folder_export($folder),
	));
}

function drive_folder_create_logic_descriptor(): array {
	return array(
		'description'      => 'Create a folder in the current user\'s Drive.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'name'      => array('type' => 'string', 'required' => true, 'max_length' => 255, 'label' => 'Folder name'),
			'parent_id' => array('type' => 'int', 'required' => false, 'label' => 'Parent folder id (omit for root)'),
		),
	);
}
?>

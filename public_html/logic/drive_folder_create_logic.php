<?php

function drive_folder_create_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
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

	// Protection is a property of the subtree, and a protected tree is a
	// top-level tree: under a protected parent the level is always inherited; at
	// the root the caller chooses it. Raising the level part-way down a tree is
	// refused, which is what lets a public link on a Standard folder trust its
	// whole subtree, and matches the move-boundary rule in drive_move.
	//
	// `encrypted` is the Fortress spelling the browser client uses.
	$level = ProtectionLevel::normalize($input['protection_level'] ?? null);
	if (!empty($input['encrypted'])) {
		$level = ProtectionLevel::FORTRESS;
	}
	if (!in_array($level, ProtectionLevel::DRIVE_LEVELS, true)) {
		return LogicResult::error('That is not a protection level Drive offers.');
	}

	if ($parent_id > 0) {
		$parent = DriveHelper::load_folder($parent_id);
		if (!$parent) {
			return LogicResult::error('Parent folder not found.');
		}
		if (!DriveHelper::can_write(DriveHelper::ENTITY_FOLDER, $parent, $user_id, $session->get_permission())) {
			return LogicResult::error('You do not have access to that folder.');
		}
		if (DriveHelper::folder_is_trashed($parent)) {
			return LogicResult::error('That folder is in the trash.', array('reason' => 'parent_trashed'));
		}
		if (DriveHelper::depth($parent_id) + 1 > DriveHelper::max_depth()) {
			return LogicResult::error('Maximum folder depth reached.');
		}
		$parent_level = DriveHelper::folder_level($parent);
		if ($parent_level !== ProtectionLevel::STANDARD) {
			$level = $parent_level; // inherited — a protected subtree stays uniform
		} elseif ($level !== ProtectionLevel::STANDARD) {
			return LogicResult::error(ProtectionLevel::label($level)
				. ' folders can only be created at the Drive root, or inside another '
				. ProtectionLevel::label($level) . ' folder.');
		}
	}

	// A Private folder seals to its owner's server-custody vault (scope 'user' —
	// the same one mail and chat seal to), so the owner must have one. Refuse at
	// creation rather than letting the first upload fail with a file in flight.
	if ($level === ProtectionLevel::PRIVATE_) {
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		if (UserEncryptionVault::loadForUser($user_id, UserEncryptionVault::SCOPE_USER) === null) {
			return LogicResult::error('Set up your vault before creating a Private folder.');
		}
	}

	if (DriveHelper::folder_name_taken($user_id, $parent_id, $name)) {
		return LogicResult::error('A folder with that name already exists here.', array('reason' => 'name_taken'));
	}

	$folder = new Folder(NULL);
	$folder->set('fol_usr_user_id', $user_id);
	if ($parent_id > 0) {
		$folder->set('fol_parent_folder_id', $parent_id);
	}
	$folder->set('fol_name', $name);
	$folder->set('fol_protection_level', $level);
	if (!DriveHelper::save_folder_unless_name_taken($folder)) {
		return LogicResult::error('A folder with that name already exists here.', array('reason' => 'name_taken'));
	}
	$folder->load(); // repopulate serial pkey + default columns for the export

	FileChange::record(FileChange::KIND_CREATED, DriveHelper::ENTITY_FOLDER, $folder->key, $user_id, $user_id);

	return LogicResult::render(array(
		'ok'     => true,
		'folder' => DriveHelper::folder_export($folder),
	));
}

function drive_folder_create_logic_descriptor(): array {
	return array(
		'description'      => 'Create a folder in the current user\'s Drive. `protection_level` is one of standard / private / fortress and is accepted only at the root: a folder under a protected parent inherits that parent\'s level, and raising the level part-way down a tree is refused (a protected tree is a top-level tree). `encrypted` is the older spelling for fortress and still works. A private folder requires the owner to have a vault.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'input'            => array(
			'name'             => array('type' => 'string', 'required' => true, 'max_length' => 255, 'label' => 'Folder name'),
			'parent_id'        => array('type' => 'int', 'required' => false, 'label' => 'Parent folder id (omit for root)'),
			'protection_level' => array('type' => 'string', 'required' => false, 'max_length' => 16, 'label' => 'Protection level (standard, private, fortress)'),
			'encrypted'        => array('type' => 'bool', 'required' => false, 'label' => 'Create as a Fortress (end-to-end encrypted) folder'),
		),
	);
}
?>

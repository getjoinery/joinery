<?php

/**
 * drive_version_restore — make a saved version the file's current content. The
 * current head is demoted to a new version; write access required.
 */

function drive_version_restore_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/file_versions_class.php'));
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

	$file_id    = (int)($input['file_id'] ?? 0);
	$version_id = (int)($input['version_id'] ?? 0);

	$file = DriveHelper::load_file($file_id);
	if (!$file) {
		return LogicResult::error('File not found.');
	}
	if (!DriveHelper::can_write(DriveHelper::ENTITY_FILE, $file, $user_id, $session->get_permission())) {
		return LogicResult::error('You do not have permission to update that file.');
	}

	$version = new FileVersion($version_id, true);
	if (!$version->key || (int)$version->get('fvr_fil_file_id') !== $file_id) {
		return LogicResult::error('Version not found for this file.');
	}

	FileVersion::restore_version($file, $version, $user_id);
	DriveUsage::recompute((int)$file->get('fil_usr_user_id'));
	FileChange::record(FileChange::KIND_CONTENT, DriveHelper::ENTITY_FILE, $file->key, (int)$file->get('fil_usr_user_id'), $user_id);
	DriveHelper::forget_sync_meta($file->key);

	$fresh = DriveHelper::load_file($file->key);
	return LogicResult::render(array('ok' => true, 'file' => DriveHelper::file_export($fresh)));
}

function drive_version_restore_logic_descriptor(): array {
	return array(
		'description'      => 'Restore a saved version as a Drive file\'s current content.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'file_id'    => array('type' => 'int', 'required' => true, 'label' => 'File id'),
			'version_id' => array('type' => 'int', 'required' => true, 'label' => 'Version id to restore'),
		),
	);
}
?>

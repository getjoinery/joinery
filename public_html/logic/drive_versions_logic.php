<?php

/**
 * drive_versions — list a Drive file's saved versions (newest first). Read-only.
 * The head (current content) is not a version row; it is shown separately by the
 * UI.
 */

function drive_versions_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/file_versions_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$file_id = (int)($input['file_id'] ?? 0);
	$file = DriveHelper::load_file($file_id);
	if (!$file) {
		return LogicResult::error('File not found.');
	}
	if (!DriveHelper::can_read(DriveHelper::ENTITY_FILE, $file, $user_id, $session->get_permission())) {
		return LogicResult::error('You do not have access to that file.');
	}

	$versions = new MultiFileVersion(array('file_id' => $file_id), array('fvr_version_number' => 'DESC'));
	$versions->load();

	$out = array();
	foreach ($versions as $v) {
		$out[] = array(
			'version_id'     => (int)$v->key,
			'version_number' => (int)$v->get('fvr_version_number'),
			'size'           => (int)$v->get('fvr_size_bytes'),
			'create_time'    => $v->get('fvr_create_time'),
			'saved_by'       => (int)$v->get('fvr_usr_user_id'),
		);
	}

	return LogicResult::render(array('ok' => true, 'file_id' => $file_id, 'versions' => $out));
}

function drive_versions_logic_descriptor(): array {
	return array(
		'description'      => 'List a Drive file\'s saved versions.',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array('capability' => 'read'),
		'input'            => array(
			'file_id' => array('type' => 'int', 'required' => true, 'label' => 'File id'),
		),
	);
}
?>

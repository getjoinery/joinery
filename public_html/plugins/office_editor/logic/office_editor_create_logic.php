<?php
/**
 * office_editor/office_editor_create — a blank document or spreadsheet in a
 * Drive folder, opened in the editor next. Drive's New menu calls it (the
 * creators registered in includes/bootstrap.php).
 *
 * The blank comes from the plugin's templates/ and lands the way an upload
 * does: owned by the folder's owner, at the folder's level (Standard only),
 * under the owner's quota and write locks, with a `created` change.
 *
 * @version 0.1.0
 */

function office_editor_create_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('You must be signed in.', array('reason' => 'signed_out'));
	}
	if (!Globalvars::get_instance()->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.', array('reason' => 'drive_disabled'));
	}
	if (!OfficeEditor::installed()) {
		return LogicResult::error('The document editor is not available on this site.', array('reason' => 'unavailable', 'standing' => true));
	}
	if (!OfficeEditor::memberMay($user_id)) {
		return LogicResult::error('Editing documents in Drive isn\'t part of your plan.', array('reason' => 'not_in_plan', 'standing' => true));
	}

	$kinds = array(
		'document'    => array('ext' => 'docx', 'template' => 'blank.docx',
			'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
		'spreadsheet' => array('ext' => 'xlsx', 'template' => 'blank.xlsx',
			'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
	);
	$kind = (string)($input['kind'] ?? '');
	if (!isset($kinds[$kind])) {
		return LogicResult::error('Choose a document or a spreadsheet.', array('reason' => 'invalid_request', 'standing' => true));
	}
	$k = $kinds[$kind];

	$name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', ' ', (string)($input['name'] ?? '')));
	if ($name === '') {
		return LogicResult::error('A name is required.', array('reason' => 'invalid_request', 'standing' => true));
	}
	if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $k['ext']) {
		$name .= '.' . $k['ext'];
	}
	if (mb_strlen($name) > 255) {
		return LogicResult::error('That name is too long.', array('reason' => 'name_too_long', 'standing' => true));
	}

	// The destination decides the owner and the level, never the caller.
	$folder_id = (int)($input['folder_id'] ?? 0);
	$folder = null;
	$owner_id = $user_id;
	if ($folder_id > 0) {
		$folder = DriveHelper::load_folder($folder_id);
		if (!$folder || DriveHelper::folder_is_trashed($folder)
				|| !DriveHelper::can_write(DriveHelper::ENTITY_FOLDER, $folder, $user_id, $session->get_permission())) {
			return LogicResult::error('You can\'t add files to that folder.', array('reason' => 'forbidden', 'standing' => true));
		}
		$owner_id = (int)$folder->get('fol_usr_user_id');
	}
	$why = OfficeEditor::creatorAvailable($folder, $user_id);
	if ($why !== true) {
		return LogicResult::error(is_string($why) ? $why : 'You can\'t make a document here.', array('reason' => 'forbidden', 'standing' => true));
	}

	$holder = DriveHelper::file_name_holder($owner_id, $folder_id, $name);
	if ($holder !== null) {
		return LogicResult::error('A file with that name already exists here.', DriveHelper::name_taken_data($holder, $user_id));
	}

	$template = PathHelper::getIncludePath('plugins/office_editor/templates/' . $k['template']);
	$tmp = tempnam(sys_get_temp_dir(), 'oe_new_');
	if ($tmp === false || !@copy($template, $tmp)) {
		return LogicResult::error('The blank file could not be made.', array('reason' => 'storage_failed'));
	}

	DriveHelper::quota_lock($owner_id);
	$owner_locked = false;
	try {
		$quota = (int)SubscriptionTier::getUserFeature($owner_id, 'drive_storage_bytes', 0);
		$current = DriveUsage::recompute($owner_id);
		if ($quota > 0 && $current + (int)filesize($tmp) > $quota) {
			return LogicResult::error('There is no room left in this Drive.', array('reason' => 'quota', 'standing' => true));
		}
		$restrictions = array(
			'fil_private'          => true,
			'fil_source'           => File::SOURCE_DRIVE,
			'fil_protection_level' => ProtectionLevel::STANDARD,
			'fil_fol_folder_id'    => $folder_id ?: null,
		);
		DriveHelper::owner_write_lock($owner_id);
		$owner_locked = true;
		try {
			$file = DriveHelper::place_into($folder_id, function () use ($tmp, $name, $k, $owner_id, $restrictions) {
				return File::createFromUpload($tmp, $name, $k['mime'], $owner_id, $restrictions, false);
			});
		} catch (DriveDestinationTrashedException $e) {
			return LogicResult::error('That folder is in the trash.', array('reason' => 'parent_trashed', 'folder_id' => $folder_id));
		} catch (Exception $e) {
			// Two creates racing for one name: the partial unique index refuses
			// the loser, answered as the name clash it is.
			$holder = strpos($e->getMessage(), '23505') === false ? null : DriveHelper::file_name_holder($owner_id, $folder_id, $name);
			if ($holder === null) {
				throw $e;
			}
			return LogicResult::error('A file with that name already exists here.', DriveHelper::name_taken_data($holder, $user_id));
		}
		DriveUsage::recompute($owner_id);
		FileChange::record(FileChange::KIND_CREATED, DriveHelper::ENTITY_FILE, $file->key, $owner_id, $user_id);
		DriveHelper::owner_write_unlock($owner_id);
		$owner_locked = false;
		DriveHelper::forget_sync_meta($file->key);
	} finally {
		if ($owner_locked) {
			DriveHelper::owner_write_unlock($owner_id);
		}
		DriveHelper::quota_unlock($owner_id);
		if (is_file($tmp)) {
			@unlink($tmp);
		}
	}

	return LogicResult::render(array(
		'ok'   => true,
		'url'  => OfficeEditor::pageUrl($file->key),
		'file' => DriveHelper::file_export($file),
	));
}

function office_editor_create_logic_descriptor(): array {
	return array(
		'description'      => 'Make a blank document (.docx) or spreadsheet (.xlsx) in a Drive folder and return the page that opens it in the document editor.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'input'            => array(
			'kind'      => array('type' => 'string', 'required' => true, 'max_length' => 16, 'label' => 'document or spreadsheet'),
			'name'      => array('type' => 'string', 'required' => true, 'max_length' => 255, 'label' => 'File name (the extension is added when missing)'),
			'folder_id' => array('type' => 'int', 'required' => false, 'label' => 'Drive folder (omit for the top of My Drive)'),
		),
	);
}

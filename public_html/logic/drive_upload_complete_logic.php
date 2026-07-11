<?php

/**
 * drive_upload_complete — finish a resumable Drive upload.
 *
 * Verifies the assembled part-file (byte count, and sha256 when the client
 * committed to one), ingests it through the single blob path (dedup applies),
 * creates the File — or a new FileVersion when the upload targeted an existing
 * file — recomputes usage, records the change, and clears the pending row.
 * Retry-safe via the standard Idempotency-Key machinery.
 */

function drive_upload_complete_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
	require_once(PathHelper::getIncludePath('data/file_uploads_class.php'));
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

	$token = (string)($input['upload_token'] ?? '');
	$up = FileUpload::load_by_token($token);
	if (!$up || (int)$up->get('fup_usr_user_id') !== $user_id) {
		return LogicResult::error('Upload not found or already completed.');
	}

	$part = $up->part_path();
	if ((int)$up->get('fup_received_bytes') !== (int)$up->get('fup_expected_bytes')) {
		return LogicResult::error('Upload is incomplete.');
	}
	if (!is_file($part)) {
		// A genuinely empty (0-byte) upload never sends a chunk; materialize it.
		if ((int)$up->get('fup_expected_bytes') === 0) {
			@touch($part);
		} else {
			$up->discard();
			return LogicResult::error('Uploaded bytes are missing; please retry.');
		}
	}
	if ((int)filesize($part) !== (int)$up->get('fup_expected_bytes')) {
		return LogicResult::error('Uploaded size does not match; please retry.');
	}
	$expected_sha = (string)$up->get('fup_expected_sha256');
	if ($expected_sha !== '' && hash_file('sha256', $part) !== $expected_sha) {
		return LogicResult::error('Uploaded content failed its checksum; please retry.');
	}

	$folder_id = (int)$up->get('fup_fol_folder_id');
	$file_id   = (int)$up->get('fup_fil_file_id');
	$mime      = (string)($up->get('fup_mime_type') ?: 'application/octet-stream');
	$name      = (string)$up->get('fup_display_name');

	// Re-resolve the target and its OWNER (billed; single-owner-tree rule) —
	// access may have been revoked between init and complete.
	$target_file = null;
	$owner_id = $user_id;
	if ($file_id) {
		$target_file = DriveHelper::load_file($file_id);
		if (!$target_file || !DriveHelper::can_write(DriveHelper::ENTITY_FILE, $target_file, $user_id, $session->get_permission())) {
			$up->discard();
			return LogicResult::error('You can no longer update that file.');
		}
		$owner_id = (int)$target_file->get('fil_usr_user_id');
	} elseif ($folder_id) {
		$folder = DriveHelper::load_folder($folder_id);
		if (!$folder || !DriveHelper::can_write(DriveHelper::ENTITY_FOLDER, $folder, $user_id, $session->get_permission())) {
			$up->discard();
			return LogicResult::error('You can no longer upload into that folder.');
		}
		$owner_id = (int)$folder->get('fol_usr_user_id');
	}

	// Quota is enforced HERE, where bytes are admitted to storage — the init
	// check is only a fast-fail. Serialized per owner so uploads opened while
	// under quota cannot all land past it. The pending row is kept on rejection
	// (the user can free space and retry; the stale-upload task cleans up).
	$expected = (int)$up->get('fup_expected_bytes');
	DriveHelper::quota_lock($owner_id);
	try {
		require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
		$quota = (int)SubscriptionTier::getUserFeature($owner_id, 'drive_storage_bytes', 0);
		$current = DriveUsage::recompute($owner_id);
		if ($quota <= 0 || $current + $expected > $quota) {
			return LogicResult::error('That upload would exceed the storage quota.');
		}

		if ($target_file) {
			// New version of an existing file.
			$blob = FileBlob::createFromPath($part, $mime, true);
			FileVersion::save_new_content($target_file, $blob, $user_id);
			$up->discard();
			DriveUsage::recompute($owner_id);
			FileChange::record(FileChange::KIND_CONTENT, DriveHelper::ENTITY_FILE, $target_file->key, $owner_id, $user_id);
			$fresh = DriveHelper::load_file($target_file->key);
			return LogicResult::render(array('ok' => true, 'file' => DriveHelper::file_export($fresh)));
		}

		// New file — owned by the folder's owner (or the actor at root).
		$file = File::createFromUpload($part, $name, $mime, $owner_id, array(
			'fil_private' => true,
			'fil_source'  => File::SOURCE_DRIVE,
		));
		if (!$file || !$file->key) {
			$up->discard();
			return LogicResult::error('The file could not be stored.');
		}
		if ($folder_id) {
			$file->set('fil_fol_folder_id', $folder_id);
			$file->save();
		}
		if ($file->is_image()) {
			$file->resize('all');
		}
		$up->discard();
		DriveUsage::recompute($owner_id);
		FileChange::record(FileChange::KIND_CREATED, DriveHelper::ENTITY_FILE, $file->key, $owner_id, $user_id);

		return LogicResult::render(array('ok' => true, 'file' => DriveHelper::file_export($file)));
	} finally {
		DriveHelper::quota_unlock($owner_id);
	}
}

function drive_upload_complete_logic_descriptor(): array {
	return array(
		'description'      => 'Finish a resumable Drive upload and create the file (or a new version).',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'upload_token' => array('type' => 'string', 'required' => true, 'max_length' => 64, 'label' => 'Upload token'),
		),
	);
}
?>

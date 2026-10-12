<?php

/**
 * upload_complete — finish a resumable upload opened by upload_init.
 *
 * The same verification and ingest as drive_upload_complete, minus Drive's
 * feature gate. The purpose is read from the upload, never from the request; an
 * upload opened for Drive is refused here.
 *
 * @version 1.0
 */

function upload_complete_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/UploadPurposeRegistry.php'));
	require_once(PathHelper::getIncludePath('data/file_uploads_class.php'));
	require_once(PathHelper::getIncludePath('logic/drive_upload_complete_logic.php'));

	$session = SessionControl::get_instance();
	$up = FileUpload::load_by_token((string)($input['upload_token'] ?? ''));
	if (!$up || (int)$up->get('fup_usr_user_id') !== (int)$session->get_user_id()) {
		return LogicResult::error('Upload not found or already completed.');
	}
	if (UploadPurposeRegistry::isDrive((string)$up->get('fup_purpose'))) {
		return LogicResult::error('That is a Drive upload; finish it through Drive.');
	}
	return drive_upload_complete_logic($input);
}

function upload_complete_logic_descriptor(): array {
	return array(
		'description'      => 'Finish a resumable upload for a registered purpose (not Drive) and create its file. Retry-safe via Idempotency-Key.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'upload_token' => array('type' => 'string', 'required' => true, 'max_length' => 64, 'label' => 'Upload token'),
		),
	);
}

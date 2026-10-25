<?php

/**
 * upload_init — begin a resumable upload for a purpose other than Drive.
 *
 * A subsystem that registered an upload purpose (UploadPurposeRegistry) opens its
 * uploads here. It is the same transport and the same init as drive_upload_init,
 * minus Drive's feature gate: a site with Drive switched off still takes a board
 * attachment or a mail archive of any size. A Drive upload is refused here and
 * goes through drive_upload_init, which carries Drive's gate.
 *
 * @version 1.0
 */

function upload_init_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/UploadPurposeRegistry.php'));
	require_once(PathHelper::getIncludePath('logic/drive_upload_init_logic.php'));

	$purpose = trim((string)($input['purpose'] ?? ''));
	if ($purpose === '' || UploadPurposeRegistry::isDrive($purpose)) {
		return LogicResult::error('Say what the upload is for. Drive files are uploaded through Drive.');
	}
	return drive_upload_init_logic($input);
}

function upload_init_logic_descriptor(): array {
	return array(
		'auth' => array('min_user_permission' => 0),
		'description'      => 'Begin a resumable upload for a registered purpose (not Drive): returns an upload token + chunk size. The bytes go up over PUT /api/v1/drive_upload/{token}; upload_complete finishes it.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'purpose'    => array('type' => 'string', 'required' => true, 'max_length' => 64, 'label' => 'What the upload is for (a registered purpose)'),
			'name'       => array('type' => 'string', 'required' => true, 'max_length' => 255, 'label' => 'File name'),
			'size_bytes' => array('type' => 'int', 'required' => true, 'min' => 1, 'label' => 'File size in bytes'),
			'sha256'     => array('type' => 'string', 'required' => false, 'max_length' => 64, 'label' => 'Content SHA-256'),
			'mime_type'  => array('type' => 'string', 'required' => false, 'max_length' => 128, 'label' => 'MIME type'),
		),
	);
}

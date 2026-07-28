<?php

/**
 * drive_upload_init — begin a Drive upload.
 *
 * Gates quota + per-file size + write access, then either:
 *   - dedup short-circuit: if the client's sha256 matches an existing private
 *     blob, complete immediately (retain the blob, create the File or a new
 *     FileVersion) and return the file — no bytes transferred; or
 *   - open a resumable upload: create a FileUpload and return an upload_token
 *     (raw, only its hash stored) plus the chunk size.
 */

function drive_upload_init_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/UploadPurposeRegistry.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
	require_once(PathHelper::getIncludePath('data/file_uploads_class.php'));
	require_once(PathHelper::getIncludePath('data/file_versions_class.php'));
	require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('data/file_changes_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$user_id) {
		return LogicResult::error('You must be signed in to upload.');
	}

	// A non-Drive purpose (specs/chunked_upload_purposes.md) takes the transport but
	// none of Drive's policy, and branches out BEFORE any of it runs — no Drive
	// gate, no quota, no folder, no encryption, no dedup. Drive's own path below is
	// untouched, which is the point: it handles encrypted files and versions, and is
	// not worth restructuring to make it an instance of something simpler.
	$purpose = trim((string)($input['purpose'] ?? UploadPurposeRegistry::PURPOSE_DRIVE));
	if (!UploadPurposeRegistry::isDrive($purpose)) {
		return drive_upload_init_for_purpose($purpose, $user_id, $input);
	}

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}

	$name       = trim((string)($input['name'] ?? ''));
	$size_bytes = (int)($input['size_bytes'] ?? 0);
	$sha256     = isset($input['sha256']) ? strtolower(trim((string)$input['sha256'])) : '';
	$mime       = (string)($input['mime_type'] ?? 'application/octet-stream');
	$folder_id  = (isset($input['folder_id']) && (int)$input['folder_id'] > 0) ? (int)$input['folder_id'] : 0;
	$file_id    = (isset($input['file_id']) && (int)$input['file_id'] > 0) ? (int)$input['file_id'] : 0;

	if ($name === '' && !$file_id) {
		return LogicResult::error('A file name is required.');
	}
	if ($size_bytes < 0) {
		return LogicResult::error('Invalid file size.');
	}
	if ($sha256 !== '' && !preg_match('/^[0-9a-f]{64}$/', $sha256)) {
		return LogicResult::error('Invalid content hash.');
	}

	// Resolve the write target: a new version of an existing file, or a new file
	// in a folder. The OWNER of the result — who is billed, and whose tier
	// limits apply — is the target file's owner, else the destination folder's
	// owner, else the actor (single-owner-tree rule: everything under a folder
	// belongs to the folder's owner, whoever uploads it).
	$target_file = null;
	$owner_id = $user_id;
	$encrypted = false; // whether the created file will be an encrypted vault file
	if ($file_id) {
		$target_file = DriveHelper::load_file($file_id);
		if (!$target_file) {
			return LogicResult::error('The file to update was not found.');
		}
		if (!DriveHelper::can_write(DriveHelper::ENTITY_FILE, $target_file, $user_id, $session->get_permission())) {
			return LogicResult::error('You do not have permission to update that file.');
		}
		$folder_id = (int)$target_file->get('fil_fol_folder_id');
		$owner_id  = (int)$target_file->get('fil_usr_user_id');
		$encrypted = $target_file->is_encrypted();
	} elseif ($folder_id) {
		$folder = DriveHelper::load_folder($folder_id);
		if (!$folder) {
			return LogicResult::error('Destination folder not found.');
		}
		if (!DriveHelper::can_write(DriveHelper::ENTITY_FOLDER, $folder, $user_id, $session->get_permission())) {
			return LogicResult::error('You do not have access to that folder.');
		}
		$owner_id = (int)$folder->get('fol_usr_user_id');
		$encrypted = DriveHelper::folder_is_encrypted($folder);
	}

	// Tier gates — the owner's plan, since the owner is billed. The per-file cap
	// means PLAINTEXT bytes; an encrypted upload arrives as ciphertext (a fixed
	// 32 bytes per 4 MiB chunk larger), so a vault destination is gated against
	// the deterministic ciphertext ceiling — a file that fits the cap must not
	// fail only because its destination is encrypted.
	$max_file = (int)SubscriptionTier::getUserFeature($owner_id, 'drive_max_file_bytes', 0);
	$quota    = (int)SubscriptionTier::getUserFeature($owner_id, 'drive_storage_bytes', 0);
	if ($quota <= 0 || $max_file <= 0) {
		return LogicResult::error('Uploads are not available on the owner\'s current plan.');
	}
	$size_cap = $encrypted ? DriveHelper::encrypted_size_ceiling($max_file) : $max_file;
	if ($size_bytes > $size_cap) {
		return LogicResult::error('That file is larger than the per-file limit.');
	}
	$usage = DriveUsage::for_user($owner_id);
	if ((int)$usage->get('dru_bytes_used') + $size_bytes > $quota) {
		return LogicResult::error('That upload would exceed the storage quota.');
	}

	// Dedup short-circuit: identical private bytes the ACTOR already possesses
	// (their own files/versions only — a client-claimed hash is not proof of
	// possession, so a foreign hash+size must never match; see find_dedup).
	// Encrypted uploads are excluded: their ciphertext is unique per file (random
	// file key + IVs, so it never matches), and the short-circuit path does not
	// carry the encrypted metadata / key-grant a vault file needs.
	if ($sha256 !== '' && !$encrypted) {
		$cand = FileBlob::find_dedup($sha256, $size_bytes, true, $user_id);
		if ($cand) {
			if ($target_file) {
				if (FileBlob::retain($cand->key)) {
					FileVersion::save_new_content($target_file, $cand, $user_id);
					DriveUsage::recompute($owner_id);
					FileChange::record(FileChange::KIND_CONTENT, DriveHelper::ENTITY_FILE, $target_file->key, $owner_id, $user_id);
					$fresh = DriveHelper::load_file($target_file->key);
					return LogicResult::render(array('ok' => true, 'deduped' => true, 'file' => DriveHelper::file_export($fresh)));
				}
			} else {
				$restrictions = array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE);
				$file = File::createFromExistingBlob($cand, $name, $mime, $owner_id, $restrictions);
				if ($file && $file->key) {
					if ($folder_id) { $file->set('fil_fol_folder_id', $folder_id); $file->save(); }
					DriveUsage::recompute($owner_id);
					FileChange::record(FileChange::KIND_CREATED, DriveHelper::ENTITY_FILE, $file->key, $owner_id, $user_id);
					return LogicResult::render(array('ok' => true, 'deduped' => true, 'file' => DriveHelper::file_export($file)));
				}
			}
			// Fall through to a normal upload if the candidate was reclaimed.
		}
	}

	// Open a resumable upload.
	$raw_token = bin2hex(random_bytes(32));
	$up = new FileUpload(NULL);
	$up->set('fup_token_sha256', hash('sha256', $raw_token));
	$up->set('fup_usr_user_id', $user_id);
	if ($folder_id) { $up->set('fup_fol_folder_id', $folder_id); }
	if ($file_id)   { $up->set('fup_fil_file_id', $file_id); }
	$up->set('fup_display_name', $name !== '' ? substr($name, 0, 255) : ($target_file ? $target_file->get('fil_title') : 'upload.bin'));
	$up->set('fup_mime_type', substr($mime, 0, 128));
	$up->set('fup_expected_bytes', $size_bytes);
	if ($sha256 !== '') { $up->set('fup_expected_sha256', $sha256); }
	$up->set('fup_received_bytes', 0);
	$up->set('fup_update_time', gmdate('Y-m-d H:i:s'));
	$up->save();

	$chunk = (int)$settings->get_setting('drive_upload_chunk_bytes');
	if ($chunk <= 0) { $chunk = 8388608; }

	return LogicResult::render(array(
		'ok'           => true,
		'deduped'      => false,
		'upload_token' => $raw_token,
		'chunk_bytes'  => $chunk,
	));
}

/**
 * Open a resumable upload for a NON-Drive purpose.
 *
 * Deliberately small: check the purpose will have it, mint a token, record the
 * pending upload, hand back the chunk size. No quota, no folder, no encryption, no
 * dedup — those are Drive's, and a purpose that wants something like them enforces
 * it in its own authorize hook.
 *
 * Dedup is skipped rather than forgotten. It short-circuits by handing back a file
 * built on bytes the caller already possesses, and "already possesses" is a
 * Drive-shaped question; answering it wrongly for another purpose would hand
 * somebody a file they should not have.
 */
function drive_upload_init_for_purpose(string $purpose, int $user_id, array $input): LogicResult {
	require_once(PathHelper::getIncludePath('data/file_uploads_class.php'));

	$spec = UploadPurposeRegistry::get($purpose);
	if ($spec === null) {
		return LogicResult::error('Unknown upload purpose.');
	}

	$name       = trim((string)($input['name'] ?? ''));
	$size_bytes = (int)($input['size_bytes'] ?? 0);
	$mime       = (string)($input['mime_type'] ?? 'application/octet-stream');

	if ($name === '') {
		return LogicResult::error('A file name is required.');
	}
	if ($size_bytes <= 0) {
		return LogicResult::error('A file size is required.');
	}

	$refusal = UploadPurposeRegistry::authorize($purpose, $user_id, $input);
	if ($refusal !== null) {
		return LogicResult::error($refusal);
	}

	$raw_token = bin2hex(random_bytes(32));
	$up = new FileUpload(NULL);
	$up->set('fup_token_sha256', hash('sha256', $raw_token));
	$up->set('fup_usr_user_id', $user_id);
	$up->set('fup_purpose', substr($purpose, 0, 64));
	$up->set('fup_display_name', substr($name, 0, 255));
	$up->set('fup_mime_type', substr($mime, 0, 128));
	$up->set('fup_expected_bytes', $size_bytes);
	$sha256 = isset($input['sha256']) ? strtolower(trim((string)$input['sha256'])) : '';
	if ($sha256 !== '') {
		$up->set('fup_expected_sha256', $sha256);
	}
	$up->set('fup_received_bytes', 0);
	$up->set('fup_update_time', gmdate('Y-m-d H:i:s'));
	$up->save();

	$settings = Globalvars::get_instance();
	$chunk = (int)$settings->get_setting('drive_upload_chunk_bytes');
	if ($chunk <= 0) { $chunk = 8388608; }

	return LogicResult::render(array(
		'ok'           => true,
		'deduped'      => false,
		'upload_token' => $raw_token,
		'chunk_bytes'  => $chunk,
	));
}

function drive_upload_init_logic_descriptor(): array {
	return array(
		'description'      => 'Begin a Drive upload: returns an upload token + chunk size, or dedup-completes immediately.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'name'       => array('type' => 'string', 'required' => false, 'max_length' => 255, 'label' => 'File name'),
			'folder_id'  => array('type' => 'int', 'required' => false, 'label' => 'Destination folder id'),
			'file_id'    => array('type' => 'int', 'required' => false, 'label' => 'Existing file id (upload a new version)'),
			'size_bytes' => array('type' => 'int', 'required' => true, 'min' => 0, 'label' => 'File size in bytes'),
			'sha256'     => array('type' => 'string', 'required' => false, 'max_length' => 64, 'label' => 'Content SHA-256 (enables dedup)'),
			'mime_type'  => array('type' => 'string', 'required' => false, 'max_length' => 128, 'label' => 'MIME type'),
			'purpose'    => array('type' => 'string', 'required' => false, 'max_length' => 64, 'label' => 'What the upload is for (default: drive)'),
		),
	);
}
?>

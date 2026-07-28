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
	require_once(PathHelper::getIncludePath('includes/UploadPurposeRegistry.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
	require_once(PathHelper::getIncludePath('data/file_uploads_class.php'));
	require_once(PathHelper::getIncludePath('data/file_versions_class.php'));
	require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));
	require_once(PathHelper::getIncludePath('data/file_changes_class.php'));
	require_once(PathHelper::getIncludePath('data/file_key_grants_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$user_id) {
		return LogicResult::error('You must be signed in to upload.');
	}

	$token = (string)($input['upload_token'] ?? '');
	$up = FileUpload::load_by_token($token);
	if (!$up || (int)$up->get('fup_usr_user_id') !== $user_id) {
		return LogicResult::error('Upload not found or already completed.');
	}

	// The purpose is read from the UPLOAD, never from this request: it was settled
	// at init, so an upload cannot be opened under one purpose and completed under
	// another to borrow the other's policy.
	$purpose  = (string)$up->get('fup_purpose');
	$is_drive = UploadPurposeRegistry::isDrive($purpose);

	if ($is_drive && !$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
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

	// Everything above is universal — the bytes are all here, the right size, and
	// the checksum matches — so a purpose inherits it rather than restating it.
	// Everything below is Drive's: versions, encryption, key grants, quota, folders.
	if (!$is_drive) {
		return drive_upload_complete_for_purpose($purpose, $up, $part, $user_id);
	}

	$folder_id = (int)$up->get('fup_fol_folder_id');
	$file_id   = (int)$up->get('fup_fil_file_id');
	$mime      = (string)($up->get('fup_mime_type') ?: 'application/octet-stream');
	$name      = (string)$up->get('fup_display_name');

	// Re-resolve the target and its OWNER (billed; single-owner-tree rule) —
	// access may have been revoked between init and complete.
	$target_file = null;
	$owner_id = $user_id;
	$encrypted = false; // authoritative: derived from the destination, not the client
	if ($file_id) {
		$target_file = DriveHelper::load_file($file_id);
		if (!$target_file || !DriveHelper::can_write(DriveHelper::ENTITY_FILE, $target_file, $user_id, $session->get_permission())) {
			$up->discard();
			return LogicResult::error('You can no longer update that file.');
		}
		$owner_id = (int)$target_file->get('fil_usr_user_id');
		$encrypted = $target_file->is_encrypted();
	} elseif ($folder_id) {
		$folder = DriveHelper::load_folder($folder_id);
		if (!$folder || !DriveHelper::can_write(DriveHelper::ENTITY_FOLDER, $folder, $user_id, $session->get_permission())) {
			$up->discard();
			return LogicResult::error('You can no longer upload into that folder.');
		}
		$owner_id = (int)$folder->get('fol_usr_user_id');
		$encrypted = DriveHelper::folder_is_encrypted($folder);
	}

	// Client-custody encryption payloads (docs/drive_encryption.md). Opaque here —
	// produced in the browser, the server never inspects them. Passed through the
	// action boundary untouched (not declared in the input schema).
	$enc_metadata      = $encrypted && isset($input['encrypted_metadata']) ? (string)$input['encrypted_metadata'] : null;
	$enc_thumb_b64     = $encrypted && isset($input['encrypted_thumbnail']) ? (string)$input['encrypted_thumbnail'] : null;
	// `wrapped_file_keys`: { user_id: file key sealed to that user's drive vault
	// public key }. The uploader seals to the destination's full reader set
	// (owner + grantees, resolved via drive_public_keys) so the file lands
	// readable by everyone who can already reach it.
	$wrapped_keys = array();
	if ($encrypted && isset($input['wrapped_file_keys']) && is_array($input['wrapped_file_keys'])) {
		foreach ($input['wrapped_file_keys'] as $uid => $blob) {
			$uid = (int)$uid;
			$blob = (string)$blob;
			if ($uid > 0 && $blob !== '') {
				$wrapped_keys[$uid] = $blob;
			}
		}
	}
	if ($encrypted && $target_file) {
		// A new version reuses the existing file key and content id (prior
		// versions and every FileKeyGrant stay valid). A wrapped-key payload here
		// means the client minted a FRESH key — accepting it would strand the new
		// content behind grants that wrap the old key. Refuse instead.
		if (!empty($wrapped_keys) || isset($input['wrapped_file_key'])) {
			$up->discard();
			return LogicResult::error('A new version of an encrypted file must reuse its existing file key; do not send a new wrapped key.');
		}
		// Holding a key grant is the only available proof the uploader COULD
		// reuse the file key; without one the ciphertext is unreadable by every
		// grant holder.
		if (FileKeyGrant::wrapped_key_for($target_file->key, $user_id) === null) {
			$up->discard();
			return LogicResult::error('You do not hold this file\'s key, so you cannot upload a new version of it.');
		}
	}
	if ($encrypted && !$target_file) {
		// A new encrypted file must arrive with its metadata and its wrapped
		// keys — without them it would be unreadable and unlisted. The map must
		// cover the folder owner (a vault file its owner can never read must not
		// be creatable), and every target must already be a reader of the
		// destination — a grant to an arbitrary user would be a key-exfiltration
		// primitive.
		if ($enc_metadata === null || $enc_metadata === '' || empty($wrapped_keys)) {
			$up->discard();
			return LogicResult::error('Encrypted upload is missing its metadata or keys.');
		}
		if (!isset($wrapped_keys[$owner_id])) {
			$up->discard();
			return LogicResult::error('Encrypted upload is missing the folder owner\'s wrapped key.');
		}
		$dest_folder = isset($folder) ? $folder : null;
		foreach (array_keys($wrapped_keys) as $key_uid) {
			$is_reader = ($key_uid === $owner_id)
				|| ($dest_folder && DriveHelper::can_read(DriveHelper::ENTITY_FOLDER, $dest_folder, $key_uid));
			if (!$is_reader) {
				$up->discard();
				return LogicResult::error('Encrypted upload includes a wrapped key for a user without access.');
			}
		}
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
			// For an encrypted file the head metadata (and thumbnail) follow the
			// new content; the file key and content id are stable across versions
			// (enforced above: fresh wrapped keys are refused and the uploader
			// must hold a grant), so every key grant stays valid and prior
			// versions stay decryptable.
			if ($encrypted) {
				$fresh_target = DriveHelper::load_file($target_file->key);
				if ($fresh_target) {
					if ($enc_metadata !== null && $enc_metadata !== '') {
						$fresh_target->set('fil_encrypted_metadata', $enc_metadata);
						$fresh_target->save();
					}
					_drive_store_encrypted_thumbnail($fresh_target, $enc_thumb_b64);
				}
			}
			$up->discard();
			DriveUsage::recompute($owner_id);
			FileChange::record(FileChange::KIND_CONTENT, DriveHelper::ENTITY_FILE, $target_file->key, $owner_id, $user_id);
			$fresh = DriveHelper::load_file($target_file->key);
			$wrapped = $encrypted ? FileKeyGrant::wrapped_key_for($fresh->key, $user_id) : null;
			return LogicResult::render(array('ok' => true, 'file' => DriveHelper::file_export($fresh, null, null, $wrapped)));
		}

		// New file — owned by the folder's owner (or the actor at root).
		$restrictions = array(
			'fil_private' => true,
			'fil_source'  => File::SOURCE_DRIVE,
		);
		if ($encrypted) {
			$restrictions['fil_encrypted'] = true;
			$restrictions['fil_encrypted_metadata'] = $enc_metadata;
		}
		$file = File::createFromUpload($part, $name, $mime, $owner_id, $restrictions);
		if (!$file || !$file->key) {
			$up->discard();
			return LogicResult::error('The file could not be stored.');
		}
		if ($folder_id) {
			$file->set('fil_fol_folder_id', $folder_id);
			$file->save();
		}
		if ($encrypted) {
			// One key grant per reader (validated above): the file key sealed to
			// each user's drive vault key in the uploader's browser. The share
			// dialog reconciles this set when grants later change. Client
			// thumbnail rides into the blob's thumb variant slot (the server
			// resize pipeline skips ciphertext).
			foreach ($wrapped_keys as $key_uid => $blob_key) {
				FileKeyGrant::put($file->key, $key_uid, $blob_key);
			}
			_drive_store_encrypted_thumbnail($file, $enc_thumb_b64);
		} elseif ($file->is_image()) {
			$file->resize('all');
		}
		$up->discard();
		DriveUsage::recompute($owner_id);
		FileChange::record(FileChange::KIND_CREATED, DriveHelper::ENTITY_FILE, $file->key, $owner_id, $user_id);

		$wrapped = $encrypted ? (isset($wrapped_keys[$user_id]) ? $wrapped_keys[$user_id] : null) : null;
		return LogicResult::render(array('ok' => true, 'file' => DriveHelper::file_export($file, null, null, $wrapped)));
	} finally {
		DriveHelper::quota_unlock($owner_id);
	}
}

/**
 * Finish a resumable upload for a NON-Drive purpose: turn the staged bytes into
 * the File that purpose asked for, and hand it back.
 *
 * The verified bytes arrive here already checked, so this only has to make the
 * File and let the purpose do its own bookkeeping. The pending upload is discarded
 * either way — on success the bytes have moved into a blob, and on failure keeping
 * a part file whose File could not be created only leaks disk.
 */
function drive_upload_complete_for_purpose(string $purpose, $up, string $part, int $user_id): LogicResult {
	require_once(PathHelper::getIncludePath('includes/UploadPurposeRegistry.php'));

	$name = (string)$up->get('fup_display_name');
	$mime = (string)($up->get('fup_mime_type') ?: 'application/octet-stream');

	$file = UploadPurposeRegistry::finalize($purpose, $part, $name, $mime, $user_id, $up);
	$up->discard();

	if ($file === null) {
		return LogicResult::error('The ' . UploadPurposeRegistry::label($purpose) . ' could not be stored.');
	}

	return LogicResult::render(array(
		'ok'   => true,
		'file' => array(
			'id'         => (int)$file->key,
			'name'       => (string)$file->get('fil_title'),
			'size_bytes' => $file->size_bytes(),
			'mime_type'  => (string)$file->get('fil_type'),
			'source'     => (string)$file->get('fil_source'),
		),
	));
}

/**
 * Write a client-encrypted thumbnail (base64) into the file's blob thumb variant
 * slot. No-op when there is no thumbnail, no configured thumb size, or the file
 * has no blob. The bytes are ciphertext the browser decrypts after fetching the
 * thumb signed URL; the server never decodes them.
 */
function _drive_store_encrypted_thumbnail($file, $b64) {
	if ($b64 === null || $b64 === '') {
		return;
	}
	$thumb_key = DriveHelper::thumb_size_key();
	if ($thumb_key === null) {
		return; // no image size variants configured — client falls back to a type icon
	}
	$bytes = base64_decode($b64, true);
	if ($bytes === false || $bytes === '') {
		return;
	}
	require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
	$blob_id = (int)$file->get('fil_fbb_file_blob_id');
	if ($blob_id <= 0) {
		return;
	}
	$blob = new FileBlob($blob_id, true);
	if ($blob->key) {
		$blob->store_encrypted_variant($thumb_key, $bytes);
	}
}

function drive_upload_complete_logic_descriptor(): array {
	return array(
		'description'      => 'Finish a resumable Drive upload and create the file (or a new version). For a NEW encrypted vault file the body also carries opaque client-produced payloads — `encrypted_metadata` (the FK-encrypted name/mime/etc. blob), `wrapped_file_keys` (a map { user_id: file key sealed to that user\'s drive vault public key } covering the destination\'s reader set, owner entry required — resolve the set via drive_public_keys with folder_id), and optionally `encrypted_thumbnail` (base64 ciphertext for the thumb variant slot). A new VERSION of an encrypted file must be produced with the file\'s existing key and content id (DriveCrypto.encryptFileWith): the uploader must hold a FileKeyGrant, and any wrapped-key payload is refused. All payloads are validated in the logic and pass through the boundary untouched.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'upload_token' => array('type' => 'string', 'required' => true, 'max_length' => 64, 'label' => 'Upload token'),
		),
	);
}
?>

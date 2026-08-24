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

	// The client's own modification time for the content, carried onto the file
	// at complete. Optional — the browser uploader does not send one.
	$modified_time = _drive_parse_modified_time($input['modified_time'] ?? null);
	if ($modified_time === false) {
		return LogicResult::error('Invalid modification time; use ISO-8601 UTC.');
	}

	// Resolve the write target: a new version of an existing file, or a new file
	// in a folder. The OWNER of the result — who is billed, and whose tier
	// limits apply — is the target file's owner, else the destination folder's
	// owner, else the actor (single-owner-tree rule: everything under a folder
	// belongs to the folder's owner, whoever uploads it).
	$target_file = null;
	$owner_id = $user_id;
	$encrypted = false; // Fortress: client custody, ciphertext arrives from the browser
	$sealed    = false; // Private: server custody, plaintext arrives and is sealed here
	if ($file_id) {
		$target_file = DriveHelper::load_file($file_id);
		if (!$target_file) {
			return LogicResult::error('The file to update was not found.');
		}
		if (!DriveHelper::can_write(DriveHelper::ENTITY_FILE, $target_file, $user_id, $session->get_permission())) {
			return LogicResult::error('You do not have permission to update that file.');
		}
		// A new version of a file in the trash has nowhere to be seen. The row is
		// hidden from every listing, so the bytes would be admitted, charged to
		// quota, and invisible — and to the uploader the save looks like it
		// worked. Restoring is drive_restore's job; a sync client whose edit beat
		// somebody's delete sends the rescued bytes up as a NEW file instead.
		if ($target_file->get('fil_delete_time') !== null && $target_file->get('fil_delete_time') !== '') {
			return LogicResult::error('That file is in the trash.', array('reason' => 'file_trashed', 'file_id' => $file_id));
		}
		$folder_id = (int)$target_file->get('fil_fol_folder_id');
		$owner_id  = (int)$target_file->get('fil_usr_user_id');
		$encrypted = $target_file->is_encrypted();
		$sealed    = $target_file->is_sealed();
	} elseif ($folder_id) {
		$folder = DriveHelper::load_folder($folder_id);
		if (!$folder) {
			return LogicResult::error('Destination folder not found.');
		}
		if (!DriveHelper::can_write(DriveHelper::ENTITY_FOLDER, $folder, $user_id, $session->get_permission())) {
			return LogicResult::error('You do not have access to that folder.');
		}
		if (DriveHelper::folder_is_trashed($folder)) {
			return LogicResult::error('That folder is in the trash.', array('reason' => 'parent_trashed', 'folder_id' => (int)$folder_id));
		}
		$owner_id = (int)$folder->get('fol_usr_user_id');
		require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
		$level = DriveHelper::folder_level($folder);
		$encrypted = ($level === ProtectionLevel::FORTRESS);
		$sealed    = ($level === ProtectionLevel::PRIVATE_);
	}

	// A plaintext modification time on an encrypted file would leak when the
	// file was last worked on, so the vault path refuses it: the client puts the
	// true mtime inside the encrypted metadata blob instead.
	if ($encrypted && $modified_time !== null) {
		return LogicResult::error('An encrypted upload carries its modification time inside its encrypted metadata, not as a parameter.');
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

	// A quota is what the owner is allowed; this is what the machine can actually
	// hold. Both have to be true, and the second is the one whose failure takes
	// the rest of the deployment down with it.
	$no_room = FileUpload::space_refusal($size_bytes);
	if ($no_room !== '') {
		return LogicResult::error($no_room);
	}

	// A new file cannot take a name a live sibling already holds, and the
	// question is asked HERE as well as at completion — before a byte is sent,
	// rather than after the whole file has crossed the wire only to be refused.
	// The dedup branch below needs it for a second reason: it creates the file
	// itself and never reaches the completion path at all.
	if (!$target_file && DriveHelper::file_name_taken($owner_id, $folder_id, $name)) {
		return LogicResult::error('A file with that name already exists here.', array('reason' => 'name_taken'));
	}

	// Dedup short-circuit: identical private bytes the ACTOR already possesses
	// (their own files/versions only — a client-claimed hash is not proof of
	// possession, so a foreign hash+size must never match; see find_dedup).
	// Encrypted uploads are excluded: their ciphertext is unique per file (random
	// file key + IVs, so it never matches), and the short-circuit path does not
	// carry the encrypted metadata / key-grant a vault file needs.
	//
	// Sealed destinations are excluded for a different reason, and it matters: the
	// hash the client sends is of the PLAINTEXT, which really can match a
	// plaintext twin the actor holds in a Standard folder. Linking to it would
	// quietly place unsealed bytes inside a Private folder — the promise on the
	// card broken by an optimization. The upload runs, and the bytes are sealed.
	if ($sha256 !== '' && !$encrypted && !$sealed) {
		$cand = FileBlob::find_dedup($sha256, $size_bytes, true, $user_id);
		if ($cand) {
			if ($target_file) {
				if (FileBlob::retain($cand->key)) {
					// Taken before the version row that will hold it exists. If
					// making that row fails, the count has to come back down --
					// otherwise the bytes are pinned for good, still counted
					// against the owner and reachable by nobody.
					try {
						FileVersion::save_new_content($target_file, $cand, $user_id);
					} catch (Exception $e) {
						FileBlob::release($cand->key);
						throw $e;
					}
					if ($modified_time !== null) {
						$target_file->set('fil_content_modified_time', $modified_time);
						$target_file->save();
					}
					DriveUsage::recompute($owner_id);
					FileChange::record(FileChange::KIND_CONTENT, DriveHelper::ENTITY_FILE, $target_file->key, $owner_id, $user_id);
					DriveHelper::forget_sync_meta($target_file->key);
					$fresh = DriveHelper::load_file($target_file->key);
					return LogicResult::render(array('ok' => true, 'deduped' => true, 'file' => DriveHelper::file_export($fresh)));
				}
			} else {
				// The destination folder is part of the creation, exactly as it is
				// on the completion path. Creating at the root and relocating would
				// put the file briefly in the root's namespace, where it can collide
				// with an unrelated root file of the same name — and the sibling-name
				// rule would refuse it there for a reason that has nothing to do with
				// where the user put it.
				$restrictions = array(
					'fil_private' => true,
					'fil_source'  => File::SOURCE_DRIVE,
					'fil_fol_folder_id' => $folder_id ?: null,
				);
				if ($modified_time !== null) {
					$restrictions['fil_content_modified_time'] = $modified_time;
				}
				try {
					$file = File::createFromExistingBlob($cand, $name, $mime, $owner_id, $restrictions);
				} catch (Exception $e) {
					// The check above is a fast path: two uploads can both pass it and
					// both reach the insert, where the partial unique index refuses the
					// loser. Answered as the name clash it is rather than as a database
					// fault, so a sync client retries instead of withdrawing the work.
					if (strpos($e->getMessage(), '23505') === false
						|| !DriveHelper::file_name_taken($owner_id, $folder_id, $name)) {
						throw $e;
					}
					return LogicResult::error('A file with that name already exists here.', array('reason' => 'name_taken'));
				}
				if ($file && $file->key) {
					DriveUsage::recompute($owner_id);
					FileChange::record(FileChange::KIND_CREATED, DriveHelper::ENTITY_FILE, $file->key, $owner_id, $user_id);
					DriveHelper::forget_sync_meta($file->key);
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
	if ($modified_time !== null) { $up->set('fup_content_modified_time', $modified_time); }
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

	$no_room = FileUpload::space_refusal($size_bytes);
	if ($no_room !== '') {
		return LogicResult::error($no_room);
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

/**
 * Parse a client-supplied content modification time into the UTC string the
 * database stores.
 *
 * Returns null when nothing was sent, false when what was sent is unusable
 * (the caller turns that into an error rather than silently dropping a
 * timestamp the client believes it set). Anything strtotime understands is
 * accepted and normalized to UTC; a bare naive timestamp is read as UTC, which
 * is what the parameter is documented to carry.
 */
function _drive_parse_modified_time($raw) {
	if ($raw === null || $raw === '' || $raw === false) {
		return null;
	}
	if (!is_string($raw) && !is_numeric($raw)) {
		return false;
	}
	$ts = strtotime((string)$raw . (preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', (string)$raw) ? '' : ' UTC'));
	if ($ts === false) {
		return false;
	}
	// A timestamp far outside anything a real filesystem reports is a client bug
	// or a hostile value, not a date. Postgres would take it; sync clients that
	// sort on it would not enjoy it.
	if ($ts < 0 || $ts > strtotime('+50 years')) {
		return false;
	}
	return gmdate('Y-m-d H:i:s', $ts);
}

function drive_upload_init_logic_descriptor(): array {
	return array(
		'description'      => 'Begin a Drive upload: returns an upload token + chunk size, or dedup-completes immediately.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'input'            => array(
			'name'       => array('type' => 'string', 'required' => false, 'max_length' => 255, 'label' => 'File name'),
			'folder_id'  => array('type' => 'int', 'required' => false, 'label' => 'Destination folder id'),
			'file_id'    => array('type' => 'int', 'required' => false, 'label' => 'Existing file id (upload a new version)'),
			'size_bytes' => array('type' => 'int', 'required' => true, 'min' => 0, 'label' => 'File size in bytes'),
			'sha256'     => array('type' => 'string', 'required' => false, 'max_length' => 64, 'label' => 'Content SHA-256 (enables dedup)'),
			'mime_type'  => array('type' => 'string', 'required' => false, 'max_length' => 128, 'label' => 'MIME type'),
			'purpose'    => array('type' => 'string', 'required' => false, 'max_length' => 64, 'label' => 'What the upload is for (default: drive)'),
			'modified_time' => array('type' => 'string', 'required' => false, 'max_length' => 40, 'label' => 'Content modification time (ISO-8601 UTC; plaintext files only)'),
		),
	);
}
?>

<?php

/**
 * drive_upload_complete — finish a resumable Drive upload.
 *
 * Verifies the assembled part-file (byte count, and sha256 when the client
 * committed to one), ingests it through the single blob path (dedup applies),
 * creates the File — or a new FileVersion when the upload targeted an existing
 * file — recomputes usage, records the change, and clears the pending row.
 * Retry-safe via the standard Idempotency-Key machinery.
 *
 * A new version may say what it was based on (`base_sha256`, and for an
 * encrypted file `base_metadata_sha256`); one whose file moved past that is
 * refused (head_moved / moved_since) where the version is written, under the
 * file's row lock, its staged bytes discarded and its blob reference given back.
 *
 * @version 1.1 - the response carries `warning` when a photo was saved but its sizes refused
 *                (specs/image_decode_memory.md)
 * @version 1.2 - base_sha256 / base_metadata_sha256 guard a new version (B10)
 * @version 1.3 - a collaborator's new version of the owner's file is written under rule_checked_write() behind can_write()
 */

function drive_upload_complete_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/UploadPurposeRegistry.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
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
		return LogicResult::error('You must be signed in to upload.', array('reason' => 'signed_out'));
	}

	// Finishing an upload verifies and ingests the WHOLE file — hashing and
	// moving work that scales with its size, so a multi-GB archive cannot fit
	// inside the web tier's ordinary execution limit. The bytes are already here
	// and the client is only waiting for a receipt, so this request runs to
	// completion even if the caller (or a proxy in front) gives up first — dying
	// mid-ingest is the one outcome with nothing to recommend it.
	@set_time_limit(0);
	@ignore_user_abort(true);

	$token = (string)($input['upload_token'] ?? '');
	$up = FileUpload::load_by_token($token);
	if (!$up || (int)$up->get('fup_usr_user_id') !== $user_id) {
		return LogicResult::error('Upload not found or already completed.', array('reason' => 'not_found'));
	}

	// The purpose is read from the UPLOAD, never from this request: it was settled
	// at init, so an upload cannot be opened under one purpose and completed under
	// another to borrow the other's policy.
	$purpose  = (string)$up->get('fup_purpose');
	$is_drive = UploadPurposeRegistry::isDrive($purpose);

	if ($is_drive && !$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.', array('reason' => 'drive_disabled'));
	}

	$part = $up->part_path();
	if ((int)$up->get('fup_received_bytes') !== (int)$up->get('fup_expected_bytes')) {
		return LogicResult::error('Upload is incomplete.', array('reason' => 'upload_incomplete'));
	}
	if (!is_file($part)) {
		// A genuinely empty (0-byte) upload never sends a chunk; materialize it.
		if ((int)$up->get('fup_expected_bytes') === 0) {
			@touch($part);
		} else {
			$up->discard();
			return LogicResult::error('Uploaded bytes are missing; please retry.', array('reason' => 'upload_incomplete'));
		}
	}
	if ((int)filesize($part) !== (int)$up->get('fup_expected_bytes')) {
		return LogicResult::error('Uploaded size does not match; please retry.', array('reason' => 'upload_incomplete'));
	}
	$expected_sha = (string)$up->get('fup_expected_sha256');
	if ($expected_sha !== '' && hash_file('sha256', $part) !== $expected_sha) {
		return LogicResult::error('Uploaded content failed its checksum; please retry.', array('reason' => 'upload_incomplete'));
	}

	// Everything above is universal — the bytes are all here, the right size, and
	// the checksum matches — so a purpose inherits it rather than restating it.
	// Everything below is Drive's: versions, encryption, key grants, quota, folders.
	if (!$is_drive) {
		return drive_upload_complete_for_purpose($purpose, $up, $part, $user_id);
	}

	$folder_id = (int)$up->get('fup_fol_folder_id');
	$file_id   = (int)$up->get('fup_fil_file_id');
	$base          = isset($input['base_sha256']) ? strtolower(trim((string)$input['base_sha256'])) : '';
	$base_metadata = isset($input['base_metadata_sha256']) ? strtolower(trim((string)$input['base_metadata_sha256'])) : '';
	foreach (array($base, $base_metadata) as $hash) {
		if ($hash !== '' && !preg_match('/^[0-9a-f]{64}$/', $hash)) {
			return LogicResult::error('That is not a SHA-256 hash.', array('reason' => 'invalid_request', 'standing' => true));
		}
	}
	$mime      = (string)($up->get('fup_mime_type') ?: 'application/octet-stream');
	$name      = (string)$up->get('fup_display_name');
	// Settled at init (and refused there for a vault destination), so an upload
	// cannot acquire a plaintext mtime it was not opened with.
	$modified_time = $up->get('fup_content_modified_time');
	if ($modified_time === '') { $modified_time = null; }

	// Re-resolve the target and its OWNER (billed; single-owner-tree rule) —
	// access may have been revoked between init and complete.
	// The protection level is authoritative from the DESTINATION, never from the
	// client: a file lands at whatever level the folder it lands in promises.
	$target_file = null;
	$owner_id = $user_id;
	$level = ProtectionLevel::STANDARD;
	if ($file_id) {
		$target_file = DriveHelper::load_file($file_id);
		if (!$target_file || !DriveHelper::can_write(DriveHelper::ENTITY_FILE, $target_file, $user_id, $session->get_permission())) {
			$up->discard();
			return LogicResult::error('You can no longer update that file.', array('reason' => 'forbidden', 'standing' => true));
		}
		// Trashed while the bytes were in flight — asked again here for the same
		// reason the destination folder is: a version admitted into a hidden row
		// is a save that reports success and shows nothing.
		if ($target_file->get('fil_delete_time') !== null && $target_file->get('fil_delete_time') !== '') {
			$up->discard();
			return LogicResult::error('That file is in the trash.', array('reason' => 'file_trashed', 'file_id' => $file_id, 'standing' => true));
		}
		$owner_id = (int)$target_file->get('fil_usr_user_id');
		$level = $target_file->protection_level();
	} elseif ($folder_id) {
		$folder = DriveHelper::load_folder($folder_id);
		if (!$folder || !DriveHelper::can_write(DriveHelper::ENTITY_FOLDER, $folder, $user_id, $session->get_permission())) {
			$up->discard();
			return LogicResult::error('You can no longer upload into that folder.', array('reason' => 'forbidden', 'standing' => true));
		}
		if (DriveHelper::folder_is_trashed($folder)) {
			// Trashed while the bytes were in flight. Discard the staged upload
			// rather than leave it to expire: the destination is gone and this
			// transfer has nowhere left to land.
			$up->discard();
			return LogicResult::error('That folder is in the trash.', array('reason' => 'parent_trashed', 'folder_id' => (int)$folder_id));
		}
		$owner_id = (int)$folder->get('fol_usr_user_id');
		$level = DriveHelper::folder_level($folder);
	}
	$encrypted = ($level === ProtectionLevel::FORTRESS); // client custody
	$warning = null;   // set when the photo was saved but its sizes were refused
	$sealed    = ($level === ProtectionLevel::PRIVATE_);  // server custody

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
			return LogicResult::error('A new version of an encrypted file must reuse its existing file key; do not send a new wrapped key.', array('reason' => 'invalid_request', 'standing' => true));
		}
		// Holding a key grant is the only available proof the uploader COULD
		// reuse the file key; without one the ciphertext is unreadable by every
		// grant holder.
		if (FileKeyGrant::wrapped_key_for($target_file->key, $user_id) === null) {
			$up->discard();
			return LogicResult::error('You do not hold this file\'s key, so you cannot upload a new version of it.', array('reason' => 'key_unavailable', 'standing' => true));
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
			return LogicResult::error('Encrypted upload is missing its metadata or keys.', array('reason' => 'invalid_request', 'standing' => true));
		}
		if (!isset($wrapped_keys[$owner_id])) {
			$up->discard();
			return LogicResult::error('Encrypted upload is missing the folder owner\'s wrapped key.', array('reason' => 'invalid_request', 'standing' => true));
		}
		$dest_folder = isset($folder) ? $folder : null;
		foreach (array_keys($wrapped_keys) as $key_uid) {
			$is_reader = ($key_uid === $owner_id)
				|| ($dest_folder && DriveHelper::can_read(DriveHelper::ENTITY_FOLDER, $dest_folder, $key_uid));
			if (!$is_reader) {
				$up->discard();
				// Not standing: the key list was right when the client made it,
				// and planned again from the member list as it is now, it is.
				return LogicResult::error('Encrypted upload includes a wrapped key for a user without access.', array('reason' => 'invalid_request'));
			}
		}
	}

	// A new file cannot take a name a live sibling already holds. Asked before
	// any bytes are admitted, so the cheap answer costs nothing; the save below
	// re-asks it, because this check is a fast path and not a guarantee.
	$holder = $target_file ? null : DriveHelper::file_name_holder($owner_id, $folder_id, $name);
	if ($holder !== null) {
		$up->discard();
		return LogicResult::error('A file with that name already exists here.', DriveHelper::name_taken_data($holder, $user_id));
	}

	// Quota is enforced HERE, where bytes are admitted to storage — the init
	// check is only a fast-fail. Serialized per owner so uploads opened while
	// under quota cannot all land past it. The pending row is kept on rejection
	// (the user can free space and retry; the stale-upload task cleans up).
	$expected = (int)$up->get('fup_expected_bytes');
	if ($sealed) {
		// Quota is charged on what lands in storage, and sealing adds a fixed
		// per-chunk overhead. The client uploaded plaintext, so the admitted size
		// has to be the container's size or the check would admit bytes the
		// recompute afterwards counts as over quota.
		require_once(PathHelper::getIncludePath('includes/SealedFileContainer.php'));
		$expected = SealedFileContainer::cipherSizeFor($expected);
	}
	DriveHelper::quota_lock($owner_id);
	// Each write below is held against a trash of the owner's tree from the
	// moment it lands to its change row (DriveHelper::owner_write_lock): only
	// that long, never across the byte work before it -- a trash waiting on a
	// long seal would hold every other write of the owner back behind it. After
	// the quota lock, which a trash never takes; before the destination's
	// placement lock, which it does. Released here too if a write throws.
	$owner_locked = false;
	try {
		require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
		// Quota 0 is no quota; the disk-space reserve is the only bound then.
		$quota = (int)SubscriptionTier::getUserFeature($owner_id, 'drive_storage_bytes', 0);
		$current = DriveUsage::recompute($owner_id);
		if ($quota > 0 && $current + $expected > $quota) {
			return LogicResult::error('That upload would exceed the storage quota.', array('reason' => 'quota', 'standing' => true));
		}

		if ($target_file) {
			// New version of an existing file.
			if ($sealed) {
				// A version reuses the file's OWN key — the same rule Fortress
				// versions follow, for the same reason: prior versions must stay
				// openable and the row's single wrapping has to cover them all.
				// Sealing needs no window, so uploading into a Private folder
				// works locked; only reading does not.
				require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
				VaultUnlock::loadConsumerBootstraps(); // DriveSealed loads only through the loader
				require_once(PathHelper::getIncludePath('includes/SealedFileContainer.php'));
				try {
					$fk = DriveSealed::versionKeyFor($target_file);
				} catch (VaultLockedException $e) {
					// The one upload that genuinely needs the window: a new version
					// must be sealed under the file's EXISTING key, and reading that
					// key is what the window is for. The pending upload is kept, so
					// unlocking and retrying costs no bytes.
					return LogicResult::error('Unlock your vault to replace the contents of a Private file.', array('reason' => 'vault_needed'));
				} catch (Exception $e) {
					$up->discard();
					return LogicResult::error('That file\'s key could not be read, so a new version cannot be stored.', array('reason' => 'key_unavailable', 'standing' => true));
				}
				$sealed_tmp = $part . '.sealed';
				try {
					$info = SealedFileContainer::sealStream($part, $sealed_tmp, $fk);
					$blob = FileBlob::createFromPath($sealed_tmp, 'application/octet-stream', true);
					// The blob is inserted already holding one reference, on the
					// promise that the row about to be made will hold it. If that
					// row never appears the promise has to be taken back: bytes
					// with a reference nothing holds are never reclaimed, keep
					// counting against the owner's quota, and cannot be reached.
					DriveHelper::owner_write_lock($owner_id);
					$owner_locked = true;
					// can_write() above admitted the uploader; the file is the
					// folder owner's, and pruning old versions destroys rows.
					try {
						SystemBase::rule_checked_write(function () use ($target_file, $blob, $user_id) {
							FileVersion::save_new_content($target_file, $blob, $user_id);
						});
					} catch (Exception $e) {
						FileBlob::release($blob->key);
						throw $e;
					}
					$fresh_target = DriveHelper::load_file($target_file->key);
					if ($fresh_target) {
						$fresh_target->set('fil_plain_size_bytes', (int)$info['plain_size']);
						SystemBase::rule_checked_write(function () use ($fresh_target, $part, $fk) {
							$fresh_target->save();
							DriveSealed::storeSealedThumbnail($fresh_target, $part, $fk);
						});
					}
				} finally {
					if (is_file($sealed_tmp)) { @unlink($sealed_tmp); }
				}
				$up->discard();
				DriveUsage::recompute($owner_id);
				FileChange::record(FileChange::KIND_CONTENT, DriveHelper::ENTITY_FILE, $target_file->key, $owner_id, $user_id);
				DriveHelper::owner_write_unlock($owner_id);
				$owner_locked = false;
				DriveHelper::forget_sync_meta($target_file->key);
				$fresh = DriveHelper::load_file($target_file->key);
				return LogicResult::render(array('ok' => true, 'file' => DriveHelper::file_export($fresh)));
			}
			$blob = FileBlob::createFromPath($part, $mime, true);
			// See the sealed branch above: the fresh blob already counts the
			// reference the version row is about to hold, so a failure here has
			// to give it back or the bytes are pinned and unreachable for good.
			DriveHelper::owner_write_lock($owner_id);
			$owner_locked = true;
			// For an encrypted file the head metadata follows the new content,
			// written with it under the file's row lock so a rename cannot land
			// between the check of the blob it was sealed from and the write.
			$guard = array('base_sha256' => $base);
			if ($encrypted) {
				$guard['base_metadata_sha256'] = $base_metadata;
				$guard['encrypted_metadata'] = ($enc_metadata !== null) ? (string)$enc_metadata : '';
			}
			try {
				// can_write() above admitted the uploader; the file is the
				// folder owner's, and pruning old versions destroys rows.
				$wrote = SystemBase::rule_checked_write(function () use ($target_file, $blob, $user_id, $guard) {
					return FileVersion::save_new_content($target_file, $blob, $user_id, $guard);
				});
			} catch (FileHeadMovedException $moved) {
				// The bytes were for a base that no longer holds. Nothing keeps
				// them: the blob's reference goes back and the staged upload goes.
				FileBlob::release($blob->key);
				$up->discard();
				return LogicResult::error($moved->getMessage(), $moved->refusal_data());
			} catch (Exception $e) {
				FileBlob::release($blob->key);
				throw $e;
			}
			if (!$wrote) {
				// The head already holds these bytes: this upload landed before.
				// Nothing new happened, so nothing is recorded.
				FileBlob::release($blob->key);
				$up->discard();
				DriveHelper::forget_sync_meta($target_file->key);
				$fresh = DriveHelper::load_file($target_file->key);
				$wrapped = $encrypted ? FileKeyGrant::wrapped_key_for($fresh->key, $user_id) : null;
				return LogicResult::render(array('ok' => true, 'file' => DriveHelper::file_export($fresh, null, null, $wrapped)));
			}
			// The thumbnail follows the new content too. The file key and content
			// id are stable across versions (enforced above: fresh wrapped keys
			// are refused and the uploader must hold a grant), so every key grant
			// stays valid and prior versions stay decryptable.
			if ($encrypted) {
				$fresh_target = DriveHelper::load_file($target_file->key);
				if ($fresh_target) {
					_drive_store_encrypted_thumbnail($fresh_target, $enc_thumb_b64);
				}
			}
			// The date is this upload's, or none: a new version sent without
			// one (a browser) must not keep the previous version's, or every
			// computer gets the edit stamped with the old time. An encrypted
			// file's date lives in its encrypted metadata.
			if (!$encrypted) {
				$fresh_target = DriveHelper::load_file($target_file->key);
				if ($fresh_target) {
					$fresh_target->set('fil_content_modified_time', $modified_time);
					SystemBase::rule_checked_write(function () use ($fresh_target) { $fresh_target->save(); });
				}
			}
			$up->discard();
			DriveUsage::recompute($owner_id);
			FileChange::record(FileChange::KIND_CONTENT, DriveHelper::ENTITY_FILE, $target_file->key, $owner_id, $user_id);
			DriveHelper::owner_write_unlock($owner_id);
			$owner_locked = false;
			DriveHelper::forget_sync_meta($target_file->key);
			$fresh = DriveHelper::load_file($target_file->key);
			$wrapped = $encrypted ? FileKeyGrant::wrapped_key_for($fresh->key, $user_id) : null;
			return LogicResult::render(array('ok' => true, 'file' => DriveHelper::file_export($fresh, null, null, $wrapped)));
		}

		// New file — owned by the folder's owner (or the actor at root).
		//
		// The destination folder is part of the creation, not a move afterwards.
		// Creating at the root and relocating would put every upload briefly in
		// the root's namespace, where it can collide with a root file of the
		// same name that has nothing to do with it — and would leave a homeless
		// row behind if the relocation failed.
		$restrictions = array(
			'fil_private' => true,
			'fil_source'  => File::SOURCE_DRIVE,
			'fil_protection_level' => $level,
			'fil_fol_folder_id' => $folder_id ?: null,
		);
		if ($encrypted) {
			$restrictions['fil_encrypted_metadata'] = $enc_metadata;
		}
		if (!$encrypted && $modified_time !== null) {
			$restrictions['fil_content_modified_time'] = $modified_time;
		}
		// A sealed upload is sealed here, before the owner's lock: the seal is
		// the long part, and only the row has to land under the lock.
		$sealed_upload = null;
		if ($sealed) {
			require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
			VaultUnlock::loadConsumerBootstraps(); // DriveSealed loads only through the loader
			try {
				$sealed_upload = DriveSealed::sealForCreate($part, $mime, $owner_id);
			} catch (DriveSealedException $e) {
				$up->discard();
				return LogicResult::error($e->getMessage(), array('reason' => 'storage_failed'));
			}
		}
		DriveHelper::owner_write_lock($owner_id);
		$owner_locked = true;
		try {
			// The trashed-destination check near the top of this function is a
			// fast fail, made before the bytes were touched. It is not the
			// guarantee: quota, sealing and thumbnailing all run between there
			// and here, and a folder trashed inside that window would leave this
			// file live and unreachable underneath it. place_into re-asks the
			// question with the destination held, so the cascade either sweeps
			// this row or never starts.
			$file = DriveHelper::place_into($folder_id, function () use ($sealed_upload, $part, $name, $mime, $owner_id, $restrictions) {
				if ($sealed_upload !== null) {
					// Server custody: the bytes were sealed above on the way into
					// the blob, so the plaintext never lands in the file store at
					// all. The type was sniffed from the plaintext (sniffing a
					// container would read application/octet-stream forever), and
					// the thumbnail is rendered while the plaintext is still on
					// disk.
					return DriveSealed::createFromSealed($sealed_upload, $part, $name, $owner_id, $restrictions);
				}
				return File::createFromUpload($part, $name, $mime, $owner_id, $restrictions);
			});
		} catch (DriveDestinationTrashedException $e) {
			$up->discard();
			return LogicResult::error('That folder is in the trash.', array('reason' => 'parent_trashed', 'folder_id' => (int)$folder_id));
		} catch (DriveSealedException $e) {
			$up->discard();
			return LogicResult::error($e->getMessage(), array('reason' => 'storage_failed'));
		} catch (Exception $e) {
			// The check above is a fast path: two uploads can both pass it and
			// both reach the insert, where the partial unique index refuses the
			// loser. Answered as the name clash it is rather than as a database
			// fault — a sync client knows what to do with the first and retries
			// the second forever. Anything else is somebody else's problem and
			// travels on untouched.
			$holder = strpos($e->getMessage(), '23505') === false
				? null : DriveHelper::file_name_holder($owner_id, $folder_id, $name);
			if ($holder === null) {
				throw $e;
			}
			$up->discard();
			return LogicResult::error('A file with that name already exists here.', DriveHelper::name_taken_data($holder, $user_id));
		}
		if (!$file || !$file->key) {
			$up->discard();
			return LogicResult::error('The file could not be stored.', array('reason' => 'storage_failed'));
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
			// A photo too large to decode within image_decode_max_mb is kept as
			// uploaded, without sizes; the response says so.
			if (!$file->resize('all') && $file->variant_refusal() !== null) {
				$warning = $file->variant_refusal();
			}
		}
		$up->discard();
		DriveUsage::recompute($owner_id);
		FileChange::record(FileChange::KIND_CREATED, DriveHelper::ENTITY_FILE, $file->key, $owner_id, $user_id);
		DriveHelper::owner_write_unlock($owner_id);
		$owner_locked = false;
		DriveHelper::forget_sync_meta($file->key);

		$wrapped = $encrypted ? (isset($wrapped_keys[$user_id]) ? $wrapped_keys[$user_id] : null) : null;
		$out = array('ok' => true, 'file' => DriveHelper::file_export($file, null, null, $wrapped));
		if ($warning !== null) {
			$out['warning'] = $warning;
		}
		return LogicResult::render($out);
	} finally {
		if ($owner_locked) {
			DriveHelper::owner_write_unlock($owner_id);
		}
		DriveHelper::quota_unlock($owner_id);
		// A container sealed for a file that never landed.
		if (isset($sealed_upload) && $sealed_upload !== null) {
			DriveSealed::discardSealed($sealed_upload);
		}
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
		return LogicResult::error('The ' . UploadPurposeRegistry::label($purpose) . ' could not be stored.', array('reason' => 'storage_failed'));
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
		'auth' => array('min_user_permission' => 0),
		'description'      => 'Finish a resumable Drive upload and create the file (or a new version). For a NEW encrypted vault file the body also carries opaque client-produced payloads — `encrypted_metadata` (the FK-encrypted name/mime/etc. blob), `wrapped_file_keys` (a map { user_id: file key sealed to that user\'s drive vault public key } covering the destination\'s reader set, owner entry required — resolve the set via drive_public_keys with folder_id), and optionally `encrypted_thumbnail` (base64 ciphertext for the thumb variant slot). A new VERSION of an encrypted file must be produced with the file\'s existing key and content id (DriveCrypto.encryptFileWith): the uploader must hold a FileKeyGrant, and any wrapped-key payload is refused. All payloads are validated in the logic and pass through the boundary untouched.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'input'            => array(
			'upload_token' => array('type' => 'string', 'required' => true, 'max_length' => 64, 'label' => 'Upload token'),
			'base_sha256' => array('type' => 'string', 'required' => false, 'max_length' => 64, 'label' => 'For a new version: SHA-256 (hex) of the head content it was based on; a head that moved since refuses (head_moved). Encrypted files name the ciphertext.'),
			'base_metadata_sha256' => array('type' => 'string', 'required' => false, 'max_length' => 64, 'label' => 'For a new version of an encrypted file: SHA-256 (hex) of the metadata blob its new one was sealed from; one rewritten since by a rename refuses (moved_since)'),
		),
	);
}
?>

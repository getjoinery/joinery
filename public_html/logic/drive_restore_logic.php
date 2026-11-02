<?php
/**
 * drive_restore — bring a Drive file or folder back from the trash (owner only).
 *
 * A folder whose parent is still in the trash comes back at the top of the
 * Drive, out of every share that reached it through that parent. For a Fortress
 * folder that changes who must hold its files' keys, so the re-rooting carries
 * them (`file_keys`, as drive_move takes) or is refused as `keys_needed`, the
 * folder staying in the trash (DriveHelper::rekey_fortress). A protected file
 * whose folder is still in the trash is not put loose at the top, where nothing
 * would protect it: its folder is restored first. Restored where it was, a
 * Fortress item's readers are those of that place, which can have changed while
 * it sat in the trash, so its keys are put right the same way before anything
 * comes back.
 *
 * @version 1.3 - a restore in place puts the keys right first (keys_needed), the folder and file paths alike
 * @version 1.2 - every refusal says why (reason)
 * @version 1.1 - a re-rooted Fortress folder re-keys its files; a protected file waits for its folder
 */

function drive_restore_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/file_changes_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.', array('reason' => 'drive_disabled'));
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.', array('reason' => 'signed_out'));
	}

	$entity_type = (string)($input['entity_type'] ?? '');
	$entity_id   = (int)($input['entity_id'] ?? 0);
	$file_keys   = (isset($input['file_keys']) && is_array($input['file_keys'])) ? $input['file_keys'] : array();

	if ($entity_type !== DriveHelper::ENTITY_FILE && $entity_type !== DriveHelper::ENTITY_FOLDER) {
		return LogicResult::error('Invalid entity type.', array('reason' => 'invalid_request', 'standing' => true));
	}

	$entity = DriveHelper::load_entity($entity_type, $entity_id);
	if (!$entity) {
		return LogicResult::error('Item not found.', array('reason' => 'not_found'));
	}
	if (!DriveHelper::owns($entity_type, $entity, $user_id)) {
		return LogicResult::error('You do not have permission to restore this item.', array('reason' => 'forbidden', 'standing' => true));
	}

	// Held against a trash of the owner's tree from the restore to its change
	// row (DriveHelper::owner_write_lock): a restored subtree comes back with
	// rows older than any cursor, and only this row says it came. Keyed on the
	// item's owner, which the lock and the row are about, not on who asked.
	$entity_owner = (int)$entity->get($entity_type === DriveHelper::ENTITY_FOLDER ? 'fol_usr_user_id' : 'fil_usr_user_id');
	DriveHelper::owner_write_lock($entity_owner);
	try {
		if ($entity_type === DriveHelper::ENTITY_FOLDER) {
			// If the folder's parent is itself still trashed, re-root the folder so
			// it doesn't come back inside a folder that remains in the trash (which
			// would make it unreachable in every view). Root sibling-name collisions
			// are resolved with a suffix.
			//
			// Re-rooted, it leaves every share that reached it through the folder
			// it was in, and the `restored` row below reaches only those who see
			// it where it lands: the members it left are told as a move tells
			// them (DriveHelper::reparent_and_tell).
			$parent_id = (int)$entity->get('fol_parent_folder_id');
			if ($parent_id > 0) {
				$parent = DriveHelper::load_folder($parent_id);
				if (!$parent || ($parent->get('fol_delete_time') !== null && $parent->get('fol_delete_time') !== '')) {
					$owner_id = (int)$entity->get('fol_usr_user_id');
					$name = $entity->get('fol_name');
					if (DriveHelper::folder_name_taken($owner_id, 0, $name, (int)$entity->key)) {
						$base = $name . ' (restored)';
						$name = $base;
						for ($i = 2; DriveHelper::folder_name_taken($owner_id, 0, $name, (int)$entity->key) && $i < 100; $i++) {
							$name = $base . ' ' . $i;
						}
						$entity->set('fol_name', substr($name, 0, 255));
					}
					try {
						$saved = DriveHelper::reparent_and_tell(DriveHelper::ENTITY_FOLDER, (int)$entity->key, $parent_id, 0,
							$entity_owner, $user_id, function () use ($entity, $entity_owner, $user_id, $file_keys) {
								$entity->set('fol_parent_folder_id', null);
								$saved = DriveHelper::save_folder_unless_name_taken($entity);
								// Its readers are the ones at the top now: a throw
								// here rolls the re-rooting back with it.
								if ($saved) {
									DriveHelper::rekey_fortress(DriveHelper::ENTITY_FOLDER, (int)$entity->key, $entity_owner, $user_id, $file_keys);
								}
								return $saved;
							});
					} catch (DriveKeysNeededException $e) {
						return DriveHelper::keys_needed_refusal($e);
					}
					if (!$saved) {
						// Something else claimed the free name between the search
						// above and here. The folder is still safely in the trash.
						return LogicResult::error('That name was taken while restoring. Try again.', array('reason' => 'name_taken'));
					}
				}
			}
			// Restored where it was, its readers are those of the place it stands,
			// which can have changed while it sat in the trash; the keys are put
			// right before anything comes back (the re-rooting above already did).
			if ((int)$entity->get('fol_parent_folder_id') === $parent_id) {
				$refused = _drive_restore_rekey_in_place(DriveHelper::ENTITY_FOLDER, (int)$entity->key, $entity_owner, $user_id, $file_keys);
				if ($refused !== null) {
					return $refused;
				}
			}
			// The re-rooting above read the parent; the cascade below writes. Held
			// against that parent going to the trash in between, which would bring
			// the whole subtree back somewhere nothing lists it.
			try {
				DriveHelper::place_into((int)$entity->get('fol_parent_folder_id'), function () use ($entity) {
					DriveHelper::restore_folder_cascade($entity);
				});
			} catch (DriveDestinationTrashedException $e) {
				return LogicResult::error('The folder this was in went to the trash while restoring. Try again.', array('reason' => 'parent_trashed'));
			}
		} else {
			// If the file's folder is itself trashed, restore it to the root so it
			// doesn't reappear inside a folder that is still in the trash -- and
			// out of the shares that reached it there, whose members are told as
			// for a folder above.
			$from_folder_id = (int)$entity->get('fil_fol_folder_id');
			$folder_id = $from_folder_id;
			if ($folder_id > 0) {
				$folder = DriveHelper::load_folder($folder_id);
				if ($folder && $folder->get('fol_delete_time') !== null && $folder->get('fol_delete_time') !== '') {
					// A file's protection is its folder's: loose at the top it
					// would read as Standard on every device that sees it.
					require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
					if ($entity->protection_level() !== ProtectionLevel::STANDARD) {
						return LogicResult::error('This file is protected by the folder it was in, which is still in the trash. Restore that folder first.',
							array('reason' => 'protection_boundary', 'folder_id' => (int)$folder_id, 'standing' => true));
					}
					$entity->set('fil_fol_folder_id', null);
					$folder_id = 0;
				}
			}
			// Asked wherever it lands, not only when it is re-rooted: while this file
			// sat in the trash somebody may have made a new one by the same name in
			// the same folder, and coming back on top of it is not a restore.
			// Suffixed rather than refused — the user asked for their file back, and
			// a name is a smaller thing to change than the answer.
			$owner_id = (int)$entity->get('fil_usr_user_id');
			$title    = $entity->get('fil_title');
			if (DriveHelper::file_name_taken($owner_id, $folder_id, $title, (int)$entity->key)) {
				$base = _drive_restore_suffixed($title, ' (restored)');
				$name = $base;
				for ($i = 2; DriveHelper::file_name_taken($owner_id, $folder_id, $name, (int)$entity->key) && $i < 100; $i++) {
					$name = _drive_restore_suffixed($title, ' (restored) ' . $i);
				}
				$entity->set('fil_title', $name);
			}
			if ($folder_id > 0) {
				$refused = _drive_restore_rekey_in_place(DriveHelper::ENTITY_FILE, (int)$entity->key, $entity_owner, $user_id, $file_keys);
				if ($refused !== null) {
					return $refused;
				}
			}
			$entity->set('fil_delete_time', null);
			try {
				$saved = DriveHelper::place_into($folder_id, function () use ($entity, $from_folder_id, $folder_id, $entity_owner, $user_id) {
					$save = function () use ($entity) {
						return DriveHelper::save_file_unless_name_taken($entity);
					};
					if ($folder_id === $from_folder_id) {
						return $save();
					}
					return DriveHelper::reparent_and_tell(DriveHelper::ENTITY_FILE, (int)$entity->key, $from_folder_id, $folder_id,
						$entity_owner, $user_id, $save);
				});
			} catch (DriveDestinationTrashedException $e) {
				// The folder was live when it was chosen above and is not now. The
				// file stays in the trash rather than coming back invisible.
				return LogicResult::error('The folder this was in went to the trash while restoring. Try again.', array('reason' => 'parent_trashed'));
			}
			if (!$saved) {
				// Something else claimed the free name between the search above and
				// here. The file is still safely in the trash.
				return LogicResult::error('That name was taken while restoring. Try again.', array('reason' => 'name_taken'));
			}
		}

		FileChange::record(FileChange::KIND_RESTORED, $entity_type, $entity_id, $entity_owner, $user_id);
	} finally {
		DriveHelper::owner_write_unlock($entity_owner);
	}

	return LogicResult::render(array('ok' => true));
}

/**
 * Put a Fortress item's keys right for where it is restored to, before any of
 * it comes back: the readers of a folder can change while something sits in
 * its trash, and the cascade that un-trashes a subtree moves bytes row by row,
 * so it cannot be rolled back afterwards. Its own transaction; supplied keys
 * that match are applied, anything else is refused as keys_needed with nothing
 * restored. Returns the refusal, or null to go on.
 */
function _drive_restore_rekey_in_place($entity_type, $entity_id, $owner_id, $actor_id, array $file_keys) {
	$db = DbConnector::get_instance()->get_db_link();
	$own = !$db->inTransaction();
	if ($own) $db->beginTransaction();
	try {
		DriveHelper::rekey_fortress($entity_type, $entity_id, $owner_id, $actor_id, $file_keys);
		if ($own) $db->commit();
	} catch (DriveKeysNeededException $e) {
		if ($own && $db->inTransaction()) $db->rollBack();
		return DriveHelper::keys_needed_refusal($e);
	} catch (Throwable $e) {
		if ($own && $db->inTransaction()) $db->rollBack();
		throw $e;
	}
	return null;
}

/**
 * Put a suffix on a file name before its extension, so a restored `report.docx`
 * comes back as `report (restored).docx` and still opens in the thing that made
 * it. Trimmed to the column width from the front of the stem, never the suffix
 * — a name cut to `report (restor` would collide all over again.
 */
function _drive_restore_suffixed($title, $suffix) {
	$dot  = strrpos($title, '.');
	$stem = ($dot === false || $dot === 0) ? $title : substr($title, 0, $dot);
	$ext  = ($dot === false || $dot === 0) ? ''     : substr($title, $dot);
	$room = 255 - strlen($suffix) - strlen($ext);
	if ($room < 1) {
		return substr($suffix . $ext, 0, 255);
	}
	return substr($stem, 0, $room) . $suffix . $ext;
}

function drive_restore_logic_descriptor(): array {
	return array(
		'auth' => array('min_user_permission' => 0),
		'description'      => 'Restore a Drive file or folder from the trash. Owner only.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'input'            => array(
			'entity_type' => array('type' => 'string', 'required' => true, 'enum' => array('file', 'folder'), 'label' => 'Entity type'),
			'entity_id'   => array('type' => 'int', 'required' => true, 'label' => 'Entity id'),
			'file_keys'   => array('type' => 'object', 'required' => false, 'label' => 'For a Fortress restore that changes who reads its files: { file_id: { user_id: wrapped_file_key } } for their readers where it lands, as a keys_needed refusal names them'),
		),
	);
}
?>

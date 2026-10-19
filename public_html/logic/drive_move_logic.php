<?php

function drive_move_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	// DriveSealed is the drive_sealed consumer bootstrap — it loads only through
	// the loader, so its registrations attribute to the consumer.
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	VaultUnlock::loadConsumerBootstraps();
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
	// 0 and omitted both mean the root. A negative id is not a folder and not
	// the root either: a client that sends one (a provisional local id, an
	// arithmetic slip) meant a folder this server has never seen, and moving
	// the item to the root instead would look like success.
	$parent_id   = isset($input['parent_id']) ? (int)$input['parent_id'] : 0;
	if ($parent_id < 0) {
		return LogicResult::error('Destination folder not found.', array('reason' => 'invalid_parent', 'folder_id' => $parent_id, 'standing' => true));
	}

	if ($entity_type !== DriveHelper::ENTITY_FILE && $entity_type !== DriveHelper::ENTITY_FOLDER) {
		return LogicResult::error('Invalid entity type.', array('reason' => 'invalid_request', 'standing' => true));
	}

	$entity = DriveHelper::load_entity($entity_type, $entity_id);
	if (!$entity) {
		return LogicResult::error('Item not found.', array('reason' => 'not_found'));
	}
	if (!DriveHelper::can_write($entity_type, $entity, $user_id, $session->get_permission())) {
		return LogicResult::error('You do not have permission to move this item.', array('reason' => 'forbidden', 'standing' => true));
	}

	// Already there: nothing to move. Answered as success so a retried move
	// converges instead of being refused for a collision with itself, and so
	// no conversion, depth or cycle work runs for a move that changes nothing.
	$current_parent = (int)$entity->get($entity_type === DriveHelper::ENTITY_FOLDER ? 'fol_parent_folder_id' : 'fil_fol_folder_id');
	if ($current_parent === $parent_id) {
		return LogicResult::render(array(
			'ok'        => true,
			'unchanged' => true,
			'item'      => ($entity_type === DriveHelper::ENTITY_FOLDER)
				? DriveHelper::folder_export($entity)
				: DriveHelper::file_export($entity),
		));
	}

	// Where the client last saw the item: `from_parent_id`, 0 for the top. A
	// client that decided this move before another one reached the server would
	// otherwise put the item back over that move unseen; refused, it hears the
	// newer move and settles the two as moves made on both sides are settled.
	// Asked here, before any conversion's byte work, and again under the owner's
	// lock where the move lands.
	$from_parent = (isset($input['from_parent_id']) && $input['from_parent_id'] !== '') ? (int)$input['from_parent_id'] : null;
	if ($from_parent !== null && $current_parent !== $from_parent) {
		return LogicResult::error('That item has been moved since.', array('reason' => 'moved_since'));
	}

	if ($parent_id > 0) {
		$target = DriveHelper::load_folder($parent_id);
		if (!$target) {
			return LogicResult::error('Destination folder not found.', array('reason' => 'parent_not_found'));
		}
		if (!DriveHelper::can_write(DriveHelper::ENTITY_FOLDER, $target, $user_id, $session->get_permission())) {
			return LogicResult::error('You do not have access to the destination folder.', array('reason' => 'forbidden', 'standing' => true));
		}
		if (DriveHelper::folder_is_trashed($target)) {
			// Moving something into the trash is what drive_trash is for, and it
			// records the move so a restore can undo it. Landing an item here
			// instead would hide it with no record of where it came from.
			return LogicResult::error('That folder is in the trash.', array('reason' => 'parent_trashed', 'folder_id' => (int)$parent_id));
		}
		// Single-owner-tree rule: an item may only live under a folder owned by
		// the item's owner. Without this, the destination owner's trash /
		// delete-forever cascades (which select by folder) would operate on
		// another user's rows.
		if (DriveHelper::owner_id_of($entity_type, $entity) !== (int)$target->get('fol_usr_user_id')) {
			return LogicResult::error('Items can only be moved within their owner\'s Drive.', array('reason' => 'forbidden', 'standing' => true));
		}
	} elseif (!DriveHelper::owns($entity_type, $entity, $user_id)) {
		// The top level is the item's OWNER's: moved there, it lands at the top of
		// the owner's Drive, outside every folder shared with the mover. An editor
		// reaches the item through a share, and taking it out of the share is not
		// something that grant covers.
		return LogicResult::error('Only the owner can move this to the top of their Drive.', array('reason' => 'forbidden', 'standing' => true));
	}

	// Protection-boundary guard. A folder's level is the floor for everything
	// inside it, so an item may not land in a folder of a different level — but
	// what "may not" means depends on which boundary is crossed:
	//
	//   Fortress: refused outright. The server holds no key and never transforms
	//   those bytes, so converting is a client-side act (re-upload) —
	//   docs/drive_encryption.md.
	//
	//   Standard <-> Private: the server holds the key wrapping, so it CAN
	//   convert. A single file is converted in place here; a whole subtree, or a
	//   file too large to convert inside one request, goes through the folder's
	//   level change, which runs in bounded batches with a receipt.
	//
	// A protected folder may still move to the root (a protected tree is a
	// top-level tree).
	require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
	$item_level = ($entity_type === DriveHelper::ENTITY_FILE)
		? $entity->protection_level()
		: DriveHelper::folder_level($entity);
	// A FOLDER carries its own level wherever it goes, and the root imposes no
	// floor on it -- a protected tree is a top-level tree, which is the shape a
	// vault is created in to begin with. Reading the root as Standard made
	// moving one back to the top look like a conversion and refused it, so a
	// vault folder that had been put inside another vault could never come out
	// again.
	//
	// A FILE is the opposite, and the asymmetry is deliberate: a file's
	// protection comes from the folder holding it, so at the root there is
	// nothing to hold it. An encrypted file cannot be created at the root, and
	// must not arrive there by being moved -- every sync client reads a file's
	// encryption from its parent, so one loose at the root reads as plaintext
	// on every device that sees it.
	$dest_level = ($parent_id > 0)
		? DriveHelper::folder_level($target)
		: (($entity_type === DriveHelper::ENTITY_FOLDER) ? $item_level : ProtectionLevel::STANDARD);
	$convert_file_to = null;

	if ($entity_type === DriveHelper::ENTITY_FILE) {
		if ($item_level !== $dest_level) {
			// Nothing converts a file across a Fortress edge -- not the server, which
			// never holds a Fortress key, and not the browser. The user's own route
			// is a download and an upload, so that is what the refusal names.
			if ($item_level === ProtectionLevel::FORTRESS) {
				return LogicResult::error('A Fortress file cannot be moved out of its vault. Download it and upload it where you want it.',
					array('reason' => 'protection_boundary', 'folder_id' => (int)$parent_id, 'standing' => true));
			}
			if ($dest_level === ProtectionLevel::FORTRESS) {
				return LogicResult::error('A file cannot be moved into a Fortress vault. Download it and upload it into the vault.',
					array('reason' => 'protection_boundary', 'folder_id' => (int)$parent_id, 'standing' => true));
			}
			$plain_bytes = $entity->plain_size_bytes();
			if ($plain_bytes > ProtectionLevelChange::BYTE_BUDGET) {
				return LogicResult::error('That file is too large to convert during a move. Move it with its folder, or change the folder\'s protection level.', array('reason' => 'level_change_refused', 'standing' => true));
			}
			// Private carries no public link and no member grant. A folder-wide
			// level change reports those and revokes them once the owner confirms;
			// a move has no such step, so it refuses and names what is in the way
			// rather than cutting someone's access off the back of a drag.
			if ($dest_level === ProtectionLevel::PRIVATE_) {
				$shared = DriveHelper::file_sharing_counts($entity_id);
				if ($shared['links'] > 0 || $shared['grants'] > 0) {
					$parts = array();
					if ($shared['links'] > 0) {
						$parts[] = $shared['links'] . ' public link' . ($shared['links'] === 1 ? '' : 's');
					}
					if ($shared['grants'] > 0) {
						$parts[] = $shared['grants'] . ' member' . ($shared['grants'] === 1 ? '' : 's') . ' with access';
					}
					return LogicResult::error('That file has ' . implode(' and ', $parts)
						. ', and a Private file can have neither. Remove the sharing first, then move it.',
						array('reason' => 'level_change_refused', 'standing' => true));
				}
			}
			$convert_file_to = $dest_level;
		}
	} else {
		if ($item_level !== $dest_level) {
			if ($item_level === ProtectionLevel::FORTRESS || $dest_level === ProtectionLevel::FORTRESS) {
				return LogicResult::error('A Fortress folder can only sit at the Drive root or inside another Fortress folder.',
					array('reason' => 'protection_boundary', 'folder_id' => (int)$parent_id, 'standing' => true));
			}
			// Standard <-> Private for a whole subtree is the level change, not a
			// move: it is byte work with a receipt, and silently starting it from
			// a drag would be a surprise.
			return LogicResult::error('That folder is ' . ProtectionLevel::label($item_level)
				. ' and the destination is ' . ProtectionLevel::label($dest_level)
				. '. Change the folder\'s protection level first, then move it.',
				array('reason' => 'level_change_refused', 'standing' => true));
		}
	}

	if ($entity_type === DriveHelper::ENTITY_FOLDER) {
		if (DriveHelper::would_create_cycle($entity_id, $parent_id)) {
			return LogicResult::error('You cannot move a folder into itself or one of its subfolders.', array('reason' => 'move_into_self'));
		}
		$base_depth = DriveHelper::depth($parent_id) + 1;
		if ($base_depth + DriveHelper::subtree_height($entity_id) > DriveHelper::max_depth()) {
			return LogicResult::error('That move would exceed the maximum folder depth.', array('reason' => 'too_deep', 'standing' => true));
		}
		$owner_id = (int)$entity->get('fol_usr_user_id');
		$holder = DriveHelper::folder_name_holder($owner_id, $parent_id, $entity->get('fol_name'), $entity_id);
		if ($holder !== null) {
			return LogicResult::error('A folder with that name already exists in the destination.', DriveHelper::name_taken_data($holder, $user_id));
		}
		// Held against the destination going to the trash between the check at the
		// top and the write here — the depth, cycle and name checks all run in
		// between, and landing a subtree under a trashed folder would hide all of
		// it at once.
		//
		// And held against a trash of the owner's tree from the write to its
		// change row, so a trash that checks what changed since a cursor cannot
		// read this folder placed and not yet recorded (DriveHelper::
		// owner_write_lock).
		DriveHelper::owner_write_lock($owner_id);
		try {
			try {
				$saved = _drive_move_land($entity_type, $entity, $parent_id, $owner_id, $user_id, $from_parent);
			} catch (DriveDestinationTrashedException $e) {
				return LogicResult::error('That folder is in the trash.', array('reason' => 'parent_trashed', 'folder_id' => (int)$parent_id));
			} catch (DriveMovedSinceException $e) {
				return LogicResult::error('That item has been moved since.', array('reason' => 'moved_since'));
			}
			if (!$saved) {
				$holder = DriveHelper::folder_name_holder($owner_id, $parent_id, $entity->get('fol_name'), $entity_id);
				return LogicResult::error('A folder with that name already exists in the destination.', DriveHelper::name_taken_data($holder, $user_id));
			}
		} finally {
			DriveHelper::owner_write_unlock($owner_id);
		}
	} else {
		$owner_id = (int)$entity->get('fil_usr_user_id');
		// Asked before any conversion runs. Sealing or unsealing a file is byte
		// work, and doing it only to refuse the move afterwards would spend it
		// for nothing.
		$holder = DriveHelper::file_name_holder($owner_id, $parent_id, $entity->get('fil_title'), $entity_id);
		if ($holder !== null) {
			return LogicResult::error('A file with that name already exists in the destination.', DriveHelper::name_taken_data($holder, $user_id));
		}
		// Convert before the move lands, so a failure leaves the file where it was
		// at the level it was, rather than in a folder whose promise it breaks.
		if ($convert_file_to !== null) {
			try {
				if ($convert_file_to === ProtectionLevel::PRIVATE_) {
					DriveSealed::sealExistingFile($entity);   // public key only
				} else {
					DriveSealed::unsealExistingFile($entity); // needs the window
				}
			} catch (VaultLockedException $e) {
				return LogicResult::error('Unlock your vault to move a Private file out of its folder.', array('reason' => 'vault_needed'));
			} catch (Exception $e) {
				error_log('Drive move conversion failed for file ' . $entity_id . ': ' . $e->getMessage());
				return LogicResult::error('That file could not be converted for its new folder.', array('reason' => 'storage_failed'));
			}
			$entity = DriveHelper::load_file($entity_id);
		}
		// As above, and wider still for a file: the protection-level conversion
		// between the check and here is byte work. The owner's lock is taken
		// after it, around the write and its row only.
		DriveHelper::owner_write_lock($owner_id);
		try {
			try {
				$saved = _drive_move_land($entity_type, $entity, $parent_id, $owner_id, $user_id, $from_parent);
			} catch (DriveDestinationTrashedException $e) {
				return LogicResult::error('That folder is in the trash.', array('reason' => 'parent_trashed', 'folder_id' => (int)$parent_id));
			} catch (DriveMovedSinceException $e) {
				return LogicResult::error('That item has been moved since.', array('reason' => 'moved_since'));
			}
			if (!$saved) {
				$holder = DriveHelper::file_name_holder($owner_id, $parent_id, $entity->get('fil_title'), $entity_id);
				return LogicResult::error('A file with that name already exists in the destination.', DriveHelper::name_taken_data($holder, $user_id));
			}
		} finally {
			DriveHelper::owner_write_unlock($owner_id);
		}
	}

	return LogicResult::render(array(
		'ok'   => true,
		'item' => ($entity_type === DriveHelper::ENTITY_FOLDER)
			? DriveHelper::folder_export($entity)
			: DriveHelper::file_export($entity),
	));
}

/**
 * Land a move: the item's new parent, its `moved` row, and a `moved` row
 * addressed to each user whose share it leaves (DriveHelper::reparent_and_tell),
 * as one write, inside the destination's placement lock. The caller holds the
 * owner's write lock around it, so a trash that checks what changed since a
 * cursor never reads the item placed and its rows not yet there.
 *
 * The folder it leaves is read afresh here, under the lock: the request's own
 * read is from before a file's conversion, and another move can land in
 * between. Throws DriveMovedSinceException, with nothing written, when that
 * folder is neither `$from_parent` (the client's `from_parent_id`; null asks
 * nothing) nor the destination.
 *
 * Returns false, with nothing written, when the destination already holds the
 * name; throws DriveDestinationTrashedException as place_into() does.
 */
function _drive_move_land($entity_type, $entity, $parent_id, $owner_id, $actor_id, $from_parent) {
	$parent_column = ($entity_type === DriveHelper::ENTITY_FOLDER) ? 'fol_parent_folder_id' : 'fil_fol_folder_id';
	$entity_id = (int)$entity->key;
	$from_id = DriveHelper::parent_unless_moved_since($entity_type, $entity_id, $from_parent, $parent_id);
	return DriveHelper::place_into($parent_id, function () use ($entity_type, $entity, $entity_id, $parent_id, $owner_id, $actor_id, $parent_column, $from_id) {
		return DriveHelper::reparent_and_tell($entity_type, $entity_id, $from_id, $parent_id, $owner_id, $actor_id,
			function () use ($entity_type, $entity, $parent_id, $parent_column) {
				$entity->set($parent_column, $parent_id > 0 ? $parent_id : null);
				return ($entity_type === DriveHelper::ENTITY_FOLDER)
					? DriveHelper::save_folder_unless_name_taken($entity)
					: DriveHelper::save_file_unless_name_taken($entity);
			},
			FileChange::KIND_MOVED);
	});
}

function drive_move_logic_descriptor(): array {
	return array(
		'description'      => 'Move a Drive file or folder to another folder (or the root).',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'input'            => array(
			'entity_type' => array('type' => 'string', 'required' => true, 'enum' => array('file', 'folder'), 'label' => 'Entity type'),
			'entity_id'   => array('type' => 'int', 'required' => true, 'label' => 'Entity id'),
			'parent_id'   => array('type' => 'int', 'required' => false, 'min' => 0, 'label' => 'Destination folder id (omit or 0 for root)'),
			'from_parent_id' => array('type' => 'int', 'required' => false, 'min' => 0, 'label' => 'Folder the item was last seen in (0 for root); refused as moved_since if it stands elsewhere now'),
		),
	);
}
?>

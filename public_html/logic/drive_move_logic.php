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
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$entity_type = (string)($input['entity_type'] ?? '');
	$entity_id   = (int)($input['entity_id'] ?? 0);
	$parent_id   = (isset($input['parent_id']) && (int)$input['parent_id'] > 0) ? (int)$input['parent_id'] : 0;

	if ($entity_type !== DriveHelper::ENTITY_FILE && $entity_type !== DriveHelper::ENTITY_FOLDER) {
		return LogicResult::error('Invalid entity type.');
	}

	$entity = DriveHelper::load_entity($entity_type, $entity_id);
	if (!$entity) {
		return LogicResult::error('Item not found.');
	}
	if (!DriveHelper::can_write($entity_type, $entity, $user_id, $session->get_permission())) {
		return LogicResult::error('You do not have permission to move this item.');
	}

	if ($parent_id > 0) {
		$target = DriveHelper::load_folder($parent_id);
		if (!$target) {
			return LogicResult::error('Destination folder not found.');
		}
		if (!DriveHelper::can_write(DriveHelper::ENTITY_FOLDER, $target, $user_id, $session->get_permission())) {
			return LogicResult::error('You do not have access to the destination folder.');
		}
		if (DriveHelper::folder_is_trashed($target)) {
			// Moving something into the trash is what drive_trash is for, and it
			// records the move so a restore can undo it. Landing an item here
			// instead would hide it with no record of where it came from.
			return LogicResult::error('That folder is in the trash.', array('reason' => 'parent_trashed'));
		}
		// Single-owner-tree rule: an item may only live under a folder owned by
		// the item's owner. Without this, the destination owner's trash /
		// delete-forever cascades (which select by folder) would operate on
		// another user's rows.
		if (DriveHelper::owner_id_of($entity_type, $entity) !== (int)$target->get('fol_usr_user_id')) {
			return LogicResult::error('Items can only be moved within their owner\'s Drive.');
		}
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
	$dest_level = ($parent_id > 0) ? DriveHelper::folder_level($target) : ProtectionLevel::STANDARD;
	$convert_file_to = null;

	if ($entity_type === DriveHelper::ENTITY_FILE) {
		$item_level = $entity->protection_level();
		if ($item_level !== $dest_level) {
			if ($item_level === ProtectionLevel::FORTRESS || $dest_level === ProtectionLevel::FORTRESS) {
				return LogicResult::error('Move a Fortress file by re-uploading it; only your browser can convert it.');
			}
			$plain_bytes = $entity->plain_size_bytes();
			if ($plain_bytes > DriveSealed::TRANSITION_BYTE_BUDGET) {
				return LogicResult::error('That file is too large to convert during a move. Move it with its folder, or change the folder\'s protection level.');
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
						. ', and a Private file can have neither. Remove the sharing first, then move it.');
				}
			}
			$convert_file_to = $dest_level;
		}
	} else {
		$item_level = DriveHelper::folder_level($entity);
		if ($item_level !== $dest_level) {
			if ($item_level === ProtectionLevel::FORTRESS || $dest_level === ProtectionLevel::FORTRESS) {
				return LogicResult::error($parent_id > 0
					? 'A Fortress folder can only sit at the Drive root or inside another Fortress folder.'
					: 'That folder can\'t move here.');
			}
			// Standard <-> Private for a whole subtree is the level change, not a
			// move: it is byte work with a receipt, and silently starting it from
			// a drag would be a surprise.
			return LogicResult::error('That folder is ' . ProtectionLevel::label($item_level)
				. ' and the destination is ' . ProtectionLevel::label($dest_level)
				. '. Change the folder\'s protection level first, then move it.');
		}
	}

	if ($entity_type === DriveHelper::ENTITY_FOLDER) {
		if (DriveHelper::would_create_cycle($entity_id, $parent_id)) {
			return LogicResult::error('You cannot move a folder into itself or one of its subfolders.');
		}
		$base_depth = DriveHelper::depth($parent_id) + 1;
		if ($base_depth + DriveHelper::subtree_height($entity_id) > DriveHelper::max_depth()) {
			return LogicResult::error('That move would exceed the maximum folder depth.');
		}
		$owner_id = (int)$entity->get('fol_usr_user_id');
		if (DriveHelper::folder_name_taken($owner_id, $parent_id, $entity->get('fol_name'), $entity_id)) {
			return LogicResult::error('A folder with that name already exists in the destination.');
		}
		$entity->set('fol_parent_folder_id', $parent_id > 0 ? $parent_id : null);
		if (!DriveHelper::save_folder_unless_name_taken($entity)) {
			return LogicResult::error('A folder with that name already exists in the destination.');
		}
		$owner = $owner_id;
	} else {
		$owner_id = (int)$entity->get('fil_usr_user_id');
		// Asked before any conversion runs. Sealing or unsealing a file is byte
		// work, and doing it only to refuse the move afterwards would spend it
		// for nothing.
		if (DriveHelper::file_name_taken($owner_id, $parent_id, $entity->get('fil_title'), $entity_id)) {
			return LogicResult::error('A file with that name already exists in the destination.', array('reason' => 'name_taken'));
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
				return LogicResult::error('Unlock your vault to move a Private file out of its folder.');
			} catch (Exception $e) {
				error_log('Drive move conversion failed for file ' . $entity_id . ': ' . $e->getMessage());
				return LogicResult::error('That file could not be converted for its new folder.');
			}
			$entity = DriveHelper::load_file($entity_id);
		}
		$entity->set('fil_fol_folder_id', $parent_id > 0 ? $parent_id : null);
		if (!DriveHelper::save_file_unless_name_taken($entity)) {
			return LogicResult::error('A file with that name already exists in the destination.', array('reason' => 'name_taken'));
		}
		$owner = $owner_id;
	}

	FileChange::record(FileChange::KIND_MOVED, $entity_type, $entity_id, $owner, $user_id);

	return LogicResult::render(array(
		'ok'   => true,
		'item' => ($entity_type === DriveHelper::ENTITY_FOLDER)
			? DriveHelper::folder_export($entity)
			: DriveHelper::file_export($entity),
	));
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
			'parent_id'   => array('type' => 'int', 'required' => false, 'label' => 'Destination folder id (omit for root)'),
		),
	);
}
?>

<?php

/**
 * drive_level_change — change a Drive folder tree's protection level.
 *
 * The change to the FOLDER is immediate: from the moment it returns, everything
 * uploaded into the tree lands at the new level. The files already inside are
 * converted afterwards, in bounded batches, by drive_level_batch — the same
 * shape mail uses when a domain is raised, and for the same reason: the promise
 * has to take effect at once, while the byte work takes as long as it takes.
 *
 * v1 covers Standard <-> Private only (the server holds the key wrapping for
 * both, so it can convert). Anything involving Fortress is client-custody work
 * and is refused here.
 */

function drive_level_change_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	// DriveSealed is the drive_sealed consumer bootstrap — it loads only through
	// the loader, so its registrations attribute to the consumer.
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	VaultUnlock::loadConsumerBootstraps();
	require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
	require_once(PathHelper::getIncludePath('data/folders_class.php'));
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

	$folder_id = (int)($input['folder_id'] ?? 0);
	$target    = ProtectionLevel::normalize($input['protection_level'] ?? null);

	$folder = DriveHelper::load_folder($folder_id);
	if (!$folder) {
		return LogicResult::error('Folder not found.');
	}
	// Changing what a folder promises is the owner's call alone — not an editor's.
	if (!$folder->is_owned_by($user_id)) {
		return LogicResult::error('Only the owner can change a folder\'s protection level.');
	}

	$current = DriveHelper::folder_level($folder);
	if ($current === $target) {
		return LogicResult::render(array(
			'ok' => true, 'protection_level' => $target, 'remaining' => 0, 'unchanged' => true,
		));
	}
	if ($current === ProtectionLevel::FORTRESS || $target === ProtectionLevel::FORTRESS) {
		return LogicResult::error('A Fortress folder is encrypted by your browser, so its level can\'t be changed here.');
	}
	if (!in_array($target, array(ProtectionLevel::STANDARD, ProtectionLevel::PRIVATE_), true)) {
		return LogicResult::error('That is not a protection level Drive offers.');
	}

	// A protected tree is a top-level tree: raising a folder that sits inside a
	// Standard parent would leave a protected subtree hanging under an unprotected
	// one, which is exactly what the create/move rules refuse.
	$parent_id = (int)$folder->get('fol_parent_folder_id');
	if ($target !== ProtectionLevel::STANDARD && $parent_id > 0) {
		return LogicResult::error('Only a top-level folder can be made '
			. ProtectionLevel::label($target) . '. Move it to the Drive root first.');
	}

	if ($target === ProtectionLevel::PRIVATE_) {
		if (DriveSealed::vaultFor($user_id) === null) {
			return LogicResult::error('Set up your vault before making a folder Private.');
		}
		// A Private folder can carry no public link and no member grants (v1), so
		// say what will be revoked rather than revoking it silently.
		$blockers = _drive_level_sharing_blockers($folder_id);
		if (!empty($blockers) && empty($input['confirm_revoke_sharing'])) {
			return LogicResult::render(array(
				'ok' => false,
				'needs_confirmation' => true,
				'blockers' => $blockers,
			));
		}
		if (!empty($blockers)) {
			_drive_level_revoke_sharing($folder_id);
		}
	} else {
		// Lowering reads every sealed file, so the window has to be open before
		// the promise is dropped — not halfway through.
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		if (VaultUnlock::secretKey($user_id) === null) {
			return LogicResult::error('Unlock your vault to make this folder Standard again.');
		}
	}

	// Flip the whole subtree at once: a child never sits below its parent.
	$ids = DriveSealed::subtreeFolderIds($folder_id);
	$db = DbConnector::get_instance()->get_db_link();
	$db->prepare("UPDATE fol_folders SET fol_protection_level = ?
	              WHERE fol_folder_id IN (" . DriveHelper::int_in_list($ids) . ")")
		->execute(array($target));

	FileChange::record(FileChange::KIND_UPDATED, DriveHelper::ENTITY_FOLDER, $folder_id, $user_id, $user_id);

	$backlog = DriveSealed::transitionBacklog($folder_id, $target);
	return LogicResult::render(array(
		'ok'               => true,
		'protection_level' => $target,
		'folders'          => count($ids),
		'remaining'        => $backlog['files'],
		'remaining_bytes'  => $backlog['bytes'],
	));
}

/** Public links and member grants anywhere in the subtree — what going Private will end. */
function _drive_level_sharing_blockers($folder_id): array {
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	VaultUnlock::loadConsumerBootstraps(); // DriveSealed loads only through the loader
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	$ids = DriveSealed::subtreeFolderIds($folder_id);
	$in = DriveHelper::int_in_list($ids);
	$db = DbConnector::get_instance()->get_db_link();

	$out = array();
	$links = (int)$db->query(
		"SELECT COUNT(*) FROM fsl_file_share_links
		 WHERE fsl_revoked_time IS NULL
		   AND ((fsl_entity_type = 'folder' AND fsl_entity_id IN ($in))
		     OR (fsl_entity_type = 'file' AND fsl_entity_id IN
		         (SELECT fil_file_id FROM fil_files WHERE fil_fol_folder_id IN ($in))))")->fetchColumn();
	if ($links > 0) {
		$out[] = array('kind' => 'links', 'count' => $links,
			'label' => $links . ' public link' . ($links === 1 ? '' : 's') . ' will stop working');
	}

	$grants = (int)$db->query(
		"SELECT COUNT(*) FROM fga_file_access_grants
		 WHERE (fga_entity_type = 'folder' AND fga_entity_id IN ($in))
		    OR (fga_entity_type = 'file' AND fga_entity_id IN
		        (SELECT fil_file_id FROM fil_files WHERE fil_fol_folder_id IN ($in)))")->fetchColumn();
	if ($grants > 0) {
		$out[] = array('kind' => 'grants', 'count' => $grants,
			'label' => $grants . ' member' . ($grants === 1 ? '' : 's') . ' will lose access');
	}
	return $out;
}

/** Revoke what Private cannot carry, once the owner has confirmed it. */
function _drive_level_revoke_sharing($folder_id): void {
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	VaultUnlock::loadConsumerBootstraps(); // DriveSealed loads only through the loader
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	$ids = DriveSealed::subtreeFolderIds($folder_id);
	$in = DriveHelper::int_in_list($ids);
	$db = DbConnector::get_instance()->get_db_link();

	$db->exec(
		"UPDATE fsl_file_share_links SET fsl_revoked_time = now()
		 WHERE fsl_revoked_time IS NULL
		   AND ((fsl_entity_type = 'folder' AND fsl_entity_id IN ($in))
		     OR (fsl_entity_type = 'file' AND fsl_entity_id IN
		         (SELECT fil_file_id FROM fil_files WHERE fil_fol_folder_id IN ($in))))");
	$db->exec(
		"DELETE FROM fga_file_access_grants
		 WHERE (fga_entity_type = 'folder' AND fga_entity_id IN ($in))
		    OR (fga_entity_type = 'file' AND fga_entity_id IN
		        (SELECT fil_file_id FROM fil_files WHERE fil_fol_folder_id IN ($in)))");
}

function drive_level_change_logic_descriptor(): array {
	return array(
		'description'      => 'Change a top-level Drive folder tree\'s protection level between standard and private (owner only). The folder changes immediately; existing files inside are converted afterwards by drive_level_batch. Going Private ends any public links and member grants in the subtree — the first call reports them as `blockers` and does nothing until `confirm_revoke_sharing` is sent. Going back to Standard requires an open unlock window.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'input'            => array(
			'folder_id'               => array('type' => 'int', 'required' => true, 'label' => 'Folder id'),
			'protection_level'        => array('type' => 'string', 'required' => true, 'max_length' => 16, 'label' => 'Target level (standard or private)'),
			'confirm_revoke_sharing'  => array('type' => 'bool', 'required' => false, 'label' => 'Accept losing links and member access'),
		),
	);
}
?>

<?php

/**
 * drive_level_change — change a Drive folder tree's protection level.
 *
 * The change to the FOLDER is immediate: from the moment it returns, everything
 * uploaded into the tree lands at the new level. The files already inside are
 * converted afterwards, in bounded batches, by drive_level_batch. The sequence
 * and its security rules are ProtectionLevelChange's (a recent second factor
 * for an owner who has one; lowering needs the owner's window); what is Drive's
 * own is the tree (DriveFolderLevel) and the sharing rule below.
 *
 * Standard <-> Private only (the server holds the key wrapping for both, so it
 * can convert). Anything involving Fortress is client-custody work and is
 * refused here.
 */

function drive_level_change_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$target = ProtectionLevel::fromInput($input['protection_level'] ?? null);
	if ($target === null) {
		return LogicResult::error('That is not a protection level Drive offers.');
	}
	$folder_id = (int)($input['folder_id'] ?? 0);
	$folder = DriveHelper::load_folder($folder_id);
	if (!$folder) {
		return LogicResult::error('Folder not found.');
	}
	// Changing what a folder promises is the owner's call alone — not an editor's.
	if (!$folder->is_owned_by($user_id)) {
		return LogicResult::error('Only the owner can change a folder\'s protection level.');
	}

	$confirmed = !empty($input['confirm_revoke_sharing']);
	$scope = new DriveFolderLevel($folder, $user_id);
	$gate = ProtectionLevelChange::gate($scope, $target);
	if ($gate['status'] === ProtectionLevelChange::UNCHANGED) {
		// Nothing to change, but a conversion that stopped part-way still has
		// files to do: the page resumes them.
		return LogicResult::render(array(
			'ok' => true, 'protection_level' => $target, 'remaining' => $scope->remaining(), 'unchanged' => true,
		));
	}
	if ($gate['status'] !== ProtectionLevelChange::OK) {
		return drive_level_change_refusal($gate);
	}

	// A Private folder can carry no public link and no member grants (v1), so
	// say what will be revoked rather than revoking it silently. A prerequisite
	// that refuses the change anyway answers first: nobody is asked to give up
	// their links for a change that cannot happen.
	if ($target === ProtectionLevel::PRIVATE_ && !$confirmed) {
		$blockers = DriveFolderLevel::sharingBlockers($folder_id);
		if (!empty($blockers)) {
			$refusal = $scope->blockers($target, $user_id);
			if ($refusal !== null) {
				return LogicResult::error($refusal);
			}
			return LogicResult::render(array(
				'ok' => false,
				'needs_confirmation' => true,
				'blockers' => $blockers,
			));
		}
	}

	$change = ProtectionLevelChange::change($scope, $target, $user_id);
	if ($change['status'] !== ProtectionLevelChange::OK) {
		return drive_level_change_refusal($change);
	}

	$backlog = DriveSealed::transitionBacklog($folder_id, $target);
	return LogicResult::render(array(
		'ok'               => true,
		'protection_level' => $target,
		'folders'          => count(DriveSealed::subtreeFolderIds($folder_id)),
		'remaining'        => $backlog['files'],
		'remaining_bytes'  => $backlog['bytes'],
	));
}

/** A refused or step-up verdict as the API answers it (requires_stepup for the page to confirm and retry). */
function drive_level_change_refusal(array $verdict): LogicResult {
	if ($verdict['status'] === ProtectionLevelChange::STEPUP) {
		return LogicResult::error($verdict['error'], array('requires_stepup' => true));
	}
	return LogicResult::error($verdict['error']);
}

function drive_level_change_logic_descriptor(): array {
	return array(
		'description'      => 'Change a top-level Drive folder tree\'s protection level between standard and private (owner only). The folder changes immediately; existing files inside are converted afterwards by drive_level_batch. Going Private ends any public links and member grants in the subtree — the first call reports them as `blockers` and does nothing until `confirm_revoke_sharing` is sent. Going back to Standard requires an open unlock window. A change asks for a recent second-factor confirmation (answered as `requires_stepup`) when the owner has a second factor.',
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

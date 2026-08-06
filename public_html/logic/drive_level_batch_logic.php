<?php

/**
 * drive_level_batch — convert one bounded batch of a folder tree's files to the
 * level that folder now promises.
 *
 * Called repeatedly by the receipt card until `remaining` reaches zero. Batches
 * are byte-budgeted rather than row-counted, because a Drive file has no size
 * cap worth trusting: 200 mail messages is at most 5 GB, 200 Drive files is
 * whatever the member put there.
 *
 * Raising needs only the owner's public key, so it runs from any of the owner's
 * sessions, locked or not. Lowering decrypts and therefore needs the window.
 */

function drive_level_batch_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('includes/DriveSealed.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));

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
	$folder = DriveHelper::load_folder($folder_id);
	if (!$folder) {
		return LogicResult::error('Folder not found.');
	}
	if (!$folder->is_owned_by($user_id)) {
		return LogicResult::error('Only the owner can convert this folder\'s files.');
	}

	// The target is the folder's CURRENT level, never a parameter: the promise was
	// already made by drive_level_change, and this action only catches the files
	// up to it. A caller cannot use this to convert a tree somewhere else.
	$target = DriveHelper::folder_level($folder);

	try {
		$result = DriveSealed::runTransitionBatch($folder_id, $target);
	} catch (VaultLockedException $e) {
		return LogicResult::error('Unlock your vault to keep converting these files.');
	}

	return LogicResult::render(array(
		'ok'               => true,
		'protection_level' => $target,
		'converted'        => $result['converted'],
		'failed'           => $result['failed'],
		'bytes'            => $result['bytes'],
		'remaining'        => $result['remaining'],
	));
}

function drive_level_batch_logic_descriptor(): array {
	return array(
		'description'      => 'Convert one bounded batch of a Drive folder tree\'s files to the folder\'s current protection level (owner only). Call repeatedly until `remaining` is 0. A pass that converts nothing while files remain means those files cannot be converted — stop and report rather than looping.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'folder_id' => array('type' => 'int', 'required' => true, 'label' => 'Folder id'),
		),
	);
}
?>

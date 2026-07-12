<?php

/**
 * drive_key_grants_sync — reconcile the per-user wrapped file keys for one or
 * more encrypted files (owner only). Paired with drive_share_sync: that action
 * grants *access* (FileAccessGrant); this one grants *readability* by storing the
 * file key sealed to each recipient's drive vault public key (FileKeyGrant). The
 * owner's browser unwraps the file key once and re-wraps it to every recipient —
 * no content is re-encrypted.
 *
 * Input `file_keys`: a map { file_id: { user_id: wrapped_file_key } }. Each file
 * reconciles to exactly the listed users (the owner's own key is always kept);
 * a user omitted from a file's set has their key grant revoked (row deleted).
 * For a folder share the client enumerates the subtree's files and sends one
 * entry per file. The wrapped blobs are opaque — the server never opens them.
 */

function drive_key_grants_sync_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/file_key_grants_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$file_keys = isset($input['file_keys']) && is_array($input['file_keys']) ? $input['file_keys'] : array();
	if (empty($file_keys)) {
		return LogicResult::error('No key grants supplied.');
	}

	$synced = 0;
	$skipped = array();
	foreach ($file_keys as $file_id => $wrapped) {
		$file_id = (int)$file_id;
		$file = DriveHelper::load_file($file_id);
		if (!$file || !$file->is_encrypted()) {
			$skipped[] = $file_id;
			continue;
		}
		// Key grants are owner-only (staff may manage on a member's behalf), the
		// same rule as access sharing.
		if (!DriveHelper::owns(DriveHelper::ENTITY_FILE, $file, $user_id) && (int)$session->get_permission() < 5) {
			$skipped[] = $file_id;
			continue;
		}
		$owner_id = (int)$file->get('fil_usr_user_id');
		$map = is_array($wrapped) ? $wrapped : array();
		FileKeyGrant::sync_for_file($file_id, $map, $owner_id);
		$synced++;
	}

	return LogicResult::render(array('ok' => true, 'synced' => $synced, 'skipped' => $skipped));
}

function drive_key_grants_sync_logic_descriptor(): array {
	return array(
		'description'      => 'Reconcile per-user wrapped file keys for encrypted Drive files (owner only). Body carries `file_keys`: a map { file_id: { user_id: wrapped_file_key } } of opaque browser-produced blobs; validated in the logic and passed through the boundary untouched.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(),
	);
}
?>

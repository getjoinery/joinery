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
 *
 * Each user whose key a file gains or loses is told through the change feed:
 * one `grant_changed` row per file per such user, addressed to them and
 * written in the same transaction as the key.
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

	$db = DbConnector::get_instance()->get_db_link();
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
		if (!DriveHelper::owns(DriveHelper::ENTITY_FILE, $file, $user_id) && !$file->can_write($session)) {
			$skipped[] = $file_id;
			continue;
		}
		$owner_id = (int)$file->get('fil_usr_user_id');
		$map = is_array($wrapped) ? $wrapped : array();

		// A key that arrives says nothing to the person it is for. Their
		// devices hold the file waiting for a key, and nothing in the feed
		// names it: the access grant that reached them came earlier, from
		// drive_share_sync, so a device that read the feed between the two
		// waits until it next walks everything. One row each, addressed to
		// them, and the same for a key taken away.
		//
		// Only added and removed keys. The owner's browser re-wraps every
		// recipient's key on every change to the share, and a sealed box is
		// randomized, so a blob that differs is the ordinary case and not news.
		//
		// The key and its row are one write, so a device that reads the feed
		// never finds a key nobody announced; and the file row is held for it,
		// so two reconciles of one file take turns and each change is told
		// once.
		$own = !$db->inTransaction();
		if ($own) $db->beginTransaction();
		try {
			$db->prepare('SELECT 1 FROM fil_files WHERE fil_file_id = ? FOR UPDATE')->execute(array($file_id));
			$removed = array();
			$added = FileKeyGrant::sync_for_file($file_id, $map, $owner_id, $removed);
			foreach (array_merge($added, $removed) as $changed_id) {
				FileChange::record(FileChange::KIND_GRANT_CHANGED, DriveHelper::ENTITY_FILE, $file_id, $owner_id, $user_id, $changed_id);
			}
			if ($own) $db->commit();
		} catch (Throwable $e) {
			if ($own && $db->inTransaction()) $db->rollBack();
			throw $e;
		}
		$synced++;
	}

	return LogicResult::render(array('ok' => true, 'synced' => $synced, 'skipped' => $skipped));
}

function drive_key_grants_sync_logic_descriptor(): array {
	return array(
		'auth' => array('min_user_permission' => 0),
		'description'      => 'Reconcile per-user wrapped file keys for encrypted Drive files (owner only). Body carries `file_keys`: a map { file_id: { user_id: wrapped_file_key } } of opaque browser-produced blobs; validated in the logic and passed through the boundary untouched.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'input'            => array(),
	);
}
?>

<?php

/**
 * drive_public_keys — batch-resolve members' `drive`-scope vault public keys, so
 * the share dialog can seal (wrap) an encrypted file's key to each recipient in
 * the browser. Keys are inherently public (anything may seal to them); the value
 * is null for a member who has not set up a Drive vault (the dialog then tells
 * the owner that member can't receive encrypted files yet).
 *
 * Input `identifiers`: a list of user ids and/or email addresses. Emails resolve
 * to a user server-side. The response echoes each identifier with its public key
 * (or null), plus the resolved user id when known.
 *
 * Input `folder_id` (alternative mode): resolve the folder's full READER set —
 * the owner plus every holder of an access grant on the folder or an ancestor —
 * and return their keys. An encrypted upload seals its file key to this set, so
 * the file lands readable by everyone who can already reach it. Requires write
 * access to the folder (public keys are public; the gate only prevents fishing
 * for another user's grant lists).
 */

function drive_public_keys_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$folder_id = (isset($input['folder_id']) && (int)$input['folder_id'] > 0) ? (int)$input['folder_id'] : 0;
	if ($folder_id) {
		$folder = DriveHelper::load_folder($folder_id);
		if (!$folder || !DriveHelper::can_write(DriveHelper::ENTITY_FOLDER, $folder, $user_id, $session->get_permission())) {
			return LogicResult::error('You do not have access to that folder.');
		}
		$raw = DriveHelper::reader_user_ids_for_folder($folder);
	} else {
		$raw = isset($input['identifiers']) && is_array($input['identifiers']) ? $input['identifiers'] : array();
	}
	$out = array();
	foreach ($raw as $ident) {
		$ident = trim((string)$ident);
		if ($ident === '') {
			continue;
		}
		$uid = 0;
		if (is_numeric($ident)) {
			$uid = (int)$ident;
		} else {
			$u = User::GetByEmail($ident);
			$uid = ($u && $u->key) ? (int)$u->key : 0;
		}
		$public_key = null;
		if ($uid > 0) {
			$vault = UserEncryptionVault::loadForUser($uid, UserEncryptionVault::SCOPE_DRIVE);
			if ($vault && $vault->key) {
				$public_key = (string)$vault->get('uev_public_key');
			}
		}
		$out[] = array(
			'identifier' => $ident,
			'user_id'    => $uid > 0 ? $uid : null,
			'public_key' => $public_key,
		);
	}

	return LogicResult::render(array('ok' => true, 'keys' => $out));
}

function drive_public_keys_logic_descriptor(): array {
	return array(
		'description'      => 'Resolve members\' drive-scope vault public keys for encrypted-file key wraps. Body carries either `identifiers` (a list of user ids and/or emails — the share-dialog mode) or `folder_id` (returns the folder\'s full reader set: owner + all grant holders; requires write access — the encrypted-upload mode). Both are validated in the logic and pass the boundary untouched.',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array('capability' => 'read'),
		'input'            => array(
			'folder_id' => array('type' => 'int', 'required' => false, 'label' => 'Folder id (reader-set mode)'),
		),
	);
}
?>

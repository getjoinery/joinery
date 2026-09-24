<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * drive_key_grants_reseal — the Drive half of rotating the caller's drive
 * vault key (VaultClientRotation). Every file key the caller can read is a
 * FileKeyGrant sealed to their drive public key: on their own files and on
 * files others shared with them. The browser opens each with the old key and
 * seals it to the new one.
 *
 * Only the caller's own grant rows, and only while their drive vault has a
 * rotation pending. drive_key_grants_sync is not this: it reconciles a file's
 * whole grantee set and is owner-only, so it could neither reach a grant on a
 * shared-with-me file nor be sent without restating everyone else's.
 *
 *   {mode: 'list', after_id, limit} -> {grants: [{grant_id, file_id, wrapped_file_key}], next_after_id}
 *   {mode: 'write', keys: {file_id: wrapped_file_key}} -> {written}
 *
 * @version 1.0
 */
function drive_key_grants_reseal_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/file_key_grants_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$vault = VaultClientCustody::loadVault($user_id, 'drive');
	if (!$vault || $vault->get('uev_pending_key_generation') === null) {
		return LogicResult::error('No key rotation is under way for your Drive vault.');
	}

	$db = DbConnector::get_instance()->get_db_link();
	$mode = (string)($input['mode'] ?? 'list');

	if ($mode === 'list') {
		$after = (int)($input['after_id'] ?? 0);
		$limit = max(1, min(VaultClientRotation::PAGE_MAX, (int)($input['limit'] ?? VaultClientRotation::PAGE_MAX)));
		$q = $db->prepare('SELECT fkg_file_key_grant_id, fkg_fil_file_id, fkg_wrapped_file_key FROM fkg_file_key_grants
			WHERE fkg_usr_user_id = ? AND fkg_file_key_grant_id > ? ORDER BY fkg_file_key_grant_id LIMIT ' . $limit);
		$q->execute(array($user_id, $after));
		$grants = array();
		$last = 0;
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$grants[] = array(
				'grant_id'         => (int)$row['fkg_file_key_grant_id'],
				'file_id'          => (int)$row['fkg_fil_file_id'],
				'wrapped_file_key' => (string)$row['fkg_wrapped_file_key'],
			);
			$last = (int)$row['fkg_file_key_grant_id'];
		}
		return LogicResult::render(array('grants' => $grants, 'next_after_id' => count($grants) === $limit ? $last : null));
	}

	if ($mode === 'write') {
		$keys = isset($input['keys']) && is_array($input['keys']) ? $input['keys'] : array();
		if (count($keys) > VaultClientRotation::PAGE_MAX) {
			return LogicResult::error('Too many keys in one request.');
		}
		$update = $db->prepare('UPDATE fkg_file_key_grants SET fkg_wrapped_file_key = ? WHERE fkg_fil_file_id = ? AND fkg_usr_user_id = ?');
		$written = 0;
		foreach ($keys as $file_id => $blob) {
			$blob = is_string($blob) ? trim($blob) : '';
			if ($blob === '' || strlen($blob) > 4096) {
				return LogicResult::error('A re-sealed file key is missing or malformed.');
			}
			$update->execute(array($blob, (int)$file_id, $user_id));
			$written += $update->rowCount();
		}
		return LogicResult::render(array('written' => $written));
	}

	return LogicResult::error('Unknown mode.');
}

function drive_key_grants_reseal_logic_descriptor(): array {
	return array(
		'description'      => 'During a drive vault key rotation: list the caller\'s own file key grants (mode list) or store them re-sealed to the new key (mode write, keys: {file_id: wrapped_file_key}). Only the caller\'s rows, only while a rotation is pending.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'auth'             => array('requires_browser_session' => true),
		'input'            => array(
			'mode'     => array('type' => 'string', 'required' => false, 'enum' => array('list', 'write'), 'label' => 'list or write'),
			'after_id' => array('type' => 'int', 'required' => false, 'label' => 'Cursor: last grant id'),
			'limit'    => array('type' => 'int', 'required' => false, 'label' => 'Grants per page'),
			'keys'     => array('type' => 'object', 'required' => false, 'label' => 'Re-sealed file keys by file id'),
		),
	);
}
?>

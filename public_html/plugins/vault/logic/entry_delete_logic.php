<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/** Trash one entry (soft delete). A trashed ciphertext is no less protected;
 *  restore returns it. */
function entry_delete_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/vault/data/vault_entries_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$id = isset($input['id']) ? (int)$input['id'] : 0;
	if (!$id) {
		return LogicResult::error('Missing the entry to delete.');
	}
	$entry = new VaultEntry($id, TRUE);
	if (!$entry->key || (int)$entry->get('vle_usr_user_id') !== $user_id) {
		return LogicResult::error('That entry does not belong to you.');
	}
	$entry->soft_delete();

	return LogicResult::render(['deleted' => true]);
}

function entry_delete_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Trash one encrypted password entry (soft delete)',
	];
}
?>

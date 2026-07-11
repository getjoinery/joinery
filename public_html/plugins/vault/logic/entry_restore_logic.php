<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/** Restore one trashed entry. */
function entry_restore_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/vault/data/vault_entries_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$id = isset($input['id']) ? (int)$input['id'] : 0;
	if (!$id) {
		return LogicResult::error('Missing the entry to restore.');
	}
	$entry = new VaultEntry($id, TRUE);
	if (!$entry->key || (int)$entry->get('vle_usr_user_id') !== $user_id) {
		return LogicResult::error('That entry does not belong to you.');
	}
	$entry->set('vle_delete_time', null);
	$entry->set('vle_updated_time', gmdate('Y-m-d H:i:s'));
	$entry->save();

	return LogicResult::render(['restored' => true]);
}

function entry_restore_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Restore one trashed encrypted password entry',
	];
}
?>

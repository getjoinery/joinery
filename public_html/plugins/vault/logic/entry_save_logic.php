<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/** Create or update one encrypted entry. `ciphertext` is a single opaque blob
 *  the browser produced; the server stores it without inspection. Passing `id`
 *  updates that (owned) entry; omitting it creates a new one. */
function entry_save_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/vault/data/vault_entries_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$ciphertext = isset($input['ciphertext']) ? (string)$input['ciphertext'] : '';
	if ($ciphertext === '') {
		return LogicResult::error('Missing the encrypted entry.');
	}

	$id = isset($input['id']) ? (int)$input['id'] : 0;
	if ($id) {
		$entry = new VaultEntry($id, TRUE);
		if (!$entry->key || (int)$entry->get('vle_usr_user_id') !== $user_id) {
			return LogicResult::error('That entry does not belong to you.');
		}
		$entry->set('vle_updated_time', gmdate('Y-m-d H:i:s'));
	} else {
		$entry = new VaultEntry(NULL);
		$entry->set('vle_usr_user_id', $user_id);
	}
	$entry->set('vle_ciphertext', $ciphertext);
	$entry->save();

	return LogicResult::render(['id' => (int)$entry->key]);
}

function entry_save_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Create or update one encrypted password entry (opaque blob)',
	];
}
?>

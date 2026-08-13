<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/** Return every entry blob for the user (live, or trashed when trashed=1). The
 *  client decrypts and searches in memory - there is no server-side search
 *  because there is deliberately no plaintext to index. */
function entries_list_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/vault/data/vault_entries_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$trashed = !empty($input['trashed']);
	$entries = new MultiVaultEntry(['user_id' => $user_id, 'deleted' => $trashed], ['vle_updated_time' => 'DESC']);
	$entries->load();

	$out = [];
	foreach ($entries as $entry) {
		$out[] = [
			'id'           => (int)$entry->key,
			'ciphertext'   => $entry->get('vle_ciphertext'),
			'created_time' => $entry->get('vle_created_time'),
			'updated_time' => $entry->get('vle_updated_time'),
		];
	}

	return LogicResult::render(['entries' => $out, 'trashed' => $trashed]);
}

function entries_list_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'List the user\'s encrypted password entries (opaque blobs) - live, or trashed',
	];
}
?>

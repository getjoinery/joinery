<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/** Persist the store DEK sealed to the vault public key, once. CREATE-ONLY:
 *  the sealed blob is the only copy of the store key in existence, so an
 *  overwrite would permanently orphan every entry encrypted under it (a
 *  session-riding attacker, a stale tab, and a two-device first-unlock race
 *  must all bounce off). A future key-rotation flow ships as its own action
 *  with its own proof; this one refuses to touch an existing row. */
function keyring_save_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/vault/data/vault_keyring_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$wrapped_dek = isset($input['wrapped_dek']) ? (string)$input['wrapped_dek'] : '';
	if ($wrapped_dek === '') {
		return LogicResult::error('Missing the sealed store key.');
	}

	if (VaultKeyring::loadForUser($user_id)) {
		return LogicResult::error('Your vault store key is already set up.', ['already_set_up' => true]);
	}

	$keyring = new VaultKeyring(NULL);
	$keyring->set('vlk_usr_user_id', $user_id);
	$keyring->set('vlk_wrapped_dek', $wrapped_dek);
	$keyring->save();

	return LogicResult::render(['set_up' => true]);
}

function keyring_save_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Store the password store DEK sealed to the vault public key (opaque blob) - create-only, an existing sealed key is never overwritten',
	];
}
?>

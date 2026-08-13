<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/** Return the store DEK sealed to the user's vault public key (opaque blob), or
 *  null if the store isn't initialised yet. */
function keyring_get_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/vault/data/vault_keyring_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$keyring = VaultKeyring::loadForUser($user_id);
	return LogicResult::render([
		'set_up'      => $keyring !== null,
		'wrapped_dek' => $keyring ? $keyring->get('vlk_wrapped_dek') : null,
	]);
}

function keyring_get_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Return the password store DEK sealed to the vault public key (opaque blob) - never inspected server-side',
	];
}
?>

<?php
/**
 * The signed-in member's vault key file, built fresh: the wrapped-key rows,
 * public key and salts that let a recovery code rebuild the account key
 * offline. Nothing in it opens anything alone, so it needs no unlock and no
 * step-up; it is offered on the security page as an optional download.
 *
 * @version 1.0
 */
function vault_key_file_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(0);

	$vault = UserEncryptionVault::loadForUser((int)$session->get_user_id());
	if (!$vault) {
		return LogicResult::error('You have no encryption key yet, so there is no key file.');
	}

	return LogicResult::render(['key_file' => VaultCeremonies::keyFile($vault)]);
}

function vault_key_file_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('min_user_permission' => 0, 'requires_browser_session' => true),
		'description' => 'Return the current user\'s vault key file, built from the vault as it stands now (wrapped keys, public key, salts; no secret material)',
	];
}
?>

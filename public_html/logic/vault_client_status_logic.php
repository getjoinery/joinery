<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Client-custody vault status: the keyring view the browser needs to unlock a
 * scope (public key, KDF salt/params, and every wrapping's opaque blob). No
 * secret material - the blobs are useless without a KEK the server never holds.
 *
 * Also what the core ceremony (VaultKeyring.ensureUnlocked) needs to word its
 * prompt and offer only what will work: the scope's `label` from the registry,
 * and `passkeys_enabled`, since vault_client_setup refuses every setup while
 * passkeys are off.
 *
 * `has_second_factor` lets the ceremony tell a factorless account, before
 * setup, that a vault needs one (the re-enrollment gate asks for it after).
 *
 * @version 1.2 - has_second_factor in the payload
 * @version 1.1 - label and passkeys_enabled in the payload
 */
function vault_client_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$scope = isset($input['scope']) ? (string)$input['scope'] : '';
	try {
		VaultClientCustody::assertClientScope($scope);
		$payload = VaultClientCustody::statusPayload($user_id, $scope);
		$payload['label'] = VaultScopes::labelFor($scope);
		$payload['passkeys_enabled'] = (bool)Globalvars::get_instance()->get_setting('passkeys_enabled');
		$payload['has_second_factor'] = $session->user_has_second_factor(new User($user_id, TRUE));
		return LogicResult::render($payload);
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function vault_client_status_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Client-custody vault keyring status for a scope (public key, KDF params, opaque wrapping blobs) - no secret material. '
			. 'Also returns label (the scope\'s display name), passkeys_enabled (setup is refused while it is false) '
			. 'and has_second_factor (a vault needs one on the account).',
		'input' => [
			'scope' => ['type' => 'string', 'required' => true, 'label' => 'Client-custody scope'],
		],
	];
}
?>

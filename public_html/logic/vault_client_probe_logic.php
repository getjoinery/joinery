<?php

/**
 * vault_client_probe — the three facts a native client needs about one of the
 * caller's client-custody vaults, and nothing else.
 *
 * The full keyring view (vault_client_status) is deliberately browser-session
 * only: wrappings, salts, and KDF parameters are the material an unlock is
 * performed from, and unlocking belongs in the browser, where WebAuthn works
 * and where the user is present. A native client never unlocks — it received a
 * scope's secret key once, sealed to the device, during the device-link
 * ceremony. What it still needs to know is whether that vault exists at all,
 * its public key (to seal keys for what it writes), and the key generation (so
 * it can notice a rotation and stop trusting the key it holds).
 *
 * So this returns exactly those three, for any registered client-custody scope
 * (Drive's by default), and is reachable with a session key.
 *
 * @version 1.0
 */

function vault_client_probe_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}

	$scope = isset($input['scope']) && $input['scope'] !== '' ? (string)$input['scope'] : 'drive';

	try {
		// Refuses an unregistered scope and a server-custody one: the server
		// window's key is never a native client's to hold.
		VaultClientCustody::assertClientScope($scope);
		$vault = VaultClientCustody::loadVault($user_id, $scope);
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}

	if (!$vault) {
		return LogicResult::render(array(
			'ok'             => true,
			'scope'          => $scope,
			'set_up'         => false,
			'public_key'     => null,
			'key_generation' => 0,
		));
	}

	return LogicResult::render(array(
		'ok'             => true,
		'scope'          => $scope,
		'set_up'         => true,
		'public_key'     => $vault->get('uev_public_key'),
		'key_generation' => (int)$vault->get('uev_key_generation'),
	));
}

function vault_client_probe_logic_descriptor(): array {
	return array(
		'description'      => 'Whether the caller has a client-custody vault for a scope (drive by default), its public key, and its key generation — the lean probe native clients use. Carries no wrappings, salts, or KDF parameters: those are unlock material and stay on the browser-only vault_client_status action.',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array('capability' => 'read'),
		'input'            => array(
			'scope' => array('type' => 'string', 'required' => false, 'max_length' => 32, 'label' => 'Client-custody vault scope (default drive)'),
		),
	);
}
?>

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
 * Two more facts let a device notice it has been forgotten: a rotation or a
 * recovery-code use clears the scope from every device's row
 * (VaultClientCustody::forgetScopeOnDevices / forgetDevices), and the device
 * that asks learns it here — `held_by_this_device` goes false and it wipes the
 * key it holds. `pending_public_key` is a rotation in progress: the key a
 * device will need to be handed again once it commits. A call from a device
 * also counts as the device being seen. `device_linked` says whether the
 * calling key has a device row at all: false before the first handover, and
 * false again once the device was unlinked, so a device that knows it was
 * handed a key reads false as "unlinked".
 *
 * @version 1.2 - device_linked
 * @version 1.1 - pending_public_key and held_by_this_device (specs/fortress_mobile_apps.md § R2)
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

	// The calling device's own row, when the caller is a key that has one. A
	// browser session has no device and holds nothing on one.
	$held = false;
	$api_key_id = (int)$session->get_api_key_id();
	$device = $api_key_id ? SyncDevice::for_api_key($api_key_id) : null;
	if ($device) {
		$held = in_array($scope, $device->vault_scopes(), true);
		try {
			$device->touch_seen();
		} catch (Exception $e) {
			// a check-in stamp never disturbs the answer it rides in on
		}
	}

	if (!$vault) {
		return LogicResult::render(array(
			'ok'                  => true,
			'scope'               => $scope,
			'set_up'              => false,
			'public_key'          => null,
			'key_generation'      => 0,
			'pending_public_key'  => null,
			'held_by_this_device' => false,
			'device_linked'       => $device !== null,
		));
	}

	return LogicResult::render(array(
		'ok'                  => true,
		'scope'               => $scope,
		'set_up'              => true,
		'public_key'          => $vault->get('uev_public_key'),
		'key_generation'      => (int)$vault->get('uev_key_generation'),
		'pending_public_key'  => $vault->get('uev_pending_key_generation') !== null
			? $vault->get('uev_pending_public_key') : null,
		'held_by_this_device' => $held,
		'device_linked'       => $device !== null,
	));
}

function vault_client_probe_logic_descriptor(): array {
	return array(
		'description'      => 'Whether the caller has a client-custody vault for a scope (drive by default), its public key, its key generation, a rotation\'s pending public key, and whether the calling device is still listed as holding the scope — the lean probe native clients use. Carries no wrappings, salts, or KDF parameters: those are unlock material and stay on the browser-only vault_client_status action.',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array('capability' => 'read'),
		'input'            => array(
			'scope' => array('type' => 'string', 'required' => false, 'max_length' => 32, 'label' => 'Client-custody vault scope (default drive)'),
		),
	);
}
?>

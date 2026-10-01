<?php

/**
 * drive_device_link_approve — the moment a computer becomes one of the user's
 * devices.
 *
 * Everything that makes this safe happens here, in the browser, where the user
 * is signed in and can be asked to prove it again: a fresh step-up gates the
 * approval, the credential is minted server-side (the device never sends one),
 * and the device identity is created at the same instant so a linked machine is
 * never a nameless key.
 *
 * If the user chose to give this device their encrypted folders, the browser
 * has already unlocked the vault and sealed the vault secret key to the
 * device's public key. That sealed blob passes through here untouched — the
 * server stores ciphertext it cannot open, exactly as it does everywhere else
 * in the client-custody design.
 *
 * Drive's key rides in `sealed_vault_key` (with `enable_vault`), the field the
 * shipped sync client reads. Every other client-custody scope the user chose
 * rides in `sealed_vault_keys` ({scope: blob}), each sealed to the same device
 * key in its own unlock. The device records which scopes it was handed.
 *
 * A ceremony a signed-in app opened (device_key_enroll) is bound to that app's
 * key already: approving it mints nothing. The vaults handed over are recorded
 * on the SyncDevice row for that key — created here on the first handover,
 * reused after — and only the bound user may approve it.
 *
 * @version 1.2 - bound ceremonies (a signed-in app): mint nothing, record the handover on its key's device;
 *   open to anyone holding a content vault, not only with Drive on
 * @version 1.1 - sealed_vault_keys for scopes beyond Drive; sde_vault_scopes recorded
 */

function drive_device_link_approve_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/device_links_class.php'));
	require_once(PathHelper::getIncludePath('data/sync_devices_class.php'));
	require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$code = (string)($input['code'] ?? '');
	if (trim($code) === '') {
		return LogicResult::error('Enter the code shown on the device.');
	}

	// Linking a device is a credential change — the same bar as adding a vault
	// unlocker. A borrowed unlocked browser must not be able to mint a
	// standing credential for a machine the owner has never seen. Outstanding
	// rather than "recent" because an account with no second factor has nothing
	// to step up with, and would otherwise be refused forever.
	if ($session->step_up_outstanding(null, 300)) {
		return LogicResult::error('Confirm it is you before linking a new device.', array('requires_stepup' => true));
	}

	if (DeviceLink::guessing_too_much()) {
		return LogicResult::error('Too many incorrect codes. Wait a few minutes and try again.');
	}

	if (!DeviceLink::linking_available($user_id)) {
		return LogicResult::error('There is nothing to hand a device: turn on Drive or set up an end-to-end vault first.');
	}

	$link = DeviceLink::load_open_by_code($code);
	if (!$link || !$link->actionable_by($user_id)) {
		DeviceLink::record_failed_guess();
		return LogicResult::error('That code is not valid, or it has expired. Codes last ten minutes — start again on the device for a fresh one.');
	}

	$enable_vault     = !empty($input['enable_vault']);
	$sealed_vault_key = isset($input['sealed_vault_key']) ? trim((string)$input['sealed_vault_key']) : '';
	$device_pubkey    = (string)$link->get('dlk_device_pubkey');

	if ($enable_vault) {
		if ($device_pubkey === '') {
			return LogicResult::error('This device did not offer a key to receive your encrypted folders, so it cannot be given them.');
		}
		if ($sealed_vault_key === '') {
			return LogicResult::error('The sealed vault key is missing. Unlock your vault and try again.');
		}
	}

	// Any other vault the user chose to hand over. Each must be a registered
	// client-custody scope the user has set up; Drive has its own field above.
	$sealed_vault_keys = array();
	$posted_keys = $input['sealed_vault_keys'] ?? array();
	if (!is_array($posted_keys)) {
		return LogicResult::error('sealed_vault_keys must map each vault to its sealed key.');
	}
	if ($posted_keys && $device_pubkey === '') {
		return LogicResult::error('This device did not offer a key to receive your vaults, so it cannot be given them.');
	}
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));
	foreach ($posted_keys as $scope => $blob) {
		$scope = (string)$scope;
		$blob = is_string($blob) ? trim($blob) : '';
		if ($scope === 'drive') {
			return LogicResult::error('Drive\'s key travels in sealed_vault_key, not sealed_vault_keys.');
		}
		if (!VaultScopes::isClientCustody($scope)) {
			return LogicResult::error('Unknown vault: ' . $scope . '.');
		}
		if (!VaultClientCustody::loadVault($user_id, $scope)) {
			return LogicResult::error('You have not set up ' . lcfirst(VaultScopes::labelFor($scope)) . ', so there is no key to hand over.');
		}
		if ($blob === '' || strlen($blob) > 4096) {
			return LogicResult::error('The sealed key for ' . lcfirst(VaultScopes::labelFor($scope)) . ' is missing or malformed. Unlock it and try again.');
		}
		$sealed_vault_keys[$scope] = $blob;
	}
	$handed_scopes = array_keys($sealed_vault_keys);
	if ($enable_vault && $sealed_vault_key !== '') {
		array_unshift($handed_scopes, 'drive');
	}

	$device_name = (string)$link->get('dlk_device_name');

	if ($link->is_bound()) {
		return _device_link_approve_bound($link, $user_id, $device_pubkey, $handed_scopes,
			$enable_vault ? $sealed_vault_key : '', $sealed_vault_keys);
	}

	// Mint the credential, then the identity that owns it. The key is labelled
	// with the device name so it is recognizable on the API Keys page too.
	$minted = ApiKey::CreateSessionKey($user_id, $device_name);
	$api_key = $minted['api_key'];

	$device = new SyncDevice(NULL);
	$device->set('sde_usr_user_id', $user_id);
	$device->set('sde_apk_api_key_id', (int)$api_key->key);
	$device->set('sde_device_name', substr($device_name, 0, 64));
	$device->set('sde_platform', (string)$link->get('dlk_platform'));
	if ($handed_scopes && $device_pubkey !== '') {
		$device->set('sde_device_pubkey', $device_pubkey);
		$device->set('sde_vault_scopes', implode(',', $handed_scopes));
	}
	$device->save();

	$link->set('dlk_usr_user_id', $user_id);
	$link->set('dlk_apk_api_key_id', (int)$api_key->key);
	$link->set('dlk_sde_sync_device_id', (int)$device->key);
	$link->set('dlk_status', DeviceLink::STATUS_APPROVED);
	if ($enable_vault && $sealed_vault_key !== '') {
		$link->set('dlk_sealed_vault_key', $sealed_vault_key);
	}
	if ($sealed_vault_keys) {
		$link->set('dlk_sealed_vault_keys', json_encode($sealed_vault_keys));
	}
	$link->seal_secret($minted['secret_key']);
	$link->save();

	return LogicResult::render(array(
		'ok'          => true,
		'approved'    => true,
		'device_id'   => (int)$device->key,
		'device_name' => $device_name,
		'vault_shared' => (bool)($enable_vault && $sealed_vault_key !== ''),
		'vault_scopes' => $handed_scopes,
	));
}

/**
 * Approve a ceremony a signed-in app opened: the app keeps the credential it
 * has, and what it gains is the sealed keys and a device row saying it holds
 * them. A bound ceremony exists to hand over keys, so one with none is refused.
 */
function _device_link_approve_bound(DeviceLink $link, int $user_id, string $device_pubkey,
		array $handed_scopes, string $sealed_drive_key, array $sealed_vault_keys): LogicResult {
	if (!$handed_scopes) {
		return LogicResult::error('Choose at least one vault to hand this device.');
	}
	$api_key = new ApiKey((int)$link->get('dlk_apk_api_key_id'), TRUE);
	if (!$api_key->key || $api_key->get('apk_delete_time')
		|| (int)$api_key->get('apk_usr_user_id') !== $user_id) {
		return LogicResult::error('That device has been signed out. Sign in on it again and start over.');
	}

	$device = SyncDevice::for_api_key((int)$api_key->key);
	$scopes = $handed_scopes;
	if ($device) {
		// Same device key: what it already holds stays held. A new device key
		// (the app was reinstalled) cannot open anything sealed to the old one.
		if ((string)$device->get('sde_device_pubkey') === $device_pubkey) {
			$scopes = array_values(array_unique(array_merge($device->vault_scopes(), $handed_scopes)));
		}
	} else {
		$device = new SyncDevice(NULL);
		$device->set('sde_usr_user_id', $user_id);
		$device->set('sde_apk_api_key_id', (int)$api_key->key);
		$device->set('sde_platform', (string)$link->get('dlk_platform'));
	}
	$device->set('sde_device_name', substr((string)$link->get('dlk_device_name'), 0, 64));
	$device->set('sde_device_pubkey', $device_pubkey);
	$device->set('sde_vault_scopes', implode(',', $scopes));
	$device->save();

	$link->set('dlk_sde_sync_device_id', (int)$device->key);
	$link->set('dlk_status', DeviceLink::STATUS_APPROVED);
	if ($sealed_drive_key !== '') {
		$link->set('dlk_sealed_vault_key', $sealed_drive_key);
	}
	if ($sealed_vault_keys) {
		$link->set('dlk_sealed_vault_keys', json_encode($sealed_vault_keys));
	}
	$link->save();

	return LogicResult::render(array(
		'ok'           => true,
		'approved'     => true,
		'device_id'    => (int)$device->key,
		'device_name'  => (string)$link->get('dlk_device_name'),
		'vault_shared' => $sealed_drive_key !== '',
		'vault_scopes' => $handed_scopes,
	));
}

function drive_device_link_approve_logic_descriptor(): array {
	return array(
		'requires_setting' => 'drive_active',
		'description'      => 'Approve a pending device-link ceremony: mints the device\'s session credential (none for a ceremony a signed-in app opened, which keeps its own), creates or updates its SyncDevice identity, and (optionally) stores browser-sealed vault keys for the device to collect: the drive key in `sealed_vault_key`, any other client-custody vault in `sealed_vault_keys` ({scope: blob}). Requires a signed-in browser session and a recent step-up. Every sealed key is opaque ciphertext produced in the browser — the server cannot open it.',
		'requires_session' => true,
		'mutates'          => true,
		'auth'             => array('requires_browser_session' => true),
		'input'            => array(
			'code'             => array('type' => 'string', 'required' => true, 'max_length' => 32, 'label' => 'Link code'),
			'enable_vault'     => array('type' => 'bool', 'required' => false, 'label' => 'Give this device your encrypted folders'),
			'sealed_vault_key' => array('type' => 'string', 'required' => false, 'max_length' => 4096, 'label' => 'Drive vault secret key sealed to the device public key'),
			'sealed_vault_keys' => array('type' => 'object', 'required' => false, 'label' => 'Other client-custody vault secret keys sealed to the device public key, by scope'),
		),
	);
}
?>

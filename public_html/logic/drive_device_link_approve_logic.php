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
 */

function drive_device_link_approve_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
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
	// standing credential for a machine the owner has never seen.
	$service = new PasskeyService();
	if (!$service->hasRecentStepUp(300)) {
		return LogicResult::error('Confirm it is you before linking a new device.', array('requires_stepup' => true));
	}

	if (DeviceLink::guessing_too_much()) {
		return LogicResult::error('Too many incorrect codes. Wait a few minutes and try again.');
	}

	$link = DeviceLink::load_open_by_code($code);
	if (!$link) {
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

	$device_name = (string)$link->get('dlk_device_name');

	// Mint the credential, then the identity that owns it. The key is labelled
	// with the device name so it is recognizable on the API Keys page too.
	$minted = ApiKey::CreateSessionKey($user_id, $device_name);
	$api_key = $minted['api_key'];

	$device = new SyncDevice(NULL);
	$device->set('sde_usr_user_id', $user_id);
	$device->set('sde_apk_api_key_id', (int)$api_key->key);
	$device->set('sde_device_name', substr($device_name, 0, 64));
	$device->set('sde_platform', (string)$link->get('dlk_platform'));
	if ($enable_vault && $device_pubkey !== '') {
		$device->set('sde_device_pubkey', $device_pubkey);
	}
	$device->save();

	$link->set('dlk_usr_user_id', $user_id);
	$link->set('dlk_apk_api_key_id', (int)$api_key->key);
	$link->set('dlk_sde_sync_device_id', (int)$device->key);
	$link->set('dlk_status', DeviceLink::STATUS_APPROVED);
	if ($enable_vault && $sealed_vault_key !== '') {
		$link->set('dlk_sealed_vault_key', $sealed_vault_key);
	}
	$link->seal_secret($minted['secret_key']);
	$link->save();

	return LogicResult::render(array(
		'ok'          => true,
		'approved'    => true,
		'device_id'   => (int)$device->key,
		'device_name' => $device_name,
		'vault_shared' => (bool)($enable_vault && $sealed_vault_key !== ''),
	));
}

function drive_device_link_approve_logic_descriptor(): array {
	return array(
		'description'      => 'Approve a pending device-link ceremony: mints the device\'s session credential, creates its SyncDevice identity, and (optionally) stores the browser-sealed drive vault key for the device to collect. Requires a signed-in browser session and a recent step-up. `sealed_vault_key` is opaque ciphertext produced in the browser — the server cannot open it.',
		'requires_session' => true,
		'mutates'          => true,
		'auth'             => array('requires_browser_session' => true),
		'input'            => array(
			'code'             => array('type' => 'string', 'required' => true, 'max_length' => 32, 'label' => 'Link code'),
			'enable_vault'     => array('type' => 'bool', 'required' => false, 'label' => 'Give this device your encrypted folders'),
			'sealed_vault_key' => array('type' => 'string', 'required' => false, 'max_length' => 4096, 'label' => 'Drive vault secret key sealed to the device public key'),
		),
	);
}
?>

<?php

/**
 * drive_device_revoke — the lost-laptop button.
 *
 * Unlinking has to actually cut the machine off, so this revokes the session
 * key in the same breath as retiring the device. A device row without its
 * credential revoked would be a list that lies: the user would see the laptop
 * disappear from the page while it carried on syncing.
 *
 * The device keeps whatever it has already downloaded — that is a property of
 * handing someone a copy of a file, not something a server can take back. What
 * stops here is future access.
 */

function drive_device_revoke_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/sync_devices_class.php'));
	require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}

	$device_id = (int)($input['device_id'] ?? 0);
	if ($device_id <= 0) {
		return LogicResult::error('Which device?');
	}

	$device = new SyncDevice($device_id, true);
	if (!$device->key || (int)$device->get('sde_usr_user_id') !== $user_id
		|| $device->get('sde_delete_time') !== null) {
		return LogicResult::error('Device not found.');
	}

	// Credential first: if anything goes wrong after this point the worst
	// outcome is a listed device that can no longer reach anything, which is
	// the safe direction to fail in.
	$api_key_id = (int)$device->get('sde_apk_api_key_id');
	if ($api_key_id > 0) {
		$key = new ApiKey($api_key_id, true);
		if ($key->key && (int)$key->get('apk_usr_user_id') === $user_id) {
			$key->soft_delete();
		}
	}

	$device->soft_delete();

	return LogicResult::render(array('ok' => true, 'revoked' => true, 'device_id' => $device_id));
}

function drive_device_revoke_logic_descriptor(): array {
	return array(
		'description'      => 'Unlink a sync device and revoke the session key it authenticates with, cutting off further access immediately.',
		'requires_session' => true,
		'mutates'          => true,
		'auth'             => array('capability' => 'delete'),
		'input'            => array(
			'device_id' => array('type' => 'int', 'required' => true, 'min' => 1, 'label' => 'Device id'),
		),
	);
}
?>

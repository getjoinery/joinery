<?php

/**
 * drive_device_rename — give a linked computer a name the user recognizes.
 *
 * The name is not decoration: it is what appears on a conflict copy when two
 * machines edit the same file, so "MacBook" beats whatever hostname the
 * operating system invented.
 */

function drive_device_rename_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/sync_devices_class.php'));

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}

	$device_id = (int)($input['device_id'] ?? 0);
	$name = trim((string)($input['name'] ?? ''));
	if ($device_id <= 0) {
		return LogicResult::error('Which device?');
	}
	if ($name === '') {
		return LogicResult::error('A device name is required.');
	}

	$device = new SyncDevice($device_id, true);
	if (!$device->key || (int)$device->get('sde_usr_user_id') !== $user_id
		|| $device->get('sde_delete_time') !== null) {
		return LogicResult::error('Device not found.');
	}

	$device->set('sde_device_name', substr($name, 0, 64));
	$device->save();

	return LogicResult::render(array('ok' => true, 'device' => $device->export()));
}

function drive_device_rename_logic_descriptor(): array {
	return array(
		'description'      => 'Rename one of the caller\'s linked sync devices. The name is used in conflict-copy filenames.',
		'requires_session' => true,
		'mutates'          => true,
		'auth'             => array('capability' => 'write'),
		'input'            => array(
			'device_id' => array('type' => 'int', 'required' => true, 'min' => 1, 'label' => 'Device id'),
			'name'      => array('type' => 'string', 'required' => true, 'max_length' => 64, 'label' => 'Device name'),
		),
	);
}
?>

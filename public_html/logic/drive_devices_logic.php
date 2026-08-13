<?php

/**
 * drive_devices — the computers currently linked to the caller's Drive.
 *
 * This is the visible trust surface: which machines hold a credential, when
 * each last checked in, and how far through the change feed it has got. A
 * device that has quietly stopped syncing is the failure mode users of other
 * sync products discover weeks late, by noticing a file is missing. Here it is
 * a line on a page that says it last checked in on Tuesday.
 */

function drive_devices_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/sync_devices_class.php'));

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}

	$devices = new MultiSyncDevice(
		array('user_id' => $user_id, 'deleted' => false),
		array('sde_create_time' => 'DESC')
	);
	$devices->load();

	$out = array();
	foreach ($devices as $device) {
		$out[] = $device->export();
	}

	return LogicResult::render(array('ok' => true, 'devices' => $out));
}

function drive_devices_logic_descriptor(): array {
	return array(
		'description'      => 'The sync devices linked to the caller\'s account, with last check-in time and change-feed position.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => false,
		'auth'             => array('capability' => 'read'),
		'input'            => array(),
	);
}
?>

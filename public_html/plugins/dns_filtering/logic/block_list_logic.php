<?php
/**
 * List the scheduled and always-on blocks for one owned device, with full
 * contents (filters + services + rules + schedule) and the device's
 * hard-block hostname list.
 *
 * Input: device_id.
 *
 * Exposed as POST /api/v1/action/dns_filtering/block_list.
 *
 * @version 1.0
 */

function block_list_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/devices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/scheduled_blocks_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/includes/ScrollDaddyHelper.php'));

	$session = SessionControl::get_instance();

	if (!$session->get_user_id()) {
		return LogicResult::error('Not logged in.');
	}

	$device_id = isset($input['device_id']) ? (int)$input['device_id'] : 0;
	if (!$device_id) {
		return LogicResult::error('device_id is required.');
	}

	try {
		$device = new SdDevice($device_id, TRUE);
		$device->authenticate_read(array(
			'current_user_id'         => $session->get_user_id(),
			'current_user_permission' => $session->get_permission(),
		));
	} catch (Exception $e) {
		return LogicResult::error('Device not found or access denied.');
	}

	$always_on = SdScheduledBlock::getOrCreateAlwaysOnBlock($device->key);

	$blocks = new MultiSdScheduledBlock(
		array('device_id' => $device->key, 'is_always_on' => false),
		array('sdb_scheduled_block_id' => 'ASC')
	);
	$blocks->load();

	$scheduled = array();
	foreach ($blocks as $block) {
		$scheduled[] = ScrollDaddyHelper::exportBlock($block, true);
	}

	return LogicResult::render(array(
		'device_id' => $device->key,
		'always_on_block' => ScrollDaddyHelper::exportBlock($always_on, true),
		'scheduled_blocks' => $scheduled,
		'hard_block_hostnames' => ScrollDaddyHelper::getHardBlockHostnames($device->key),
	));
}

function block_list_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'List a device\'s always-on and scheduled blocks with full contents (device_id)',
	];
}

?>

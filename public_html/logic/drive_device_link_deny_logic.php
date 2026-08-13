<?php

/**
 * drive_device_link_deny — refuse a pending device-link ceremony.
 *
 * The important case is the one where the user did not start it. Denying ends
 * the ceremony immediately instead of letting it sit open for its remaining
 * minutes, and the waiting client is told plainly that it was refused rather
 * than being left to time out — which is the difference between "someone said
 * no" and "the network is flaky".
 *
 * No step-up: refusing access can only ever reduce it.
 */

function drive_device_link_deny_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/device_links_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);

	$code = (string)($input['code'] ?? '');
	if (trim($code) === '') {
		return LogicResult::error('Enter the code shown on the device.');
	}

	if (DeviceLink::guessing_too_much()) {
		return LogicResult::error('Too many incorrect codes. Wait a few minutes and try again.');
	}

	$link = DeviceLink::load_open_by_code($code);
	if (!$link) {
		DeviceLink::record_failed_guess();
		return LogicResult::error('That code is not valid, or it has expired.');
	}

	$link->set('dlk_status', DeviceLink::STATUS_DENIED);
	$link->save();

	return LogicResult::render(array('ok' => true, 'denied' => true));
}

function drive_device_link_deny_logic_descriptor(): array {
	return array(
		'description'      => 'Refuse a pending device-link ceremony so the waiting client is told no immediately instead of timing out.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => true,
		'auth'             => array('requires_browser_session' => true),
		'input'            => array(
			'code' => array('type' => 'string', 'required' => true, 'max_length' => 32, 'label' => 'Link code'),
		),
	);
}
?>

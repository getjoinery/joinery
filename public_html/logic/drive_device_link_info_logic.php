<?php

/**
 * drive_device_link_info — what the browser shows before the user decides.
 *
 * The user is about to hand a machine standing access to their files, so they
 * are shown what is actually asking: the name it gave, what kind of computer it
 * says it is, and the address the request came from. If any of that is not the
 * laptop they just set up, the code did not come from them.
 *
 * This is the one place a link code can be probed from, so a run of wrong codes
 * from one address shuts that address out for a while.
 */

function drive_device_link_info_logic(array $input): LogicResult {
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
		return LogicResult::error('That code is not valid, or it has expired. Codes last ten minutes — start again on the device for a fresh one.');
	}

	return LogicResult::render(array(
		'ok'           => true,
		'device_name'  => $link->get('dlk_device_name'),
		'platform'     => $link->get('dlk_platform'),
		'request_ip'   => $link->get('dlk_request_ip'),
		'expires_time' => $link->get('dlk_expires_time'),
		// Whether this device can receive the encrypted-folder key at all. No
		// public key means it never asked for one, so the browser must not offer
		// to seal anything to it.
		'supports_vault' => ($link->get('dlk_device_pubkey') !== null && $link->get('dlk_device_pubkey') !== ''),
		'device_pubkey'  => $link->get('dlk_device_pubkey'),
	));
}

function drive_device_link_info_logic_descriptor(): array {
	return array(
		'description'      => 'Details of a pending device-link ceremony, looked up by its code, so the approval page can show the user what is asking for access.',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array('requires_browser_session' => true),
		'input'            => array(
			'code' => array('type' => 'string', 'required' => true, 'max_length' => 32, 'label' => 'Link code'),
		),
	);
}
?>

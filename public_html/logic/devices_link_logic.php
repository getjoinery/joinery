<?php

/**
 * The device-approval page's server side.
 *
 * Deliberately thin. The page renders a form and everything that matters
 * happens over the API from the browser — because the decisive steps (unlock
 * the vault, seal the key to the device, prove it is really you) can only
 * happen client-side, and splitting them across a form POST and a fetch would
 * mean two ways to approve a device instead of one.
 *
 * So this only settles what the page needs before it can draw itself: is the
 * user signed in, do they have encrypted folders at all, and what code (if any)
 * did they arrive with.
 */

function devices_link_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}

	// Whether to offer the encrypted-folders checkbox at all. Offering it to
	// someone with no vault would be offering to share a key that does not
	// exist.
	$has_vault = false;
	try {
		$vault = VaultClientCustody::loadVault($user_id, 'drive');
		$has_vault = ($vault !== null);
	} catch (Exception $e) {
		$has_vault = false;
	}

	return LogicResult::render(array(
		'code'      => trim((string)($input['code'] ?? '')),
		'has_vault' => $has_vault,
		'passkeys_enabled' => (bool)$settings->get_setting('passkeys_enabled'),
	));
}

/**
 * The approval form. Shared with GET /api/v1/form/devices_link so a native
 * client (or a future app screen) renders the same fields the website does.
 */
function devices_link_logic_form($formwriter, $page_vars = array(), $input = array()) {
	$formwriter->textinput('code', 'Code from the device', array(
		'required' => true,
		'value'    => $page_vars['code'] ?? '',
		'helptext' => 'Eight characters, shown on the computer you are linking.',
	));

	if (!empty($page_vars['has_vault'])) {
		$formwriter->checkboxinput('enable_vault', 'Let this device open my encrypted folders', array(
			'helptext' => 'You will be asked to unlock your vault. Your key is sealed to this device in your browser — the server never sees it. Leave this off and the device syncs everything except encrypted folders.',
		));
	}

	$formwriter->submitbutton('btn_approve', 'Link this device');
}

function devices_link_logic_descriptor(): array {
	return array(
		'description'      => 'The device-approval page: resolves whether to offer the encrypted-folders handoff and carries the arriving link code.',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array('requires_browser_session' => true),
		'input'            => array(
			'code' => array('type' => 'string', 'required' => false, 'max_length' => 32, 'label' => 'Link code'),
		),
	);
}
?>

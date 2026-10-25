<?php
/**
 * app_password_create — mint an app password: a credential for ONE declared
 * app purpose (AppAccess), for one device the person names.
 *
 * POST /api/v1/action/app_password_create (browser session only). Input:
 * purpose, device_name. A plugin's page calls it (the Mail apps page, for the
 * `mail` purpose). Answers the password once, as the person types it:
 * `jm-{public}-{secret}` — it is never shown again, and the server keeps only
 * a hash.
 *
 * Minting a standing credential is a credential change, the same bar as
 * linking a device: a fresh step-up, so a borrowed unlocked browser cannot
 * leave a password behind for later. An undeclared purpose is refused.
 *
 * specs/mail_apps_plugin.md § Core: app access.
 *
 * @version 1.0.0
 */

function app_password_create_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$purpose = trim((string)($input['purpose'] ?? ''));
	$device_name = trim((string)($input['device_name'] ?? ''));

	if (!AppAccess::isAvailable($purpose)) {
		return LogicResult::error('That kind of app access is not available on this site.');
	}
	if ($device_name === '') {
		return LogicResult::error('Name the device this password is for, such as "Laptop".');
	}

	if ($session->step_up_outstanding(null, 300)) {
		return LogicResult::error('Confirm it is you before making an app password.', array('requires_stepup' => true));
	}

	try {
		$minted = ApiKey::CreateAppPassword($user_id, $purpose, $device_name);
	} catch (ApiKeyException $e) {
		return LogicResult::error($e->getMessage());
	}
	$api_key = $minted['api_key'];

	// Shown in groups of four so it can be read and typed; the groups are
	// forgiven when it is entered (ApiKey::SplitAppPassword()).
	$password = $minted['password'];

	return LogicResult::render(array(
		'credential_id' => (int)$api_key->key,
		'device_name'   => (string)$api_key->get('apk_name'),
		'purpose'       => $purpose,
		'label'         => AppAccess::label($purpose),
		'password'      => $password,
	));
}

function app_password_create_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('min_user_permission' => 0, 'requires_browser_session' => true),
		'description' => 'Make an app password for one declared app purpose (such as mail apps) and one named device; answers the password once',
		'input' => array(
			'purpose'     => array('type' => 'string', 'required' => true, 'label' => 'Purpose'),
			'device_name' => array('type' => 'string', 'required' => true, 'label' => 'Device name'),
		),
	);
}
?>

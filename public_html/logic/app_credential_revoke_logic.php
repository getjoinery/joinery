<?php
/**
 * app_credential_revoke — sign out one app credential (any kind: an app
 * password today), by its id. The person's own credentials only.
 *
 * POST /api/v1/action/app_credential_revoke (browser session only). Input:
 * credential_id. Revoking ends the window it borrowed from the person's unlock
 * at once (ApiKey::soft_delete()).
 *
 * specs/mail_apps_plugin.md § Core: app access.
 *
 * @version 1.0.0
 */

function app_credential_revoke_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$credential_id = intval($input['credential_id'] ?? 0);
	$api_key = $credential_id > 0 ? new ApiKey($credential_id, TRUE) : null;
	if (!$api_key || !$api_key->key || !$api_key->is_app_credential()
			|| (int)$api_key->get('apk_usr_user_id') !== $user_id) {
		return LogicResult::error('That app password was not found.');
	}
	if (!$api_key->get('apk_delete_time')) {
		$api_key->soft_delete();
	}
	return LogicResult::render(array('revoked' => true, 'credential_id' => $credential_id));
}

function app_credential_revoke_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('min_user_permission' => 0, 'requires_browser_session' => true),
		'description' => 'Revoke one of your app credentials (such as a mail app\'s app password); ends any unlock it borrowed',
		'input' => array(
			'credential_id' => array('type' => 'int', 'required' => true, 'label' => 'Credential'),
		),
	);
}
?>

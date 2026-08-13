<?php
/**
 * API action: security_overview — the owner's security status as JSON.
 *
 * POST /api/v1/action/security_overview (session key). Returns TOTP
 * status, the app-session list (the only read surface for ApiKey — do
 * not add CRUD exposure to ApiKey instead), passkey count, and vault
 * status. Shares security_logic.php's data sources.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function security_overview_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/api_keys_class.php'));
	require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$user = new User($session->get_user_id(), TRUE);

	$backup_codes_remaining = 0;
	if ($user->has_totp_enabled()) {
		$hashes = $user->get('usr_totp_backup_codes');
		if (is_string($hashes)) {
			$hashes = json_decode($hashes, true);
		}
		$backup_codes_remaining = is_array($hashes) ? count($hashes) : 0;
	}

	$app_sessions = new MultiApiKey(array(
		'user_id' => $user->key,
		'type'    => ApiKey::TYPE_SESSION,
		'deleted' => false,
	), array('create_time' => 'DESC'));
	$app_sessions->load();

	$current_key_id = $session->get_api_key_id();

	$sessions_out = array();
	foreach ($app_sessions as $key) {
		$sessions_out[] = array(
			'api_key_id'    => (int)$key->key,
			'device_label'  => $key->get('apk_name'),
			'created_time'  => $key->get('apk_create_time'),
			'last_used_time'=> $key->get('apk_last_used_time'),
			'is_current'    => $current_key_id !== null && (int)$key->key === (int)$current_key_id,
		);
	}

	$passkeys = new MultiPasskey(array('user_id' => $user->key, 'deleted' => false));

	return LogicResult::render(array(
		'totp_enabled'             => $user->has_totp_enabled(),
		'totp_enabled_time'        => $user->get('usr_totp_enabled_time'),
		'backup_codes_remaining'   => $backup_codes_remaining,
		'app_sessions'             => $sessions_out,
		'passkey_count'            => $passkeys->count_all(),
		'vault_active'             => UserEncryptionVault::loadForUser($user->key) !== null,
	));
}

function security_overview_logic_descriptor() {
	return [
		'requires_session' => true,
		'description' => 'TOTP status, app-session list, passkey count, and vault status for the signed-in owner',
	];
}

?>

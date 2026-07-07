<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	$vault = UserEncryptionVault::loadForUser($user->key);
	if (!$vault) {
		return LogicResult::render(['set_up' => false]);
	}

	$wrappings = new MultiUserEncryptionWrapping(['vault_id' => $vault->key]);
	$wrappings->load();
	$wrapping_list = [];
	$unused_recovery = 0;
	$passkey_count = 0;
	$has_passphrase = false;
	foreach ($wrappings as $w) {
		$type = $w->get('uew_unlocker_type');
		if ($type === UserEncryptionWrapping::TYPE_RECOVERY && !$w->get('uew_is_used')) {
			$unused_recovery++;
		}
		if ($type === UserEncryptionWrapping::TYPE_PASSKEY) {
			$passkey_count++;
		}
		if ($type === UserEncryptionWrapping::TYPE_PASSPHRASE) {
			$has_passphrase = true;
		}
		$wrapping_list[] = [
			'id'            => (int)$w->key,
			'unlocker_type' => $type,
			'credential_id' => $w->get('uew_pkc_credential_id') ? (int)$w->get('uew_pkc_credential_id') : null,
			'label'         => $w->get('uew_label'),
			'is_used'       => (bool)$w->get('uew_is_used'),
			'created_time'  => $w->get('uew_created_time'),
		];
	}

	return LogicResult::render([
		'set_up'                    => true,
		'unlocked'                  => VaultUnlock::isOpen($user->key, UserEncryptionVault::SCOPE_USER),
		'key_generation'            => (int)$vault->get('uev_key_generation'),
		'passkey_wrapping_count'    => $passkey_count,
		'unused_recovery_code_count'=> $unused_recovery,
		'has_passphrase'            => $has_passphrase,
		'regenerate_recommended'    => $unused_recovery < 3,
		'wrappings'                 => $wrapping_list,
	]);
}

function vault_status_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Report the current user\'s vault setup/unlock status and enrolled unlockers (no secret material)',
	];
}
?>
